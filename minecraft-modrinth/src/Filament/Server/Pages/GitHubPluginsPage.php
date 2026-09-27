<?php

namespace Boy132\MinecraftModrinth\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use Boy132\MinecraftModrinth\GitHub\CiStatus;
use Boy132\MinecraftModrinth\GitHub\GitHubOffers;
use Boy132\MinecraftModrinth\GitHub\GitHubPluginService;
use Boy132\MinecraftModrinth\GitHub\GitHubSourceException;
use Boy132\MinecraftModrinth\GitHub\GitHubSourceRules;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * Plugins from the configured GitHub repository (see README "GitHub repository source"),
 * for the platform of this server: install, update and remove them. Plugins that aren't
 * installed yet are offered ("New") until an admin installs them or says they don't belong on
 * this server (see README "New plugins").
 *
 * @phpstan-import-type Snapshot from GitHubPluginService
 * @phpstan-import-type InstalledPlugin from GitHubPluginService
 */
class GitHubPluginsPage extends Page implements HasTable
{
    use BlockAccessInConflict;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-brand-github';

    protected static ?string $slug = 'github_plugins';

    protected static ?int $navigationSort = 31;

    /** @var Snapshot|null */
    protected ?array $snapshot = null;

    protected ?GitHubSourceException $snapshotError = null;

    protected bool $snapshotLoaded = false;

    /** @var array<int, InstalledPlugin>|null */
    protected ?array $installed = null;

    protected ?GitHubSourceException $installedError = null;

    /** @var array<int, string>|null */
    protected ?array $jarNames = null;

    protected ?GitHubSourceException $jarNamesError = null;

    /** @var array<int, string>|null */
    protected ?array $dismissed = null;

    protected ?GitHubSourceException $dismissedError = null;

    /** @var array<string, array<string, mixed>>|null */
    protected ?array $allRows = null;

    /** Also list the plugins dismissed for this server. */
    public bool $showDismissed = false;

    protected static function service(): GitHubPluginService
    {
        return app(GitHubPluginService::class);
    }

    protected static function offers(): GitHubOffers
    {
        return app(GitHubOffers::class);
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
        return trans('minecraft-modrinth::strings.github.page.navigation');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    /**
     * @return array{label: string, color: string, new: int, updates: int}|null
     */
    protected static function navigationBadge(): ?array
    {
        // Only the cached counts (hourly check / last page visit): the menu is rendered on every
        // page, so this must never ask GitHub or Wings. No cache, no badge.
        return GitHubSourceRules::navigationBadge(static::offers()->cachedCounts(static::server()));
    }

    public static function getNavigationBadge(): ?string
    {
        return static::navigationBadge()['label'] ?? null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return static::navigationBadge()['color'] ?? null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        $badge = static::navigationBadge();

        return $badge === null ? null : trans('minecraft-modrinth::strings.github.offers.badge_tooltip', [
            'new' => $badge['new'],
            'updates' => $badge['updates'],
        ]);
    }

    /** @return Snapshot|null */
    protected function getSnapshot(): ?array
    {
        if (!$this->snapshotLoaded) {
            $this->snapshotLoaded = true;

            try {
                $this->snapshot = static::service()->getSnapshot();
            } catch (GitHubSourceException $exception) {
                $this->snapshotError = $exception;
            }
        }

        return $this->snapshot;
    }

    /** @return array<int, InstalledPlugin> */
    protected function getInstalled(): array
    {
        if ($this->installed === null) {
            try {
                $this->installed = static::service()->getInstalled(static::server());
            } catch (GitHubSourceException $exception) {
                $this->installedError = $exception;
                $this->installed = [];
            }
        }

        return $this->installed;
    }

    /** @return array<int, string> jar names in plugins/ */
    protected function getJarNames(): array
    {
        if ($this->jarNames === null) {
            try {
                $this->jarNames = static::service()->listJarNames(static::server());
            } catch (GitHubSourceException $exception) {
                $this->jarNamesError = $exception;
                $this->jarNames = [];
            }
        }

        return $this->jarNames;
    }

    /** @return array<int, string> ids of the plugins dismissed for this server */
    protected function getDismissed(): array
    {
        if ($this->dismissed === null) {
            try {
                $this->dismissed = static::offers()->dismissedIds(static::server());
            } catch (GitHubSourceException $exception) {
                $this->dismissedError = $exception;
                $this->dismissed = [];
            }
        }

        return $this->dismissed;
    }

    protected function forgetState(bool $refresh = true): void
    {
        $this->snapshot = null;
        $this->snapshotError = null;
        $this->snapshotLoaded = false;
        $this->installed = null;
        $this->installedError = null;
        $this->jarNames = null;
        $this->jarNamesError = null;
        $this->dismissed = null;
        $this->dismissedError = null;
        $this->allRows = null;

        if ($refresh) {
            $this->js('$wire.$refresh()');
        }
    }

    /**
     * One row per plugin of this server's platform in the index, plus every installed plugin
     * that is no longer in it (so it can still be removed). Offered plugins (new, then updates)
     * come first; plugins dismissed for this server only with "Show hidden".
     *
     * @return array<string, array<string, mixed>>
     */
    protected function getRows(): array
    {
        $rows = $this->getAllRows();

        if (!$this->showDismissed) {
            $rows = array_filter($rows, fn (array $row) => $row['state'] !== 'dismissed');
        }

        return $rows;
    }

    /** @return array<string, array<string, mixed>> */
    protected function getAllRows(): array
    {
        return $this->allRows ??= $this->buildRows();
    }

    /** @return array<string, array<string, mixed>> */
    protected function buildRows(): array
    {
        $server = static::server();
        $platform = static::service()->platformForServer($server);
        $snapshot = $this->getSnapshot();
        $installed = $this->getInstalled();
        $jarNames = $this->getJarNames();
        $dismissed = $this->getDismissed();

        // Without the installed list or the directory listing we can't tell what is new.
        $knowsServerState = $this->installedError === null && $this->jarNamesError === null;
        $managedFilenames = array_column($installed, 'filename');

        $rows = [];

        foreach ($snapshot ? static::service()->pluginsForPlatform($snapshot['plugins'], $platform) : [] as $plugin) {
            $entry = static::service()->findInstalled($installed, $plugin['id']);

            $state = $entry === null && !$knowsServerState
                ? 'not_installed'
                : GitHubSourceRules::pluginState(
                    $plugin,
                    $entry,
                    in_array($plugin['id'], $dismissed, true),
                    $entry === null && GitHubSourceRules::hasUnmanagedCopy($plugin, $jarNames, $managedFilenames),
                );

            $rows[$plugin['id']] = [
                '__key' => $plugin['id'],
                'id' => $plugin['id'],
                'name' => $plugin['name'],
                'frozen' => $plugin['frozen'],
                'repo_version' => $plugin['version'],
                'installed_version' => $entry['version'] ?? null,
                'filename' => $entry['filename'] ?? $plugin['filename'],
                'state' => $state,
            ];
        }

        foreach ($installed as $entry) {
            if (isset($rows[$entry['id']]) || ($snapshot === null && $entry['platform'] !== $platform)) {
                continue;
            }

            $rows[$entry['id']] = [
                '__key' => $entry['id'],
                'id' => $entry['id'],
                'name' => $entry['name'],
                'frozen' => false,
                'repo_version' => null,
                'installed_version' => $entry['version'],
                'filename' => $entry['filename'],
                // Without a snapshot we simply don't know what the repository has.
                'state' => $snapshot === null ? 'unknown' : 'removed',
            ];
        }

        $priority = fn (array $row) => match ($row['state']) {
            'new' => 0,
            'update_available' => 1,
            default => 2,
        };

        uasort($rows, fn (array $a, array $b) => [$priority($a), strtolower($a['name'])] <=> [$priority($b), strtolower($b['name'])]);

        // Keep the navigation badge in step with what this page shows.
        if ($snapshot !== null && $platform !== null && $knowsServerState && $this->dismissedError === null) {
            static::offers()->rememberCounts($server, GitHubSourceRules::offers($snapshot['plugins'], $platform, $installed, $dismissed, $jarNames));
        }

        return $rows;
    }

    /** @return array{new: int, dismissed: int} */
    protected function countOffered(): array
    {
        $states = array_column($this->getAllRows(), 'state');

        return [
            'new' => count(array_keys($states, 'new', true)),
            'dismissed' => count(array_keys($states, 'dismissed', true)),
        ];
    }

    protected function ciAllowsInstall(): bool
    {
        $snapshot = $this->getSnapshot();

        return $snapshot !== null && (!static::service()->requiresGreenCi() || $snapshot['ci'] === CiStatus::Green);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => $this->getRows())
            ->paginated(false)
            ->emptyStateHeading(fn () => $this->snapshotError
                ? trans('minecraft-modrinth::strings.github.page.unavailable')
                : trans('minecraft-modrinth::strings.github.page.empty'))
            ->emptyStateDescription(fn () => $this->snapshotError?->getUserMessage())
            ->columns([
                TextColumn::make('name')
                    ->label(trans('minecraft-modrinth::strings.github.table.name'))
                    ->description(fn (array $record) => $record['id']),
                TextColumn::make('installed_version')
                    ->label(trans('minecraft-modrinth::strings.github.table.installed_version'))
                    ->placeholder('-')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('repo_version')
                    ->label(trans('minecraft-modrinth::strings.github.table.repo_version'))
                    ->placeholder('-')
                    ->badge()
                    ->color('info'),
                TextColumn::make('state')
                    ->label(trans('minecraft-modrinth::strings.github.table.state'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => trans('minecraft-modrinth::strings.github.state.'.$state))
                    ->icon(fn (string $state) => match ($state) {
                        'new' => 'tabler-sparkles',
                        'dismissed' => 'tabler-eye-off',
                        default => null,
                    })
                    ->tooltip(fn (string $state) => in_array($state, ['new', 'dismissed', 'manual'], true)
                        ? trans('minecraft-modrinth::strings.github.state.'.$state.'_hint')
                        : null)
                    ->color(fn (string $state) => match ($state) {
                        'up_to_date' => 'success',
                        'update_available' => 'warning',
                        'new' => 'info',
                        'modified', 'repo_older', 'removed' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('frozen')
                    ->label(trans('minecraft-modrinth::strings.github.table.frozen'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (bool $state) => $state ? trans('minecraft-modrinth::strings.github.table.frozen_yes') : '')
                    ->tooltip(fn (array $record) => $record['frozen'] ? trans('minecraft-modrinth::strings.github.table.frozen_hint') : null)
                    ->toggleable(),
            ])
            ->recordActions([
                Action::make('install')
                    ->iconButton()
                    ->icon('tabler-download')
                    ->color('success')
                    ->tooltip(fn () => $this->ciAllowsInstall()
                        ? trans('minecraft-modrinth::strings.actions.install')
                        : trans('minecraft-modrinth::strings.github.page.ci_blocks_install'))
                    ->visible(fn (array $record) => in_array($record['state'], ['new', 'not_installed', 'dismissed'], true))
                    ->disabled(fn () => !$this->ciAllowsInstall())
                    ->authorize(fn () => static::userCan(SubuserPermission::FileCreate))
                    ->requiresConfirmation()
                    ->modalHeading(trans('minecraft-modrinth::strings.github.modals.install_heading'))
                    ->modalDescription(fn (array $record) => trans('minecraft-modrinth::strings.github.modals.install_description', [
                        'name' => $record['name'],
                        'version' => $record['repo_version'],
                    ]))
                    ->action(fn (array $record) => $this->install($record['id'], false)),
                Action::make('update')
                    ->iconButton()
                    ->icon('tabler-refresh')
                    ->color('warning')
                    ->tooltip(fn () => $this->ciAllowsInstall()
                        ? trans('minecraft-modrinth::strings.actions.update')
                        : trans('minecraft-modrinth::strings.github.page.ci_blocks_install'))
                    ->visible(fn (array $record) => $record['state'] === 'update_available')
                    ->disabled(fn () => !$this->ciAllowsInstall())
                    ->authorize(fn () => static::userCan(SubuserPermission::FileCreate, SubuserPermission::FileDelete))
                    ->requiresConfirmation()
                    ->modalHeading(trans('minecraft-modrinth::strings.github.modals.update_heading'))
                    ->modalDescription(fn (array $record) => trans('minecraft-modrinth::strings.github.modals.update_description', [
                        'name' => $record['name'],
                        'old_version' => $record['installed_version'],
                        'new_version' => $record['repo_version'],
                    ]))
                    ->action(fn (array $record) => $this->install($record['id'], true)),
                Action::make('dismiss')
                    ->iconButton()
                    ->icon('tabler-eye-off')
                    ->color('gray')
                    ->tooltip(trans('minecraft-modrinth::strings.github.offers.dismiss'))
                    ->visible(fn (array $record) => $record['state'] === 'new')
                    ->authorize(fn () => static::userCan(SubuserPermission::FileCreate))
                    ->requiresConfirmation()
                    ->modalHeading(trans('minecraft-modrinth::strings.github.offers.dismiss_heading'))
                    ->modalDescription(fn (array $record) => trans('minecraft-modrinth::strings.github.offers.dismiss_description', ['name' => $record['name']]))
                    ->action(fn (array $record) => $this->setDismissed($record['id'], $record['name'], true)),
                Action::make('restore')
                    ->iconButton()
                    ->icon('tabler-eye')
                    ->color('info')
                    ->tooltip(trans('minecraft-modrinth::strings.github.offers.restore'))
                    ->visible(fn (array $record) => $record['state'] === 'dismissed')
                    ->authorize(fn () => static::userCan(SubuserPermission::FileCreate))
                    ->action(fn (array $record) => $this->setDismissed($record['id'], $record['name'], false)),
                Action::make('installed')
                    ->iconButton()
                    ->icon('tabler-check')
                    ->color('success')
                    ->tooltip(trans('minecraft-modrinth::strings.actions.installed'))
                    ->disabled()
                    ->visible(fn (array $record) => $record['state'] === 'up_to_date'),
                Action::make('uninstall')
                    ->iconButton()
                    ->icon('tabler-trash')
                    ->color('danger')
                    ->tooltip(trans('minecraft-modrinth::strings.actions.uninstall'))
                    ->visible(fn (array $record) => $record['installed_version'] !== null)
                    ->authorize(fn () => static::userCan(SubuserPermission::FileDelete))
                    ->requiresConfirmation()
                    ->modalHeading(trans('minecraft-modrinth::strings.github.modals.uninstall_heading'))
                    ->modalDescription(fn (array $record) => trans('minecraft-modrinth::strings.github.modals.uninstall_description', [
                        'name' => $record['name'],
                        'filename' => $record['filename'],
                    ]))
                    ->action(fn (array $record) => $this->uninstall($record['id'], $record['name'])),
            ]);
    }

    protected function install(string $id, bool $isUpdate): void
    {
        $server = static::server();

        try {
            // Always the current state, never a stale page: the confirmation may have been open
            // for a while, and the CI rule has to hold for exactly what gets installed.
            static::service()->forgetCachedState();
            $this->forgetState(false);

            $snapshot = $this->getSnapshot();
            if ($snapshot === null) {
                throw $this->snapshotError ?? new GitHubSourceException(GitHubSourceException::NOT_CONFIGURED, 'No repository state');
            }

            $plugin = null;
            foreach ($snapshot['plugins'] as $candidate) {
                if ($candidate['id'] === $id) {
                    $plugin = $candidate;
                }
            }

            if ($plugin === null) {
                throw new GitHubSourceException(GitHubSourceException::INVALID_INDEX, "$id is not in the index at {$snapshot['sha']}");
            }

            $entry = static::service()->installOrUpdate($server, $plugin, $snapshot, static::service()->requiresGreenCi());

            Notification::make()
                ->title(trans($isUpdate ? 'minecraft-modrinth::strings.notifications.update_success' : 'minecraft-modrinth::strings.notifications.install_success'))
                ->body(trans('minecraft-modrinth::strings.github.notifications.installed_body', [
                    'name' => $entry['name'],
                    'version' => $entry['version'],
                ]))
                ->success()
                ->send();
        } catch (GitHubSourceException $exception) {
            static::service()->reportOncePerWindow("ui:{$server->id}:$id:{$exception->reason}", $exception);

            Notification::make()
                ->title(trans($isUpdate ? 'minecraft-modrinth::strings.notifications.update_failed' : 'minecraft-modrinth::strings.notifications.install_failed'))
                ->body($exception->getUserMessage())
                ->danger()
                ->send();
        }

        $this->forgetState();
    }

    /** "Doesn't belong on this server" (hide the offer) or "Offer again". */
    protected function setDismissed(string $id, string $name, bool $dismissed): void
    {
        $server = static::server();

        try {
            $dismissed ? static::offers()->dismiss($server, $id) : static::offers()->restore($server, $id);

            Notification::make()
                ->title(trans($dismissed ? 'minecraft-modrinth::strings.github.offers.dismissed_title' : 'minecraft-modrinth::strings.github.offers.restored_title'))
                ->body(trans($dismissed ? 'minecraft-modrinth::strings.github.offers.dismissed_body' : 'minecraft-modrinth::strings.github.offers.restored_body', ['name' => $name]))
                ->success()
                ->send();
        } catch (GitHubSourceException $exception) {
            static::service()->reportOncePerWindow("ui:{$server->id}:offers:{$exception->reason}", $exception);

            Notification::make()
                ->title(trans('minecraft-modrinth::strings.github.offers.store_failed'))
                ->body($exception->getUserMessage())
                ->danger()
                ->send();
        }

        $this->forgetState();
    }

    protected function uninstall(string $id, string $name): void
    {
        $server = static::server();

        try {
            static::service()->uninstall($server, $id);

            Notification::make()
                ->title(trans('minecraft-modrinth::strings.notifications.uninstall_success'))
                ->body(trans('minecraft-modrinth::strings.github.notifications.uninstalled_body', ['name' => $name]))
                ->success()
                ->send();
        } catch (GitHubSourceException $exception) {
            static::service()->reportOncePerWindow("ui:{$server->id}:$id:uninstall:{$exception->reason}", $exception);

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
            Action::make('toggle_dismissed')
                ->label(fn () => $this->showDismissed
                    ? trans('minecraft-modrinth::strings.github.offers.hide_dismissed')
                    : trans('minecraft-modrinth::strings.github.offers.show_dismissed', ['count' => $this->countOffered()['dismissed']]))
                ->icon(fn () => $this->showDismissed ? 'tabler-eye-off' : 'tabler-eye')
                ->color('gray')
                ->visible(fn () => $this->showDismissed || $this->countOffered()['dismissed'] > 0)
                ->action(function () {
                    $this->showDismissed = !$this->showDismissed;
                    $this->resetTable();
                }),
            Action::make('refresh')
                ->tooltip(trans('minecraft-modrinth::strings.github.page.refresh'))
                ->icon('tabler-reload')
                ->color('gray')
                ->action(function () {
                    static::service()->forgetCachedState();
                    $this->forgetState();
                }),
            Action::make('open_folder')
                ->tooltip(fn () => trans('minecraft-modrinth::strings.page.open_folder', ['folder' => GitHubPluginService::FOLDER]))
                ->icon('tabler-folder-open')
                ->url(fn () => ListFiles::getUrl(['path' => GitHubPluginService::FOLDER]), true),
        ];
    }

    public function content(Schema $schema): Schema
    {
        $platform = static::service()->platformForServer(static::server());

        return $schema
            ->components([
                Grid::make(4)
                    ->schema([
                        TextEntry::make('platform')
                            ->label(trans('minecraft-modrinth::strings.github.page.platform'))
                            ->state(fn () => $platform ? ucfirst($platform) : trans('minecraft-modrinth::strings.page.unknown'))
                            ->badge(),
                        TextEntry::make('repository')
                            ->label(trans('minecraft-modrinth::strings.github.page.repository'))
                            ->state(fn () => static::service()->repository().' @ '.static::service()->branch())
                            ->url(fn () => GitHubSourceRules::isValidRepository(static::service()->repository())
                                ? 'https://github.com/'.static::service()->repository().'/tree/'.static::service()->branch()
                                : null, true),
                        TextEntry::make('commit')
                            ->label(trans('minecraft-modrinth::strings.github.page.commit'))
                            ->state(fn () => ($sha = $this->getSnapshot()['sha'] ?? null) ? substr($sha, 0, 7) : trans('minecraft-modrinth::strings.page.unknown'))
                            ->url(fn () => ($sha = $this->getSnapshot()['sha'] ?? null) ? static::service()->commitUrl($sha) : null, true)
                            ->fontFamily('mono'),
                        TextEntry::make('ci')
                            ->label(trans('minecraft-modrinth::strings.github.page.ci'))
                            ->state(fn () => ($this->getSnapshot()['ci'] ?? CiStatus::Unverified)->getLabel())
                            ->color(fn () => ($this->getSnapshot()['ci'] ?? CiStatus::Unverified)->getColor())
                            ->icon(fn () => ($this->getSnapshot()['ci'] ?? CiStatus::Unverified)->getIcon())
                            ->url(fn () => ($sha = $this->getSnapshot()['sha'] ?? null) ? static::service()->commitUrl($sha).'/checks' : null, true)
                            ->badge(),
                    ]),
                Callout::make(trans('minecraft-modrinth::strings.github.page.restart_notice_heading'))
                    ->description(trans('minecraft-modrinth::strings.github.page.restart_notice'))
                    ->info(),
                Callout::make(trans('minecraft-modrinth::strings.github.page.unavailable'))
                    ->description(fn () => $this->snapshotError?->getUserMessage())
                    ->danger()
                    ->visible(fn () => $this->getSnapshot() === null && $this->snapshotError !== null),
                Callout::make(trans('minecraft-modrinth::strings.github.page.metadata_error'))
                    ->description(fn () => $this->installedError?->getUserMessage())
                    ->danger()
                    ->visible(function () {
                        $this->getInstalled();

                        return $this->installedError !== null;
                    }),
                Callout::make(fn () => trans_choice('minecraft-modrinth::strings.github.offers.callout_heading', $this->countOffered()['new'], ['count' => $this->countOffered()['new']]))
                    ->description(trans('minecraft-modrinth::strings.github.offers.callout_description'))
                    ->info()
                    ->icon('tabler-sparkles')
                    ->visible(fn () => $this->countOffered()['new'] > 0),
                Callout::make(trans('minecraft-modrinth::strings.github.offers.store_missing'))
                    ->description(fn () => $this->dismissedError?->getUserMessage())
                    ->warning()
                    ->visible(function () {
                        $this->getDismissed();

                        return $this->dismissedError !== null;
                    }),
                Callout::make(trans('minecraft-modrinth::strings.github.page.ci_not_green'))
                    ->description(trans('minecraft-modrinth::strings.github.page.ci_not_green_description'))
                    ->warning()
                    ->visible(fn () => $this->getSnapshot() !== null && !$this->ciAllowsInstall()),
                EmbeddedTable::make(),
            ]);
    }
}
