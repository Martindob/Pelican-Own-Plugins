<?php

namespace Martindob\PaperVelocityUpdater;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;

class PaperVelocityUpdaterPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'paper-velocity-updater';
    }

    public function register(Panel $panel): void
    {
        // No other Filament UI is needed: the hourly background check and the
        // start/restart swap hook are both wired up in
        // PaperVelocityUpdaterPluginProvider so they run regardless of which
        // request context triggers them (scheduler, client API power actions,
        // scheduled tasks), not just panel requests.
    }

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        return config('paper-velocity-updater');
    }

    public function getSettingsForm(): array
    {
        return [
            Toggle::make('enabled')
                ->label('Enabled')
                ->helperText('Automatically stage and install the newest Paper/Velocity build. Checked hourly in the background; applied on the next server start/restart.')
                ->inline(false)
                ->default(fn () => config('paper-velocity-updater.enabled')),
            TextInput::make('cache_minutes')
                ->label('Version/build cache (minutes)')
                ->helperText('How long a resolved "latest version"/"latest build" lookup is cached before PaperMC is asked again.')
                ->numeric()
                ->minValue(1)
                ->required()
                ->default(fn () => config('paper-velocity-updater.cache_minutes')),
            TextInput::make('download_timeout_seconds')
                ->label('Download timeout (seconds)')
                ->helperText('How long the hourly background check waits for the daemon to download and stage a new jar. Raise this if your nodes have a slow link to PaperMC\'s CDN. Kept well above the daemon client\'s own 15 second default on purpose - a ~50-60MB jar realistically needs more than that. Never affects a server start/restart itself, which only ever swaps in what was already staged.')
                ->numeric()
                ->minValue(60)
                ->required()
                ->default(fn () => config('paper-velocity-updater.download_timeout_seconds')),
            TextInput::make('report_throttle_minutes')
                ->label('Failure log throttle (minutes)')
                ->helperText('The same failure for a given server is only logged once per this many minutes, no matter how many times the hourly check hits it in the meantime. Set to 0 to log every occurrence.')
                ->numeric()
                ->minValue(0)
                ->required()
                ->default(fn () => config('paper-velocity-updater.report_throttle_minutes')),
        ];
    }

    public function saveSettings(array $data): void
    {
        $this->writeToEnvironment([
            'PAPER_VELOCITY_UPDATER_ENABLED' => $data['enabled'],
            'PAPER_VELOCITY_UPDATER_CACHE_MINUTES' => $data['cache_minutes'],
            'PAPER_VELOCITY_UPDATER_DOWNLOAD_TIMEOUT_SECONDS' => $data['download_timeout_seconds'],
            'PAPER_VELOCITY_UPDATER_REPORT_THROTTLE_MINUTES' => $data['report_throttle_minutes'],
        ]);

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }
}
