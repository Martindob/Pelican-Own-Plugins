<?php

namespace Martindob\PaperVelocityUpdater\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PaperVelocityUpdateService
{
    private const STATE_FILE = '.paper-velocity-updater.json';

    private const PENDING_SUFFIX = '.pending';

    private const FILL_BASE_URL = 'https://fill.papermc.io/v3';

    /** How many versions resolveLatestStable() will check before giving up. */
    private const MAX_LATEST_VERSION_CANDIDATES = 10;

    /** Egg variable name(s) that identify a project and hold its target version. */
    private const PROJECT_VERSION_VARIABLES = [
        'paper' => ['MINECRAFT_VERSION', 'MC_VERSION'],
        'velocity' => ['VELOCITY_VERSION'],
    ];

    /**
     * Called on a schedule (see PaperVelocityUpdaterPluginProvider), never from
     * a power action: for every Paper/Velocity server, downloads a newer build
     * - if one is available and not already installed or staged - into a
     * `<jarfile>.pending` file, without ever touching the live jar. This is
     * deliberately the only place that talks to PaperMC or does the
     * potentially slow (~50-60MB) download, so a start/restart never has to
     * wait on either - see applyPendingUpdate(), which just renames this
     * staged file into place, right before the power signal reaches Wings.
     */
    public function checkForUpdates(): void
    {
        if (!config('paper-velocity-updater.enabled', true)) {
            return;
        }

        Server::query()->chunk(50, function ($servers) {
            foreach ($servers as $server) {
                $this->stage($server);
            }
        });
    }

    /**
     * Applies whatever update checkForUpdates() already staged for this
     * server, if any. Meant to be called synchronously from the start/restart
     * power action hook (UpdateCheckingDaemonServerRepository), right before
     * the signal reaches Wings - which is exactly why this only ever does a
     * couple of fast, local-ish rename calls on the daemon, never a network
     * round-trip to PaperMC or a multi-megabyte download: that already
     * happened ahead of time in checkForUpdates(), on its own schedule, with
     * its own timeout budget.
     *
     * Safe to call even while the server is still running - which is the
     * normal case for a scheduled restart, since this hook fires *before*
     * Wings stops anything. That's different from overwriting the live jar
     * in place, which the old, download-at-restart-time design of this
     * plugin had to avoid for Velocity: Wings' file-pull writes a new file's
     * bytes into an *existing* filename by truncating that same inode, and
     * truncating a jar a running JVM already has open corrupts whatever that
     * JVM still reads from it (Velocity's classloader keeps its jar's
     * ZipFile/JarFile handle open for the life of the process and can lazily
     * load a class from it at any time). A *rename*, which is all this
     * method ever does to the live jar, has no such effect: it only changes
     * which path points at which inode. A process that already has the old
     * jar open keeps reading from that same, untouched inode regardless of
     * what the live filename now points to - it never re-opens the jar by
     * path while running. So there's no analogue of the truncate-while-open
     * risk here, and Velocity no longer needs its update gated on the server
     * being confirmed stopped.
     *
     * Never throws: a failed swap (the daemon being briefly unreachable, the
     * staged file having been removed manually, ...) must never block the
     * actual start/restart the admin or a schedule asked for. Worst case,
     * the server just starts on its current jar and this is retried on the
     * next restart, since the pending marker is only cleared once the swap
     * actually succeeds.
     */
    public function applyPendingUpdate(Server $server): void
    {
        if (!config('paper-velocity-updater.enabled', true)) {
            return;
        }

        $server->loadMissing('variables');
        if ($this->detectProject($server) === null) {
            return;
        }

        try {
            // Short TTL/wait on purpose: this only ever does a couple of
            // rename calls, nothing like the multi-minute download the
            // staging lock below has to cover. If another start/restart on
            // the same server is already mid-swap, waiting 5 seconds for it
            // to finish is more than enough; if it isn't, this swap is
            // simply skipped for now rather than risking a noticeable delay
            // on the power action - the next restart retries it.
            Cache::lock("paper-velocity-updater:swap:{$server->id}", 30)
                ->block(5, function () use ($server) {
                    $this->swap($server);
                });
        } catch (Exception $exception) {
            $this->reportOncePerWindow("server:{$server->id}:swap", $exception);
        }
    }

    /**
     * Resolves the target build for one server and, if it isn't already
     * installed or already staged, downloads it into a `<jarfile>.pending`
     * file. Every failure here is safe to swallow: nothing on the live jar
     * is ever touched by this method, so the worst outcome is that this is
     * simply retried on the next scheduled check.
     */
    private function stage(Server $server): void
    {
        try {
            $server->loadMissing('variables');

            if ($this->detectProject($server) === null) {
                return;
            }

            // Guards against two overlapping scheduled runs (e.g. a manual
            // `artisan p:paper-velocity-updater:check` overlapping the
            // scheduled one) double-downloading for the same server.
            Cache::lock("paper-velocity-updater:stage:{$server->id}", $this->downloadTimeoutSeconds() + 60)
                ->block(5, function () use ($server) {
                    $target = $this->resolveTarget($server);
                    if ($target === null) {
                        return;
                    }

                    $fileRepository = app(DaemonFileRepository::class)->setServer($server);
                    $state = $this->readState($fileRepository, $server->id);

                    if ($this->matchesTarget($state['installed'] ?? null, $target)
                        || $this->matchesTarget($state['pending'] ?? null, $target)) {
                        return;
                    }

                    $stagedAs = $target['jar'] . self::PENDING_SUFFIX;

                    $fileRepository->getHttpClient()
                        ->timeout(max((int) config('panel.guzzle.timeout'), $this->downloadTimeoutSeconds()))
                        ->post("/api/servers/{$server->uuid}/files/pull", [
                            'url' => $target['url'],
                            'root' => '/',
                            'file_name' => $stagedAs,
                            'foreground' => true,
                        ])
                        ->throw();

                    $state['pending'] = [
                        'project' => $target['project'],
                        'version' => $target['version'],
                        'build' => $target['build'],
                        'jar' => $target['jar'],
                        'staged_jar' => $stagedAs,
                        'sha256' => $target['sha256'],
                        'staged_at' => now()->toIso8601String(),
                    ];

                    $this->writeState($fileRepository, $state);
                });
        } catch (Exception $exception) {
            // A server that's failing this check every hour (e.g. PaperMC
            // being unreachable, or a pinned version that can't be verified)
            // shouldn't get one log entry per check - one per throttle
            // window is enough to notice a real, persistent problem.
            $this->reportOncePerWindow("server:{$server->id}:" . $exception::class, $exception);
        }
    }

    /**
     * Actually applies a staged update: renames the current jar to
     * `<jarfile>.old` (best-effort backup) and the staged
     * `<jarfile>.pending` into the live jar's name, then records it as
     * installed and clears the pending marker.
     *
     * A no-op if nothing is staged, or if what's staged no longer matches
     * this server's current configuration (e.g. an admin changed
     * SERVER_JARFILE or the project's version variable after the last
     * check, or switched DL_PATH on) - swapping it in anyway would replace
     * the current jar with a build resolved for a configuration that no
     * longer applies. The next scheduled check re-stages the right thing
     * (or nothing, if the server opted out via DL_PATH) once it notices.
     */
    private function swap(Server $server): void
    {
        $fileRepository = app(DaemonFileRepository::class)->setServer($server);
        $state = $this->readState($fileRepository, $server->id);

        $pending = $state['pending'] ?? null;
        if (!is_array($pending) || !isset($pending['staged_jar'], $pending['jar'], $pending['project'])) {
            return;
        }

        $project = $this->detectProject($server);
        $currentJarFile = $this->getVariable($server, 'SERVER_JARFILE') ?? ($project === 'velocity' ? 'velocity.jar' : 'server.jar');
        if ($project !== $pending['project'] || $currentJarFile !== $pending['jar'] || $this->getVariable($server, 'DL_PATH') !== null) {
            return;
        }

        if (!$this->fileExists($fileRepository, $pending['staged_jar'])) {
            // Staged file is gone (manually deleted, or never actually
            // finished downloading) - drop the stale marker instead of
            // retrying this forever, and let the next scheduled check
            // re-stage it properly.
            unset($state['pending']);
            $this->writeState($fileRepository, $state);

            return;
        }

        $this->backupExistingJar($server, $fileRepository, $pending['jar']);

        $fileRepository->renameFiles('/', [
            ['from' => $pending['staged_jar'], 'to' => $pending['jar']],
        ]);

        $state['installed'] = [
            'project' => $pending['project'],
            'version' => $pending['version'] ?? null,
            'build' => $pending['build'] ?? null,
            'jar' => $pending['jar'],
            'sha256' => $pending['sha256'] ?? null,
            'updated_at' => now()->toIso8601String(),
        ];
        unset($state['pending']);

        $this->writeState($fileRepository, $state);
    }

    /**
     * Resolves what SHOULD be installed for a server, if anything - the
     * target project/jar/version/build/download URL - without checking that
     * against what's currently installed or staged (see stage(), which does
     * that comparison). Every failure here returns null: PaperMC or the
     * daemon being briefly unreachable just means this is retried on the
     * next scheduled check, same as no update being available.
     *
     * @return array{project: string, jar: string, version: string, build: int, url: string, sha256: ?string}|null
     */
    private function resolveTarget(Server $server): ?array
    {
        $project = $this->detectProject($server);
        if ($project === null) {
            return null;
        }

        // Respect an explicitly pinned build number; only "latest" (the default
        // shown in the startup variables) triggers an automatic update.
        $buildVariable = $this->getVariable($server, 'BUILD_NUMBER');
        if ($buildVariable !== null && !$this->isLatest($buildVariable)) {
            return null;
        }

        // DL_PATH (present on both official eggs) tells the egg's own install
        // script to download from a custom URL instead of resolving anything
        // through PaperMC - used for mirrors, patched/forked builds, or a
        // private build server. If an admin has set it, this server has
        // opted out of PaperMC-based resolution entirely, and this plugin has
        // no way to know what that custom URL should resolve to - staging a
        // stock PaperMC jar would silently undo that choice on the next
        // restart.
        if ($this->getVariable($server, 'DL_PATH') !== null) {
            return null;
        }

        $jarFile = $this->getVariable($server, 'SERVER_JARFILE') ?? ($project === 'velocity' ? 'velocity.jar' : 'server.jar');

        // SERVER_JARFILE is an admin-editable startup variable, not a
        // trusted constant. Wings itself rejects a rename/pull target
        // that escapes the server's directory, but that shouldn't be the
        // only thing standing between an unexpected value here and a
        // file operation - reject anything that isn't a plain filename
        // ending in .jar before it's used for anything.
        if ($jarFile !== basename($jarFile) || !str_ends_with(strtolower($jarFile), '.jar')) {
            return null;
        }

        $requestedVersion = null;
        foreach (self::PROJECT_VERSION_VARIABLES[$project] as $variableName) {
            $requestedVersion = $this->getVariable($server, $variableName);
            if ($requestedVersion !== null) {
                break;
            }
        }

        // A pinned version is a hard lock: if it can't be positively
        // verified against PaperMC's own version list - genuinely invalid,
        // or the API/cache being temporarily unavailable - this skips the
        // update rather than silently falling back to "latest". Falling
        // back on an inconclusive check would mean a single transient
        // PaperMC hiccup could bump a pinned server onto a version its
        // admin never asked for, which defeats the entire point of
        // pinning one.
        if ($this->isLatest($requestedVersion)) {
            $resolved = $this->resolveLatestStable($project);
            if ($resolved === null) {
                return null;
            }

            $version = $resolved['version'];
            $build = $resolved['build'];
        } else {
            $version = trim((string) $requestedVersion);

            if (!$this->versionExists($project, $version)) {
                return null;
            }

            $build = $this->resolveBuildForPinnedVersion($project, $version);
            if ($build === null) {
                return null;
            }
        }

        $download = $build['downloads']['server:default'] ?? null;
        if (!is_array($download) || !isset($download['url'])) {
            return null;
        }

        // The download URL comes straight from the Fill API response and
        // is otherwise trusted as-is - if that response were ever spoofed
        // (a compromised DNS/CDN/MITM) it could point Wings at an
        // attacker-controlled file. Fill only ever serves its own
        // downloads, so pinning to its own host closes that path off
        // without narrowing anything Fill would legitimately return.
        $downloadHost = strtolower((string) parse_url((string) $download['url'], PHP_URL_HOST));
        $downloadScheme = strtolower((string) parse_url((string) $download['url'], PHP_URL_SCHEME));
        if ($downloadScheme !== 'https' || !in_array($downloadHost, ['fill.papermc.io', 'api.papermc.io'], true)) {
            return null;
        }

        return [
            'project' => $project,
            'jar' => $jarFile,
            'version' => $version,
            'build' => $build['id'],
            'url' => $download['url'],
            'sha256' => $download['checksums']['sha256'] ?? null,
        ];
    }

    /**
     * @param  array{project?: string, version?: string, build?: int, jar?: string}|null  $recorded
     * @param  array{project: string, jar: string, version: string, build: int}  $target
     */
    private function matchesTarget(?array $recorded, array $target): bool
    {
        return $recorded !== null
            && ($recorded['project'] ?? null) === $target['project']
            && ($recorded['version'] ?? null) === $target['version']
            && ($recorded['build'] ?? null) === $target['build']
            && ($recorded['jar'] ?? null) === $target['jar'];
    }

    /**
     * Only ever keeps a single rolling "<jarfile>.old" backup - never a new
     * file per update - so this can't grow disk usage over time. Any previous
     * backup is deleted first (silently, since not having one yet is the
     * normal case for the first update) before renaming the current jar into
     * its place.
     */
    private function backupExistingJar(Server $server, DaemonFileRepository $fileRepository, string $jarFile): void
    {
        try {
            try {
                $fileRepository->deleteFiles('/', ["$jarFile.old"]);
            } catch (Exception) {
                // Nothing to delete yet - expected on the first ever update.
            }

            $fileRepository->renameFiles('/', [
                ['from' => $jarFile, 'to' => "$jarFile.old"],
            ]);
        } catch (Exception $exception) {
            $this->reportOncePerWindow("server:{$server->id}:backup-jar", $exception);
        }
    }

    private function downloadTimeoutSeconds(): int
    {
        // Clamped here too, not just in the settings form's minValue(60): a
        // value edited directly in .env could otherwise bypass that and bring
        // back the daemon client's own too-short 15 second default.
        return max(60, (int) config('paper-velocity-updater.download_timeout_seconds', 300));
    }

    /**
     * Detects whether this server is a Paper or Velocity server from its
     * startup variables. Requires BUILD_NUMBER to be defined alongside the
     * version variable: MINECRAFT_VERSION/MC_VERSION alone is not unique to
     * Paper - the sibling minecraft-modrinth plugin (and Fabric/Forge/Quilt/
     * NeoForge eggs generally) reads the exact same variable for modded
     * loaders, which have no concept of a PaperMC "build number". Without this
     * check, a modded server whose egg happens to define MINECRAFT_VERSION but
     * no BUILD_NUMBER would be misidentified as Paper and have its actual jar
     * overwritten with a vanilla Paper one.
     */
    private function detectProject(Server $server): ?string
    {
        if (!$this->hasVariable($server, 'BUILD_NUMBER')) {
            return null;
        }

        foreach (self::PROJECT_VERSION_VARIABLES as $project => $variableNames) {
            foreach ($variableNames as $variableName) {
                if ($this->hasVariable($server, $variableName)) {
                    return $project;
                }
            }
        }

        return null;
    }

    /** Whether the egg defines this variable at all, regardless of its value. */
    private function hasVariable(Server $server, string $name): bool
    {
        return $server->variables->firstWhere('env_variable', $name) !== null;
    }

    private function getVariable(Server $server, string $name): ?string
    {
        /** @var \App\Models\EggVariable|null $variable */
        $variable = $server->variables->firstWhere('env_variable', $name);
        if (!$variable) {
            return null;
        }

        $value = $variable->server_value ?? $variable->default_value;

        return $value !== null && $value !== '' ? $value : null;
    }

    private function isLatest(?string $value): bool
    {
        return $value === null || strtolower(trim($value)) === 'latest';
    }

    /** @return array<string, array<int, string>> */
    private function fetchVersionGroups(string $project): array
    {
        return cache()->remember(
            "paper-velocity-updater:versions:$project",
            now()->addMinutes($this->cacheMinutes()),
            function () use ($project) {
                try {
                    $versions = $this->http()->get("/projects/$project")->json('versions');

                    return is_array($versions) ? $versions : [];
                } catch (Exception $exception) {
                    $this->reportOncePerWindow("versions:$project", $exception);

                    return [];
                }
            }
        );
    }

    /**
     * Resolves "latest" to the newest version that actually has a
     * STABLE-channel build, returning both together.
     *
     * Version *names* are not a reliable stability signal, verified live
     * against the Fill API: Paper's own "26.3" is a perfectly clean version
     * string with no snapshot/rc/pre suffix, yet every one of its builds is
     * currently channel ALPHA, while every "26.2" build is STABLE - matching
     * papermc.io/downloads/paper's own "Latest Stable Version: Paper 26.2".
     * Conversely Velocity's "4.2.1-SNAPSHOT" - which *does* look like a
     * pre-release by name - has a STABLE build and is exactly what
     * papermc.io/downloads/velocity itself presents as the current download.
     * So this ignores version names entirely and walks versions newest first,
     * accepting the first one whose builds actually include a STABLE one.
     *
     * @return array{version: string, build: array{id: int, channel: string, downloads: array<string, array{name: string, url: string}>}}|null
     */
    private function resolveLatestStable(string $project): ?array
    {
        $checked = 0;

        foreach ($this->fetchVersionGroups($project) as $group) {
            if (!is_array($group)) {
                continue;
            }

            foreach ($group as $version) {
                if (!is_string($version)) {
                    continue;
                }

                // Bounded so a long run of alpha/experimental versions (e.g. a
                // whole new major line still early in development) can't turn
                // a single "latest" resolution into an unbounded chain of API
                // calls.
                if (++$checked > self::MAX_LATEST_VERSION_CANDIDATES) {
                    return null;
                }

                $build = $this->pickStableBuild($this->fetchBuilds($project, $version));
                if ($build !== null) {
                    return ['version' => $version, 'build' => $build];
                }
            }
        }

        return null;
    }

    private function versionExists(string $project, ?string $version): bool
    {
        if ($version === null) {
            return false;
        }

        foreach ($this->fetchVersionGroups($project) as $group) {
            if (is_array($group) && in_array($version, $group, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves the build to use for a version the admin explicitly pinned.
     * Unlike resolveLatestStable(), this falls back to the newest build of
     * any channel if the pinned version genuinely has no STABLE build yet -
     * the admin asked for this exact version, so it's better to give them the
     * best available build for it than nothing at all.
     *
     * @return array{id: int, channel: string, downloads: array<string, array{name: string, url: string}>}|null
     */
    private function resolveBuildForPinnedVersion(string $project, string $version): ?array
    {
        $builds = $this->fetchBuilds($project, $version);

        return $this->pickStableBuild($builds) ?? $this->pickAnyBuild($builds);
    }

    /** @param  array<int, mixed>  $builds */
    private function pickStableBuild(array $builds): ?array
    {
        foreach ($builds as $build) {
            if (is_array($build) && ($build['channel'] ?? null) === 'STABLE' && isset($build['id'])) {
                return $build;
            }
        }

        return null;
    }

    /** @param  array<int, mixed>  $builds */
    private function pickAnyBuild(array $builds): ?array
    {
        $candidate = $builds[0] ?? null;

        return is_array($candidate) && isset($candidate['id']) ? $candidate : null;
    }

    /**
     * Raw, cached build list for a single version. An empty array is used as
     * the "nothing/failed" sentinel (not null/false): cache()->remember()
     * can't distinguish a cached null from a cache miss, but an empty array
     * is unambiguous, so a failure still actually gets cached instead of
     * re-hitting PaperMC's API on every single check during an outage.
     *
     * @return array<int, mixed>
     */
    private function fetchBuilds(string $project, string $version): array
    {
        $builds = cache()->remember(
            "paper-velocity-updater:build:$project:$version",
            now()->addMinutes($this->cacheMinutes()),
            function () use ($project, $version) {
                try {
                    $builds = $this->http()->get("/projects/$project/versions/$version/builds")->json();
                } catch (Exception $exception) {
                    $this->reportOncePerWindow("build:$project:$version", $exception);

                    return [];
                }

                return is_array($builds) ? $builds : [];
            }
        );

        return is_array($builds) ? $builds : [];
    }

    private function cacheMinutes(): int
    {
        return (int) config('paper-velocity-updater.cache_minutes', 15);
    }

    /**
     * Reports an exception at most once per $key within the configured throttle
     * window, so a persistent problem (daemon unreachable, bad credentials, ...)
     * isn't logged again on every single scheduled check.
     */
    private function reportOncePerWindow(string $key, Exception $exception): void
    {
        $minutes = (int) config('paper-velocity-updater.report_throttle_minutes', 30);

        if ($minutes <= 0 || Cache::add("paper-velocity-updater:reported:$key", true, now()->addMinutes($minutes))) {
            report($exception);
        }
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(self::FILL_BASE_URL)
            ->withHeaders(['User-Agent' => config('paper-velocity-updater.user_agent')])
            ->timeout(10)
            ->connectTimeout(5)
            ->throw();
    }

    /**
     * @return array{installed?: array{project?: string, version?: string, build?: int, jar?: string, sha256?: ?string, updated_at?: string}, pending?: array{project?: string, version?: string, build?: int, jar?: string, staged_jar?: string, sha256?: ?string, staged_at?: string}}
     */
    private function readState(DaemonFileRepository $fileRepository, int $serverId): array
    {
        try {
            $content = $fileRepository->getContent(self::STATE_FILE);
        } catch (FileNotFoundException) {
            // No marker yet - first check for this server.
            return [];
        } catch (Exception $exception) {
            $this->reportOncePerWindow("server:$serverId:read-state", $exception);

            return [];
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return [];
        }

        // Back-compat with the flat, pre-1.2.0 state file format (no
        // installed/pending wrapper) - treat it as the "installed" side so
        // upgrading to this version doesn't cause a spurious re-download.
        if (!isset($decoded['installed']) && !isset($decoded['pending']) && isset($decoded['project'])) {
            return ['installed' => $decoded];
        }

        return $decoded;
    }

    /**
     * @param  array{installed?: array, pending?: array}  $state
     *
     * sha256 is recorded for manual comparison only (Wings itself has no way
     * to verify it during the pull) - if a jar ever looks suspicious, this
     * gives you the hash Fill reported for the build it says was installed
     * or staged, to check against.
     */
    private function writeState(DaemonFileRepository $fileRepository, array $state): void
    {
        $fileRepository->putContent(self::STATE_FILE, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    private function fileExists(DaemonFileRepository $fileRepository, string $fileName): bool
    {
        try {
            $files = $fileRepository->getDirectory('/');
        } catch (RequestException $exception) {
            if ($exception->response->status() === 404) {
                return false;
            }

            throw $exception;
        }

        foreach ($files as $file) {
            if (is_array($file) && ($file['name'] ?? null) === $fileName) {
                return true;
            }
        }

        return false;
    }
}
