<?php

namespace Boy132\MinecraftModrinth\Providers;

use Boy132\MinecraftModrinth\Console\Commands\AutoUpdateModsCommand;
use Boy132\MinecraftModrinth\Console\Commands\CheckGitHubOffersCommand;
use Boy132\MinecraftModrinth\GitHub\GitHubOffers;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;

class MinecraftModrinthPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        // One instance per request/run: caches what it looked up (e.g. whether its table exists).
        $this->app->singleton(GitHubOffers::class);
    }

    public function boot(): void
    {
        Schedule::command(AutoUpdateModsCommand::class)
            ->timezone(config('minecraft-modrinth.auto_update_timezone') ?: config('app.timezone'))
            ->dailyAt(config('minecraft-modrinth.auto_update_time', '00:00'))
            ->withoutOverlapping();

        // New plugins / updates from the GitHub repository source: badge counts and one
        // notification per new plugin and server. Does nothing while the source is off.
        Schedule::command(CheckGitHubOffersCommand::class)
            ->hourlyAt(7)
            ->withoutOverlapping()
            ->when(fn () => (bool) config('minecraft-modrinth.github.enabled'));
    }
}
