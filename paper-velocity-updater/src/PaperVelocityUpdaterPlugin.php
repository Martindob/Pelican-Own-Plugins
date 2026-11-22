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
                ->label(trans('paper-velocity-updater::settings.enabled'))
                ->helperText(trans('paper-velocity-updater::settings.enabled_helper'))
                ->inline(false)
                ->default(fn () => config('paper-velocity-updater.enabled')),
            TextInput::make('cache_minutes')
                ->label(trans('paper-velocity-updater::settings.cache_minutes'))
                ->helperText(trans('paper-velocity-updater::settings.cache_minutes_helper'))
                ->numeric()
                ->minValue(1)
                ->required()
                ->default(fn () => config('paper-velocity-updater.cache_minutes')),
            TextInput::make('download_timeout_seconds')
                ->label(trans('paper-velocity-updater::settings.download_timeout'))
                ->helperText(trans('paper-velocity-updater::settings.download_timeout_helper'))
                ->numeric()
                ->minValue(60)
                ->required()
                ->default(fn () => config('paper-velocity-updater.download_timeout_seconds')),
            TextInput::make('report_throttle_minutes')
                ->label(trans('paper-velocity-updater::settings.report_throttle'))
                ->helperText(trans('paper-velocity-updater::settings.report_throttle_helper'))
                ->numeric()
                ->minValue(0)
                ->required()
                ->default(fn () => config('paper-velocity-updater.report_throttle_minutes')),
            Actions::make([
                Action::make('check_now')
                    ->label(trans('paper-velocity-updater::settings.check_now'))
                    ->button()
                    ->icon('tabler-refresh')
                    ->color('gray')
                    ->action(function () {
                        app(PaperVelocityUpdateService::class)->checkForUpdates();

                        Notification::make()
                            ->title(trans('paper-velocity-updater::settings.check_complete'))
                            ->body(trans('paper-velocity-updater::settings.check_complete_body'))
                            ->success()
                            ->send();
                    }),
            ]),
            TextEntry::make('last_check_at')
                ->label(trans('paper-velocity-updater::settings.last_check'))
                ->state(fn () => $this->formatLastCheckAt()),
            TextEntry::make('recent_activity')
                ->label(trans('paper-velocity-updater::settings.recent_activity'))
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
            return trans('paper-velocity-updater::settings.last_check_never');
        }

        $suffix = $lastCheckAt->lt(now()->subHours(2))
            ? trans('paper-velocity-updater::settings.last_check_stale')
            : '';

        return $lastCheckAt->diffForHumans() . $suffix;
    }

    private function formatActivityLog(): string
    {
        $service = app(PaperVelocityUpdateService::class);
        $entries = $service->getActivityLog();

        if (empty($entries)) {
            return '<p>'.e(trans('paper-velocity-updater::settings.no_activity')).'</p>';
        }

        $rows = array_map(function (array $entry) use ($service) {
            $time = e(Carbon::parse($entry['at'])->diffForHumans());
            $message = e($service->describeActivity($entry));
            $color = match ($entry['level'] ?? 'info') {
                'error' => 'rgb(220 38 38)',
                'success' => 'rgb(22 163 74)',
                'warning' => 'rgb(217 119 6)',
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
            ->title(trans('paper-velocity-updater::settings.saved'))
            ->success()
            ->send();
    }
}
