<?php

namespace Boy132\MinecraftModrinth\Console\Commands;

use App\Models\Server;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
use Boy132\MinecraftModrinth\Facades\MinecraftModrinth;
use Boy132\MinecraftModrinth\GitHub\CiStatus;
use Boy132\MinecraftModrinth\GitHub\GitHubPluginService;
use Boy132\MinecraftModrinth\GitHub\GitHubSourceException;
use Boy132\MinecraftModrinth\Modrinth\ModrinthRules;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class AutoUpdateModsCommand extends Command
{
    protected $signature = 'p:minecraft-modrinth:auto-update';

    protected $description = 'Automatically updates installed Modrinth mods/plugins to their latest compatible version, plugins installed from the GitHub repository source to a newer version with green CI (replacing manual copies of them when that setting is on).';

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
     * @param  array{repository: string, branch: string, sha: string, ci: CiStatus, plugins: array<int, array{id: string, name: string, platform: string, version: string, path: string, filename: string, sha256: string, size: int, frozen: bool, previous_ids: array<int, string>}>, problems: array<int, string>}|null  $githubSnapshot
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
     * The GitHub repository state to update from, fetched once for every server - or null when the source is off,
     * unreachable, or no commit qualifies. Not simply the head: the newest commit of the branch that is older than
     * the quarantine, has green CI of the required workflow on exactly that SHA and passes the author allowlist
     * (GitHubPluginService::autoUpdateSnapshot()) - so a commit pushed shortly before the nightly run doesn't stop
     * it, the run takes the newest older commit instead. Automatic updates always require green CI, whatever the
     * manual "require green CI" setting says. GitHub unreachable: nothing is updated (fail closed).
     *
     * @return array{repository: string, branch: string, sha: string, ci: CiStatus, plugins: array<int, array{id: string, name: string, platform: string, version: string, path: string, filename: string, sha256: string, size: int, frozen: bool, previous_ids: array<int, string>}>, problems: array<int, string>, author?: ?string}|null
     */
    protected function getGitHubSnapshot(GitHubPluginService $github): ?array
    {
        if (!$github->isConfigured()) {
            return null;
        }

        try {
            $github->forgetCachedState();
            $choice = $github->autoUpdateSnapshot();
        } catch (GitHubSourceException $exception) {
            $github->reportOncePerWindow('auto:'.$exception->reason, $exception);
            $this->warn('GitHub source unavailable: '.$exception->getMessage());

            return null;
        }

        if ($choice === null) {
            $this->line('GitHub source: no commit qualifies for an automatic update (see the panel log), not updating.');

            return null;
        }

        $snapshot = $choice['snapshot'];
        if ($snapshot['ci'] !== CiStatus::Green) {
            return null;
        }

        $this->line("GitHub source: updating from commit {$snapshot['sha']}".($choice['author'] !== null ? " by {$choice['author']}" : '')
            .($snapshot['sha'] !== $choice['head'] ? " ({$choice['held_back']} newer commit(s) up to {$choice['head']} held back)" : '').'.');

        // The commit servers are updated from now: what comes in after it is checked against the allowlist next time.
        $github->rememberDeployed($snapshot['sha']);

        return $snapshot + ['author' => $choice['author']];
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
            // Only a newer, at least as stable version - never a downgrade, never a beta over a release.
            $latestVersion = ModrinthRules::autoUpdateTarget($installedMod['version_id'], $versionsByProject[$installedMod['project_id']] ?? []);

            if ($latestVersion === null) {
                continue;
            }
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
