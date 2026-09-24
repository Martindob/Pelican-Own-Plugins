<?php

namespace Boy132\MinecraftModrinth\Providers;

use Boy132\MinecraftModrinth\Console\Commands\AutoUpdateModsCommand;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;

class MinecraftModrinthPluginProvider extends ServiceProvider
{
    public function boot(): void
    {
        Schedule::command(AutoUpdateModsCommand::class)
            ->timezone(config('minecraft-modrinth.auto_update_timezone') ?: config('app.timezone'))
            ->dailyAt(config('minecraft-modrinth.auto_update_time', '00:00'))
            ->withoutOverlapping();
    }
}
