<?php

namespace Boy132\MinecraftModrinth\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use Boy132\MinecraftModrinth\LuckPerms\LuckPermsService;
use Boy132\MinecraftModrinth\LuckPerms\LuckPermsSourceException;
use Boy132\MinecraftModrinth\LuckPerms\LuckPermsSourceRules;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;

/**
 * LuckPerms straight from luckperms.net (see README "LuckPerms source"): the installed and the
 * newest version, the latest changes, and Install / Update / Remove for this server.
 *
 * @phpstan-import-type Latest from LuckPermsSourceRules
 * @phpstan-import-type ServerState from LuckPermsService
 */
class LuckPermsPage extends Page
{
    use BlockAccessInConflict;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-shield-lock';

    protected static ?string $slug = 'luckperms';

    protected static ?int $navigationSort = 32;

    /** Changelog entries shown on the page. */
    protected const CHANGELOG_ENTRIES = 8;

    /** A forced check of luckperms.net: at most once per server in this many seconds. */
    protected const REFRESH_PER_SERVER_SECONDS = 10;

    /** @var Latest|null */
    protected ?array $latest = null;

    protected ?LuckPermsSourceException $latestError = null;

    protected bool $latestLoaded = false;

    /** @var ServerState|null */
    protected ?array $state = null;

    protected ?LuckPermsSourceException $stateError = null;

    protected bool $stateLoaded = false;

    protected static function service(): LuckPermsService
    {
        return app(LuckPermsService::class);
    }

    protected static function server(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    public static function canAccess(): bool
    {
        $server = static::server();

        return parent::canAccess()
            && static::service()->isEnabled()
            && static::service()->platformForServer($server) !== null
            && (bool) user()?->can(SubuserPermission::FileRead, $server);
    }

    protected static function userCan(SubuserPermission ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!user()?->can($permission, static::server())) {
                return false;
            }
        }

        return true;
    }

    public static function getNavigationLabel(): string
    {
        return trans('minecraft-modrinth::strings.luckperms.page.navigation');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    /** @return Latest|null */
    protected function getLatest(): ?array
    {
        if (!$this->latestLoaded) {
            $this->latestLoaded = true;

            try {
                $this->latest = static::service()->getLatest();
            } catch (LuckPermsSourceException $exception) {
                $this->latestError = $exception;
            }
        }

        return $this->latest;
    }

    /** @return ServerState|null */
    protected function getState(): ?array
    {
        if (!$this->stateLoaded) {
            $this->stateLoaded = true;

            try {
                $this->state = static::service()->inspectServer(static::server(), $this->getLatest()['version'] ?? null);
            } catch (LuckPermsSourceException $exception) {
                $this->stateError = $exception;
            }
        }

        return $this->state;
    }

    protected function stateName(): ?string
    {
        return $this->getState()['state'] ?? null;
    }

    /** @return array{filename: string, platform: string, version: string, size: int}|null */
    protected function installedJar(): ?array
    {
        return $this->getState()['installed'] ?? null;
    }

    protected function isFromModrinth(): bool
    {
        $jar = $this->installedJar();

        return $jar !== null && isset($this->getState()['modrinth'][$jar['filename']]);
    }

    /** Whether the newest version can be downloaded for this server's platform. */
    protected function hasDownload(): bool
    {
        $platform = $this->getState()['platform'] ?? null;

        return $platform !== null && isset($this->getLatest()['downloads'][$platform]);
    }

    protected function forgetState(bool $refresh = true): void
    {
        $this->latest = null;
        $this->latestError = null;
        $this->latestLoaded = false;
        $this->state = null;
        $this->stateError = null;
        $this->stateLoaded = false;

        if ($refresh) {
            $this->js('$wire.$refresh()');
        }
    }

    protected function install(bool $isUpdate): void
    {
        // Also checked here: the action's own authorization is the first line, not the only one.
        if (!static::userCan(...($isUpdate ? [SubuserPermission::FileCreate, SubuserPermission::FileDelete] : [SubuserPermission::FileCreate]))) {
            abort(403);
        }

        $server = static::server();
        $expected = $this->installedJar()['filename'] ?? null;

        try {
            $latest = $this->getLatest();
            if ($latest === null) {
                throw $this->latestError ?? new LuckPermsSourceException(LuckPermsSourceException::NETWORK, 'The newest LuckPerms version is unknown');
            }

            $result = static::service()->installOrUpdate($server, $latest, $isUpdate, $expected);

            Notification::make()
                ->title(trans($isUpdate ? 'minecraft-modrinth::strings.notifications.update_success' : 'minecraft-modrinth::strings.notifications.install_success'))
                ->body(trans($result['took_over_modrinth'] ? 'minecraft-modrinth::strings.luckperms.notifications.installed_from_modrinth_body' : 'minecraft-modrinth::strings.luckperms.notifications.installed_body', [
                    'version' => e($result['version']),
                    'filename' => e($result['filename']),
                ]))
                ->success()
                ->send();
        } catch (LuckPermsSourceException $exception) {
            static::service()->reportOncePerDay("ui:{$server->id}:install:{$exception->reason}", $exception->getMessage());

            Notification::make()
                ->title(trans($isUpdate ? 'minecraft-modrinth::strings.notifications.update_failed' : 'minecraft-modrinth::strings.notifications.install_failed'))
                ->body($exception->getUserMessage())
                ->danger()
                ->persistent()
                ->send();
        } finally {
            static::service()->forgetDownloads();
        }

        $this->forgetState();
    }

    protected function uninstall(): void
    {
        if (!static::userCan(SubuserPermission::FileDelete)) {
            abort(403);
        }

        $server = static::server();
        $jar = $this->installedJar();

        try {
            if ($jar === null) {
                throw new LuckPermsSourceException(LuckPermsSourceException::STATE_CHANGED, 'LuckPerms is not installed');
            }

            $complete = static::service()->uninstall($server, $jar['filename']);

            Notification::make()
                ->title(trans('minecraft-modrinth::strings.notifications.uninstall_success'))
                ->body(trans($complete ? 'minecraft-modrinth::strings.luckperms.notifications.uninstalled_body' : 'minecraft-modrinth::strings.luckperms.notifications.uninstalled_modrinth_left_body', ['filename' => e($jar['filename'])]))
                ->status($complete ? 'success' : 'warning')
                ->send();
        } catch (LuckPermsSourceException $exception) {
            static::service()->reportOncePerDay("ui:{$server->id}:uninstall:{$exception->reason}", $exception->getMessage());

            Notification::make()
                ->title(trans('minecraft-modrinth::strings.notifications.uninstall_failed'))
                ->body($exception->getUserMessage())
                ->danger()
                ->send();
        }

        $this->forgetState();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('install')
                ->label(trans('minecraft-modrinth::strings.actions.install'))
                ->icon('tabler-download')
                ->color('success')
                ->visible(fn () => $this->stateName() === 'not_installed')
                ->disabled(fn () => !$this->hasDownload())
                ->authorize(fn () => static::userCan(SubuserPermission::FileCreate))
                ->requiresConfirmation()
                ->modalHeading(trans('minecraft-modrinth::strings.luckperms.modals.install_heading'))
                ->modalDescription(fn () => trans('minecraft-modrinth::strings.luckperms.modals.install_description', [
                    'version' => $this->getLatest()['version'] ?? '?',
                ]))
                ->action(fn () => $this->install(false)),
            Action::make('update')
                ->label(trans('minecraft-modrinth::strings.actions.update'))
                ->icon('tabler-refresh')
                ->color('warning')
                ->visible(fn () => $this->stateName() === 'update_available')
                ->disabled(fn () => !$this->hasDownload())
                ->authorize(fn () => static::userCan(SubuserPermission::FileCreate, SubuserPermission::FileDelete))
                ->requiresConfirmation()
                ->modalHeading(trans('minecraft-modrinth::strings.luckperms.modals.update_heading'))
                ->modalDescription(fn () => trans('minecraft-modrinth::strings.luckperms.modals.update_description', [
                    'old_version' => $this->installedJar()['version'] ?? '?',
                    'new_version' => $this->getLatest()['version'] ?? '?',
                    'filename' => $this->installedJar()['filename'] ?? '?',
                ]).($this->isFromModrinth() ? ' '.trans('minecraft-modrinth::strings.luckperms.modals.update_takeover') : ''))
                ->action(fn () => $this->install(true)),
            Action::make('uninstall')
                ->label(trans('minecraft-modrinth::strings.actions.uninstall'))
                ->icon('tabler-trash')
                ->color('danger')
                ->outlined()
                ->visible(fn () => $this->installedJar() !== null && $this->stateName() !== 'conflict')
                ->authorize(fn () => static::userCan(SubuserPermission::FileDelete))
                ->requiresConfirmation()
                ->modalHeading(trans('minecraft-modrinth::strings.luckperms.modals.uninstall_heading'))
                ->modalDescription(fn () => trans('minecraft-modrinth::strings.luckperms.modals.uninstall_description', [
                    'filename' => $this->installedJar()['filename'] ?? '?',
                ]))
                ->action(fn () => $this->uninstall()),
            Action::make('refresh')
                ->tooltip(trans('minecraft-modrinth::strings.luckperms.page.refresh'))
                ->icon('tabler-reload')
                ->color('gray')
                ->action(function () {
                    $key = 'minecraft-modrinth:luckperms:refresh:'.static::server()->id;

                    if (RateLimiter::tooManyAttempts($key, 1)) {
                        Notification::make()
                            ->title(trans('minecraft-modrinth::strings.luckperms.page.refresh_throttled', ['seconds' => max(1, RateLimiter::availableIn($key))]))
                            ->warning()
                            ->send();

                        return;
                    }

                    RateLimiter::hit($key, self::REFRESH_PER_SERVER_SECONDS);
                    static::service()->forgetLatest();
                    $this->forgetState();
                }),
            Action::make('open_folder')
                ->tooltip(fn () => trans('minecraft-modrinth::strings.page.open_folder', ['folder' => LuckPermsService::FOLDER]))
                ->icon('tabler-folder-open')
                ->url(fn () => ListFiles::getUrl(['path' => LuckPermsService::FOLDER]), true),
        ];
    }

    protected function formatTimestamp(?int $milliseconds): ?string
    {
        if ($milliseconds === null) {
            return null;
        }

        return Carbon::createFromTimestampMs($milliseconds, 'UTC')
            ->timezone(user()->timezone ?? config('app.timezone', 'UTC'))
            ->format('Y-m-d H:i');
    }

    public function content(Schema $schema): Schema
    {
        $changelog = array_slice($this->getLatest()['changelog'] ?? [], 0, self::CHANGELOG_ENTRIES);

        return $schema
            ->components([
                Grid::make(4)
                    ->schema([
                        TextEntry::make('platform')
                            ->label(trans('minecraft-modrinth::strings.github.page.platform'))
                            ->state(fn () => ($platform = static::service()->platformForServer(static::server())) ? LuckPermsSourceRules::platformLabel($platform) : trans('minecraft-modrinth::strings.page.unknown'))
                            ->badge(),
                        TextEntry::make('installed')
                            ->label(trans('minecraft-modrinth::strings.luckperms.page.installed'))
                            ->state(fn () => $this->installedJar()['version'] ?? ($this->stateName() === 'not_installed' ? trans('minecraft-modrinth::strings.luckperms.page.not_installed') : trans('minecraft-modrinth::strings.page.unknown')))
                            ->helperText(fn () => $this->installedJar()['filename'] ?? null)
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('latest')
                            ->label(trans('minecraft-modrinth::strings.luckperms.page.latest'))
                            ->state(fn () => $this->getLatest()['version'] ?? trans('minecraft-modrinth::strings.page.unknown'))
                            ->helperText(fn () => $this->formatTimestamp($this->getLatest()['timestamp'] ?? null))
                            ->url('https://luckperms.net/download', true)
                            ->badge()
                            ->color('info'),
                        TextEntry::make('state')
                            ->label(trans('minecraft-modrinth::strings.github.table.state'))
                            ->state(fn () => trans('minecraft-modrinth::strings.luckperms.state.'.($this->stateName() ?? 'unreadable')))
                            ->color(fn () => match ($this->stateName()) {
                                'up_to_date' => 'success',
                                'update_available' => 'warning',
                                'conflict' => 'danger',
                                'not_installed', 'newer_installed' => 'info',
                                default => 'gray',
                            })
                            ->badge(),
                    ]),
                Callout::make(trans('minecraft-modrinth::strings.github.page.restart_notice_heading'))
                    ->description(trans('minecraft-modrinth::strings.luckperms.page.restart_notice'))
                    ->info(),
                Callout::make(trans('minecraft-modrinth::strings.luckperms.page.unavailable'))
                    ->description(fn () => $this->latestError?->getUserMessage())
                    ->danger()
                    ->visible(fn () => $this->getLatest() === null),
                Callout::make(trans('minecraft-modrinth::strings.luckperms.page.server_unreadable'))
                    ->description(fn () => $this->stateError?->getUserMessage())
                    ->danger()
                    ->visible(fn () => $this->getState() === null),
                Callout::make(trans('minecraft-modrinth::strings.luckperms.page.conflict_heading'))
                    ->description(function () {
                        $state = $this->getState();
                        $files = match (true) {
                            $state === null => [],
                            $state['reason'] === 'unverified' => $state['unverified'],
                            default => array_column($state['jars'], 'filename'),
                        };

                        return trans('minecraft-modrinth::strings.luckperms.page.conflict_'.($state['reason'] ?? 'several'), ['files' => implode(', ', $files)]);
                    })
                    ->danger()
                    ->visible(fn () => $this->stateName() === 'conflict'),
                Callout::make(trans('minecraft-modrinth::strings.luckperms.page.from_modrinth_heading'))
                    ->description(trans('minecraft-modrinth::strings.luckperms.page.from_modrinth'))
                    ->info()
                    ->visible(fn () => $this->isFromModrinth()),
                Callout::make(trans('minecraft-modrinth::strings.luckperms.page.modrinth_unreadable_heading'))
                    ->description(trans('minecraft-modrinth::strings.luckperms.page.modrinth_unreadable'))
                    ->warning()
                    ->visible(fn () => ($this->getState()['modrinth_error'] ?? false) === true),
                Callout::make(trans('minecraft-modrinth::strings.luckperms.page.no_download'))
                    ->warning()
                    ->visible(fn () => $this->getLatest() !== null && $this->getState() !== null && !$this->hasDownload()),
                Callout::make(fn () => static::service()->mode() === 'notify'
                    ? trans('minecraft-modrinth::strings.luckperms.page.mode_notify')
                    : trans('minecraft-modrinth::strings.luckperms.page.mode_auto'))
                    ->description(fn () => trans('minecraft-modrinth::strings.luckperms.page.mode_description', [
                        'time' => (string) config('minecraft-modrinth.auto_update_time', '00:00'),
                        'timezone' => (string) (config('minecraft-modrinth.auto_update_timezone') ?: config('app.timezone')),
                    ]))
                    ->icon('tabler-clock')
                    ->color('gray'),
                Section::make(trans('minecraft-modrinth::strings.luckperms.page.changelog'))
                    ->description(trans('minecraft-modrinth::strings.luckperms.page.changelog_description'))
                    ->collapsible()
                    ->visible(!empty($changelog))
                    ->schema(array_map(fn (array $entry, int $index) => TextEntry::make('changelog_'.$index)
                        ->label($entry['version'].(($time = $this->formatTimestamp($entry['timestamp'])) !== null ? ' · '.$time : ''))
                        ->state($entry['title'])
                        ->url(LuckPermsSourceRules::commitUrl($entry['commit']), true), $changelog, array_keys($changelog))),
            ]);
    }
}
