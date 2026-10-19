<?php

namespace Boy132\MinecraftModrinth\LuckPerms;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Models\User;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
use Boy132\MinecraftModrinth\Filament\Server\Pages\LuckPermsPage;
use Boy132\MinecraftModrinth\GitHub\GitHubSourceRules;
use Boy132\MinecraftModrinth\Modrinth\ModrinthMetadataException;
use Boy132\MinecraftModrinth\Services\MinecraftModrinthService;
use Boy132\MinecraftModrinth\Support\DaemonFileException;
use Boy132\MinecraftModrinth\Support\DaemonFiles;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Keeps LuckPerms up to date straight from luckperms.net (see README "LuckPerms source"):
 * the newest build is usually there days before it is on Modrinth.
 *
 * - There is no metadata file of its own: whatever LuckPerms jar is in plugins/ (installed by
 *   hand, from Modrinth or from here) is LuckPerms on that server. A jar named like a LuckPerms
 *   build is recognised by its name, any other jar with "luckperms" in its name by its
 *   plugin.yml / velocity-plugin.json.
 * - The panel downloads the jar itself (only https://download.luckperms.net, no redirects),
 *   checks that it is a jar of exactly that platform and version and only then writes it to the
 *   server through Wings (temporary name, then rename). The old jar is deleted only after the new
 *   one is in place; if that fails the new one is removed again - never two LuckPerms jars.
 * - A LuckPerms jar listed in plugins/.modrinth-metadata.json is taken over: its Modrinth entry
 *   is removed when it is replaced, so Modrinth doesn't manage (or re-add) it any more.
 *
 * @phpstan-import-type Latest from LuckPermsSourceRules
 * @phpstan-import-type DetectedJar from LuckPermsSourceRules
 *
 * @phpstan-type ServerState array{platform: string, state: string, reason: string|null, installed: DetectedJar|null, jars: array<int, DetectedJar>, unverified: array<int, string>, modrinth: array<string, array<string, mixed>>, modrinth_error: bool, files: array<int, array<string, mixed>>}
 */
class LuckPermsService
{
    public const FOLDER = 'plugins';

    protected const METADATA_CACHE_KEY = 'minecraft-modrinth:luckperms:metadata';

    protected const METADATA_CACHE_MINUTES = 10;

    /** After a failed metadata request nobody waits for luckperms.net again for a while. */
    protected const COOLDOWN_KEY = 'minecraft-modrinth:luckperms:cooldown';

    protected const CONNECT_TIMEOUT = 5;

    protected const METADATA_TIMEOUT = 10;

    protected const DOWNLOAD_TIMEOUT = 60;

    // Wings holds the request open while it writes the jar to disk.
    protected const DAEMON_WRITE_TIMEOUT = 120;

    /** How long a "this jar is (not) LuckPerms" result for an unusually named jar is remembered. */
    protected const INSPECTION_CACHE_HOURS = 24;

    /** A notification about the same version on the same server is sent once in this many days. */
    protected const NOTIFY_ONCE_DAYS = 60;

    /** @var array<string, string> Verified downloads of this run, by "platform:version" (one download for many servers). */
    protected array $downloads = [];

    public function __destruct()
    {
        $this->forgetDownloads();
    }

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    public function isEnabled(): bool
    {
        return (bool) config('minecraft-modrinth.luckperms.enabled');
    }

    /** "auto" or "notify" (see LuckPermsSourceRules::mode()). */
    public function mode(): string
    {
        return LuckPermsSourceRules::mode(config('minecraft-modrinth.luckperms.mode'));
    }

    /**
     * "bukkit" or "velocity" for a server whose egg has the plugins feature, the minecraft tag and
     * a matching loader tag (paper/spigot/bukkit/folia/purpur/... or velocity), otherwise null.
     */
    public function platformForServer(Server $server): ?string
    {
        if (!in_array(ModrinthProjectType::Plugin, ModrinthProjectType::fromServer($server), true)) {
            return null;
        }

        $tags = $server->egg->tags ?? [];

        if (!in_array('minecraft', $tags, true)) {
            return null;
        }

        return LuckPermsSourceRules::platformForLoaderTags($tags);
    }

    // ------------------------------------------------------------------
    // luckperms.net
    // ------------------------------------------------------------------

    /**
     * The newest LuckPerms version with its downloads and changelog, cached for 10 minutes.
     * After a failure the same error is returned straight away for a while (1 minute, doubling
     * up to 10), so an outage of luckperms.net never makes every page view wait for a timeout.
     * $fresh (the daily run, once) skips both and asks luckperms.net right away.
     *
     * @return Latest
     *
     * @throws LuckPermsSourceException
     */
    public function getLatest(bool $fresh = false): array
    {
        if (!$fresh) {
            $cached = Cache::get(self::METADATA_CACHE_KEY);
            if (is_array($cached) && isset($cached['version'], $cached['downloads'], $cached['changelog'])) {
                return $cached;
            }
        }

        $cooldown = Cache::get(self::COOLDOWN_KEY);
        if (!$fresh && is_array($cooldown) && ($cooldown['until'] ?? 0) > time() && is_string($cooldown['reason'] ?? null)) {
            throw new LuckPermsSourceException($cooldown['reason'], 'luckperms.net was unavailable a moment ago: '.($cooldown['message'] ?? ''));
        }

        try {
            $latest = LuckPermsSourceRules::parseMetadata($this->fetchMetadata());
        } catch (LuckPermsSourceException $exception) {
            $failures = (int) (is_array($cooldown) ? ($cooldown['failures'] ?? 0) : 0) + 1;
            $seconds = min(600, 60 * 2 ** min($failures - 1, 4));

            Cache::put(self::COOLDOWN_KEY, [
                'until' => time() + $seconds,
                'failures' => $failures,
                'reason' => $exception->reason,
                'message' => $exception->getMessage(),
            ], now()->addHours(6));

            $this->reportOncePerDay('metadata:'.$exception->reason, $exception->getMessage());

            throw $exception;
        }

        if (is_array($cooldown) && ($cooldown['failures'] ?? 0) > 0) {
            Log::info('minecraft-modrinth: LuckPerms metadata from luckperms.net works again');
        }
        Cache::forget(self::COOLDOWN_KEY);
        Cache::put(self::METADATA_CACHE_KEY, $latest, now()->addMinutes(self::METADATA_CACHE_MINUTES));

        return $latest;
    }

    public function forgetLatest(): void
    {
        Cache::forget(self::METADATA_CACHE_KEY);
    }

    /** @throws LuckPermsSourceException */
    protected function fetchMetadata(): string
    {
        try {
            $response = Http::withUserAgent('pelican-minecraft-modrinth')
                ->accept('application/json')
                ->withOptions([
                    'allow_redirects' => false,
                    'on_headers' => function (ResponseInterface $response) {
                        $length = $response->getHeaderLine('Content-Length');
                        if ($length !== '' && ctype_digit($length) && (int) $length > LuckPermsSourceRules::MAX_METADATA_BYTES) {
                            throw new RuntimeException('minecraft-modrinth: response too large');
                        }
                    },
                    'progress' => function ($downloadTotal, $downloaded) {
                        if ((int) $downloaded > LuckPermsSourceRules::MAX_METADATA_BYTES) {
                            throw new RuntimeException('minecraft-modrinth: response too large');
                        }
                    },
                ])
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::METADATA_TIMEOUT)
                ->get(LuckPermsSourceRules::METADATA_URL);
        } catch (Throwable $exception) {
            throw new LuckPermsSourceException(
                self::causedByTooLarge($exception) ? LuckPermsSourceException::TOO_LARGE : LuckPermsSourceException::NETWORK,
                'Could not read '.LuckPermsSourceRules::METADATA_URL.' ('.(self::causedByTooLarge($exception) ? 'response too large' : get_class($exception)).')'
            );
        }

        if ($response->status() !== 200) {
            throw new LuckPermsSourceException(LuckPermsSourceException::NETWORK, LuckPermsSourceRules::METADATA_URL." returned HTTP {$response->status()}");
        }

        return $response->body();
    }

    protected static function causedByTooLarge(Throwable $exception): bool
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if (str_contains($current->getMessage(), 'minecraft-modrinth: response too large')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Download the jar of this platform/version into a temporary file of the panel and check it.
     * Kept for the rest of this run (several servers, one download).
     *
     * @param  Latest  $latest
     * @return string path of the verified temporary file
     *
     * @throws LuckPermsSourceException
     */
    public function downloadVerified(array $latest, string $platform): string
    {
        $key = $platform.':'.$latest['version'];

        if (isset($this->downloads[$key]) && is_file($this->downloads[$key])) {
            return $this->downloads[$key];
        }

        $url = $latest['downloads'][$platform] ?? null;

        // Checked again right before downloading, not only when the metadata was parsed.
        if (!LuckPermsSourceRules::isAllowedDownloadUrl($url, $platform, $latest['version'])) {
            throw new LuckPermsSourceException(LuckPermsSourceException::NO_DOWNLOAD, "luckperms.net offers no valid $platform download for {$latest['version']}");
        }

        $temporary = tempnam(sys_get_temp_dir(), 'minecraft-modrinth-luckperms-');
        if ($temporary === false) {
            throw new LuckPermsSourceException(LuckPermsSourceException::DOWNLOAD_FAILED, 'Could not create a temporary file for the LuckPerms download');
        }

        $abortIfLarger = function (int $bytes): void {
            if ($bytes > LuckPermsSourceRules::MAX_JAR_BYTES) {
                throw new RuntimeException('minecraft-modrinth: response too large');
            }
        };

        try {
            $response = Http::withUserAgent('pelican-minecraft-modrinth')
                ->withOptions([
                    'allow_redirects' => false,
                    'on_headers' => function (ResponseInterface $response) use ($abortIfLarger) {
                        $length = $response->getHeaderLine('Content-Length');
                        if ($length !== '' && ctype_digit($length)) {
                            $abortIfLarger((int) $length);
                        }
                    },
                    'progress' => fn ($downloadTotal, $downloaded) => $abortIfLarger((int) $downloaded),
                ])
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::DOWNLOAD_TIMEOUT)
                ->sink($temporary)
                ->get($url);
        } catch (Throwable $exception) {
            @unlink($temporary);

            throw new LuckPermsSourceException(
                self::causedByTooLarge($exception) ? LuckPermsSourceException::TOO_LARGE : LuckPermsSourceException::DOWNLOAD_FAILED,
                'Downloading '.LuckPermsSourceRules::canonicalFilename($platform, $latest['version']).' failed ('.(self::causedByTooLarge($exception) ? 'larger than '.LuckPermsSourceRules::MAX_JAR_BYTES.' bytes' : get_class($exception)).')'
            );
        }

        if ($response->status() !== 200) {
            @unlink($temporary);

            throw new LuckPermsSourceException(LuckPermsSourceException::DOWNLOAD_FAILED, 'Downloading '.LuckPermsSourceRules::canonicalFilename($platform, $latest['version'])." failed (HTTP {$response->status()})");
        }

        $problem = null;
        if (!LuckPermsSourceRules::verifyDownloadedJar($temporary, $platform, $latest['version'], $problem)) {
            @unlink($temporary);

            throw new LuckPermsSourceException(LuckPermsSourceException::INVALID_JAR, 'The download of '.LuckPermsSourceRules::canonicalFilename($platform, $latest['version'])." was refused: $problem");
        }

        return $this->downloads[$key] = $temporary;
    }

    /** Delete the temporary files of this run's downloads. */
    public function forgetDownloads(): void
    {
        foreach ($this->downloads as $path) {
            @unlink($path);
        }

        $this->downloads = [];
    }

    // ------------------------------------------------------------------
    // State of a server
    // ------------------------------------------------------------------

    /**
     * Which LuckPerms jars are in plugins/ and what can be done about them.
     *
     * @param  string|null  $latestVersion  null when luckperms.net can't be reached right now
     * @return ServerState
     *
     * @throws LuckPermsSourceException when the server or plugins/ can't be read
     */
    public function inspectServer(Server $server, ?string $latestVersion): array
    {
        $platform = $this->platformForServer($server);

        if ($platform === null) {
            throw new LuckPermsSourceException(LuckPermsSourceException::UNSUPPORTED, "Server #{$server->id} is not a Paper/Spigot/Folia/Purpur or Velocity server");
        }

        try {
            $files = DaemonFiles::listDirectory($server, self::FOLDER) ?? [];
        } catch (DaemonFileException $exception) {
            throw LuckPermsSourceException::fromDaemon($exception);
        }

        $jars = [];
        $unverified = [];

        foreach ($files as $file) {
            $name = $file['name'] ?? null;

            if (!is_string($name) || !DaemonFiles::isRegularFile($file) || !LuckPermsSourceRules::mayBeLuckPermsJar($name)) {
                continue;
            }

            $size = (int) ($file['size'] ?? 0);
            $canonical = LuckPermsSourceRules::parseCanonicalFilename($name);

            if ($canonical !== null) {
                $jars[] = ['filename' => $name, 'platform' => $canonical['platform'], 'version' => $canonical['version'], 'size' => $size];

                continue;
            }

            $inspected = $this->inspectServerJar($server, $file);

            if ($inspected === false) {
                $unverified[] = $name;
            } elseif ($inspected !== null) {
                $jars[] = ['filename' => $name, 'platform' => $inspected['platform'], 'version' => $inspected['version'], 'size' => $size];
            }
        }

        // Which of these jars Modrinth manages (taken over on update). A Modrinth metadata file
        // that can't be read makes it impossible to say - nothing is changed then.
        $modrinth = [];
        $modrinthError = false;

        if (!empty($jars)) {
            try {
                $entries = app(MinecraftModrinthService::class)->readModsMetadata($server, ModrinthProjectType::Plugin)['entries'];

                foreach ($entries as $entry) {
                    if (in_array($entry['filename'], array_column($jars, 'filename'), true)) {
                        $modrinth[$entry['filename']] = $entry;
                    }
                }
            } catch (ModrinthMetadataException) {
                $modrinthError = true;
            }
        }

        $decision = LuckPermsSourceRules::decideState($jars, $unverified, $platform, $latestVersion);

        return [
            'platform' => $platform,
            'state' => $decision['state'],
            'reason' => $decision['reason'],
            'installed' => $decision['installed'],
            'jars' => $jars,
            'unverified' => $unverified,
            'modrinth' => $modrinth,
            'modrinth_error' => $modrinthError,
            'files' => $files,
        ];
    }

    /**
     * Look into a jar that has "luckperms" in its name but isn't named like a LuckPerms build.
     * Remembered for a day by name, size and modification time.
     *
     * @param  array<string, mixed>  $file
     * @return array{platform: string, version: string}|false|null LuckPerms, false (couldn't be checked) or null (not LuckPerms)
     */
    protected function inspectServerJar(Server $server, array $file): array|false|null
    {
        $name = (string) $file['name'];
        $key = 'minecraft-modrinth:luckperms:jar:'.md5(implode("\0", [$server->uuid, $name, (string) ($file['size'] ?? ''), (string) ($file['modified'] ?? '')]));

        $cached = Cache::get($key);
        if (is_array($cached) && array_key_exists('jar', $cached)) {
            return $cached['jar'];
        }

        if ((int) ($file['size'] ?? 0) > LuckPermsSourceRules::MAX_JAR_BYTES) {
            // Too large to be LuckPerms (or to be read into memory): not LuckPerms.
            Cache::put($key, ['jar' => null], now()->addHours(self::INSPECTION_CACHE_HOURS));

            return null;
        }

        $temporary = null;

        try {
            $content = DaemonFiles::readSmallFile($server, self::FOLDER.'/'.$name, LuckPermsSourceRules::MAX_JAR_BYTES);

            if ($content === null) {
                return null;
            }

            $temporary = tempnam(sys_get_temp_dir(), 'minecraft-modrinth-luckperms-');
            if ($temporary === false || file_put_contents($temporary, $content) !== strlen($content)) {
                return false;
            }

            $jar = LuckPermsSourceRules::inspectJar($temporary);
        } catch (DaemonFileException $exception) {
            $this->reportOncePerDay("server:{$server->id}:inspect:$name", "Could not check whether plugins/$name on server #{$server->id} is LuckPerms: {$exception->getMessage()}");

            return false;
        } finally {
            if (is_string($temporary)) {
                @unlink($temporary);
            }
        }

        Cache::put($key, ['jar' => $jar], now()->addHours(self::INSPECTION_CACHE_HOURS));

        return $jar;
    }

    // ------------------------------------------------------------------
    // Install, update, remove
    // ------------------------------------------------------------------

    /**
     * Install LuckPerms (nothing installed yet) or update the one LuckPerms jar in plugins/ to the
     * newest version. The new jar is written first (temporary name, then renamed), then the
     * Modrinth entry of the old jar is removed (if Modrinth managed it), then the old jar is
     * deleted. If any of that fails, the new jar is removed again and the old state restored.
     *
     * @param  Latest  $latest
     * @param  string|null  $expectedFilename  the jar the user saw (update) - refused when it changed meanwhile
     * @return array{version: string, filename: string, replaced: string|null, took_over_modrinth: bool}
     *
     * @throws LuckPermsSourceException
     */
    public function installOrUpdate(Server $server, array $latest, bool $isUpdate, ?string $expectedFilename = null): array
    {
        if (!$this->isEnabled()) {
            throw new LuckPermsSourceException(LuckPermsSourceException::DISABLED, 'The LuckPerms source is disabled');
        }

        $lock = Cache::lock("minecraft-modrinth:luckperms:install:{$server->id}", self::DOWNLOAD_TIMEOUT + 2 * self::DAEMON_WRITE_TIMEOUT + 60);

        if (!$lock->get()) {
            throw new LuckPermsSourceException(LuckPermsSourceException::STATE_CHANGED, "Another LuckPerms installation is running on server #{$server->id}");
        }

        try {
            return $this->installLocked($server, $latest, $isUpdate, $expectedFilename);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  Latest  $latest
     * @return array{version: string, filename: string, replaced: string|null, took_over_modrinth: bool}
     */
    protected function installLocked(Server $server, array $latest, bool $isUpdate, ?string $expectedFilename): array
    {
        $state = $this->inspectServer($server, $latest['version']);
        $platform = $state['platform'];
        $old = $state['installed'];

        if ($state['state'] === 'conflict') {
            throw new LuckPermsSourceException(LuckPermsSourceException::CONFLICT, "plugins/ of server #{$server->id} has ".$this->describeConflict($state));
        }

        if ($isUpdate) {
            if ($old === null || ($expectedFilename !== null && $old['filename'] !== $expectedFilename)) {
                throw new LuckPermsSourceException(LuckPermsSourceException::STATE_CHANGED, "LuckPerms on server #{$server->id} changed in the meantime");
            }

            if ($state['state'] !== 'update_available') {
                throw new LuckPermsSourceException(LuckPermsSourceException::NOT_NEWER, "LuckPerms {$old['version']} on server #{$server->id} is not older than {$latest['version']}");
            }
        } elseif ($state['state'] !== 'not_installed') {
            throw new LuckPermsSourceException(LuckPermsSourceException::STATE_CHANGED, "LuckPerms was installed on server #{$server->id} in the meantime");
        }

        $modrinthEntry = $old !== null ? ($state['modrinth'][$old['filename']] ?? null) : null;

        if ($old !== null && $state['modrinth_error']) {
            throw new LuckPermsSourceException(LuckPermsSourceException::MODRINTH_METADATA, "plugins/.modrinth-metadata.json of server #{$server->id} can't be read, so it is unknown whether Modrinth manages plugins/{$old['filename']}");
        }

        $target = LuckPermsSourceRules::canonicalFilename($platform, $latest['version']);

        if (DaemonFiles::find($state['files'], $target) !== null) {
            throw new LuckPermsSourceException(LuckPermsSourceException::CONFLICT, "plugins/$target already exists on server #{$server->id}");
        }

        // Download and verify before touching the server at all.
        $localFile = $this->downloadVerified($latest, $platform);

        // Temporary uploads left behind by an interrupted installation (older than an hour).
        foreach ($state['files'] as $file) {
            if (is_string($file['name'] ?? null) && DaemonFiles::isRegularFile($file)
                && GitHubSourceRules::isStaleTemporaryUpload($file['name'], $file['modified'] ?? null, time())) {
                $this->deleteQuietly($server, $file['name']);
            }
        }

        $this->writeJar($server, $target, $localFile);

        if ($old === null) {
            return ['version' => $latest['version'], 'filename' => $target, 'replaced' => null, 'took_over_modrinth' => false];
        }

        $modrinth = app(MinecraftModrinthService::class);

        if ($modrinthEntry !== null && !$modrinth->removeModMetadata($server, ModrinthProjectType::Plugin, $modrinthEntry['project_id'])) {
            $this->deleteQuietly($server, $target);

            throw new LuckPermsSourceException(LuckPermsSourceException::MODRINTH_METADATA, "Could not remove the Modrinth entry of plugins/{$old['filename']} on server #{$server->id}; LuckPerms was left as it was");
        }

        try {
            DaemonFiles::delete($server, self::FOLDER, $old['filename']);
        } catch (DaemonFileException $exception) {
            if (!$this->isGone($server, $old['filename'])) {
                // Back to where it was: never two LuckPerms jars in plugins/.
                $this->deleteQuietly($server, $target);

                if ($modrinthEntry !== null && !$modrinth->saveModMetadata($server, ModrinthProjectType::Plugin, $modrinthEntry['project_id'], $modrinthEntry['project_slug'], $modrinthEntry['project_title'], $modrinthEntry['version_id'], $modrinthEntry['version_number'], $modrinthEntry['filename'], $modrinthEntry['author'] ?? null)) {
                    $this->reportOncePerDay("server:{$server->id}:modrinth-restore", "Could not restore the Modrinth entry of plugins/{$old['filename']} on server #{$server->id} after a failed LuckPerms update");
                }

                throw LuckPermsSourceException::fromDaemon($exception);
            }
        }

        return ['version' => $latest['version'], 'filename' => $target, 'replaced' => $old['filename'], 'took_over_modrinth' => $modrinthEntry !== null];
    }

    /**
     * Remove the one LuckPerms jar (and its Modrinth entry, if Modrinth managed it). Its folder
     * plugins/LuckPerms/ (config, database, ...) is never touched.
     *
     * @return bool false when the jar is gone but its Modrinth entry couldn't be removed
     *
     * @throws LuckPermsSourceException
     */
    public function uninstall(Server $server, string $expectedFilename): bool
    {
        $lock = Cache::lock("minecraft-modrinth:luckperms:install:{$server->id}", 60);

        if (!$lock->get()) {
            throw new LuckPermsSourceException(LuckPermsSourceException::STATE_CHANGED, "Another LuckPerms installation is running on server #{$server->id}");
        }

        try {
            $state = $this->inspectServer($server, null);
            $jar = $state['installed'];

            if ($jar === null || $jar['filename'] !== $expectedFilename || count($state['jars']) !== 1 || !empty($state['unverified'])) {
                throw new LuckPermsSourceException(LuckPermsSourceException::STATE_CHANGED, "LuckPerms on server #{$server->id} changed in the meantime");
            }

            if ($state['modrinth_error']) {
                throw new LuckPermsSourceException(LuckPermsSourceException::MODRINTH_METADATA, "plugins/.modrinth-metadata.json of server #{$server->id} can't be read");
            }

            try {
                DaemonFiles::delete($server, self::FOLDER, $jar['filename']);
            } catch (DaemonFileException $exception) {
                throw LuckPermsSourceException::fromDaemon($exception);
            }

            $modrinthEntry = $state['modrinth'][$jar['filename']] ?? null;

            return $modrinthEntry === null
                || app(MinecraftModrinthService::class)->removeModMetadata($server, ModrinthProjectType::Plugin, $modrinthEntry['project_id']);
        } finally {
            $lock->release();
        }
    }

    /**
     * Write the verified jar under a temporary name that Paper/Velocity ignore, rename it into
     * place (Wings refuses to rename onto an existing file) and check its size.
     *
     * @throws LuckPermsSourceException
     */
    protected function writeJar(Server $server, string $filename, string $localFile): void
    {
        $size = filesize($localFile);
        $stream = fopen($localFile, 'rb');

        if ($size === false || $stream === false) {
            throw new LuckPermsSourceException(LuckPermsSourceException::DOWNLOAD_FAILED, 'Could not open the downloaded LuckPerms jar');
        }

        $temporary = LuckPermsSourceRules::temporaryUploadName(bin2hex(random_bytes(6)));

        try {
            DaemonFiles::writeFile($server, self::FOLDER.'/'.$temporary, Utils::streamFor($stream), self::DAEMON_WRITE_TIMEOUT);
        } catch (DaemonFileException $exception) {
            $this->deleteQuietly($server, $temporary);

            throw LuckPermsSourceException::fromDaemon($exception);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        try {
            DaemonFiles::rename($server, self::FOLDER, $temporary, $filename);
        } catch (DaemonFileException $exception) {
            $this->deleteQuietly($server, $temporary);

            throw LuckPermsSourceException::fromDaemon($exception);
        }

        try {
            $file = DaemonFiles::find(DaemonFiles::listDirectory($server, self::FOLDER) ?? [], $filename);
        } catch (DaemonFileException) {
            $file = null;
        }

        if ($file === null || (int) ($file['size'] ?? -1) !== $size) {
            $this->deleteQuietly($server, $filename);

            throw new LuckPermsSourceException(LuckPermsSourceException::DAEMON, "plugins/$filename on server #{$server->id} is missing or has the wrong size after writing it");
        }
    }

    /** After a failed delete: whether the file is gone anyway (e.g. the request timed out after it). */
    protected function isGone(Server $server, string $filename): bool
    {
        try {
            return DaemonFiles::find(DaemonFiles::listDirectory($server, self::FOLDER) ?? [], $filename) === null;
        } catch (DaemonFileException) {
            return false;
        }
    }

    protected function deleteQuietly(Server $server, string $filename): void
    {
        try {
            DaemonFiles::delete($server, self::FOLDER, $filename);
        } catch (DaemonFileException $exception) {
            $this->reportOncePerDay("server:{$server->id}:delete:$filename", $exception->getMessage());
        }
    }

    /** @param  ServerState  $state */
    public function describeConflict(array $state): string
    {
        return match ($state['reason']) {
            'unverified' => 'jars that could not be checked: '.implode(', ', $state['unverified']),
            'wrong_platform' => 'a LuckPerms jar for the other platform: '.($state['installed']['filename'] ?? ''),
            default => 'several LuckPerms jars: '.implode(', ', array_column($state['jars'], 'filename')),
        };
    }

    // ------------------------------------------------------------------
    // Daily run (AutoUpdateModsCommand)
    // ------------------------------------------------------------------

    /**
     * Update LuckPerms on one server to the newest version ("auto"), or tell its users once per
     * version that it can be updated ("notify"). Only a server that has exactly one LuckPerms jar
     * of its own platform is touched; nothing is installed where LuckPerms isn't. Never throws.
     *
     * @param  Latest  $latest
     * @return string|null what happened, for the command output
     */
    public function autoUpdateServer(Server $server, array $latest): ?string
    {
        if (!$this->isEnabled() || $this->platformForServer($server) === null) {
            return null;
        }

        try {
            $state = $this->inspectServer($server, $latest['version']);

            if ($state['state'] === 'conflict') {
                $this->reportOncePerDay("server:{$server->id}:conflict", "Not updating LuckPerms on server #{$server->id}: plugins/ has ".$this->describeConflict($state));

                return null;
            }

            if ($state['state'] !== 'update_available' || $state['installed'] === null) {
                return null;
            }

            if ($this->mode() === 'notify') {
                return $this->notifyUpdate($server, $state['installed']['version'], $latest['version'])
                    ? "LuckPerms {$state['installed']['version']} -> {$latest['version']}: notified"
                    : null;
            }

            $result = $this->installOrUpdate($server, $latest, true, $state['installed']['filename']);

            return "LuckPerms {$state['installed']['version']} -> {$result['version']}".($result['took_over_modrinth'] ? ' (taken over from Modrinth)' : '');
        } catch (LuckPermsSourceException $exception) {
            if ($exception->reason !== LuckPermsSourceException::UNKNOWN_SERVER) {
                $this->reportOncePerDay("server:{$server->id}:update:{$exception->reason}", $exception->getMessage());
            }
        } catch (Throwable $exception) {
            $this->reportOncePerDay("server:{$server->id}:update:".get_class($exception), "LuckPerms update on server #{$server->id} failed: ".get_class($exception));
        }

        return null;
    }

    /**
     * One database notification per server and version to the owner and every subuser who may
     * update it (file.create and file.delete). Sent right away, not through the queue.
     */
    public function notifyUpdate(Server $server, string $installedVersion, string $latestVersion): bool
    {
        if (!Cache::add("minecraft-modrinth:luckperms:notified:{$server->id}:$latestVersion", true, now()->addDays(self::NOTIFY_ONCE_DAYS))) {
            return false;
        }

        $url = null;
        try {
            $url = LuckPermsPage::getUrl(panel: 'server', tenant: $server);
        } catch (Throwable) {
        }

        foreach ($this->recipients($server) as $user) {
            $locale = $user->language ?? 'en';

            try {
                $user->notifyNow(Notification::make()
                    ->warning()
                    ->icon('tabler-shield-lock')
                    ->title(trans('minecraft-modrinth::strings.luckperms.notifications.update_title', [], $locale))
                    // Filament renders the body as (sanitized) HTML: escape what isn't our own text.
                    ->body(trans('minecraft-modrinth::strings.luckperms.notifications.update_body', [
                        'server' => e($server->name),
                        'installed' => e($installedVersion),
                        'latest' => e($latestVersion),
                    ], $locale))
                    ->actions($url === null ? [] : [
                        Action::make('open_luckperms')
                            ->button()
                            ->label(trans('minecraft-modrinth::strings.luckperms.notifications.open', [], $locale))
                            ->markAsRead()
                            ->url($url),
                    ])
                    ->toDatabase());
            } catch (Throwable $exception) {
                $this->reportOncePerDay("server:{$server->id}:notify", "Could not notify user #{$user->id} about a LuckPerms update on server #{$server->id}: ".get_class($exception));
            }
        }

        return true;
    }

    /** @return Collection<int, User> */
    public function recipients(Server $server): Collection
    {
        $server->loadMissing(['user', 'subusers.user']);

        /** @var Collection<int, User> $candidates */
        $candidates = collect([$server->user])
            ->concat($server->subusers->map(fn ($subuser) => $subuser->user))
            ->filter(fn ($user) => $user instanceof User)
            ->unique('id')
            ->values();

        return $candidates
            ->filter(fn (User $user) => $user->id === $server->owner_id
                || ($user->can(SubuserPermission::FileCreate, $server) && $user->can(SubuserPermission::FileDelete, $server)))
            ->values();
    }

    // ------------------------------------------------------------------
    // Logging
    // ------------------------------------------------------------------

    /** One line in the log, the same problem at most once a day. */
    public function reportOncePerDay(string $key, string $message): void
    {
        if (Cache::add('minecraft-modrinth:luckperms:reported:'.md5($key), true, now()->addDay())) {
            Log::error('minecraft-modrinth: LuckPerms: '.preg_replace('/[\r\n]+/', ' ', $message));
        }
    }
}
