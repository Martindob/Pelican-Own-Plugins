<?php

namespace Martindob\PaperVelocityUpdater;

use Filament\Contracts\Plugin;
use Filament\Panel;

class PaperVelocityUpdaterPlugin implements Plugin
{
    public function getId(): string
    {
        return 'paper-velocity-updater';
    }

    public function register(Panel $panel): void
    {
        // No Filament UI is needed: the update check is wired up in
        // PaperVelocityUpdaterPluginProvider so it also runs for the client API
        // (used by power actions and scheduled tasks), not just panel requests.
    }

    public function boot(Panel $panel): void {}
}
