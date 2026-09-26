<?php

namespace Martindob\PaperVelocityUpdater\Providers;

use App\Repositories\Daemon\DaemonServerRepository;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use Martindob\PaperVelocityUpdater\Console\Commands\CheckForUpdatesCommand;
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
        //
        // Known limitation: the incoming $repository is intentionally not
        // reused/composed with - UpdateCheckingDaemonServerRepository is a
        // subclass, not a wrapper, so there is no clean way to layer it on top
        // of an arbitrary already-decorated instance another plugin might have
        // produced. If some other installed plugin ever also decorates
        // DaemonServerRepository this way, only one of the two decorations
        // ends up active depending on registration order, with no error
        // raised. As of this writing no other plugin in this repository does
        // that (a quick grep for $app->extend(DaemonServerRepository confirms
        // it), so this is a documented risk rather than an active bug.
        $this->app->extend(
            DaemonServerRepository::class,
            fn (DaemonServerRepository $repository, $app) => $app->make(UpdateCheckingDaemonServerRepository::class)
        );
    }

    public function boot(): void
    {
        // Hourly comfortably covers a once-a-day restart with hours to spare
        // even if a check happens to fail (PaperMC hiccup, node briefly
        // unreachable, ...) - the next one an hour later just retries. This
        // is deliberately not tied to any particular server's own restart
        // schedule: staging ahead of time only requires "at some point
        // before the next restart", not a precisely timed check.
        Schedule::command(CheckForUpdatesCommand::class)
            ->hourly()
            ->withoutOverlapping();
    }
}
