<?php

namespace Boy132\MinecraftModrinth\GitHub;

/**
 * Pure validation/decision logic for the GitHub repository source.
 *
 * Deliberately free of any Laravel/Pelican dependency, so every rule that decides what gets
 * installed on a server (index validation, CI evaluation, version comparison, file names) can
 * be unit tested on its own.
 */
final class GitHubSourceRules
{
    /** The only index format this plugin understands. Anything else installs nothing. */
    public const INDEX_SCHEMA = 1;

    /** GitHub's own limit for the raw contents API - larger files can't be fetched that way. */
    public const MAX_JAR_BYTES = 100 * 1024 * 1024;

    public const MAX_INDEX_BYTES = 1024 * 1024;

    public const PLATFORMS = ['paper', 'velocity'];

    /** Server loaders (egg tags) that can run plugins built for the "paper" platform. */
    public const PAPER_LOADERS = ['paper', 'purpur', 'pufferfish', 'leaf'];

    public const VELOCITY_LOADERS = ['velocity'];

    public static function isValidRepository(string $repository): bool
    {
        if (!preg_match('/^[A-Za-z0-9-]{1,39}\/[A-Za-z0-9._-]{1,100}$/', $repository)) {
            return false;
        }

        [, $name] = explode('/', $repository, 2);

        return $name !== '.' && $name !== '..';
    }

    /**
     * A conservative subset of what git allows in a branch name - enough for "main",
     * "release/1.x" and the like, but nothing that could change the meaning of a URL path.
     */
    public static function isValidBranch(string $branch): bool
    {
        if (!preg_match('/^[A-Za-z0-9._\/-]{1,100}$/', $branch)) {
            return false;
        }

        if (str_starts_with($branch, '/') || str_ends_with($branch, '/') || str_starts_with($branch, '-')
            || str_contains($branch, '//') || str_contains($branch, '..') || str_ends_with($branch, '.lock')) {
            return false;
        }

        foreach (explode('/', $branch) as $segment) {
            if ($segment === '' || str_starts_with($segment, '.')) {
                return false;
            }
        }

        return true;
    }

    /** A safe path relative to the repository root: no absolute path, no "." or ".." segments. */
    public static function isSafeRelativePath(string $path, ?string $requiredExtension = null): bool
    {
        if ($path === '' || strlen($path) > 512 || str_starts_with($path, '/')) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || !preg_match('/^[A-Za-z0-9._+-]+$/', $segment)) {
                return false;
            }
        }

        return $requiredExtension === null || str_ends_with(strtolower($path), '.'.strtolower($requiredExtension));
    }

    public static function isValidIndexPath(string $path): bool
    {
        return self::isSafeRelativePath($path, 'json');
    }

    /**
     * Fine-grained tokens start with "github_pat_", classic ones with "ghp_" - both are plain
     * [A-Za-z0-9_]. Only the character set is checked, not the prefix, so a future token format
     * with the same alphabet keeps working.
     */
    public static function isPlausibleToken(string $token): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_]{20,255}$/', $token);
    }

    public static function isValidSha(string $sha): bool
    {
        return (bool) preg_match('/^[0-9a-f]{40}$/', $sha);
    }

    public static function isValidVersion(string $version): bool
    {
        return (bool) preg_match('/^(0|[1-9]\d{0,8})\.(0|[1-9]\d{0,8})\.(0|[1-9]\d{0,8})$/', $version);
    }

    /** Semver-style comparison of two x.y.z versions: <0, 0 or >0. */
    public static function compareVersions(string $a, string $b): int
    {
        $pa = array_map('intval', explode('.', $a));
        $pb = array_map('intval', explode('.', $b));

        for ($i = 0; $i < 3; $i++) {
            $cmp = ($pa[$i] ?? 0) <=> ($pb[$i] ?? 0);
            if ($cmp !== 0) {
                return $cmp;
            }
        }

        return 0;
    }

    public static function isNewerVersion(string $candidate, string $installed): bool
    {
        if (!self::isValidVersion($candidate)) {
            return false;
        }

        // An installed entry with an unparsable version can only be replaced by hand.
        if (!self::isValidVersion($installed)) {
            return false;
        }

        return self::compareVersions($candidate, $installed) > 0;
    }

    /** File name a plugin is stored under in plugins/: must be a plain, visible .jar name. */
    public static function isValidJarFilename(string $filename): bool
    {
        return strlen($filename) <= 132 && (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]*\.jar$/', $filename) && !str_contains($filename, '..');
    }

    /**
     * The file name without ".jar" and without a trailing version, e.g.
     * "gdprerase-paper-1.4.0.jar" -> "gdprerase-paper". Used to recognise every jar that belongs
     * to the same plugin regardless of its version, so plugins/ never ends up with two of them.
     */
    public static function jarStem(string $filename): string
    {
        $stem = preg_replace('/\.jar$/i', '', $filename) ?? $filename;
        // A separator, an optional "v", then a dotted version and any "-SNAPSHOT"/"+build"/"-<hash>" suffixes.
        $withoutVersion = preg_replace('/[-_]v?\d+(\.\d+)*([-+][A-Za-z0-9.]+)*$/', '', $stem);

        return strtolower($withoutVersion !== null && $withoutVersion !== '' ? $withoutVersion : $stem);
    }

    /** Whether $filename looks like another version (or a copy) of the plugin stored as $reference. */
    public static function belongsToSamePlugin(string $filename, string $reference): bool
    {
        if (!preg_match('/\.jar$/i', $filename)) {
            return false;
        }

        return self::jarStem($filename) === self::jarStem($reference);
    }

    /**
     * Whether a jar already in plugins/ looks like a copy of the plugin with this file name and
     * plugin name: another version of the same file ("gdprerase-paper-1.3.0.jar") or a jar
     * named after the plugin itself ("GdprErase.jar", "GdprErase-1.3.jar" - a typical manual
     * install). Two copies would make Paper/Velocity refuse to load either.
     */
    public static function looksLikeSamePlugin(string $existing, string $filename, string $pluginName): bool
    {
        if (self::belongsToSamePlugin($existing, $filename)) {
            return true;
        }

        $name = strtolower(preg_replace('/[^A-Za-z0-9]+/', '', $pluginName) ?? '');
        $stem = preg_replace('/[^a-z0-9]+/', '', self::jarStem($existing)) ?? '';

        return $name !== '' && preg_match('/\.jar$/i', $existing) === 1 && $stem === $name;
    }

    /**
     * An alternative name for when the indexed file name is already taken in plugins/ - an
     * existing file is never overwritten in place, since that is exactly what a running server
     * has open (and what a failed write would leave broken).
     */
    public static function alternativeFilename(string $filename, string $sha256): string
    {
        $stem = preg_replace('/\.jar$/i', '', $filename) ?? $filename;

        return $stem.'-'.substr($sha256, 0, 12).'.jar';
    }

    /** @param  array<mixed>  $tags */
    public static function platformForLoaderTags(array $tags): ?string
    {
        $tags = array_map(fn ($tag) => strtolower((string) $tag), $tags);

        if (array_intersect($tags, self::VELOCITY_LOADERS)) {
            return 'velocity';
        }

        if (array_intersect($tags, self::PAPER_LOADERS)) {
            return 'paper';
        }

        return null;
    }

    public static function verifyJar(string $content, int $expectedSize, string $expectedSha256): bool
    {
        return strlen($content) === $expectedSize && hash_equals(strtolower($expectedSha256), hash('sha256', $content));
    }

    /**
     * Validate a decoded releases index.
     *
     * Returns the valid plugin entries plus a list of problems for entries that were skipped.
     * Throws when the index as a whole can't be trusted (not JSON, unknown schema, ...).
     *
     * @return array{plugins: array<int, array{id: string, name: string, platform: string, version: string, path: string, filename: string, sha256: string, size: int, frozen: bool}>, problems: array<int, string>}
     *
     * @throws GitHubSourceException
     */
    public static function parseIndex(string $json): array
    {
        if (strlen($json) > self::MAX_INDEX_BYTES) {
            throw new GitHubSourceException(GitHubSourceException::INVALID_INDEX, 'Index is larger than '.self::MAX_INDEX_BYTES.' bytes');
        }

        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new GitHubSourceException(GitHubSourceException::INVALID_INDEX, 'Index is not valid JSON');
        }

        if (($data['schema'] ?? null) !== self::INDEX_SCHEMA) {
            $schema = $data['schema'] ?? null;

            throw new GitHubSourceException(GitHubSourceException::UNSUPPORTED_SCHEMA, 'Unsupported index schema: '.(is_scalar($schema) ? var_export($schema, true) : gettype($schema)));
        }

        if (!isset($data['plugins']) || !is_array($data['plugins']) || !array_is_list($data['plugins'])) {
            throw new GitHubSourceException(GitHubSourceException::INVALID_INDEX, 'Index has no "plugins" list');
        }

        $plugins = [];
        $problems = [];
        $seenIds = [];
        $seenFilenames = [];

        foreach ($data['plugins'] as $position => $entry) {
            $problem = null;
            $plugin = self::validateIndexEntry($entry, $problem);

            if ($plugin === null) {
                $problems[] = "plugins[$position]: $problem";

                continue;
            }

            if (isset($seenIds[$plugin['id']])) {
                $problems[] = "plugins[$position]: duplicate id {$plugin['id']}";

                continue;
            }

            $filenameKey = $plugin['platform'].':'.strtolower($plugin['filename']);
            if (isset($seenFilenames[$filenameKey])) {
                $problems[] = "plugins[$position]: file name {$plugin['filename']} is already used by {$seenFilenames[$filenameKey]}";

                continue;
            }

            $seenIds[$plugin['id']] = true;
            $seenFilenames[$filenameKey] = $plugin['id'];
            $plugins[] = $plugin;
        }

        return ['plugins' => $plugins, 'problems' => $problems];
    }

    /**
     * @return array{id: string, name: string, platform: string, version: string, path: string, filename: string, sha256: string, size: int, frozen: bool}|null
     */
    public static function validateIndexEntry(mixed $entry, ?string &$problem = null): ?array
    {
        if (!is_array($entry)) {
            $problem = 'not an object';

            return null;
        }

        $id = $entry['id'] ?? null;
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $id)) {
            $problem = 'invalid id';

            return null;
        }

        $name = $entry['name'] ?? null;
        if (!is_string($name) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.-]{0,63}$/', $name)) {
            $problem = "invalid name ($id)";

            return null;
        }

        $platform = $entry['platform'] ?? null;
        if (!is_string($platform) || !in_array($platform, self::PLATFORMS, true)) {
            $problem = "invalid platform ($id)";

            return null;
        }

        $version = $entry['version'] ?? null;
        if (!is_string($version) || !self::isValidVersion($version)) {
            $problem = "invalid version ($id)";

            return null;
        }

        $path = $entry['path'] ?? null;
        if (!is_string($path) || !self::isSafeRelativePath($path, 'jar')) {
            $problem = "invalid path ($id)";

            return null;
        }

        $filename = basename($path);
        if (!self::isValidJarFilename($filename)) {
            $problem = "invalid file name ($id)";

            return null;
        }

        $sha256 = $entry['sha256'] ?? null;
        if (!is_string($sha256) || !preg_match('/^[0-9a-fA-F]{64}$/', $sha256)) {
            $problem = "invalid sha256 ($id)";

            return null;
        }

        $size = $entry['size'] ?? null;
        if (!is_int($size) || $size < 1 || $size > self::MAX_JAR_BYTES) {
            $problem = "invalid size ($id)";

            return null;
        }

        $frozen = $entry['frozen'] ?? false;

        return [
            'id' => $id,
            'name' => $name,
            'platform' => $platform,
            'version' => $version,
            'path' => $path,
            'filename' => $filename,
            'sha256' => strtolower($sha256),
            'size' => $size,
            'frozen' => $frozen === true,
        ];
    }

    /**
     * Decide the CI state of a commit from its GitHub Actions workflow runs
     * (GET /repos/{repo}/actions/runs?head_sha=...&event=push&branch=...).
     *
     * - any finished run that didn't succeed -> failed (definitive, even if others still run)
     * - anything still queued/running        -> pending
     * - everything success/skipped/neutral and at least one success -> green
     * - no runs at all, or nothing actually succeeded -> unverified
     *
     * @param  array<int, mixed>  $runs
     */
    public static function evaluateWorkflowRuns(array $runs, string $sha): CiStatus
    {
        $relevant = array_values(array_filter($runs, fn ($run) => is_array($run)
            && ($run['head_sha'] ?? null) === $sha
            && ($run['event'] ?? null) === 'push'));

        if (empty($relevant)) {
            return CiStatus::Unverified;
        }

        $pending = false;
        $succeeded = false;

        foreach ($relevant as $run) {
            if (($run['status'] ?? null) !== 'completed') {
                $pending = true;

                continue;
            }

            $conclusion = $run['conclusion'] ?? null;

            if ($conclusion === 'success') {
                $succeeded = true;
            } elseif (!in_array($conclusion, ['skipped', 'neutral'], true)) {
                return CiStatus::Failed;
            }
        }

        if ($pending) {
            return CiStatus::Pending;
        }

        return $succeeded ? CiStatus::Green : CiStatus::Unverified;
    }

    /**
     * Seconds to wait before talking to GitHub again after a rate limit response.
     *
     * @param  array<string, string>  $headers  lower-cased header names
     */
    public static function retryAfterSeconds(array $headers, int $now): int
    {
        $retryAfter = $headers['retry-after'] ?? null;
        if ($retryAfter !== null && ctype_digit(trim($retryAfter))) {
            return max(1, min(3600, (int) trim($retryAfter)));
        }

        $reset = $headers['x-ratelimit-reset'] ?? null;
        if ($reset !== null && ctype_digit(trim($reset))) {
            return max(1, min(3600, (int) trim($reset) - $now));
        }

        return 60;
    }

    /** Hosts a GitHub API response may redirect a download to (never sent the token). */
    public static function isAllowedRedirectHost(string $host): bool
    {
        $host = strtolower($host);

        return $host === 'raw.githubusercontent.com'
            || $host === 'objects.githubusercontent.com'
            || $host === 'codeload.github.com'
            || (bool) preg_match('/^[a-z0-9-]+\.githubusercontent\.com$/', $host);
    }

    /** Remove anything token-shaped from a string that might end up in a log or notification. */
    public static function redact(string $text, ?string $token = null): string
    {
        if ($token !== null && $token !== '') {
            $text = str_replace($token, '[redacted]', $text);
        }

        return preg_replace('/\b(github_pat_|gh[pousr]_)[A-Za-z0-9_]+/', '$1[redacted]', $text) ?? $text;
    }
}
