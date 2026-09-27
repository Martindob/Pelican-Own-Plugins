<?php

namespace Boy132\MinecraftModrinth\GitHub;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
use Exception;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Installs and updates plugins from a GitHub repository that ships pre-built jars and an index
 * of them (see README "GitHub repository source").
 *
 * The panel downloads every jar itself, checks it against the sha256/size from the index and
 * only then writes it to the server through the Wings file API - Wings never gets a GitHub URL
 * or the token. Only the jar in plugins/ and our own metadata file are ever touched; a plugin's
 * own folder (plugins/<Name>/config.yml, ...) never is.
 *
 * @phpstan-type IndexPlugin array{id: string, name: string, platform: string, version: string, path: string, filename: string, sha256: string, size: int, frozen: bool}
 * @phpstan-type Snapshot array{repository: string, branch: string, sha: string, ci: CiStatus, plugins: array<int, IndexPlugin>, problems: array<int, string>}
 * @phpstan-type InstalledPlugin array{id: string, name: string, platform: string, version: string, sha256: string, size: int, filename: string, repository: string, commit: string, installed_at: string, updated_at?: string}
 */
class GitHubPluginService
{
    public const FOLDER = 'plugins';

    public const METADATA_FILE = '.github-plugins.json';

    protected const HEAD_CACHE_MINUTES = 5;

    protected const INDEX_CACHE_MINUTES = 60;

    // Wings holds the request open while it writes the jar to disk.
    protected const DAEMON_WRITE_TIMEOUT = 120;

    protected const DOWNLOAD_TIMEOUT = 120;

    protected const JAR_CACHE_BYTES = 64 * 1024 * 1024;

    /** @var array<string, string> Verified jar contents of this run, by sha256 (one download for many servers). */
    protected array $jarCache = [];

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    public function isEnabled(): bool
    {
        return (bool) config('minecraft-modrinth.github.enabled');
    }

    public function repository(): string
    {
        return trim((string) config('minecraft-modrinth.github.repository'));
    }

    public function branch(): string
    {
        $branch = trim((string) config('minecraft-modrinth.github.branch'));

        return $branch !== '' ? $branch : 'main';
    }

    public function indexPath(): string
    {
        $path = trim((string) config('minecraft-modrinth.github.index_path'));

        return $path !== '' ? $path : 'minecraft/releases.json';
    }

    public function requiresGreenCi(): bool
    {
        return (bool) config('minecraft-modrinth.github.require_green_ci', true);
    }

    public function hasToken(): bool
    {
        return trim((string) config('minecraft-modrinth.github.token_encrypted')) !== '';
    }

    /** Whether the source is switched on and its settings are usable. */
    public function isConfigured(): bool
    {
        return $this->isEnabled()
            && GitHubSourceRules::isValidRepository($this->repository())
            && GitHubSourceRules::isValidBranch($this->branch())
            && GitHubSourceRules::isValidIndexPath($this->indexPath());
    }

    public function commitUrl(string $sha): string
    {
        return 'https://github.com/'.$this->repository().'/commit/'.$sha;
    }

    /**
     * The decrypted token, or null. Never logged, never put into an exception message.
     */
    protected function token(): ?string
    {
        $encrypted = trim((string) config('minecraft-modrinth.github.token_encrypted'));

        if ($encrypted === '') {
            return null;
        }

        try {
            $token = Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            // APP_KEY changed since the token was saved: carry on without it (a public repository
            // still works) and ask for it to be saved again.
            $this->reportOncePerWindow('token-decrypt', new GitHubSourceException(GitHubSourceException::UNAUTHORIZED, 'The saved GitHub token can no longer be decrypted (APP_KEY changed?) - save it again in the plugin settings'));

            return null;
        }

        return $token !== '' ? $token : null;
    }

    protected function client(): GitHubClient
    {
        if (!$this->isConfigured()) {
            throw new GitHubSourceException(GitHubSourceException::NOT_CONFIGURED, 'The GitHub repository source is disabled or not configured');
        }

        return new GitHubClient($this->repository(), $this->token());
    }

    // ------------------------------------------------------------------
    // Repository state
    // ------------------------------------------------------------------

    protected function cacheKey(string $kind, string ...$parts): string
    {
        return "minecraft-modrinth:github:$kind:".md5(implode("\0", [$this->repository(), ...$parts]));
    }

    public function getHeadSha(): string
    {
        $key = $this->cacheKey('head', $this->branch());

        $cached = Cache::get($key);
        if (is_string($cached) && GitHubSourceRules::isValidSha($cached)) {
            return $cached;
        }

        $sha = $this->client()->getBranchHeadSha($this->branch());
        Cache::put($key, $sha, now()->addMinutes(self::HEAD_CACHE_MINUTES));

        return $sha;
    }

    public function getCiStatus(string $sha): CiStatus
    {
        $key = $this->cacheKey('ci', $this->branch(), $sha);

        $cached = Cache::get($key);
        if (is_string($cached) && ($status = CiStatus::tryFrom($cached))) {
            return $status;
        }

        $status = GitHubSourceRules::evaluateWorkflowRuns($this->client()->getWorkflowRuns($sha, $this->branch()), $sha);
        Cache::put($key, $status->value, now()->addMinutes($status->cacheMinutes()));

        return $status;
    }

    /**
     * @return array{plugins: array<int, IndexPlugin>, problems: array<int, string>}
     */
    public function getIndex(string $sha): array
    {
        $key = $this->cacheKey('index', $this->indexPath(), $sha);

        $cached = Cache::get($key);
        if (is_array($cached) && isset($cached['plugins'], $cached['problems'])) {
            return $cached;
        }

        $json = $this->client()->getRawFile($this->indexPath(), $sha, GitHubSourceRules::MAX_INDEX_BYTES);

        // An invalid index or unknown schema throws: nothing at all is installed from it
        // (reported by getSnapshot()).
        $index = GitHubSourceRules::parseIndex($json);

        if (!empty($index['problems'])) {
            $this->reportOncePerWindow('index-problems:'.$sha, new GitHubSourceException(
                GitHubSourceException::INVALID_INDEX,
                'Skipped invalid entries in '.$this->indexPath().' at '.$sha.': '.implode('; ', array_slice($index['problems'], 0, 10))
            ));
        }

        // A commit's content never changes, so this only expires to bound the cache size.
        Cache::put($key, $index, now()->addMinutes(self::INDEX_CACHE_MINUTES));

        return $index;
    }

    /**
     * Everything the UI and the updater need: head commit, its CI state and its index.
     *
     * @return Snapshot
     *
     * @throws GitHubSourceException
     */
    public function getSnapshot(): array
    {
        try {
            $sha = $this->getHeadSha();
            $ci = $this->getCiStatus($sha);
            $index = $this->getIndex($sha);
        } catch (GitHubSourceException $exception) {
            if ($exception->reason !== GitHubSourceException::NOT_CONFIGURED) {
                $this->reportOncePerWindow('snapshot:'.$exception->reason, $exception);
            }

            throw $exception;
        }

        return [
            'repository' => $this->repository(),
            'branch' => $this->branch(),
            'sha' => $sha,
            'ci' => $ci,
            'plugins' => $index['plugins'],
            'problems' => $index['problems'],
        ];
    }

    /** Forget the cached head commit and CI state so the next read asks GitHub again. */
    public function forgetCachedState(): void
    {
        $key = $this->cacheKey('head', $this->branch());
        $sha = Cache::get($key);

        Cache::forget($key);

        if (is_string($sha)) {
            Cache::forget($this->cacheKey('ci', $this->branch(), $sha));
        }
    }

    // ------------------------------------------------------------------
    // Servers
    // ------------------------------------------------------------------

    /**
     * "paper" or "velocity" for a server whose egg has the plugins feature and a matching loader
     * tag (the same egg setup the Modrinth plugins page needs), otherwise null.
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

        return GitHubSourceRules::platformForLoaderTags($tags);
    }

    /**
     * @param  array<int, IndexPlugin>  $plugins
     * @return array<int, IndexPlugin>
     */
    public function pluginsForPlatform(array $plugins, string $platform): array
    {
        return array_values(array_filter($plugins, fn (array $plugin) => $plugin['platform'] === $platform));
    }

    /**
     * @return array<int, InstalledPlugin>
     *
     * @throws GitHubSourceException when the metadata file exists but can't be read or trusted
     */
    public function getInstalled(Server $server): array
    {
        try {
            $content = app(DaemonFileRepository::class)->setServer($server)->getContent(self::FOLDER.'/'.self::METADATA_FILE);
        } catch (FileNotFoundException) {
            return [];
        } catch (Exception) {
            throw new GitHubSourceException(GitHubSourceException::DAEMON, "Could not read the plugin metadata of server #{$server->id} from the daemon");
        }

        $data = json_decode($content, true);

        if (!is_array($data) || !isset($data['installed']) || !is_array($data['installed'])) {
            // Refuse instead of starting over: an empty list would be written back on the next
            // install and every jar recorded in the damaged file would become untracked.
            throw new GitHubSourceException(GitHubSourceException::METADATA, 'plugins/'.self::METADATA_FILE." on server #{$server->id} is damaged");
        }

        $installed = [];
        foreach ($data['installed'] as $entry) {
            if (!is_array($entry)
                || !is_string($entry['id'] ?? null)
                || !is_string($entry['filename'] ?? null) || !GitHubSourceRules::isValidJarFilename($entry['filename'])
                || !is_string($entry['version'] ?? null)
                || isset($installed[$entry['id']])) {
                continue;
            }

            $installed[$entry['id']] = [
                'id' => $entry['id'],
                'name' => is_string($entry['name'] ?? null) ? $entry['name'] : $entry['id'],
                'platform' => is_string($entry['platform'] ?? null) ? $entry['platform'] : '',
                'version' => $entry['version'],
                'sha256' => is_string($entry['sha256'] ?? null) ? $entry['sha256'] : '',
                'size' => is_int($entry['size'] ?? null) ? $entry['size'] : 0,
                'filename' => $entry['filename'],
                'repository' => is_string($entry['repository'] ?? null) ? $entry['repository'] : '',
                'commit' => is_string($entry['commit'] ?? null) ? $entry['commit'] : '',
                'installed_at' => is_string($entry['installed_at'] ?? null) ? $entry['installed_at'] : '',
            ] + (is_string($entry['updated_at'] ?? null) ? ['updated_at' => $entry['updated_at']] : []);
        }

        return array_values($installed);
    }

    /**
     * @param  array<int, InstalledPlugin>  $installed
     * @return InstalledPlugin|null
     */
    public function findInstalled(array $installed, string $id): ?array
    {
        foreach ($installed as $entry) {
            if ($entry['id'] === $id) {
                return $entry;
            }
        }

        return null;
    }

    /** @param  array<int, InstalledPlugin>  $installed */
    protected function writeMetadata(Server $server, array $installed): void
    {
        $json = json_encode(['schema' => 1, 'installed' => array_values($installed)], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        try {
            app(DaemonFileRepository::class)->setServer($server)->putContent(self::FOLDER.'/'.self::METADATA_FILE, $json);
        } catch (Exception) {
            throw new GitHubSourceException(GitHubSourceException::METADATA, 'Could not write plugins/'.self::METADATA_FILE." on server #{$server->id}");
        }
    }

    /**
     * Install a plugin from the snapshot, or update it when it's already installed.
     *
     * The new jar is written under a name that isn't taken yet (never over an existing file),
     * then the metadata is saved, then the old jar is removed - rolled back step by step when
     * something fails, so plugins/ never keeps two jars of the same plugin. The running server
     * keeps using what it loaded; the new version is used after its next restart.
     *
     * @param  IndexPlugin  $plugin
     * @param  Snapshot  $snapshot
     * @return InstalledPlugin
     *
     * @throws GitHubSourceException
     */
    public function installOrUpdate(Server $server, array $plugin, array $snapshot, bool $requireGreenCi): array
    {
        if ($this->platformForServer($server) !== $plugin['platform']) {
            throw new GitHubSourceException(GitHubSourceException::INVALID_INDEX, "{$plugin['id']} is for {$plugin['platform']}, which server #{$server->id} is not");
        }

        if ($requireGreenCi && $snapshot['ci'] !== CiStatus::Green) {
            throw new GitHubSourceException(GitHubSourceException::CI_NOT_GREEN, "CI of {$snapshot['sha']} is {$snapshot['ci']->value}, not green");
        }

        $lock = Cache::lock("minecraft-modrinth:github:install:{$server->id}", self::DOWNLOAD_TIMEOUT + self::DAEMON_WRITE_TIMEOUT + 60);

        if (!$lock->get()) {
            throw new GitHubSourceException(GitHubSourceException::FILE_CONFLICT, "Another plugin installation is running on server #{$server->id}");
        }

        try {
            return $this->installLocked($server, $plugin, $snapshot);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  IndexPlugin  $plugin
     * @param  Snapshot  $snapshot
     * @return InstalledPlugin
     */
    protected function installLocked(Server $server, array $plugin, array $snapshot): array
    {
        $installedList = $this->getInstalled($server);
        $previous = $this->findInstalled($installedList, $plugin['id']);

        // Download and verify before touching the server at all.
        $content = $this->downloadVerifiedJar($plugin, $snapshot['sha']);

        $existing = $this->listJarNames($server);
        $target = $this->chooseTargetFilename($plugin, $previous, $existing);

        $this->writeJar($server, $target, $content);

        $entry = [
            'id' => $plugin['id'],
            'name' => $plugin['name'],
            'platform' => $plugin['platform'],
            'version' => $plugin['version'],
            'sha256' => $plugin['sha256'],
            'size' => $plugin['size'],
            'filename' => $target,
            'repository' => $snapshot['repository'],
            'commit' => $snapshot['sha'],
            'installed_at' => $previous['installed_at'] ?? now()->toIso8601String(),
        ];

        if ($previous) {
            $entry['updated_at'] = now()->toIso8601String();
        }

        $newList = [];
        $replaced = false;
        foreach ($installedList as $item) {
            if ($item['id'] === $plugin['id']) {
                $newList[] = $entry;
                $replaced = true;
            } else {
                $newList[] = $item;
            }
        }
        if (!$replaced) {
            $newList[] = $entry;
        }

        try {
            $this->writeMetadata($server, $newList);
        } catch (GitHubSourceException $exception) {
            $this->deleteQuietly($server, $target);

            throw $exception;
        }

        if ($previous && $previous['filename'] !== $target) {
            try {
                $this->deleteJar($server, $previous['filename']);
            } catch (GitHubSourceException $exception) {
                // Back to exactly how it was: old jar + old metadata, new jar gone.
                $this->deleteQuietly($server, $target);

                try {
                    $this->writeMetadata($server, $installedList);
                } catch (GitHubSourceException $restoreException) {
                    $this->report($restoreException);
                }

                throw $exception;
            }
        }

        return $entry;
    }

    /**
     * Remove a plugin's jar and its metadata entry. Its folder (config, data) stays untouched.
     *
     * @throws GitHubSourceException
     */
    public function uninstall(Server $server, string $id): void
    {
        $lock = Cache::lock("minecraft-modrinth:github:install:{$server->id}", 60);

        if (!$lock->get()) {
            throw new GitHubSourceException(GitHubSourceException::FILE_CONFLICT, "Another plugin installation is running on server #{$server->id}");
        }

        try {
            $installedList = $this->getInstalled($server);
            $entry = $this->findInstalled($installedList, $id);

            if (!$entry) {
                return;
            }

            $this->deleteJar($server, $entry['filename']);
            $this->writeMetadata($server, array_values(array_filter($installedList, fn (array $item) => $item['id'] !== $id)));
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  IndexPlugin  $plugin
     *
     * @throws GitHubSourceException
     */
    public function downloadVerifiedJar(array $plugin, string $sha): string
    {
        if (isset($this->jarCache[$plugin['sha256']])) {
            return $this->jarCache[$plugin['sha256']];
        }

        $content = $this->client()->getRawFile($plugin['path'], $sha, min($plugin['size'], GitHubSourceRules::MAX_JAR_BYTES), self::DOWNLOAD_TIMEOUT);

        if (!GitHubSourceRules::verifyJar($content, $plugin['size'], $plugin['sha256'])) {
            throw new GitHubSourceException(GitHubSourceException::CHECKSUM_MISMATCH, "{$plugin['path']} at $sha does not match the size/sha256 in the index");
        }

        // An auto-update run installs the same jar on several servers: keep what we downloaded,
        // but bounded, since this lives in PHP memory.
        while (!empty($this->jarCache) && array_sum(array_map('strlen', $this->jarCache)) + strlen($content) > self::JAR_CACHE_BYTES) {
            array_shift($this->jarCache);
        }
        if (strlen($content) <= self::JAR_CACHE_BYTES) {
            $this->jarCache[$plugin['sha256']] = $content;
        }

        return $content;
    }

    /**
     * Pick the file name for a new jar: the indexed name, or - if that is taken by the plugin's
     * own current jar - an alternative one. Any other jar that looks like the same plugin
     * (another version, a manual copy named after the plugin, a Modrinth install of it) is a
     * conflict: installing next to it would leave two copies of the plugin in plugins/.
     *
     * @param  IndexPlugin  $plugin
     * @param  InstalledPlugin|null  $previous
     * @param  array<int, string>  $existing  jar names currently in plugins/
     *
     * @throws GitHubSourceException
     */
    public function chooseTargetFilename(array $plugin, ?array $previous, array $existing): string
    {
        $previousName = $previous['filename'] ?? null;

        foreach ($existing as $name) {
            if ($name === $previousName) {
                continue;
            }

            if (GitHubSourceRules::looksLikeSamePlugin($name, $plugin['filename'], $plugin['name'])
                || ($previousName !== null && GitHubSourceRules::belongsToSamePlugin($name, $previousName))) {
                throw new GitHubSourceException(GitHubSourceException::FILE_CONFLICT, "plugins/$name looks like another copy of {$plugin['name']} that this plugin doesn't manage - remove it first");
            }
        }

        foreach ([$plugin['filename'], GitHubSourceRules::alternativeFilename($plugin['filename'], $plugin['sha256'])] as $candidate) {
            if (!in_array($candidate, $existing, true)) {
                return $candidate;
            }
        }

        throw new GitHubSourceException(GitHubSourceException::FILE_CONFLICT, "No free file name for {$plugin['filename']} in plugins/");
    }

    /**
     * @return array<int, string>
     *
     * @throws GitHubSourceException
     */
    protected function listJarNames(Server $server): array
    {
        try {
            $files = app(DaemonFileRepository::class)->setServer($server)->getDirectory(self::FOLDER);
        } catch (RequestException $exception) {
            if ($exception->response->status() === 404) {
                return [];
            }

            throw new GitHubSourceException(GitHubSourceException::DAEMON, "Could not list plugins/ of server #{$server->id}");
        } catch (Exception) {
            throw new GitHubSourceException(GitHubSourceException::DAEMON, "Could not list plugins/ of server #{$server->id}");
        }

        if (isset($files['error'])) {
            throw new GitHubSourceException(GitHubSourceException::DAEMON, "Daemon returned an error while listing plugins/ of server #{$server->id}");
        }

        $names = [];
        foreach ($files as $file) {
            if (is_array($file) && is_string($file['name'] ?? null) && ($file['file'] ?? true) && str_ends_with(strtolower($file['name']), '.jar')) {
                $names[] = $file['name'];
            }
        }

        return $names;
    }

    /**
     * @return array{name: string, size: int}|null
     */
    protected function statFile(Server $server, string $filename): ?array
    {
        try {
            $files = app(DaemonFileRepository::class)->setServer($server)->getDirectory(self::FOLDER);
        } catch (Exception) {
            return null;
        }

        foreach ($files as $file) {
            if (is_array($file) && ($file['name'] ?? null) === $filename) {
                return ['name' => $filename, 'size' => (int) ($file['size'] ?? -1)];
            }
        }

        return null;
    }

    /**
     * Write the jar under a temporary name that Paper/Velocity ignore (not *.jar), then rename it
     * into place. Wings refuses a rename onto an existing file, so nothing is ever overwritten,
     * and a write that dies halfway never leaves a truncated jar for the next start to load.
     *
     * @throws GitHubSourceException
     */
    protected function writeJar(Server $server, string $filename, string $content): void
    {
        if (!GitHubSourceRules::isValidJarFilename($filename)) {
            throw new GitHubSourceException(GitHubSourceException::INVALID_INDEX, 'Refusing to write an invalid file name');
        }

        $temporary = '.github-'.bin2hex(random_bytes(6)).'.part';
        $repository = app(DaemonFileRepository::class)->setServer($server);

        try {
            $repository->getHttpClient()
                ->timeout(max((int) config('panel.guzzle.timeout'), self::DAEMON_WRITE_TIMEOUT))
                ->withQueryParameters(['file' => self::FOLDER.'/'.$temporary])
                ->withBody($content, 'application/octet-stream')
                ->post("/api/servers/{$server->uuid}/files/write");
        } catch (Exception) {
            $this->deleteQuietly($server, $temporary);

            throw new GitHubSourceException(GitHubSourceException::DAEMON, "Could not write the jar to server #{$server->id}");
        }

        try {
            $repository->renameFiles(self::FOLDER, [['from' => $temporary, 'to' => $filename]]);
        } catch (Exception) {
            $this->deleteQuietly($server, $temporary);

            throw new GitHubSourceException(GitHubSourceException::FILE_CONFLICT, "Could not move the new jar into place as plugins/$filename on server #{$server->id} (does it exist already?)");
        }

        $stat = $this->statFile($server, $filename);
        if ($stat === null || $stat['size'] !== strlen($content)) {
            $this->deleteQuietly($server, $filename);

            throw new GitHubSourceException(GitHubSourceException::DAEMON, "plugins/$filename on server #{$server->id} is missing or has the wrong size after writing it");
        }
    }

    /** @throws GitHubSourceException */
    protected function deleteJar(Server $server, string $filename): void
    {
        if (!GitHubSourceRules::isValidJarFilename($filename)) {
            throw new GitHubSourceException(GitHubSourceException::METADATA, 'Refusing to delete an invalid file name');
        }

        $this->deleteFile($server, $filename);
    }

    /** @throws GitHubSourceException */
    protected function deleteFile(Server $server, string $filename): void
    {
        try {
            $response = app(DaemonFileRepository::class)->setServer($server)->deleteFiles(self::FOLDER, [$filename]);
        } catch (RequestException $exception) {
            if ($exception->response->status() === 404) {
                return;
            }

            throw new GitHubSourceException(GitHubSourceException::DAEMON, "Could not delete plugins/$filename on server #{$server->id}");
        } catch (Exception) {
            throw new GitHubSourceException(GitHubSourceException::DAEMON, "Could not delete plugins/$filename on server #{$server->id}");
        }

        if ($response->failed() && $response->status() !== 404) {
            throw new GitHubSourceException(GitHubSourceException::DAEMON, "Could not delete plugins/$filename on server #{$server->id}");
        }
    }

    protected function deleteQuietly(Server $server, string $filename): void
    {
        try {
            $this->deleteFile($server, $filename);
        } catch (GitHubSourceException $exception) {
            $this->report($exception);
        }
    }

    // ------------------------------------------------------------------
    // Automatic updates
    // ------------------------------------------------------------------

    /**
     * Update every plugin already installed from the repository on this server to a newer
     * version from a commit with green CI. Never installs anything new.
     *
     * @param  Snapshot  $snapshot
     * @return array<int, string> ids that were updated
     */
    public function autoUpdateServer(Server $server, array $snapshot): array
    {
        $platform = $this->platformForServer($server);
        if ($platform === null || $snapshot['ci'] !== CiStatus::Green) {
            return [];
        }

        try {
            $installed = $this->getInstalled($server);
        } catch (GitHubSourceException $exception) {
            $this->reportOncePerWindow("server:{$server->id}:metadata", $exception);

            return [];
        }

        $updated = [];

        foreach ($installed as $entry) {
            $plugin = null;
            foreach ($this->pluginsForPlatform($snapshot['plugins'], $platform) as $candidate) {
                if ($candidate['id'] === $entry['id']) {
                    $plugin = $candidate;

                    break;
                }
            }

            if ($plugin === null || !GitHubSourceRules::isNewerVersion($plugin['version'], $entry['version'])) {
                continue;
            }

            try {
                $this->installOrUpdate($server, $plugin, $snapshot, true);
                $updated[] = $plugin['id'];
            } catch (GitHubSourceException $exception) {
                $this->reportOncePerWindow("server:{$server->id}:update:{$plugin['id']}:{$exception->reason}", $exception);
            }
        }

        return $updated;
    }

    // ------------------------------------------------------------------
    // Logging
    // ------------------------------------------------------------------

    /**
     * Report a failure at most once per key within the configured window, so a persistent
     * problem (bad token, GitHub down, ...) isn't logged again on every page view or run.
     */
    public function reportOncePerWindow(string $key, Throwable $exception): void
    {
        $minutes = (int) config('minecraft-modrinth.github.report_throttle_minutes', 60);

        if ($minutes <= 0 || Cache::add('minecraft-modrinth:github:reported:'.md5($key), true, now()->addMinutes($minutes))) {
            $this->report($exception);
        }
    }

    protected function report(Throwable $exception): void
    {
        // Only our own exceptions are reported, and those never carry the token (see
        // GitHubSourceException). Anything else is reduced to its class name.
        report($exception instanceof GitHubSourceException
            ? $exception
            : new GitHubSourceException(GitHubSourceException::DAEMON, 'Unexpected '.get_class($exception)));
    }
}
