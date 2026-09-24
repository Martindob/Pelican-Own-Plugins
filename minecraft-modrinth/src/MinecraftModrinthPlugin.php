<?php

namespace Boy132\MinecraftModrinth;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;

class MinecraftModrinthPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'minecraft-modrinth';
    }

    public function register(Panel $panel): void
    {
        $id = str($panel->getId())->title();

        $panel->discoverPages(plugin_path($this->getId(), "src/Filament/$id/Pages"), "Boy132\\MinecraftModrinth\\Filament\\$id\\Pages");
    }

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        return config('minecraft-modrinth');
    }

    public function getSettingsForm(): array
    {
        return [
            Toggle::make('always_use_latest_version')
                ->label(trans('minecraft-modrinth::strings.settings.always_use_latest_version'))
                ->hintIcon('tabler-question-mark')
                ->hintIconTooltip(trans('minecraft-modrinth::strings.settings.always_use_latest_version_hint'))
                ->inline(false)
                ->default(fn () => config('minecraft-modrinth.always_use_latest_version')),
            Toggle::make('auto_update_enabled')
                ->label(trans('minecraft-modrinth::strings.settings.auto_update_enabled'))
                ->hintIcon('tabler-question-mark')
                ->hintIconTooltip(trans('minecraft-modrinth::strings.settings.auto_update_enabled_hint'))
                ->inline(false)
                ->default(fn () => config('minecraft-modrinth.auto_update_enabled')),
            TimePicker::make('auto_update_time')
                ->label(trans('minecraft-modrinth::strings.settings.auto_update_time'))
                ->hintIcon('tabler-question-mark')
                ->hintIconTooltip(trans('minecraft-modrinth::strings.settings.auto_update_time_hint', ['timezone' => user()->timezone ?? config('app.timezone')]))
                ->native(false)
                ->seconds(false)
                ->required()
                ->default(fn () => config('minecraft-modrinth.auto_update_time')),
        ];
    }

    public function saveSettings(array $data): void
    {
        $this->writeToEnvironment([
            'MINECRAFT_MODRINTH_ALWAYS_USE_LATEST_VERSION' => $data['always_use_latest_version'],
            'MINECRAFT_MODRINTH_AUTO_UPDATE_ENABLED' => $data['auto_update_enabled'],
            'MINECRAFT_MODRINTH_AUTO_UPDATE_TIME' => $data['auto_update_time'],
            // The time above is only meaningful together with a timezone: capture the saving
            // admin's own account timezone here so the schedule actually fires at that wall-clock
            // time, instead of Schedule::dailyAt() defaulting to config('app.timezone') (UTC on a
            // typical Pelican install), which would silently shift it for anyone elsewhere.
            'MINECRAFT_MODRINTH_AUTO_UPDATE_TIMEZONE' => user()->timezone ?? config('app.timezone'),
        ]);

        Notification::make()
            ->title(trans('minecraft-modrinth::strings.settings.settings_saved'))
            ->success()
            ->send();
    }
}
