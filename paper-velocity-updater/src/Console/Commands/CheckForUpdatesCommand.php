<?php

namespace Martindob\PaperVelocityUpdater\Console\Commands;

use Illuminate\Console\Command;
use Martindob\PaperVelocityUpdater\Services\PaperVelocityUpdateService;

class CheckForUpdatesCommand extends Command
{
    protected $signature = 'p:paper-velocity-updater:check';

    protected $description = 'Downloads newer Paper/Velocity builds into a staging file for servers that need one, ahead of their next start/restart.';

    public function handle(PaperVelocityUpdateService $updateService): int
    {
        if (!config('paper-velocity-updater.enabled')) {
            $this->line('Paper & Velocity Updater is disabled.');

            return 0;
        }

        $updateService->checkForUpdates();

        return 0;
    }
}
