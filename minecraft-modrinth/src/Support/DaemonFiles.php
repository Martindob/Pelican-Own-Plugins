<?php

namespace Boy132\MinecraftModrinth\Support;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Boy132\MinecraftModrinth\GitHub\GitHubSourceRules;
use Illuminate\Http\Client\RequestException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

/**
 * The few Wings file operations both sources need, with what Pelican's DaemonFileRepository
 * doesn't give us: a size limit that stops reading early, "file missing" told apart from
 * "the node doesn't know this server", and a full disk told apart from other failures.
 */
class DaemonFiles
{
    protected const TOO_LARGE_MARKER = 'minecraft-modrinth: response too large';

    protected static function repository(Server $server): DaemonFileRepository
    {
        return app(DaemonFileRepository::class)->setServer($server);
    }

    /**
     * Contents of a small file, or null when it doesn't exist.
     *
     * @throws DaemonFileException
     */
    public static function readSmallFile(Server $server, string $path, int $maxBytes): ?string
    {
        $abortIfLarger = function (int $bytes) use ($maxBytes): void {
            if ($bytes > $maxBytes) {
                throw new RuntimeException(self::TOO_LARGE_MARKER);
            }
        };

        try {
            $response = static::repository($server)->getHttpClient()
                ->withOptions([
                    'on_headers' => function (ResponseInterface $response) use ($abortIfLarger) {
                        $length = $response->getHeaderLine('Content-Length');
                        if ($length !== '' && ctype_digit($length)) {
                            $abortIfLarger((int) $length);
                        }
                    },
                    // Also stops a response without Content-Length once it gets too big.
                    'progress' => fn ($downloadTotal, $downloaded) => $abortIfLarger((int) $downloaded),
                ])
                ->get("/api/servers/{$server->uuid}/files/contents", ['file' => $path]);
        } catch (RequestException $exception) {
            if ($exception->response->status() === 404 && !static::isUnknownServer($exception)) {
                return null;
            }

            throw static::classify($exception, "Could not read $path of server #{$server->id}");
        } catch (Throwable $exception) {
            throw static::classify($exception, "Could not read $path of server #{$server->id}");
        }

        $body = $response->body();

        if (strlen($body) > $maxBytes) {
            throw new DaemonFileException(DaemonFileException::TOO_LARGE, "$path of server #{$server->id} is larger than $maxBytes bytes");
        }

        return $body;
    }

    /**
     * Entries of a directory (Wings list-directory objects), or null when it doesn't exist.
     *
     * @return array<int, array<string, mixed>>|null
     *
     * @throws DaemonFileException
     */
    public static function listDirectory(Server $server, string $folder): ?array
    {
        try {
            $files = static::repository($server)->getDirectory($folder);
        } catch (RequestException $exception) {
            if ($exception->response->status() === 404 && !static::isUnknownServer($exception)) {
                return null;
            }

            throw static::classify($exception, "Could not list $folder/ of server #{$server->id}");
        } catch (Throwable $exception) {
            throw static::classify($exception, "Could not list $folder/ of server #{$server->id}");
        }

        if (!is_array($files) || isset($files['error'])) {
            throw new DaemonFileException(DaemonFileException::FAILED, "The daemon returned an error while listing $folder/ of server #{$server->id}");
        }

        return array_values(array_filter($files, 'is_array'));
    }

    /**
     * Find one entry of a listing by exact name.
     *
     * @param  array<int, array<string, mixed>>  $files
     * @return array<string, mixed>|null
     */
    public static function find(array $files, string $name): ?array
    {
        foreach ($files as $file) {
            if (($file['name'] ?? null) === $name) {
                return $file;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $file */
    public static function isRegularFile(array $file): bool
    {
        // Wings marks every entry with "file" and "directory"; a symlink is neither trusted nor deleted.
        return ($file['file'] ?? false) === true && ($file['directory'] ?? false) !== true && ($file['symlink'] ?? false) !== true;
    }

    /**
     * Write a file (string or stream) through Wings.
     *
     * @throws DaemonFileException
     */
    public static function writeFile(Server $server, string $path, string|StreamInterface $body, int $timeout): void
    {
        try {
            static::repository($server)->getHttpClient()
                ->timeout(max((int) config('panel.guzzle.timeout'), $timeout))
                ->withQueryParameters(['file' => $path])
                ->withBody($body, 'application/octet-stream')
                ->post("/api/servers/{$server->uuid}/files/write");
        } catch (Throwable $exception) {
            throw static::classify($exception, "Could not write $path on server #{$server->id}");
        }
    }

    /**
     * Rename inside one folder. Wings refuses to rename onto an existing file.
     *
     * @throws DaemonFileException
     */
    public static function rename(Server $server, string $folder, string $from, string $to): void
    {
        try {
            static::repository($server)->renameFiles($folder, [['from' => $from, 'to' => $to]]);
        } catch (RequestException $exception) {
            $classified = static::classify($exception, "Could not rename $folder/$from to $to on server #{$server->id}");

            // A 4xx here is Wings refusing the rename (the target exists); a 5xx is not a conflict.
            if ($classified->reason === DaemonFileException::FAILED && in_array($exception->response->status(), [400, 409], true)) {
                throw new DaemonFileException(DaemonFileException::CONFLICT, $classified->getMessage());
            }

            throw $classified;
        } catch (Throwable $exception) {
            throw static::classify($exception, "Could not rename $folder/$from to $to on server #{$server->id}");
        }
    }

    /**
     * Delete one file. A file that is already gone counts as deleted.
     *
     * @throws DaemonFileException
     */
    public static function delete(Server $server, string $folder, string $name): void
    {
        try {
            $response = static::repository($server)->deleteFiles($folder, [$name]);
        } catch (RequestException $exception) {
            if ($exception->response->status() === 404 && !static::isUnknownServer($exception)) {
                return;
            }

            throw static::classify($exception, "Could not delete $folder/$name on server #{$server->id}");
        } catch (Throwable $exception) {
            throw static::classify($exception, "Could not delete $folder/$name on server #{$server->id}");
        }

        if ($response->failed() && $response->status() !== 404) {
            throw new DaemonFileException(DaemonFileException::FAILED, "Could not delete $folder/$name on server #{$server->id}");
        }
    }

    public static function isUnknownServer(Throwable $exception): bool
    {
        return $exception instanceof RequestException
            && GitHubSourceRules::isUnknownServerResponse($exception->response->status(), (string) $exception->response->body());
    }

    public static function classify(Throwable $exception, string $message): DaemonFileException
    {
        if ($exception instanceof DaemonFileException) {
            return $exception;
        }

        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current->getMessage() === self::TOO_LARGE_MARKER) {
                return new DaemonFileException(DaemonFileException::TOO_LARGE, "$message: larger than allowed");
            }
        }

        if ($exception instanceof RequestException) {
            $body = (string) $exception->response->body();

            if (static::isUnknownServer($exception)) {
                return new DaemonFileException(DaemonFileException::UNKNOWN_SERVER, "$message: the node does not know this server");
            }

            if (GitHubSourceRules::isDiskSpaceResponse($body)) {
                return new DaemonFileException(DaemonFileException::DISK_FULL, "$message: not enough disk space");
            }

            return new DaemonFileException(DaemonFileException::FAILED, "$message (HTTP {$exception->response->status()})");
        }

        return new DaemonFileException(DaemonFileException::FAILED, "$message (".get_class($exception).')');
    }
}
