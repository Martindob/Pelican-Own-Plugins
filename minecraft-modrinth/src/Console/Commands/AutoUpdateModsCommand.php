<?php

namespace Boy132\MinecraftModrinth\Console\Commands;

use App\Models\Server;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
use Boy132\MinecraftModrinth\Facades\MinecraftModrinth;
use Exception;
use Illuminate\Console\Command;

class AutoUpdateModsCommand extends Command
{
    protected $signature = 'p:minecraft-modrinth:auto-update';

    protected $description = 'Automatically updates installed Modrinth mods/plugins to their latest compatible version.';

    public function handle(): int
    {
        if (!config('minecraft-modrinth.auto_update_enabled')) {
            $this->line('Automatic updates are disabled.');

            return 0;
        }

        Server::query()->chunk(50, function ($servers) {
            foreach ($servers as $server) {
                foreach (ModrinthProjectType::fromServer($server) as $modrinthProjectType) {
                    $this->updateServer($server, $modrinthProjectType);
                }
            }
        });

        return 0;
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
                report($exception);
            }
        }
    }
}
