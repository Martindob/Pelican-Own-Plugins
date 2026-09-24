<?php

namespace Martindob\PaperVelocityUpdater\Providers;

use App\Repositories\Daemon\DaemonServerRepository;
use Illuminate\Support\ServiceProvider;
use Martindob\PaperVelocityUpdater\Repositories\UpdateCheckingDaemonServerRepository;

class PaperVelocityUpdaterPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        // The panel has no event hook around power actions, so the update check
        // is wired in by decorating the repository every power action (manual,
        // client API, or scheduled task) resolves out of the container.
        // At this point in resolution nothing has called setServer() on $repository
        // yet, so it can simply be swapped out for our decorated implementation.
        $this->app->extend(
            DaemonServerRepository::class,
            fn (DaemonServerRepository $repository, $app) => $app->make(UpdateCheckingDaemonServerRepository::class)
        );
    }

    public function boot(): void {}
}
