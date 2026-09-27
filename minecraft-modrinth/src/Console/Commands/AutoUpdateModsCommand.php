<?php

namespace Boy132\MinecraftModrinth\Console\Commands;

use App\Models\Server;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
use Boy132\MinecraftModrinth\Facades\MinecraftModrinth;
use Boy132\MinecraftModrinth\GitHub\CiStatus;
use Boy132\MinecraftModrinth\GitHub\GitHubPluginService;
use Boy132\MinecraftModrinth\GitHub\GitHubSourceException;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class AutoUpdateModsCommand extends Command
{
    protected $signature = 'p:minecraft-modrinth:auto-update';

    protected $description = 'Automatically updates installed Modrinth mods/plugins to their latest compatible version, and plugins installed from the GitHub repository source to a newer version with green CI.';

    public function handle(): int
    {
        if (!config('minecraft-modrinth.auto_update_enabled')) {
            $this->line('Automatic updates are disabled.');

            return 0;
        }

        $github = app(GitHubPluginService::class);
        $githubSnapshot = $this->getGitHubSnapshot($github);

        Server::query()->with(['egg', 'node', 'transfer'])->chunkById(50, function ($servers) use ($github, $githubSnapshot) {
            foreach ($servers as $server) {
                // One broken server (unreachable node, damaged metadata, ...) must never stop the
                // run for all the others.
                try {
                    $this->updateServerFromAllSources($server, $github, $githubSnapshot);
                } catch (Throwable $exception) {
                    $this->reportThrottled("server:{$server->id}:".get_class($exception), $exception);
                    $this->warn("Server #{$server->id}: skipped after an error (".get_class($exception).').');
                }
            }
        });

        return 0;
    }

    /**
     * @param  array{repository: string, branch: string, sha: string, ci: CiStatus, plugins: array<int, array{id: string, name: string, platform: string, version: string, path: string, filename: string, sha256: string, size: int, frozen: bool}>, problems: array<int, string>}|null  $githubSnapshot
     */
    protected function updateServerFromAllSources(Server $server, GitHubPluginService $github, ?array $githubSnapshot): void
    {
        // Suspended, (re)installing, being transferred or restored, node in maintenance: leave it alone.
        if ($server->isInConflictState()) {
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
