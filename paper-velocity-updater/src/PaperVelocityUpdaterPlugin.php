<?php

namespace Martindob\PaperVelocityUpdater;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Actions\Action;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Actions;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Martindob\PaperVelocityUpdater\Services\PaperVelocityUpdateService;

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
            Actions::make([
                Action::make('check_now')
                    ->label('Run check now')
                    ->icon('tabler-refresh')
                    ->color('gray')
                    ->action(function () {
                        app(PaperVelocityUpdateService::class)->checkForUpdates();

                        Notification::make()
                            ->title('Check complete')
                            ->body('Close and reopen this settings dialog to see the result below.')
                            ->success()
                            ->send();
                    }),
            ]),
            TextEntry::make('last_check_at')
                ->label('Last check')
                ->state(fn () => $this->formatLastCheckAt()),
            TextEntry::make('recent_activity')
                ->label('Recent activity')
                ->state(fn () => new HtmlString($this->formatActivityLog()))
                ->columnSpanFull(),
        ];
    }

    /**
     * "Run check now" doesn't run on a fresh page - the settings form only
     * gets rebuilt when this modal is (re)opened (see App\Models\Plugin::
     * getSettingsForm(), which constructs a fresh plugin instance each time)
     * - so its own Notification is the only immediate feedback; this and
     * formatActivityLog() below only pick up what it did once the dialog is
     * reopened.
     */
    private function formatLastCheckAt(): string
    {
        $lastCheckAt = app(PaperVelocityUpdateService::class)->getLastCheckAt();

        if (!$lastCheckAt) {
            return 'Never run yet. Use "Run check now" above, then reopen this dialog - if it still says this afterwards, the hourly scheduler likely isn\'t running (verify `php artisan schedule:run` is wired into cron on the panel host).';
        }

        $suffix = $lastCheckAt->lt(now()->subHours(2))
            ? ' - this is more than 2 hours ago, which usually means the hourly scheduled check has stopped running (verify `php artisan schedule:run` is wired into cron on the panel host).'
            : '';

        return $lastCheckAt->diffForHumans() . $suffix;
    }

    private function formatActivityLog(): string
    {
        $entries = app(PaperVelocityUpdateService::class)->getActivityLog();

        if (empty($entries)) {
            return '<p>No activity recorded yet.</p>';
        }

        $rows = array_map(function (array $entry) {
            $time = e(Carbon::parse($entry['at'])->diffForHumans());
            $message = e($entry['message']);
            $color = match ($entry['level'] ?? 'info') {
                'error' => 'rgb(220 38 38)',
                'success' => 'rgb(22 163 74)',
                default => 'rgb(100 116 139)',
            };

            return "<div style=\"margin-bottom:0.25rem;\"><span style=\"color:{$color};font-weight:600;\">{$time}</span> — {$message}</div>";
        }, $entries);

        return implode('', $rows);
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
