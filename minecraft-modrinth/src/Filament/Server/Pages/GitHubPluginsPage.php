<?php

namespace Boy132\MinecraftModrinth\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Filament\Server\Resources\Files\Pages\ListFiles;
use App\Models\Server;
use App\Traits\Filament\BlockAccessInConflict;
use Boy132\MinecraftModrinth\GitHub\CiStatus;
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
 * for the platform of this server: install, update and remove them.
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

    protected static function service(): GitHubPluginService
    {
        return app(GitHubPluginService::class);
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

    protected function forgetState(bool $refresh = true): void
    {
        $this->snapshot = null;
        $this->snapshotError = null;
        $this->snapshotLoaded = false;
        $this->installed = null;
        $this->installedError = null;

        if ($refresh) {
            $this->js('$wire.$refresh()');
        }
    }

    /**
     * One row per plugin of this server's platform in the index, plus every installed plugin
     * that is no longer in it (so it can still be removed).
     *
     * @return array<string, array<string, mixed>>
     */
    protected function getRows(): array
    {
        $server = static::server();
        $platform = static::service()->platformForServer($server);
        $snapshot = $this->getSnapshot();
        $installed = $this->getInstalled();

        $rows = [];

        foreach ($snapshot ? static::service()->pluginsForPlatform($snapshot['plugins'], (string) $platform) : [] as $plugin) {
            $entry = static::service()->findInstalled($installed, $plugin['id']);

            $rows[$plugin['id']] = [
                '__key' => $plugin['id'],
                'id' => $plugin['id'],
                'name' => $plugin['name'],
                'frozen' => $plugin['frozen'],
                'repo_version' => $plugin['version'],
                'installed_version' => $entry['version'] ?? null,
                'filename' => $entry['filename'] ?? $plugin['filename'],
                'state' => $this->rowState($plugin, $entry),
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

        uasort($rows, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * @param  array{version: string, sha256: string}  $plugin
     * @param  array{version: string, sha256: string}|null  $entry
     */
    protected function rowState(array $plugin, ?array $entry): string
    {
        if ($entry === null) {
            return 'not_installed';
        }

        if (GitHubSourceRules::isNewerVersion($plugin['version'], $entry['version'])) {
            return 'update_available';
        }

        if (!GitHubSourceRules::isValidVersion($entry['version']) || GitHubSourceRules::compareVersions($plugin['version'], $entry['version']) < 0) {
            return 'repo_older';
        }

        return $entry['sha256'] === $plugin['sha256'] ? 'up_to_date' : 'modified';
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
                    ->color(fn (string $state) => match ($state) {
                        'up_to_date' => 'success',
                        'update_available' => 'warning',
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
                    ->visible(fn (array $record) => $record['state'] === 'not_installed')
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
                Callout::make(trans('minecraft-modrinth::strings.github.page.ci_not_green'))
                    ->description(trans('minecraft-modrinth::strings.github.page.ci_not_green_description'))
                    ->warning()
                    ->visible(fn () => $this->getSnapshot() !== null && !$this->ciAllowsInstall()),
                EmbeddedTable::make(),
            ]);
    }
}
