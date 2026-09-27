<?php

namespace Boy132\MinecraftModrinth\GitHub;

use App\Models\Server;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
use Boy132\MinecraftModrinth\Support\DaemonFileException;
use Boy132\MinecraftModrinth\Support\DaemonFiles;
use Illuminate\Contracts\Encryption\DecryptException;
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
 * @phpstan-type PendingDelete array{filename: string, size: int}
 * @phpstan-type Metadata array{installed: array<int, InstalledPlugin>, pending_delete: array<int, PendingDelete>}
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

    /** The metadata file is read into PHP memory: anything larger is refused. */
    public const MAX_METADATA_BYTES = 1024 * 1024;

    protected const MAX_METADATA_ENTRIES = 1000;

    protected const MAX_PENDING_DELETES = 50;

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

    /** Workflow file ("build.yml") that must have succeeded for CI to count as green, or null. */
    public function requiredWorkflow(): ?string
    {
        $workflow = trim((string) config('minecraft-modrinth.github.required_workflow'));

        return $workflow !== '' ? $workflow : null;
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
            && GitHubSourceRules::isValidIndexPath($this->indexPath())
            && ($this->requiredWorkflow() === null || GitHubSourceRules::isValidWorkflowFile($this->requiredWorkflow()));
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
        $key = $this->cacheKey('ci', $this->branch(), $sha, (string) $this->requiredWorkflow());

        $cached = Cache::get($key);
        if (is_string($cached) && ($status = CiStatus::tryFrom($cached))) {
            return $status;
        }

        $status = GitHubSourceRules::evaluateWorkflowRuns($this->client()->getWorkflowRuns($sha, $this->branch()), $sha, $this->branch(), $this->requiredWorkflow());
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
            Cache::forget($this->cacheKey('ci', $this->branch(), $sha, (string) $this->requiredWorkflow()));
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
    public function pluginsForPlatform(array $plugins, ?string $platform): array
    {
        return GitHubSourceRules::pluginsForPlatform($plugins, $platform);
    }

    /**
     * @return array<int, InstalledPlugin>
     *
     * @throws GitHubSourceException when the metadata file exists but can't be read or trusted
     */
    public function getInstalled(Server $server): array
    {
        return $this->readMetadata($server)['installed'];
    }

    /**
     * The metadata file: installed plugins, and old jars of updated plugins that still have to
     * be deleted (see installLocked()).
     *
     * @return Metadata
     *
     * @throws GitHubSourceException when the metadata file exists but can't be read or trusted
     */
    public function readMetadata(Server $server): array
    {
        try {
            $content = DaemonFiles::readSmallFile($server, self::FOLDER.'/'.self::METADATA_FILE, self::MAX_METADATA_BYTES);
        } catch (DaemonFileException $exception) {
            // Too large counts as damaged; an unknown server stays recognisable for the callers.
            throw GitHubSourceException::fromDaemon($exception, GitHubSourceException::DAEMON);
        }

        if ($content === null) {
            return ['installed' => [], 'pending_delete' => []];
        }

        $data = json_decode($content, true);

        if (!is_array($data) || !isset($data['installed']) || !is_array($data['installed'])) {
            // Refuse instead of starting over: an empty list would be written back on the next
            // install and every jar recorded in the damaged file would become untracked.
            throw new GitHubSourceException(GitHubSourceException::METADATA, 'plugins/'.self::METADATA_FILE." on server #{$server->id} is damaged");
        }

        $installed = [];
        foreach (array_slice($data['installed'], 0, self::MAX_METADATA_ENTRIES) as $entry) {
            if (!is_array($entry)
                || !is_string($entry['id'] ?? null) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $entry['id'])
                || !is_string($entry['filename'] ?? null) || !GitHubSourceRules::isValidJarFilename($entry['filename'])
                || !is_string($entry['version'] ?? null) || strlen($entry['version']) > 64
                || isset($installed[$entry['id']])) {
                continue;
            }

            $string = fn (string $key, int $max = 256) => is_string($entry[$key] ?? null) && strlen($entry[$key]) <= $max ? $entry[$key] : '';

            $installed[$entry['id']] = [
                'id' => $entry['id'],
                'name' => $string('name') !== '' ? $string('name') : $entry['id'],
                'platform' => $string('platform', 32),
                'version' => $entry['version'],
                'sha256' => $string('sha256', 64),
                'size' => is_int($entry['size'] ?? null) ? $entry['size'] : 0,
                'filename' => $entry['filename'],
                'repository' => $string('repository'),
                'commit' => $string('commit', 64),
                'installed_at' => $string('installed_at', 64),
            ] + ($string('updated_at', 64) !== '' ? ['updated_at' => $string('updated_at', 64)] : []);
        }

        $pending = [];
        foreach (is_array($data['pending_delete'] ?? null) ? array_slice($data['pending_delete'], 0, self::MAX_PENDING_DELETES) : [] as $item) {
            if (is_array($item) && is_string($item['filename'] ?? null) && GitHubSourceRules::isValidJarFilename($item['filename']) && is_int($item['size'] ?? null)) {
                $pending[] = ['filename' => $item['filename'], 'size' => $item['size']];
            }
        }

        return ['installed' => array_values($installed), 'pending_delete' => $pending];
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

    /**
     * @param  array<int, InstalledPlugin>  $installed
     * @param  array<int, PendingDelete>  $pendingDelete
     */
    protected function writeMetadata(Server $server, array $installed, array $pendingDelete = []): void
    {
        $data = ['schema' => 1, 'installed' => array_values($installed)];

        // Only present while there is something to delete, so the file otherwise stays as in 1.2.0.
        if (!empty($pendingDelete)) {
            $data['pending_delete'] = array_values($pendingDelete);
        }

        try {
            DaemonFiles::writeFile($server, self::FOLDER.'/'.self::METADATA_FILE, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), (int) config('panel.guzzle.timeout', 15));
        } catch (DaemonFileException $exception) {
            throw GitHubSourceException::fromDaemon($exception, GitHubSourceException::METADATA);
        }
    }

    /**
     * Install a plugin from the snapshot, or update it when it's already installed.
     *
     * The new jar is written under a name that isn't taken yet (never over an existing file),
     * then the metadata is saved (recording the old jar as "to delete"), then the old jar is
     * removed. If the old jar can't be removed, the new version stays recorded and the old jar
     * stays in the metadata as pending deletion: it is deleted at the next action or check, so
     * no jar is ever left untracked. The running server keeps using what it loaded; the new
     * version is used after its next restart.
     *
     * @param  IndexPlugin  $plugin
     * @param  Snapshot  $snapshot
     * @param  bool|null  $expectInstalled  true for "Update", false for "Install": refused when the state changed meanwhile
     * @return InstalledPlugin
     *
     * @throws GitHubSourceException
     */
    public function installOrUpdate(Server $server, array $plugin, array $snapshot, bool $requireGreenCi, ?bool $expectInstalled = null): array
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
            return $this->installLocked($server, $plugin, $snapshot, $expectInstalled);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  IndexPlugin  $plugin
     * @param  Snapshot  $snapshot
     * @return InstalledPlugin
     */
    protected function installLocked(Server $server, array $plugin, array $snapshot, ?bool $expectInstalled): array
    {
        $metadata = $this->readMetadata($server);
        $installedList = $metadata['installed'];
        $previous = $this->findInstalled($installedList, $plugin['id']);

        if ($expectInstalled === false && $previous !== null) {
            throw new GitHubSourceException(GitHubSourceException::STATE_CHANGED, "{$plugin['id']} was installed on server #{$server->id} in the meantime");
        }

        if ($expectInstalled === true && $previous === null) {
            throw new GitHubSourceException(GitHubSourceException::STATE_CHANGED, "{$plugin['id']} is no longer installed on server #{$server->id}");
        }

        // Download and verify before touching the server at all.
        $content = $this->downloadVerifiedJar($plugin, $snapshot['sha']);

        $files = $this->listPluginFiles($server);
        $pending = $this->deletePending($server, $metadata['pending_delete'], $installedList, $files);

        // Old jars still waiting for deletion are ours, not another copy of the plugin.
        $pendingNames = array_column($pending, 'filename');
        $existing = array_values(array_diff($this->jarNamesOf($files), $pendingNames));
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

        $replacesOldJar = $previous !== null && $previous['filename'] !== $target;

        // The old jar is recorded as "to delete" before it is deleted: whatever fails from here
        // on, the metadata always knows about every jar of this plugin.
        $pendingWithOld = $replacesOldJar
            ? [...$pending, ['filename' => $previous['filename'], 'size' => $previous['size']]]
            : $pending;

        try {
            $this->writeMetadata($server, $newList, $pendingWithOld);
        } catch (GitHubSourceException $exception) {
            // A write that timed out may still have gone through: only roll back what didn't.
            if (!$this->metadataRecords($server, $entry)) {
                $this->deleteQuietly($server, $target);

                throw $exception;
            }
        }

        if (!$replacesOldJar) {
            return $entry;
        }

        try {
            $this->deleteJar($server, $previous['filename']);
        } catch (GitHubSourceException $exception) {
            if (!$this->oldJarGone($server, $previous['filename'])) {
                // Stays recorded as pending deletion (written above) and is retried later.
                throw new GitHubSourceException(GitHubSourceException::OLD_JAR_PENDING, "Updated {$plugin['id']} to {$plugin['version']} on server #{$server->id}, but plugins/{$previous['filename']} could not be deleted yet ({$exception->getMessage()})");
            }
        }

        // Deleted: drop it from the pending list again. If this write fails the entry only
        // points at a file that no longer exists and is dropped at the next cleanup.
        try {
            $this->writeMetadata($server, $newList, $pending);
        } catch (GitHubSourceException $exception) {
            $this->report($exception);
        }

        return $entry;
    }

    /**
     * Whether the metadata on the server records exactly this entry (after an unclear write).
     *
     * @param  InstalledPlugin  $entry
     */
    protected function metadataRecords(Server $server, array $entry): bool
    {
        try {
            $recorded = $this->findInstalled($this->getInstalled($server), $entry['id']);
        } catch (GitHubSourceException) {
            return false;
        }

        return $recorded !== null && $recorded['filename'] === $entry['filename'] && $recorded['version'] === $entry['version'];
    }

    /** After a failed delete: whether the file is gone anyway (e.g. the request timed out after it). */
    protected function oldJarGone(Server $server, string $filename): bool
    {
        try {
            return DaemonFiles::find($this->listPluginFiles($server), $filename) === null;
        } catch (GitHubSourceException) {
            return false;
        }
    }

    /**
     * Delete old jars recorded as pending deletion. Only a regular file with exactly the
     * recorded name and size is deleted; one that is gone, changed or taken over by an
     * installed plugin is simply forgotten. Returns what is still pending.
     *
     * @param  array<int, PendingDelete>  $pending
     * @param  array<int, InstalledPlugin>  $installed
     * @param  array<int, array<string, mixed>>  $files  listing of plugins/
     * @return array<int, PendingDelete>
     */
    protected function deletePending(Server $server, array $pending, array $installed, array $files): array
    {
        $installedNames = array_column($installed, 'filename');
        $remaining = [];

        foreach ($pending as $item) {
            $file = DaemonFiles::find($files, $item['filename']);

            if (in_array($item['filename'], $installedNames, true) || $file === null || !DaemonFiles::isRegularFile($file)
                || ($item['size'] > 0 && (int) ($file['size'] ?? -1) !== $item['size'])) {
                continue;
            }

            try {
                $this->deleteJar($server, $item['filename']);
            } catch (GitHubSourceException $exception) {
                $this->reportOncePerWindow("server:{$server->id}:pending:{$item['filename']}", $exception);
                $remaining[] = $item;
            }
        }

        return $remaining;
    }

    /**
     * Housekeeping outside of an installation: delete old jars that are pending deletion and
     * temporary uploads (.github-*.part) left behind for more than an hour. Does nothing while
     * an installation runs on the server; never throws.
     *
     * @param  array<int, array<string, mixed>>|null  $files  a fresh listing of plugins/, if at hand
     */
    public function cleanupServer(Server $server, ?array $files = null): void
    {
        $lock = Cache::lock("minecraft-modrinth:github:install:{$server->id}", 60);

        if (!$lock->get()) {
            return;
        }

        try {
            $files ??= $this->listPluginFiles($server);

            foreach ($files as $file) {
                if (is_string($file['name'] ?? null) && DaemonFiles::isRegularFile($file)
                    && GitHubSourceRules::isStaleTemporaryUpload($file['name'], $file['modified'] ?? null, time())) {
                    $this->deleteQuietly($server, $file['name']);
                }
            }

            $metadata = $this->readMetadata($server);

            if (!empty($metadata['pending_delete'])) {
                $pending = $this->deletePending($server, $metadata['pending_delete'], $metadata['installed'], $files);

                if ($pending !== $metadata['pending_delete']) {
                    $this->writeMetadata($server, $metadata['installed'], $pending);
                }
            }
        } catch (GitHubSourceException $exception) {
            if ($exception->reason !== GitHubSourceException::UNKNOWN_SERVER) {
                $this->reportOncePerWindow("server:{$server->id}:cleanup:{$exception->reason}", $exception);
            }
        } finally {
            $lock->release();
        }
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
            $metadata = $this->readMetadata($server);
            $installedList = $metadata['installed'];
            $entry = $this->findInstalled($installedList, $id);

            if (!$entry) {
                return;
            }

            $this->deleteJar($server, $entry['filename']);
            $this->writeMetadata($server, array_values(array_filter($installedList, fn (array $item) => $item['id'] !== $id)), $metadata['pending_delete']);
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
     * Everything in plugins/ (Wings list-directory entries); empty when the folder doesn't exist.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws GitHubSourceException
     */
    public function listPluginFiles(Server $server): array
    {
        try {
            return DaemonFiles::listDirectory($server, self::FOLDER) ?? [];
        } catch (DaemonFileException $exception) {
            throw GitHubSourceException::fromDaemon($exception, GitHubSourceException::DAEMON);
        }
    }

    /**
     * @return array<int, string>
     *
     * @throws GitHubSourceException
     */
    public function listJarNames(Server $server): array
    {
        return $this->jarNamesOf($this->listPluginFiles($server));
    }

    /**
     * @param  array<int, array<string, mixed>>  $files
     * @return array<int, string>
     */
    public function jarNamesOf(array $files): array
    {
        $names = [];
        foreach ($files as $file) {
            if (is_string($file['name'] ?? null) && ($file['file'] ?? true) && str_ends_with(strtolower($file['name']), '.jar')) {
                $names[] = $file['name'];
            }
        }

        return $names;
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

        try {
            DaemonFiles::writeFile($server, self::FOLDER.'/'.$temporary, $content, self::DAEMON_WRITE_TIMEOUT);
        } catch (DaemonFileException $exception) {
            $this->deleteQuietly($server, $temporary);

            throw GitHubSourceException::fromDaemon($exception, GitHubSourceException::DAEMON);
        }

        try {
            DaemonFiles::rename($server, self::FOLDER, $temporary, $filename);
        } catch (DaemonFileException $exception) {
            $this->deleteQuietly($server, $temporary);

            throw GitHubSourceException::fromDaemon($exception, GitHubSourceException::DAEMON);
        }

        try {
            $file = DaemonFiles::find($this->listPluginFiles($server), $filename);
        } catch (GitHubSourceException) {
            $file = null;
        }

        if ($file === null || (int) ($file['size'] ?? -1) !== strlen($content)) {
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
            DaemonFiles::delete($server, self::FOLDER, $filename);
        } catch (DaemonFileException $exception) {
            throw GitHubSourceException::fromDaemon($exception, GitHubSourceException::DAEMON);
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
            $metadata = $this->readMetadata($server);
        } catch (GitHubSourceException $exception) {
            // A server its node doesn't know (any more) has nothing to update.
            if ($exception->reason !== GitHubSourceException::UNKNOWN_SERVER) {
                $this->reportOncePerWindow("server:{$server->id}:metadata", $exception);
            }

            return [];
        }

        if (!empty($metadata['pending_delete'])) {
            $this->cleanupServer($server);
        }

        $updated = [];

        foreach ($metadata['installed'] as $entry) {
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
                $this->installOrUpdate($server, $plugin, $snapshot, true, true);
                $updated[] = $plugin['id'];
            } catch (GitHubSourceException $exception) {
                if ($exception->reason === GitHubSourceException::OLD_JAR_PENDING) {
                    // Updated; only the old jar is left for later.
                    $updated[] = $plugin['id'];
                }

                $this->reportOncePerWindow("server:{$server->id}:update:{$plugin['id']}:{$exception->reason}", $exception);
            }
        }

        if (!empty($updated)) {
            // The cached offer count still includes these updates: drop it, the next offer check
            // (or a visit of the page) counts again.
            app(GitHubOffers::class)->forgetCounts($server);
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
