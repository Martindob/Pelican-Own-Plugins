<?php

namespace Boy132\MinecraftModrinth\GitHub;

use App\Facades\Activity;
use App\Models\Server;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
use Boy132\MinecraftModrinth\Modrinth\ModrinthMetadataException;
use Boy132\MinecraftModrinth\Services\MinecraftModrinthService;
use Boy132\MinecraftModrinth\Support\DaemonFileException;
use Boy132\MinecraftModrinth\Support\DaemonFiles;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Installs and updates plugins from a GitHub repository that ships pre-built jars and an index
 * of them (see README "GitHub repository source").
 *
 * The panel downloads every jar itself, checks it against the sha256/size from the index and
 * only then writes it to the server through the Wings file API - Wings never gets a GitHub URL
 * or the token. Only the jar in plugins/ and our own metadata file are ever touched; a plugin's
 * own folder (plugins/<Name>/config.yml, ...) never is. With "Replace manual copies" on, a jar of
 * the same plugin that was put into plugins/ by hand is replaced as well (see installLocked()).
 *
 * @phpstan-type IndexPlugin array{id: string, name: string, platform: string, version: string, path: string, filename: string, sha256: string, size: int, frozen: bool, previous_ids: array<int, string>}
 * @phpstan-type Snapshot array{repository: string, branch: string, sha: string, ci: CiStatus, plugins: array<int, IndexPlugin>, problems: array<int, string>, author?: ?string}
 * @phpstan-type InstalledPlugin array{id: string, name: string, platform: string, version: string, sha256: string, size: int, filename: string, repository: string, commit: string, installed_at: string, updated_at?: string}
 * @phpstan-type PendingDelete array{filename: string, size: int, replaced_by?: string}
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

    /** How long what was read from a manual copy (and a refused replacement) is remembered. */
    protected const MANUAL_COPY_CACHE_HOURS = 24;

    /** Head commits whose first sighting is remembered (quarantine of automatic updates). */
    protected const MAX_FIRST_SEEN = 200;

    /** Commits of the branch history the automatic update looks at (ONE GitHub request, never one per commit). */
    protected const AUTO_UPDATE_HISTORY = 30;

    /** Most commits whose CI the automatic update asks GitHub about per run (one request each, cached). */
    protected const AUTO_UPDATE_CI_CHECKS = 5;

    /** How long the chosen commit of an automatic update is shared (several runs / servers ask the same). */
    protected const AUTO_UPDATE_CHOICE_MINUTES = 10;

    /** A GitHub rate limit was hit during this automatic update: nothing more is asked of GitHub in this run. */
    protected bool $rateLimited = false;

    /** @var array<string, array<string, array{sha: string, size: int}>|null> jars of a commit's tree in this run, by commit (null: truncated) */
    protected array $treeCache = [];

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

    /**
     * Whether a jar of the same plugin that was put into plugins/ by hand is replaced with the
     * repository version (on by default; off: such a plugin is only shown as "Installed manually").
     */
    public function replacesManualCopies(): bool
    {
        return (bool) config('minecraft-modrinth.github.replace_manual', true);
    }

    /**
     * Automatic updates only use commits at least this many hours old (0 = off, default 2): time to notice a bad
     * commit on the branch before servers take it (manual installs are not delayed, the page warns).
     */
    public function quarantineHours(): int
    {
        return GitHubSourceRules::normalizeQuarantineHours(config('minecraft-modrinth.github.quarantine_hours', 2)) ?? 2;
    }

    /**
     * GitHub logins whose commits automatic updates may deploy (empty = no check), or null when the setting is
     * unusable - then automatic updates install nothing (fail closed).
     *
     * @return array<int, string>|null
     */
    public function allowedAuthors(): ?array
    {
        return GitHubSourceRules::parseAllowlist((string) config('minecraft-modrinth.github.allowed_authors', 'Martindob,arzinnn,zTeeCY,claude,noreply@anthropic.com'));
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

    /**
     * What "Test connection" should warn about in the security setup: a classic token (it reaches every repository of
     * its owner, with "repo" even with write access - a fine-grained read-only token for this one repository is the
     * recommended one), automatic updates without a required workflow (they install nothing then).
     *
     * @return array<int, string> translated warnings
     *
     * @throws GitHubSourceException
     */
    public function securityWarnings(): array
    {
        $warnings = [];
        $token = $this->token();

        $scopes = $token !== null ? $this->client()->classicTokenScopes() : null;
        if ($scopes !== null || ($token !== null && str_starts_with($token, 'ghp_'))) {
            $warnings[] = trans('minecraft-modrinth::strings.github.settings.warning_classic_token', ['scopes' => $scopes === null || $scopes === [] ? '-' : implode(', ', $scopes)]);
        }

        if ($this->requiredWorkflow() === null) {
            $warnings[] = trans('minecraft-modrinth::strings.github.settings.warning_no_workflow');
        }

        if ($this->allowedAuthors() === null) {
            $warnings[] = trans('minecraft-modrinth::strings.github.settings.allowed_authors_invalid');
        }

        return $warnings;
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
        $this->rememberFirstSeen($sha);

        return $sha;
    }

    /**
     * When this panel first saw each recent head commit of the branch (sha => unix time). The quarantine of automatic
     * updates counts from the latest of this, the commit's date and its CI run - a lost cache only makes it longer.
     *
     * @return array<string, int>
     */
    public function firstSeen(): array
    {
        $seen = Cache::get($this->cacheKey('first-seen', $this->branch()));

        return is_array($seen) ? array_filter($seen, fn ($time, $sha) => is_int($time) && is_string($sha) && GitHubSourceRules::isValidSha($sha), ARRAY_FILTER_USE_BOTH) : [];
    }

    protected function rememberFirstSeen(string $sha): void
    {
        $seen = $this->firstSeen();

        if (isset($seen[$sha])) {
            return;
        }

        $seen[$sha] = time();
        // Bounded: only the newest heads matter for a quarantine of at most a week.
        $seen = array_slice($seen, -self::MAX_FIRST_SEEN, null, true);
        Cache::put($this->cacheKey('first-seen', $this->branch()), $seen, now()->addDays(30));
    }

    public function getCiStatus(string $sha): CiStatus
    {
        return $this->getCiDetails($sha)['ci'];
    }

    /**
     * CI state of exactly $sha (only GitHub Actions runs of this repository - see GitHubSourceRules::
     * evaluateWorkflowRuns()) and when GitHub created the successful run of the required workflow.
     *
     * @return array{ci: CiStatus, tested_at: ?int}
     */
    public function getCiDetails(string $sha): array
    {
        $key = $this->cacheKey('ci-v2', $this->branch(), $sha, (string) $this->requiredWorkflow());

        $cached = Cache::get($key);
        if (is_array($cached) && is_string($cached['ci'] ?? null) && ($status = CiStatus::tryFrom($cached['ci']))
            && (is_int($cached['tested_at'] ?? null) || ($cached['tested_at'] ?? false) === null)) {
            return ['ci' => $status, 'tested_at' => $cached['tested_at']];
        }

        $runs = $this->client()->getWorkflowRuns($sha, $this->branch());
        $status = GitHubSourceRules::evaluateWorkflowRuns($runs, $sha, $this->branch(), $this->requiredWorkflow(), $this->repository());
        $testedAt = $this->requiredWorkflow() !== null && $status === CiStatus::Green
            ? GitHubSourceRules::requiredRunCreatedAt($runs, $sha, $this->branch(), $this->requiredWorkflow(), $this->repository())
            : null;

        Cache::put($key, ['ci' => $status->value, 'tested_at' => $testedAt], now()->addMinutes($status->cacheMinutes()));

        return ['ci' => $status, 'tested_at' => $testedAt];
    }

    /**
     * @return array{plugins: array<int, IndexPlugin>, problems: array<int, string>}
     */
    public function getIndex(string $sha): array
    {
        // "index-v2": entries parsed by 1.6.0 (previous_ids, ids unique per platform) - an index
        // cached by an older version of this plugin is parsed again.
        $key = $this->cacheKey('index-v2', $this->indexPath(), $sha);

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
            return $this->snapshotAt($this->getHeadSha());
        } catch (GitHubSourceException $exception) {
            if ($exception->reason !== GitHubSourceException::NOT_CONFIGURED) {
                $this->reportOncePerWindow('snapshot:'.$exception->reason, $exception);
            }

            throw $exception;
        }
    }

    /**
     * The same as getSnapshot() for exactly the commit $sha of the branch (its CI and its index).
     *
     * @return Snapshot
     *
     * @throws GitHubSourceException
     */
    protected function snapshotAt(string $sha): array
    {
        $ci = $this->getCiStatus($sha);
        $index = $this->getIndex($sha);

        return [
            'repository' => $this->repository(),
            'branch' => $this->branch(),
            'sha' => $sha,
            'ci' => $ci,
            'plugins' => $index['plugins'],
            'problems' => $index['problems'],
        ];
    }

    /**
     * The repository state the daily automatic update installs from: the newest commit of the branch that is older
     * than the quarantine, has green CI of the required workflow on exactly that SHA and passes the author allowlist
     * for every commit since the last automatically deployed one (GitHubSourceRules::selectAutoUpdateCommit()). Newer
     * commits are held back, not skipped forever: the next run takes them once they qualify. Null (logged) when no
     * commit qualifies or the settings don't allow automatic updates (no required workflow, unusable allowlist,
     * rewritten history) - nothing is installed then.
     *
     * @return array{snapshot: Snapshot, head: string, held_back: int, author: ?string}|null
     *
     * @throws GitHubSourceException GitHub unreachable / invalid answers
     */
    public function autoUpdateSnapshot(): ?array
    {
        $workflow = $this->requiredWorkflow();
        if ($workflow === null) {
            $this->reportOncePerWindow('auto:no-workflow', new GitHubSourceException(GitHubSourceException::NOT_CONFIGURED, 'Automatic updates from GitHub need a "Required workflow" (e.g. build.yml) in the plugin settings - nothing is updated without it'));

            return null;
        }

        $allowlist = $this->allowedAuthors();
        if ($allowlist === null) {
            $this->reportOncePerWindow('auto:allowlist', new GitHubSourceException(GitHubSourceException::NOT_CONFIGURED, 'The author allowlist in the plugin settings is invalid - automatic updates from GitHub install nothing until it is fixed'));

            return null;
        }

        $head = $this->getHeadSha();
        $lastDeployed = $this->lastDeployed();

        // The same decision for every server and for a run repeated shortly after (fewer GitHub requests).
        $choiceKey = $this->cacheKey('auto-choice', $this->branch(), $head, (string) $lastDeployed, $workflow, (string) $this->quarantineHours(), implode(',', $allowlist));
        $cached = Cache::get($choiceKey);
        if (is_array($cached) && is_string($cached['sha'] ?? null) && GitHubSourceRules::isValidSha($cached['sha'])) {
            $snapshot = $this->snapshotAt($cached['sha']);

            return $snapshot['ci'] === CiStatus::Green
                ? ['snapshot' => $snapshot, 'head' => $head, 'held_back' => (int) ($cached['held_back'] ?? 0), 'author' => is_string($cached['author'] ?? null) ? $cached['author'] : null]
                : null;
        }

        $commits = $this->client()->listCommits($head, self::AUTO_UPDATE_HISTORY);

        $choice = GitHubSourceRules::selectAutoUpdateCommit(
            $commits,
            fn (string $sha) => $this->getCiDetails($sha),
            time(),
            $this->quarantineHours() * 3600,
            $this->firstSeen(),
            $allowlist,
            $lastDeployed,
            self::AUTO_UPDATE_CI_CHECKS,
        );

        foreach (array_slice($choice['refused'], 0, 5) as $problem) {
            $this->reportOncePerWindow('auto:refused:'.$problem, new GitHubSourceException(GitHubSourceException::AUTHOR_NOT_ALLOWED, "Automatic update: $problem - not deployed automatically"));
        }

        if ($choice['sha'] === null) {
            Log::info('minecraft-modrinth: GitHub: automatic update: no commit of '.$this->branch()." qualifies (head {$this->shortSha($head)}: {$choice['held_back']} younger than the quarantine of {$this->quarantineHours()} h, {$choice['not_green']} without green $workflow".($choice['refused'] !== [] ? ', '.count($choice['refused']).' refused by the author allowlist' : '').') - nothing updated');

            return null;
        }

        $sha = $choice['sha'];

        // History since the last automatically deployed commit: never deploy over a rewritten branch (force push), and
        // with the allowlist check every commit in between when they weren't all in the listed history.
        if ($lastDeployed !== null && $lastDeployed !== $sha && !in_array($lastDeployed, array_column($commits, 'sha'), true)) {
            $comparison = $this->client()->compare($lastDeployed, $sha);

            if ($comparison['status'] === 'diverged') {
                $this->reportOncePerWindow('auto:diverged:'.$sha, new GitHubSourceException(GitHubSourceException::HISTORY_REWRITTEN, "Automatic update: {$this->shortSha($sha)} does not descend from the last deployed commit {$this->shortSha($lastDeployed)} - the branch history was rewritten (force push?). Nothing is updated automatically; check the repository, then install by hand"));

                return null;
            }

            if ($choice['range_check'] && $comparison['status'] === 'ahead') {
                $problem = $comparison['total'] > count($comparison['commits'])
                    ? "{$comparison['total']} commits since {$this->shortSha($lastDeployed)} - too many to check against the author allowlist"
                    : null;
                foreach ($comparison['commits'] as $commit) {
                    $problem ??= GitHubSourceRules::commitAuthorProblem($commit, $allowlist);
                }

                if ($problem !== null) {
                    $this->reportOncePerWindow('auto:refused:'.$problem, new GitHubSourceException(GitHubSourceException::AUTHOR_NOT_ALLOWED, "Automatic update: $problem - not deployed automatically"));

                    return null;
                }
            }
        }

        $snapshot = $this->snapshotAt($sha);

        if ($snapshot['ci'] !== CiStatus::Green) {
            return null;
        }

        $author = null;
        foreach ($commits as $commit) {
            if ($commit['sha'] === $sha) {
                $author = $commit['author'] ?? $commit['author_email'];
            }
        }

        Cache::put($choiceKey, ['sha' => $sha, 'held_back' => $choice['held_back'], 'author' => $author], now()->addMinutes(self::AUTO_UPDATE_CHOICE_MINUTES));

        Log::info("minecraft-modrinth: GitHub: automatic update uses commit $sha (author ".($author ?? 'unknown').", $workflow green)"
            .($sha !== $head ? "; {$choice['held_back']} newer commit(s) up to head {$this->shortSha($head)} held back by the quarantine of {$this->quarantineHours()} h or CI" : ''));

        return ['snapshot' => $snapshot, 'head' => $head, 'held_back' => $choice['held_back'], 'author' => $author];
    }

    /** The commit the last automatic update installed from (to check what came in since), or null. */
    public function lastDeployed(): ?string
    {
        $sha = Cache::get($this->cacheKey('last-deployed', $this->branch()));

        return is_string($sha) && GitHubSourceRules::isValidSha($sha) ? $sha : null;
    }

    public function rememberDeployed(string $sha): void
    {
        if (GitHubSourceRules::isValidSha($sha)) {
            Cache::put($this->cacheKey('last-deployed', $this->branch()), $sha, now()->addDays(365));
        }
    }

    /** Seconds since this panel first saw $sha as the head of the branch, or null when it doesn't know. */
    public function headAgeSeconds(string $sha): ?int
    {
        $seen = $this->firstSeen()[$sha] ?? null;

        return $seen === null ? null : max(0, time() - $seen);
    }

    protected function shortSha(string $sha): string
    {
        return substr($sha, 0, 7);
    }

    /** Forget the cached head commit and CI state so the next read asks GitHub again. */
    public function forgetCachedState(): void
    {
        $key = $this->cacheKey('head', $this->branch());
        $sha = Cache::get($key);

        Cache::forget($key);

        if (is_string($sha)) {
            Cache::forget($this->cacheKey('ci-v2', $this->branch(), $sha, (string) $this->requiredWorkflow()));
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
                || !is_string($entry['id'] ?? null) || !preg_match(GitHubSourceRules::ID_PATTERN, $entry['id'])
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
                // "replaced_by": a manual copy that was replaced (logged when it is deleted).
                $replacedBy = is_string($item['replaced_by'] ?? null) && strlen($item['replaced_by']) <= 160 ? $item['replaced_by'] : null;
                $pending[] = ['filename' => $item['filename'], 'size' => $item['size']] + ($replacedBy !== null ? ['replaced_by' => $replacedBy] : []);
            }
        }

        return ['installed' => array_values($installed), 'pending_delete' => $pending];
    }

    /**
     * The entry with exactly this id (the metadata file holds each id once). To find the entry of
     * an index entry, which may still be recorded under an earlier id, use
     * GitHubSourceRules::installedEntryFor().
     *
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
     * With $replaceManual (and the "Replace manual copies" setting on), jars of the same plugin
     * that were put into plugins/ by hand are replaced the same way as an old jar: checked first
     * (name rules, then the plugin name inside the jar), recorded as "to delete" together with the
     * new entry, and only then deleted. Without it, such a jar is a conflict as before.
     *
     * @param  IndexPlugin  $plugin
     * @param  Snapshot  $snapshot
     * @param  bool|null  $expectInstalled  true for "Update", false for "Install": refused when the state changed meanwhile
     * @param  array<int, string>|null  $replacedCopies  set to the manual copies that were deleted
     * @return InstalledPlugin
     *
     * @throws GitHubSourceException
     */
    public function installOrUpdate(Server $server, array $plugin, array $snapshot, bool $requireGreenCi, ?bool $expectInstalled = null, bool $replaceManual = false, ?array &$replacedCopies = null): array
    {
        $replacedCopies = [];

        if ($this->platformForServer($server) !== $plugin['platform']) {
            throw new GitHubSourceException(GitHubSourceException::INVALID_INDEX, "{$plugin['id']} is for {$plugin['platform']}, which server #{$server->id} is not");
        }

        if ($requireGreenCi && $snapshot['ci'] !== CiStatus::Green) {
            throw new GitHubSourceException(GitHubSourceException::CI_NOT_GREEN, "CI of {$snapshot['sha']} is {$snapshot['ci']->value}, not green");
        }

        // Also covers reading a manual copy from the node to check what it is.
        $lock = Cache::lock("minecraft-modrinth:github:install:{$server->id}", self::DOWNLOAD_TIMEOUT * 2 + self::DAEMON_WRITE_TIMEOUT + 60);

        if (!$lock->get()) {
            throw new GitHubSourceException(GitHubSourceException::FILE_CONFLICT, "Another plugin installation is running on server #{$server->id}");
        }

        try {
            return $this->installLocked($server, $plugin, $snapshot, $expectInstalled, $replaceManual && $this->replacesManualCopies(), $replacedCopies);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  IndexPlugin  $plugin
     * @param  Snapshot  $snapshot
     * @param  array<int, string>  $replacedCopies  receives the manual copies that were deleted
     * @return InstalledPlugin
     */
    protected function installLocked(Server $server, array $plugin, array $snapshot, ?bool $expectInstalled, bool $replaceManual, array &$replacedCopies): array
    {
        $metadata = $this->readMetadata($server);
        $installedList = $metadata['installed'];

        // Entries still recorded under an earlier id of a plugin get their current id with this
        // write - only from a commit with green CI, whose index is the one servers update from.
        if ($snapshot['ci'] === CiStatus::Green) {
            $installedList = GitHubSourceRules::migrateInstalledIds($installedList, $this->pluginsForPlatform($snapshot['plugins'], $plugin['platform']))['installed'];
        }

        // The plugin as installed - under its id or an earlier one ("previous_ids").
        $previous = GitHubSourceRules::installedEntryFor($installedList, $plugin);

        if ($previous === null && $this->findInstalled($installedList, $plugin['id']) !== null) {
            // The file already has this id for another platform (e.g. the egg was changed): one
            // id can be recorded only once, so that entry has to be removed first.
            throw new GitHubSourceException(GitHubSourceException::FILE_CONFLICT, 'plugins/'.self::METADATA_FILE." on server #{$server->id} already lists {$plugin['id']} for another platform - remove it on the GitHub Plugins page first");
        }

        if ($previous === null) {
            foreach ($installedList as $item) {
                // The same plugin (Paper/Velocity load only one plugin of a name) recorded under an
                // id the index doesn't name for it, e.g. a changed id without "previous_ids":
                // installing it again would leave two jars of it in plugins/.
                if (strcasecmp($item['name'], $plugin['name']) === 0 && in_array($item['platform'], ['', $plugin['platform']], true)) {
                    throw new GitHubSourceException(GitHubSourceException::FILE_CONFLICT, "{$plugin['name']} is already installed on server #{$server->id} as {$item['id']}, which the index doesn't list as an earlier id of {$plugin['id']} (previous_ids) - remove it on the GitHub Plugins page first");
                }
            }
        }

        if ($expectInstalled === false && $previous !== null) {
            throw new GitHubSourceException(GitHubSourceException::STATE_CHANGED, "{$plugin['id']} was installed on server #{$server->id} in the meantime");
        }

        if ($expectInstalled === true && $previous === null) {
            throw new GitHubSourceException(GitHubSourceException::STATE_CHANGED, "{$plugin['id']} is no longer installed on server #{$server->id}");
        }

        // Never over a newer (or another jar of the same) version: an index that points back to an older, perhaps
        // vulnerable version can't take a server there - not automatically, not by "Update" / "Update all". Going back
        // stays a deliberate admin decision (remove the plugin, then install the repository's version).
        if ($previous !== null && (!GitHubSourceRules::isValidVersion($previous['version'])
            || !GitHubSourceRules::isNewerVersion($plugin['version'], $previous['version']))) {
            throw new GitHubSourceException(GitHubSourceException::DOWNGRADE, "{$plugin['id']} {$plugin['version']} at {$snapshot['sha']} is not newer than the installed {$previous['version']} on server #{$server->id} - not installed over it");
        }

        // Download and verify before touching the server at all.
        $content = $this->downloadVerifiedJar($plugin, $snapshot['sha']);

        $files = $this->listPluginFiles($server);
        $pending = $this->deletePending($server, $metadata['pending_delete'], $installedList, $files);

        // Old jars still waiting for deletion are ours, not another copy of the plugin.
        $pendingNames = array_column($pending, 'filename');
        $existing = array_values(array_diff($this->jarNamesOf($files), $pendingNames));

        // Manual copies to replace: fully checked before anything is written (throws otherwise).
        $takeOver = $replaceManual ? $this->copiesToTakeOver($server, $plugin, $snapshot, $installedList, $pendingNames, $files, $content) : [];

        $target = $this->chooseTargetFilename($plugin, $previous, $existing, array_column($takeOver, 'filename'));

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
            // Also replaces an entry recorded under an earlier id: it gets the current one.
            if ($previous !== null && $item['id'] === $previous['id']) {
                $newList[] = $entry;
                $replaced = true;
            } else {
                $newList[] = $item;
            }
        }
        if (!$replaced) {
            $newList[] = $entry;
        }

        // Everything this installation deletes: the plugin's old jar and the manual copies.
        $toDelete = [];

        if ($previous !== null && $previous['filename'] !== $target) {
            $toDelete[] = ['filename' => $previous['filename'], 'size' => $previous['size']];
        }

        foreach ($takeOver as $copy) {
            $toDelete[] = $copy + ['replaced_by' => $plugin['name'].' '.$plugin['version']];
        }

        // Recorded as "to delete" before anything is deleted: whatever fails from here on, the
        // metadata always knows about every jar of this plugin.
        try {
            $this->writeMetadata($server, $newList, [...$pending, ...$toDelete]);
        } catch (GitHubSourceException $exception) {
            // A write that timed out may still have gone through: only roll back what didn't.
            if (!$this->metadataRecords($server, $entry)) {
                $this->deleteQuietly($server, $target);

                throw $exception;
            }
        }

        $this->logInstalled($server, $entry, $previous, $snapshot);

        if ($toDelete === []) {
            return $entry;
        }

        $stillPending = [];
        $failures = [];

        foreach ($toDelete as $item) {
            try {
                $this->deleteJar($server, $item['filename']);
            } catch (GitHubSourceException $exception) {
                if (!$this->oldJarGone($server, $item['filename'])) {
                    // Stays recorded as pending deletion (written above) and is retried later.
                    $stillPending[] = $item;
                    $failures[] = "plugins/{$item['filename']} ({$exception->getMessage()})";

                    continue;
                }
            }

            if (isset($item['replaced_by'])) {
                $replacedCopies[] = $item['filename'];
                $this->logReplacedCopy($server, $item['filename'], $item['replaced_by']);
            }
        }

        // Drop what was deleted from the pending list again. If this write fails the entries only
        // point at files that no longer exist and are dropped at the next cleanup.
        try {
            $this->writeMetadata($server, $newList, [...$pending, ...$stillPending]);
        } catch (GitHubSourceException $exception) {
            $this->report($exception);
        }

        if ($failures !== []) {
            throw new GitHubSourceException(GitHubSourceException::OLD_JAR_PENDING, ($previous ? 'Updated' : 'Installed')." {$plugin['id']} {$plugin['version']} on server #{$server->id}, but ".implode(', ', $failures).' could not be deleted yet');
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

                continue;
            }

            if (isset($item['replaced_by'])) {
                $this->logReplacedCopy($server, $item['filename'], $item['replaced_by']);
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
     * @param  array<int, string>  $otherIds  further ids of the same plugin, tried when $id isn't recorded (it
     *                                        may have been converted to the current id since the page was loaded)
     *
     * @throws GitHubSourceException
     */
    public function uninstall(Server $server, string $id, array $otherIds = []): void
    {
        $lock = Cache::lock("minecraft-modrinth:github:install:{$server->id}", 60);

        if (!$lock->get()) {
            throw new GitHubSourceException(GitHubSourceException::FILE_CONFLICT, "Another plugin installation is running on server #{$server->id}");
        }

        try {
            $metadata = $this->readMetadata($server);
            $installedList = $metadata['installed'];
            $entry = null;
            foreach ([$id, ...$otherIds] as $candidate) {
                $entry ??= $this->findInstalled($installedList, $candidate);
            }

            if (!$entry) {
                return;
            }

            $this->deleteJar($server, $entry['filename']);
            $this->writeMetadata($server, array_values(array_filter($installedList, fn (array $item) => $item['id'] !== $entry['id'])), $metadata['pending_delete']);
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

        $this->verifyDownloadedJar($plugin, $sha, $content);

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
     * Every check a downloaded jar has to pass before it may be written to any server (throws otherwise):
     *
     * 1. size and sha256 from the index of exactly that commit,
     * 2. size and git blob SHA of the file in exactly that commit's tree (GitHub's directory listing - a second,
     *    independent answer: a download that was swapped or damaged on the way can't match both),
     * 3. the plugin name and version declared inside the jar (plugin.yml / paper-plugin.yml / velocity-plugin.json)
     *    are the ones in the index entry - an index can't pass off another plugin or an old jar as a new version.
     *
     * @param  IndexPlugin  $plugin
     *
     * @throws GitHubSourceException
     */
    protected function verifyDownloadedJar(array $plugin, string $sha, string $content): void
    {
        if (!GitHubSourceRules::verifyJar($content, $plugin['size'], $plugin['sha256'])) {
            throw new GitHubSourceException(GitHubSourceException::CHECKSUM_MISMATCH, "{$plugin['path']} at $sha does not match the size/sha256 in the index");
        }

        $listing = $this->directoryListing(dirname($plugin['path']), $sha);

        if (($problem = GitHubSourceRules::treeMismatch($content, $plugin['filename'], $listing)) !== null) {
            throw new GitHubSourceException(GitHubSourceException::CHECKSUM_MISMATCH, "{$plugin['path']} at $sha: $problem");
        }

        $problem = null;
        $descriptor = $this->descriptorOfContent($content, $plugin['platform'], $problem);

        if ($descriptor === null || ($problem = GitHubSourceRules::descriptorMismatch($descriptor, $plugin)) !== null) {
            throw new GitHubSourceException(GitHubSourceException::CHECKSUM_MISMATCH, "{$plugin['path']} at $sha: $problem");
        }
    }

    /**
     * The files of a directory in exactly the tree of commit $sha (name => git blob SHA, size), once per run.
     *
     * @return array<string, array{sha: string, size: int}>
     *
     * @throws GitHubSourceException
     */
    protected function directoryListing(string $directory, string $sha): array
    {
        if (!array_key_exists($sha, $this->treeCache)) {
            // ONE request per commit for every jar of it (a commit's tree never changes - cached for an hour).
            $key = $this->cacheKey('tree-jars', $sha);
            $cached = Cache::get($key);
            $jars = is_array($cached) ? $cached : $this->client()->getTreeJars($sha);

            if ($jars !== null && !is_array($cached)) {
                Cache::put($key, $jars, now()->addMinutes(self::INDEX_CACHE_MINUTES));
            }

            $this->treeCache[$sha] = $jars;
        }

        // GitHub truncated a very large tree: that one directory is listed on its own instead.
        return $this->treeCache[$sha] !== null
            ? GitHubSourceRules::treeDirectory($this->treeCache[$sha], $directory)
            : $this->client()->getDirectoryListing($directory, $sha);
    }

    /**
     * Pick the file name for a new jar: the indexed name, or - if that is taken by the plugin's
     * own current jar (or a manual copy that is being replaced) - an alternative one. Any other
     * jar that looks like the same plugin (another version, a manual copy named after the
     * plugin, a Modrinth install of it) is a conflict: installing next to it would leave two
     * copies of the plugin in plugins/.
     *
     * @param  IndexPlugin  $plugin
     * @param  InstalledPlugin|null  $previous
     * @param  array<int, string>  $existing  jar names currently in plugins/
     * @param  array<int, string>  $takeOver  manual copies that are replaced (deleted after the write)
     *
     * @throws GitHubSourceException
     */
    public function chooseTargetFilename(array $plugin, ?array $previous, array $existing, array $takeOver = []): string
    {
        $previousName = $previous['filename'] ?? null;

        foreach ($existing as $name) {
            if ($name === $previousName || in_array($name, $takeOver, true)) {
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

    // ------------------------------------------------------------------
    // Manual copies (replaced with the repository version)
    // ------------------------------------------------------------------

    /**
     * Jar names this plugin manages on a server: installed ones and old jars pending deletion.
     *
     * @param  Metadata  $metadata
     * @return array<int, string>
     */
    public function managedFilenames(array $metadata): array
    {
        return [...array_column($metadata['installed'], 'filename'), ...array_column($metadata['pending_delete'], 'filename')];
    }

    /**
     * The manual copies that would be replaced, per plugin of the server's platform that isn't
     * installed from here and isn't hidden on this server: plugin id => jar names. Empty while
     * "Replace manual copies" is off. Only by name and what was found out before (a copy refused
     * earlier is left out) - the plugin name inside the jar is checked when it is replaced.
     * Reads Modrinth's metadata file only when there is a candidate at all.
     *
     * @param  array<int, IndexPlugin>  $platformPlugins
     * @param  Metadata  $metadata
     * @param  array<int, array<string, mixed>>  $files  listing of plugins/
     * @param  array<int, string>  $dismissedIds
     * @return array<string, array<int, string>>
     */
    public function manualReplacements(Server $server, array $platformPlugins, array $metadata, array $files, array $dismissedIds): array
    {
        if (!$this->replacesManualCopies()) {
            return [];
        }

        $managed = $this->managedFilenames($metadata);
        $jarNames = $this->jarNamesOf($files);

        // Installed (under its id or an earlier one) or hidden (likewise): not a candidate.
        $candidates = array_filter($platformPlugins, fn (array $plugin) => GitHubSourceRules::installedEntryFor($metadata['installed'], $plugin) === null
            && !GitHubSourceRules::isListed($plugin, $dismissedIds)
            && GitHubSourceRules::unmanagedCopies($plugin, $jarNames, $managed) !== []);

        if ($candidates === []) {
            return [];
        }

        $protected = $this->protectedJarNames($server, $files);

        if ($protected === null) {
            return [];
        }

        $replacements = [];

        foreach ($candidates as $plugin) {
            $copies = GitHubSourceRules::replaceableCopies($plugin, $platformPlugins, $jarNames, $managed, $protected);

            if (empty($copies)) {
                continue;
            }

            foreach ($copies as $name) {
                $file = DaemonFiles::find($files, $name);

                if ($file === null || $this->takeoverRefused($server, $plugin, $file) !== null) {
                    continue 2;
                }
            }

            $replacements[$plugin['id']] = $copies;
        }

        return $replacements;
    }

    /**
     * Jars in plugins/ that are never replaced: anything that isn't a regular file (symlinks,
     * folders named *.jar) and every jar the Modrinth page manages. Null when Modrinth's
     * metadata file can't be read (then nobody can say which jars are Modrinth's).
     *
     * @param  array<int, array<string, mixed>>  $files
     * @return array<int, string>|null
     */
    protected function protectedJarNames(Server $server, array $files): ?array
    {
        $protected = [];

        foreach ($files as $file) {
            if (is_string($file['name'] ?? null) && str_ends_with(strtolower($file['name']), '.jar') && !DaemonFiles::isRegularFile($file)) {
                $protected[] = $file['name'];
            }
        }

        try {
            $entries = app(MinecraftModrinthService::class)->readModsMetadata($server, ModrinthProjectType::Plugin)['entries'];
        } catch (ModrinthMetadataException $exception) {
            $this->reportOncePerWindow("server:{$server->id}:manual:modrinth", new GitHubSourceException(GitHubSourceException::MANUAL_COPY, "Manual copies are not replaced on server #{$server->id} while the Modrinth metadata can't be read: {$exception->getMessage()}"));

            return null;
        }

        return [...$protected, ...array_column($entries, 'filename')];
    }

    /**
     * Check the manual copies of $plugin before they are replaced: by name (replaceableCopies())
     * and then by the plugin name declared inside both jars - only a copy that declares the same
     * plugin as the new jar is replaced. A copy that turns out to be another plugin is remembered
     * and no longer offered for replacement.
     *
     * @param  IndexPlugin  $plugin
     * @param  Snapshot  $snapshot
     * @param  array<int, InstalledPlugin>  $installedList
     * @param  array<int, string>  $pendingNames
     * @param  array<int, array<string, mixed>>  $files  listing of plugins/
     * @return array<int, array{filename: string, size: int}>
     *
     * @throws GitHubSourceException MANUAL_COPY when a copy is there that can't be replaced
     */
    protected function copiesToTakeOver(Server $server, array $plugin, array $snapshot, array $installedList, array $pendingNames, array $files, string $content): array
    {
        $platformPlugins = $this->pluginsForPlatform($snapshot['plugins'], $plugin['platform']);
        $managed = [...array_column($installedList, 'filename'), ...$pendingNames];
        $jarNames = $this->jarNamesOf($files);
        $unmanaged = GitHubSourceRules::unmanagedCopies($plugin, $jarNames, $managed);

        if ($unmanaged === []) {
            return [];
        }

        $listed = 'plugins/'.implode(', plugins/', $unmanaged);
        $protected = $this->protectedJarNames($server, $files);

        if ($protected === null) {
            throw new GitHubSourceException(GitHubSourceException::MANUAL_COPY, "$listed on server #{$server->id} not replaced: the Modrinth metadata can't be read");
        }

        $copies = GitHubSourceRules::replaceableCopies($plugin, $platformPlugins, $jarNames, $managed, $protected);

        if ($copies === null) {
            throw new GitHubSourceException(GitHubSourceException::MANUAL_COPY, "$listed on server #{$server->id} not replaced: managed by the Modrinth page, not a regular file, newer than the repository version ({$plugin['version']}), matching several plugins of the index or more than ".GitHubSourceRules::MAX_TAKEOVER_COPIES.' copies - remove it by hand');
        }

        $result = [];
        $expected = null;

        foreach ($copies as $name) {
            $file = DaemonFiles::find($files, $name) ?? [];

            if (($refused = $this->takeoverRefused($server, $plugin, $file)) !== null) {
                throw new GitHubSourceException(GitHubSourceException::MANUAL_COPY, "plugins/$name on server #{$server->id} not replaced: $refused");
            }

            if ($expected === null) {
                $problem = null;
                $expected = $this->descriptorNamesOfContent($content, $plugin['platform'], $problem);

                if ($expected === null) {
                    throw new GitHubSourceException(GitHubSourceException::MANUAL_COPY, "plugins/$name on server #{$server->id} not replaced: the jar of {$plugin['id']} from the repository can't be checked ($problem)");
                }
            }

            $inspected = $this->inspectManualCopy($server, $file, $plugin['platform']);

            if ($inspected === false) {
                throw new GitHubSourceException(GitHubSourceException::DAEMON, "plugins/$name on server #{$server->id} not replaced: it could not be read from the node to check which plugin it is");
            }

            if ($inspected['names'] === null || !GitHubSourceRules::sameDescriptor($inspected['names'], $expected)) {
                $reason = $inspected['names'] === null
                    ? "it can't be checked ({$inspected['problem']})"
                    : 'it declares '.implode('/', $inspected['names']).', the repository jar '.implode('/', $expected);
                $this->rememberRefusal($server, $plugin, $file, $reason);

                throw new GitHubSourceException(GitHubSourceException::MANUAL_COPY, "plugins/$name on server #{$server->id} is not replaced with {$plugin['id']}: $reason");
            }

            $result[] = ['filename' => $name, 'size' => (int) ($file['size'] ?? 0)];
        }

        return $result;
    }

    /**
     * What a manual copy declares itself as (see GitHubSourceRules::jarDescriptorNames()), read
     * from the node - the whole jar has to be downloaded for that, so only when it is about to
     * be replaced, and remembered by name, size and modification time. False when it can't be
     * read right now.
     *
     * @param  array<string, mixed>  $file
     * @return array{names: array<int, string>|null, problem: string|null}|false
     */
    protected function inspectManualCopy(Server $server, array $file, string $platform): array|false
    {
        $name = (string) ($file['name'] ?? '');
        $key = 'minecraft-modrinth:github:copy:'.md5(implode("\0", [$server->uuid, $platform, $name, (string) ($file['size'] ?? ''), (string) ($file['modified'] ?? '')]));

        $cached = Cache::get($key);
        if (is_array($cached) && array_key_exists('names', $cached) && array_key_exists('problem', $cached)) {
            return $cached;
        }

        $tooLarge = ['names' => null, 'problem' => 'larger than '.GitHubSourceRules::MAX_JAR_BYTES.' bytes'];

        if ((int) ($file['size'] ?? 0) > GitHubSourceRules::MAX_JAR_BYTES) {
            $result = $tooLarge;
        } else {
            try {
                $content = DaemonFiles::readSmallFile($server, self::FOLDER.'/'.$name, GitHubSourceRules::MAX_JAR_BYTES);
            } catch (DaemonFileException $exception) {
                if ($exception->reason !== DaemonFileException::TOO_LARGE) {
                    $this->reportOncePerWindow("server:{$server->id}:manual:read:$name", GitHubSourceException::fromDaemon($exception));

                    return false;
                }

                $content = false;
            }

            if ($content === null) {
                // Gone in the meantime.
                return false;
            }

            if ($content === false) {
                $result = $tooLarge;
            } else {
                $problem = null;
                $result = ['names' => $this->descriptorNamesOfContent($content, $platform, $problem), 'problem' => $problem];
                unset($content);
            }
        }

        Cache::put($key, $result, now()->addHours(self::MANUAL_COPY_CACHE_HOURS));

        return $result;
    }

    /**
     * @return array{names: array<int, string>, labels: array<int, string>, versions: array<int, string>}|null
     */
    protected function descriptorOfContent(string $content, string $platform, ?string &$problem = null): ?array
    {
        $temporary = tempnam(sys_get_temp_dir(), 'minecraft-modrinth-github-');

        try {
            if ($temporary === false || file_put_contents($temporary, $content) !== strlen($content)) {
                $problem = 'could not write a temporary file';

                return null;
            }

            return GitHubSourceRules::jarDescriptor($temporary, $platform, $problem);
        } finally {
            if (is_string($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * @return array<int, string>|null
     */
    protected function descriptorNamesOfContent(string $content, string $platform, ?string &$problem = null): ?array
    {
        $temporary = tempnam(sys_get_temp_dir(), 'minecraft-modrinth-github-');

        try {
            if ($temporary === false || file_put_contents($temporary, $content) !== strlen($content)) {
                $problem = 'could not write a temporary file';

                return null;
            }

            return GitHubSourceRules::jarDescriptorNames($temporary, $platform, $problem);
        } finally {
            if (is_string($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * @param  IndexPlugin  $plugin
     * @param  array<string, mixed>  $file
     */
    protected function refusalKey(Server $server, array $plugin, array $file): string
    {
        return 'minecraft-modrinth:github:copy-refused:'.md5(implode("\0", [$server->uuid, $plugin['id'], $plugin['sha256'], (string) ($file['name'] ?? ''), (string) ($file['size'] ?? ''), (string) ($file['modified'] ?? '')]));
    }

    /**
     * Why this manual copy was not replaced with this plugin before, or null.
     *
     * @param  IndexPlugin  $plugin
     * @param  array<string, mixed>  $file
     */
    public function takeoverRefused(Server $server, array $plugin, array $file): ?string
    {
        $reason = Cache::get($this->refusalKey($server, $plugin, $file));

        return is_string($reason) ? $reason : null;
    }

    /**
     * @param  IndexPlugin  $plugin
     * @param  array<string, mixed>  $file
     */
    protected function rememberRefusal(Server $server, array $plugin, array $file, string $reason): void
    {
        Cache::put($this->refusalKey($server, $plugin, $file), $reason, now()->addHours(self::MANUAL_COPY_CACHE_HOURS));
    }

    /**
     * A jar from the repository was written and recorded: one line in the panel log and an entry in the server's
     * activity log, both with the commit (and its author, when known) - so a bad deploy can be traced to the commit
     * that brought it. Never throws.
     *
     * @param  InstalledPlugin  $entry
     * @param  InstalledPlugin|null  $previous
     * @param  Snapshot  $snapshot
     */
    protected function logInstalled(Server $server, array $entry, ?array $previous, array $snapshot): void
    {
        $author = is_string($snapshot['author'] ?? null) ? $snapshot['author'] : null;
        $change = $previous !== null ? "{$previous['version']} -> {$entry['version']}" : "{$entry['version']} (new)";

        Log::info("minecraft-modrinth: GitHub: server #{$server->id}: {$entry['name']} $change as plugins/{$entry['filename']} from {$snapshot['repository']}@{$snapshot['sha']}"
            .($author !== null ? " (author $author)" : '').", sha256 {$entry['sha256']}");

        try {
            Activity::event('server:file.write')
                ->subject($server)
                ->property('file', self::FOLDER.'/'.$entry['filename'])
                ->property('github', "{$entry['name']} $change, {$snapshot['repository']}@".substr($snapshot['sha'], 0, 12).($author !== null ? ", $author" : ''))
                ->log();
        } catch (Throwable $exception) {
            $this->report($exception);
        }
    }

    /**
     * A replaced manual copy was deleted: one line in the panel log and an entry in the server's
     * activity log ("Deleted /plugins/<file>", by the user who clicked or by the system for the
     * automatic update). Never throws.
     */
    protected function logReplacedCopy(Server $server, string $filename, string $replacedBy): void
    {
        Log::info("minecraft-modrinth: GitHub: deleted the manual copy plugins/$filename on server #{$server->id}, replaced with $replacedBy from the repository");

        try {
            Activity::event('server:file.delete')
                ->subject($server)
                ->property('directory', self::FOLDER)
                ->property('files', [$filename])
                ->property('replaced_by', "$replacedBy (GitHub)")
                ->log();
        } catch (Throwable $exception) {
            $this->report($exception);
        }
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
     * version from a commit with green CI, and - with "Replace manual copies" on - replace the
     * manual copies of plugins that aren't installed from here (not of hidden ones). Never
     * installs anything new.
     *
     * @param  Snapshot  $snapshot
     * @return array<int, string> ids that were updated (replacements as "id (replaced: file, ...)")
     */
    public function autoUpdateServer(Server $server, array $snapshot): array
    {
        $platform = $this->platformForServer($server);
        if ($platform === null || $snapshot['ci'] !== CiStatus::Green || $this->rateLimited) {
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

        // Plugins still recorded under an earlier id get their current one (metadata and stored offers).
        $this->migrateIds($server, $snapshot, $metadata);

        $updated = [];

        // An installed plugin is found under its id or an earlier one (previous_ids).
        foreach ($this->pluginsForPlatform($snapshot['plugins'], $platform) as $plugin) {
            $entry = GitHubSourceRules::installedEntryFor($metadata['installed'], $plugin);

            if ($entry === null || !GitHubSourceRules::isNewerVersion($plugin['version'], $entry['version'])) {
                continue;
            }

            try {
                $this->installOrUpdate($server, $plugin, $snapshot, true, true);
                $updated[] = $plugin['id'];
            } catch (GitHubSourceException $exception) {
                if ($exception->reason === GitHubSourceException::RATE_LIMITED) {
                    // Stop asking GitHub for the rest of this run (every server keeps its jars): one log line.
                    $this->rateLimited = true;
                    $this->reportOncePerWindow('auto:rate-limited', new GitHubSourceException(GitHubSourceException::RATE_LIMITED, 'Automatic update stopped: GitHub API rate limit (or its reserve) reached - the remaining servers keep their current jars until the next run'));

                    break;
                }

                if ($exception->reason === GitHubSourceException::OLD_JAR_PENDING) {
                    // Updated; only the old jar is left for later.
                    $updated[] = $plugin['id'];
                }

                $this->reportOncePerWindow("server:{$server->id}:update:{$plugin['id']}:{$exception->reason}", $exception);
            }
        }

        if (!$this->rateLimited) {
            array_push($updated, ...$this->autoReplaceManualCopies($server, $snapshot, $platform));
        }

        if (!empty($updated)) {
            // The cached offer count still includes these updates: drop it, the next offer check
            // (or a visit of the page) counts again.
            app(GitHubOffers::class)->forgetCounts($server);
        }

        return $updated;
    }

    /**
     * The automatic update's part for manual copies (see autoUpdateServer()).
     *
     * @param  Snapshot  $snapshot
     * @return array<int, string>
     */
    protected function autoReplaceManualCopies(Server $server, array $snapshot, string $platform): array
    {
        if (!$this->replacesManualCopies()) {
            return [];
        }

        try {
            // Hidden plugins are never replaced: without the stored decisions nothing is.
            $dismissed = app(GitHubOffers::class)->dismissedIds($server);
            $metadata = $this->readMetadata($server);
            $files = $this->listPluginFiles($server);
        } catch (GitHubSourceException $exception) {
            if ($exception->reason !== GitHubSourceException::UNKNOWN_SERVER) {
                $this->reportOncePerWindow("server:{$server->id}:manual:{$exception->reason}", $exception);
            }

            return [];
        }

        $platformPlugins = $this->pluginsForPlatform($snapshot['plugins'], $platform);
        $done = [];

        foreach ($this->manualReplacements($server, $platformPlugins, $metadata, $files, $dismissed) as $id => $copies) {
            $plugin = null;
            foreach ($platformPlugins as $candidate) {
                if ($candidate['id'] === $id) {
                    $plugin = $candidate;
                }
            }

            if ($plugin === null) {
                continue;
            }

            $replaced = [];

            try {
                $this->installOrUpdate($server, $plugin, $snapshot, true, false, true, $replaced);
                $done[] = "$id (replaced: ".implode(', ', $replaced).')';
            } catch (GitHubSourceException $exception) {
                if ($exception->reason === GitHubSourceException::OLD_JAR_PENDING) {
                    // Installed; a copy is left for later.
                    $done[] = "$id (replaced: ".implode(', ', $copies).')';
                }

                $this->reportOncePerWindow("server:{$server->id}:replace:{$id}:{$exception->reason}", $exception);
            }
        }

        return $done;
    }

    // ------------------------------------------------------------------
    // Changed ids (previous_ids)
    // ------------------------------------------------------------------

    /**
     * Give everything this server still records under an earlier id of a plugin ("previous_ids"
     * in the index) the plugin's current id: the entries of plugins/.github-plugins.json (only
     * the id changes) and the hidden offers / announcements in the panel database. Until then
     * the earlier id is simply recognised everywhere, so this only makes it permanent - after it
     * the earlier id can leave the index without anything turning into a new plugin.
     *
     * Only from a commit with green CI (the one servers update from) and only when an entry of
     * the server's platform has previous_ids at all. Never throws; failures are reported and
     * it is tried again at the next check.
     *
     * @param  Snapshot  $snapshot
     * @param  Metadata|null  $metadata  already read: nothing is read again when it has no earlier id
     * @return array<string, string> old id => new id (metadata file and stored offers)
     */
    public function migrateIds(Server $server, array $snapshot, ?array $metadata = null): array
    {
        $platform = $this->platformForServer($server);

        if ($platform === null || $snapshot['ci'] !== CiStatus::Green) {
            return [];
        }

        $platformPlugins = $this->pluginsForPlatform($snapshot['plugins'], $platform);

        if (!GitHubSourceRules::hasPreviousIds($platformPlugins)) {
            return [];
        }

        $renamed = [];

        try {
            $renamed = app(GitHubOffers::class)->migrateIds($server, $platformPlugins);
        } catch (GitHubSourceException $exception) {
            $this->reportOncePerWindow("server:{$server->id}:migrate-offers:{$exception->reason}", $exception);
        }

        if ($metadata !== null && GitHubSourceRules::migrateInstalledIds($metadata['installed'], $platformPlugins)['renamed'] === []) {
            return $renamed;
        }

        $lock = Cache::lock("minecraft-modrinth:github:install:{$server->id}", 60);

        if (!$lock->get()) {
            // An installation is running: it converts the ids itself, or the next check does.
            return $renamed;
        }

        try {
            $metadata = $this->readMetadata($server);
            $migration = GitHubSourceRules::migrateInstalledIds($metadata['installed'], $platformPlugins);

            if ($migration['renamed'] !== []) {
                $this->writeMetadata($server, $migration['installed'], $metadata['pending_delete']);
                $renamed += $migration['renamed'];

                Log::info("minecraft-modrinth: GitHub: server #{$server->id}: plugins/".self::METADATA_FILE.' now records '.implode(', ', array_map(fn (string $old, string $new) => "$old as $new", array_keys($migration['renamed']), $migration['renamed'])));
            }
        } catch (GitHubSourceException $exception) {
            if ($exception->reason !== GitHubSourceException::UNKNOWN_SERVER) {
                $this->reportOncePerWindow("server:{$server->id}:migrate:{$exception->reason}", $exception);
            }
        } finally {
            $lock->release();
        }

        return $renamed;
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
        // A missing setting is not a fault of the panel: one warning line, no stack trace (since 1.9.1).
        if ($exception instanceof GitHubSourceException && $exception->reason === GitHubSourceException::NOT_CONFIGURED) {
            Log::warning('[minecraft-modrinth] '.$exception->getMessage());

            return;
        }

        // Only our own exceptions are reported, and those never carry the token (see
        // GitHubSourceException). Anything else is reduced to its class name.
        report($exception instanceof GitHubSourceException
            ? $exception
            : new GitHubSourceException(GitHubSourceException::DAEMON, 'Unexpected '.get_class($exception)));
    }
}
