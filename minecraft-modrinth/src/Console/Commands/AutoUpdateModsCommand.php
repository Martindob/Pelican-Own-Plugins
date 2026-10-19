<?php

namespace Boy132\MinecraftModrinth\Console\Commands;

use App\Models\Server;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
use Boy132\MinecraftModrinth\Facades\MinecraftModrinth;
use Boy132\MinecraftModrinth\GitHub\CiStatus;
use Boy132\MinecraftModrinth\GitHub\GitHubPluginService;
use Boy132\MinecraftModrinth\GitHub\GitHubSourceException;
use Boy132\MinecraftModrinth\LuckPerms\LuckPermsService;
use Boy132\MinecraftModrinth\LuckPerms\LuckPermsSourceException;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class AutoUpdateModsCommand extends Command
{
    protected $signature = 'p:minecraft-modrinth:auto-update';

    protected $description = 'Automatically updates installed Modrinth mods/plugins to their latest compatible version, plugins installed from the GitHub repository source to a newer version with green CI, and LuckPerms to the newest version from luckperms.net (or notifies about it).';

    public function handle(): int
    {
        $modsEnabled = (bool) config('minecraft-modrinth.auto_update_enabled');
        $luckPerms = app(LuckPermsService::class);

        if (!$modsEnabled && !$luckPerms->isEnabled()) {
            $this->line('Automatic updates are disabled.');

            return 0;
        }

        $github = app(GitHubPluginService::class);
        $githubSnapshot = $modsEnabled ? $this->getGitHubSnapshot($github) : null;
        $luckPermsLatest = $this->getLuckPermsLatest($luckPerms);

        try {
            Server::query()->with(['egg', 'node', 'transfer'])->chunkById(50, function ($servers) use ($modsEnabled, $github, $githubSnapshot, $luckPerms, $luckPermsLatest) {
                foreach ($servers as $server) {
                    // One broken server (unreachable node, damaged metadata, ...) must never stop the
                    // run for all the others.
                    try {
                        $this->updateServerFromAllSources($server, $modsEnabled, $github, $githubSnapshot, $luckPerms, $luckPermsLatest);
                    } catch (Throwable $exception) {
                        $this->reportThrottled("server:{$server->id}:".get_class($exception), $exception);
                        $this->warn("Server #{$server->id}: skipped after an error (".get_class($exception).').');
                    }
                }
            });
        } finally {
            $luckPerms->forgetDownloads();
        }

        return 0;
    }

    /**
     * @param  array{repository: string, branch: string, sha: string, ci: CiStatus, plugins: array<int, array{id: string, name: string, platform: string, version: string, path: string, filename: string, sha256: string, size: int, frozen: bool}>, problems: array<int, string>}|null  $githubSnapshot
     * @param  array{version: string, timestamp: int|null, downloads: array<string, string>, changelog: array<int, array{version: string, timestamp: int|null, title: string, commit: string|null}>}|null  $luckPermsLatest
     */
    protected function updateServerFromAllSources(Server $server, bool $modsEnabled, GitHubPluginService $github, ?array $githubSnapshot, LuckPermsService $luckPerms, ?array $luckPermsLatest): void
    {
        // Suspended, (re)installing, being transferred or restored, node in maintenance: leave it alone.
        if ($server->isInConflictState()) {
            return;
        }

        // LuckPerms first: it takes LuckPerms over from Modrinth, so Modrinth doesn't update a
        // jar that is replaced right after.
        if ($luckPermsLatest !== null) {
            $result = $luckPerms->autoUpdateServer($server, $luckPermsLatest);

            if ($result !== null) {
                $this->line("Server #{$server->id}: $result.");
            }
        }

        if (!$modsEnabled) {
            return;
        }

        foreach (ModrinthProjectType::fromServer($server) as $modrinthProjectType) {
            $this->updateServer($server, $modrinthProjectType);
        }

        if ($githubSnapshot !== null) {
            $updated = $github->autoUpdateServer($server, $githubSnapshot);

            if (!empty($updated)) {
                $this->line("Server #{$server->id}: updated ".implode(', ', $updated).' from GitHub.');
            }
        }
    }

    /** Report a failure at most once a day per key, so a server that keeps failing doesn't flood the log. */
    protected function reportThrottled(string $key, Throwable $exception): void
    {
        if (Cache::add('minecraft-modrinth:auto-update:reported:'.md5($key), true, now()->addDay())) {
            report($exception);
        }
    }

    /**
     * The GitHub repository state to update from, fetched once for every server - or null when
     * the source is off, unreachable, or its head commit hasn't passed CI. Automatic updates
     * always require green CI, whatever the manual "require green CI" setting says.
     *
     * @return array{repository: string, branch: string, sha: string, ci: CiStatus, plugins: array<int, array{id: string, name: string, platform: string, version: string, path: string, filename: string, sha256: string, size: int, frozen: bool}>, problems: array<int, string>}|null
     */
    protected function getGitHubSnapshot(GitHubPluginService $github): ?array
    {
        if (!$github->isConfigured()) {
            return null;
        }

        try {
            $github->forgetCachedState();
            $snapshot = $github->getSnapshot();
        } catch (GitHubSourceException $exception) {
            // Already reported (throttled) by the service.
            $this->warn('GitHub source unavailable: '.$exception->getMessage());

            return null;
        }

        if ($snapshot['ci'] !== CiStatus::Green) {
            $this->line("GitHub source: CI of {$snapshot['sha']} is {$snapshot['ci']->value}, not updating from it.");

            return null;
        }

        return $snapshot;
    }

    /**
     * The newest LuckPerms from luckperms.net, read fresh once for every server - or null when
     * the LuckPerms source is off or luckperms.net can't be reached (logged once by the service).
     *
     * @return array{version: string, timestamp: int|null, downloads: array<string, string>, changelog: array<int, array{version: string, timestamp: int|null, title: string, commit: string|null}>}|null
     */
    protected function getLuckPermsLatest(LuckPermsService $luckPerms): ?array
    {
        if (!$luckPerms->isEnabled()) {
            return null;
        }

        try {
            $latest = $luckPerms->getLatest(true);
        } catch (LuckPermsSourceException $exception) {
            $this->warn('LuckPerms source unavailable: '.$exception->getMessage());

            return null;
        }

        $this->line("LuckPerms source: newest version {$latest['version']} (mode: {$luckPerms->mode()}).");

        return $latest;
    }

    protected function updateServer(Server $server, ModrinthProjectType $modrinthProjectType): void
    {
        $installedMods = MinecraftModrinth::getInstalledModsMetadata($server, $modrinthProjectType);

        if (empty($installedMods)) {
            return;
        }

        $projectIds = array_column($installedMods, 'project_id');
        $versionsByProject = MinecraftModrinth::getProjectVersionsBulk($projectIds, $server);

        foreach ($installedMods as $installedMod) {
            $versions = $versionsByProject[$installedMod['project_id']] ?? [];

            if (!MinecraftModrinth::isUpdateAvailable($installedMod, $versions)) {
                continue;
            }

            $latestVersion = $versions[0];
            $primaryFile = MinecraftModrinth::getPrimaryFile($latestVersion['files'] ?? []);

            if (!$primaryFile) {
                report(new Exception("Auto-update: no downloadable file for project {$installedMod['project_id']} ({$installedMod['project_title']}) on server #{$server->id}"));

                continue;
            }

            $record = [
                'project_id' => $installedMod['project_id'],
                'slug' => $installedMod['project_slug'],
                'title' => $installedMod['project_title'],
                'author' => $installedMod['author'] ?? null,
            ];

            try {
                MinecraftModrinth::performInstallOrUpdate($server, $modrinthProjectType, $record, $latestVersion, $primaryFile, $installedMod);
            } catch (Exception $exception) {
                $this->reportThrottled("server:{$server->id}:modrinth:{$installedMod['project_id']}", $exception);
            }
        }
    }
}
