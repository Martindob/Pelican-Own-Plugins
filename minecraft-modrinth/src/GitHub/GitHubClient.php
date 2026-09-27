<?php

namespace Boy132\MinecraftModrinth\GitHub;

use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Minimal read-only GitHub REST client for one repository.
 *
 * - Only ever talks to https://api.github.com with the token. Redirects are followed by hand:
 *   another api.github.com URL keeps the token, a GitHub content host (raw/objects
 *   .githubusercontent.com) is fetched without it, and anything else is refused.
 * - Never throws an HTTP client exception (those carry the request, including the
 *   Authorization header): every failure becomes a GitHubSourceException.
 * - The token is only held by this object and is never part of a message, cache key or URL.
 */
class GitHubClient
{
    public const API_BASE = 'https://api.github.com';

    public const API_VERSION = '2022-11-28';

    protected const CONNECT_TIMEOUT = 3;

    protected const TIMEOUT = 15;

    protected const MAX_REDIRECTS = 3;

    public function __construct(
        protected readonly string $repository,
        #[\SensitiveParameter] protected readonly ?string $token,
    ) {
        if (!GitHubSourceRules::isValidRepository($repository)) {
            throw new GitHubSourceException(GitHubSourceException::NOT_CONFIGURED, 'Invalid repository name');
        }
    }

    /** SHA of the commit a branch currently points at. */
    public function getBranchHeadSha(string $branch): string
    {
        $response = $this->get('/commits/'.$this->encodePath($branch), 'application/vnd.github.sha');
        $sha = strtolower(trim($response->body()));

        if (!GitHubSourceRules::isValidSha($sha)) {
            throw new GitHubSourceException(GitHubSourceException::INVALID_RESPONSE, 'GitHub returned no commit SHA for the branch');
        }

        return $sha;
    }

    /**
     * Every GitHub Actions workflow run of a push of $sha to $branch.
     *
     * @return array<int, mixed>
     */
    public function getWorkflowRuns(string $sha, string $branch): array
    {
        $runs = [];

        for ($page = 1; $page <= 3; $page++) {
            $response = $this->get('/actions/runs', 'application/vnd.github+json', [
                'head_sha' => $sha,
                'event' => 'push',
                'branch' => $branch,
                'per_page' => 100,
                'page' => $page,
            ]);

            $data = $response->json();
            if (!is_array($data) || !isset($data['workflow_runs']) || !is_array($data['workflow_runs'])) {
                throw new GitHubSourceException(GitHubSourceException::INVALID_RESPONSE, 'Unexpected workflow runs response');
            }

            array_push($runs, ...array_values($data['workflow_runs']));

            if (count($data['workflow_runs']) < 100 || count($runs) >= (int) ($data['total_count'] ?? 0)) {
                break;
            }
        }

        return $runs;
    }

    /** Raw content of a file at a commit, refusing anything larger than $maxBytes. */
    public function getRawFile(string $path, string $sha, int $maxBytes, int $timeout = self::TIMEOUT): string
    {
        if (!GitHubSourceRules::isSafeRelativePath($path) || !GitHubSourceRules::isValidSha($sha)) {
            throw new GitHubSourceException(GitHubSourceException::INVALID_INDEX, 'Refusing to fetch an unsafe path');
        }

        $response = $this->get('/contents/'.$this->encodePath($path), 'application/vnd.github.raw+json', ['ref' => $sha], $timeout, $maxBytes);
        $body = $response->body();

        if (strlen($body) > $maxBytes) {
            throw new GitHubSourceException(GitHubSourceException::TOO_LARGE, "File $path is larger than $maxBytes bytes");
        }

        return $body;
    }

    /** @param  array<string, scalar>  $query */
    protected function get(string $repoPath, string $accept, array $query = [], int $timeout = self::TIMEOUT, ?int $maxBytes = null): Response
    {
        $this->assertNotCoolingDown();

        $url = self::API_BASE.'/repos/'.$this->repository.$repoPath;
        if (!empty($query)) {
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $sendToken = true;

        for ($redirects = 0; ; $redirects++) {
            try {
                $response = $this->request($accept, $timeout, $maxBytes, $sendToken)->get($url);
            } catch (ConnectionException) {
                throw new GitHubSourceException(GitHubSourceException::NETWORK, 'Could not reach GitHub (connection failed or timed out)');
            } catch (GitHubSourceException $exception) {
                throw $exception;
            } catch (Exception $exception) {
                if ($this->causedByTooLarge($exception)) {
                    throw new GitHubSourceException(GitHubSourceException::TOO_LARGE, 'GitHub response is larger than allowed');
                }

                throw new GitHubSourceException(GitHubSourceException::NETWORK, 'Request to GitHub failed: '.get_class($exception));
            }

            if (!in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                break;
            }

            if ($redirects >= self::MAX_REDIRECTS) {
                throw new GitHubSourceException(GitHubSourceException::INVALID_RESPONSE, 'Too many redirects from GitHub');
            }

            [$url, $sendToken] = $this->resolveRedirect($url, (string) $response->header('Location'), $sendToken);
        }

        $this->throwForStatus($response);

        return $response;
    }

    /**
     * Where a redirect may go and whether the token may go along.
     *
     * @return array{0: string, 1: bool}
     */
    protected function resolveRedirect(string $currentUrl, string $location, bool $sendToken): array
    {
        if ($location === '') {
            throw new GitHubSourceException(GitHubSourceException::INVALID_RESPONSE, 'Redirect without a location');
        }

        // Relative redirect: same host as the current URL.
        if (str_starts_with($location, '/') && !str_starts_with($location, '//')) {
            $parts = parse_url($currentUrl);
            $location = 'https://'.($parts['host'] ?? '').$location;
        }

        $parts = parse_url($location);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if ($scheme !== 'https' || isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new GitHubSourceException(GitHubSourceException::INVALID_RESPONSE, 'GitHub redirected to a non-https location');
        }

        if ($host === 'api.github.com') {
            return [$location, $sendToken];
        }

        if (GitHubSourceRules::isAllowedRedirectHost($host)) {
            // Content hosts get pre-signed URLs; our token must never leave api.github.com.
            return [$location, false];
        }

        throw new GitHubSourceException(GitHubSourceException::INVALID_RESPONSE, "GitHub redirected to an unexpected host ($host)");
    }

    protected function request(string $accept, int $timeout, ?int $maxBytes, bool $withToken): PendingRequest
    {
        $options = [
            // Followed by hand in get(), see resolveRedirect().
            'allow_redirects' => false,
        ];

        if ($maxBytes !== null) {
            $options['on_headers'] = function ($response) use ($maxBytes) {
                $length = $response->getHeaderLine('Content-Length');
                if ($length !== '' && ctype_digit($length) && (int) $length > $maxBytes) {
                    throw new \RuntimeException('response too large');
                }
            };
        }

        $request = Http::withHeaders([
            'Accept' => $accept,
            'X-GitHub-Api-Version' => self::API_VERSION,
            'User-Agent' => 'pelican-minecraft-modrinth',
        ])
            ->withOptions($options)
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout($timeout);

        if ($withToken && $this->token !== null && $this->token !== '') {
            $request = $request->withToken($this->token);
        }

        return $request;
    }

    protected function causedByTooLarge(\Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current->getMessage() === 'response too large') {
                return true;
            }
        }

        return false;
    }

    protected function throwForStatus(Response $response): void
    {
        $status = $response->status();

        if ($status >= 200 && $status < 300) {
            return;
        }

        $headers = [];
        foreach (['retry-after', 'x-ratelimit-remaining', 'x-ratelimit-reset'] as $name) {
            $value = $response->header($name);
            if ($value !== '') {
                $headers[$name] = $value;
            }
        }

        $rateLimited = $status === 429 || ($status === 403 && (($headers['x-ratelimit-remaining'] ?? null) === '0' || isset($headers['retry-after'])));

        if ($rateLimited) {
            $seconds = GitHubSourceRules::retryAfterSeconds($headers, time());
            Cache::put($this->cooldownKey(), time() + $seconds, now()->addSeconds($seconds));

            throw new GitHubSourceException(GitHubSourceException::RATE_LIMITED, "GitHub rate limit reached, retrying after $seconds seconds", $seconds);
        }

        throw match (true) {
            $status === 401 => new GitHubSourceException(GitHubSourceException::UNAUTHORIZED, 'GitHub rejected the token (401): missing, invalid or expired'),
            $status === 403 => new GitHubSourceException(GitHubSourceException::FORBIDDEN, 'GitHub denied access (403): the token lacks Contents/Actions read access to this repository'),
            $status === 404 => new GitHubSourceException(GitHubSourceException::NOT_FOUND, 'GitHub returned 404: repository, branch or file not found, or not visible to the token'),
            $status >= 500 => new GitHubSourceException(GitHubSourceException::SERVER_ERROR, "GitHub returned $status"),
            default => new GitHubSourceException(GitHubSourceException::INVALID_RESPONSE, "GitHub returned unexpected status $status"),
        };
    }

    protected function assertNotCoolingDown(): void
    {
        $until = Cache::get($this->cooldownKey());

        if (is_int($until) && $until > time()) {
            $seconds = $until - time();

            throw new GitHubSourceException(GitHubSourceException::RATE_LIMITED, "GitHub rate limit reached, retrying after $seconds seconds", $seconds);
        }
    }

    protected function cooldownKey(): string
    {
        return 'minecraft-modrinth:github:cooldown:'.md5($this->repository);
    }

    /** Encode every segment of a path/branch on its own, keeping the "/" separators. */
    protected function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }
}
