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
use Illuminate\Support\Facades\RateLimiter;

/**
 * Plugins from the configured GitHub repository (see README "GitHub repository source"),
 * for the platform of this server: install, update and remove them. Plugins that aren't
 * installed yet are offered ("New") until an admin installs them or says they don't belong on
 * this server (see README "New plugins"). A plugin whose jar was put into plugins/ by hand is
 * offered for replacement with the repository version while "Replace manual copies" is on.
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

    /** @var array<int, string> old jars recorded for deletion */
    protected array $pendingDeletes = [];

    /** @var array<int, array{filename: string, size: int, replaced_by?: string}> the same, as recorded */
    protected array $pendingDeleteEntries = [];

    /** Forced GitHub refreshes: at most one per server in this many seconds ... */
    protected const REFRESH_PER_SERVER_SECONDS = 30;

    /** ... and at most this many per minute for the whole panel (they all use one token). */
    protected const REFRESH_GLOBAL_PER_MINUTE = 10;

    protected ?GitHubSourceException $installedError = null;

    /** @var array<int, array<string, mixed>>|null listing of plugins/ */
    protected ?array $files = null;

    protected ?GitHubSourceException $jarNamesError = null;

    /** @var array<string, array<int, string>>|null manual copies to replace, by plugin id */
    protected ?array $replacements = null;

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
                $metadata = static::service()->readMetadata(static::server());
                $this->installed = $metadata['installed'];
                $this->pendingDeleteEntries = $metadata['pending_delete'];
                $this->pendingDeletes = array_column($metadata['pending_delete'], 'filename');
            } catch (GitHubSourceException $exception) {
                $this->installedError = $exception;
                $this->installed = [];
                $this->pendingDeleteEntries = [];
                $this->pendingDeletes = [];
            }
        }

        return $this->installed;
    }

    /** @return array<int, array<string, mixed>> listing of plugins/ */
    protected function getFiles(): array
    {
        if ($this->files === null) {
            try {
                $this->files = static::service()->listPluginFiles(static::server());
            } catch (GitHubSourceException $exception) {
                $this->jarNamesError = $exception;
                $this->files = [];
            }
        }

        return $this->files;
    }

    /** @return array<int, string> jar names in plugins/ */
    protected function getJarNames(): array
    {
        return static::service()->jarNamesOf($this->getFiles());
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
        $this->pendingDeletes = [];
        $this->pendingDeleteEntries = [];
        $this->files = null;
        $this->jarNamesError = null;
        $this->replacements = null;
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
        $managedFilenames = [...array_column($installed, 'filename'), ...$this->pendingDeletes];
        $platformPlugins = $snapshot ? static::service()->pluginsForPlatform($snapshot['plugins'], $platform) : [];

        // Manual copies to replace - only when it's known what is installed and what is hidden.
        $this->replacements = $knowsServerState && $this->dismissedError === null && $platformPlugins !== []
            ? static::service()->manualReplacements($server, $platformPlugins, ['installed' => $installed, 'pending_delete' => $this->pendingDeleteEntries], $this->getFiles(), $dismissed)
            : [];

        $rows = [];

        foreach ($platformPlugins as $plugin) {
            $entry = static::service()->findInstalled($installed, $plugin['id']);
            $copies = $entry === null && $knowsServerState ? GitHubSourceRules::unmanagedCopies($plugin, $jarNames, $managedFilenames) : [];

            $state = $entry === null && !$knowsServerState
                ? 'not_installed'
                : GitHubSourceRules::pluginState(
                    $plugin,
                    $entry,
                    in_array($plugin['id'], $dismissed, true),
                    $copies !== [],
                    isset($this->replacements[$plugin['id']]),
                );

            $manualVersions = array_values(array_unique(array_filter(array_map(fn (string $name) => GitHubSourceRules::versionFromJarName($name), $copies))));

            $rows[$plugin['id']] = [
                '__key' => $plugin['id'],
                'id' => $plugin['id'],
                'name' => $plugin['name'],
                'frozen' => $plugin['frozen'],
                'repo_version' => $plugin['version'],
                'installed_version' => $entry['version'] ?? null,
                // A manual copy: its file name(s) and, if the name shows it, its version.
                'manual_files' => $copies,
                'manual_version' => $manualVersions !== [] ? implode(', ', $manualVersions) : null,
                'filename' => $entry['filename'] ?? $plugin['filename'],
                'state' => $state,
                'dismissed' => in_array($plugin['id'], $dismissed, true),
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
                'manual_files' => [],
                'manual_version' => null,
                'dismissed' => false,
                'filename' => $entry['filename'],
                // Without a snapshot we simply don't know what the repository has.
                'state' => $snapshot === null ? 'unknown' : 'removed',
            ];
        }

        $priority = fn (array $row) => match ($row['state']) {
            'new' => 0,
            'update_available', 'replace_manual' => 1,
            default => 2,
        };

        uasort($rows, fn (array $a, array $b) => [$priority($a), strtolower($a['name'])] <=> [$priority($b), strtolower($b['name'])]);

        // Keep the navigation badge in step with what this page shows.
        if ($snapshot !== null && $platform !== null && $knowsServerState && $this->dismissedError === null) {
            static::offers()->rememberCounts($server, GitHubSourceRules::offers($snapshot['plugins'], $platform, $installed, $dismissed, $jarNames, $this->pendingDeletes, array_keys($this->replacements)));
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

    /** Installed plugins with an update, plus manual copies that will be replaced. */
    protected function countUpdateAvailable(): int
    {
        return count(array_filter(array_column($this->getAllRows(), 'state'), fn (string $state) => in_array($state, ['update_available', 'replace_manual'], true)));
    }

    /** @return array<int, array<string, mixed>> rows whose manual copies will be replaced */
    protected function replaceRows(): array
    {
        return array_values(array_filter($this->getAllRows(), fn (array $row) => $row['state'] === 'replace_manual'));
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
                    // A manual copy shows its version from the file name, marked as such.
                    ->state(fn (array $record) => $record['installed_version']
                        ?? (in_array($record['state'], ['manual', 'replace_manual'], true)
                            ? trans('minecraft-modrinth::strings.github.table.manual_version', ['version' => $record['manual_version'] ?? '?'])
                            : null))
                    ->placeholder('-')
                    ->badge()
                    ->color(fn (array $record) => $record['installed_version'] === null && $record['state'] === 'replace_manual' ? 'warning' : 'gray'),
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
                        'replace_manual' => 'tabler-replace',
                        default => null,
                    })
                    ->tooltip(fn (array $record) => match ($record['state']) {
                        'new', 'dismissed' => trans('minecraft-modrinth::strings.github.state.'.$record['state'].'_hint'),
                        'replace_manual' => trans('minecraft-modrinth::strings.github.state.replace_manual_hint', ['files' => implode(', ', $record['manual_files'])]),
                        // With replacing on, a copy that stays "manual" is one that can't be replaced automatically.
                        'manual' => trans(static::service()->replacesManualCopies() && !in_array($record['id'], $this->getDismissed(), true)
                            ? 'minecraft-modrinth::strings.github.state.manual_not_replaceable_hint'
                            : 'minecraft-modrinth::strings.github.state.manual_hint', ['files' => implode(', ', $record['manual_files'])]),
                        default => null,
                    })
                    ->color(fn (string $state) => match ($state) {
                        'up_to_date' => 'success',
                        'update_available', 'replace_manual' => 'warning',
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
                Action::make('replace')
                    ->iconButton()
                    ->icon('tabler-replace')
                    ->color('warning')
                    ->tooltip(fn () => $this->ciAllowsInstall()
                        ? trans('minecraft-modrinth::strings.github.actions.replace')
                        : trans('minecraft-modrinth::strings.github.page.ci_blocks_install'))
                    ->visible(fn (array $record) => $record['state'] === 'replace_manual')
                    ->disabled(fn () => !$this->ciAllowsInstall())
                    ->authorize(fn () => static::userCan(SubuserPermission::FileCreate, SubuserPermission::FileDelete))
                    ->requiresConfirmation()
                    ->modalHeading(trans('minecraft-modrinth::strings.github.modals.replace_heading'))
                    ->modalDescription(fn (array $record) => trans('minecraft-modrinth::strings.github.modals.replace_description', [
                        'name' => $record['name'],
                        'files' => implode(', ', $record['manual_files']),
                        'old_version' => $record['manual_version'] ?? '?',
                        'new_version' => $record['repo_version'],
                    ]))
                    ->action(fn (array $record) => $this->install($record['id'], false, true)),
                Action::make('dismiss')
                    ->iconButton()
                    ->icon('tabler-eye-off')
                    ->color('gray')
                    ->tooltip(trans('minecraft-modrinth::strings.github.offers.dismiss'))
                    // Also for a manual copy that would be replaced: hiding it keeps the manual copy.
                    ->visible(fn (array $record) => in_array($record['state'], ['new', 'replace_manual'], true))
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
                    ->visible(fn (array $record) => $record['state'] === 'dismissed' || ($record['state'] === 'manual' && $record['dismissed']))
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

    /**
     * Whether a forced refresh from GitHub is allowed now: at most once per server every
     * REFRESH_PER_SERVER_SECONDS and REFRESH_GLOBAL_PER_MINUTE times a minute in total, so
     * clicking around can't use up the token's rate limit. Counts the attempt when allowed.
     */
    protected function mayRefreshFromGitHub(): bool
    {
        $serverKey = 'minecraft-modrinth:github:refresh:server:'.static::server()->id;
        $globalKey = 'minecraft-modrinth:github:refresh:global';

        if (RateLimiter::tooManyAttempts($serverKey, 1) || RateLimiter::tooManyAttempts($globalKey, self::REFRESH_GLOBAL_PER_MINUTE)) {
            return false;
        }

        RateLimiter::hit($serverKey, self::REFRESH_PER_SERVER_SECONDS);
        RateLimiter::hit($globalKey, 60);

        return true;
    }

    protected function refreshWaitSeconds(): int
    {
        return max(
            RateLimiter::availableIn('minecraft-modrinth:github:refresh:server:'.static::server()->id),
            RateLimiter::tooManyAttempts('minecraft-modrinth:github:refresh:global', self::REFRESH_GLOBAL_PER_MINUTE)
                ? RateLimiter::availableIn('minecraft-modrinth:github:refresh:global')
                : 0,
            1,
        );
    }

    protected function install(string $id, bool $isUpdate, bool $replacesManual = false): void
    {
        $result = $this->attemptInstall($id, $isUpdate, $replacesManual);

        if ($result['ok']) {
            $body = trans('minecraft-modrinth::strings.github.notifications.installed_body', [
                'name' => e($result['name']),
                'version' => e($result['version']),
            ]);

            if ($result['replaced'] !== []) {
                $body .= ' '.trans('minecraft-modrinth::strings.github.notifications.replaced_body', ['files' => e(implode(', ', $result['replaced']))]);
            }

            Notification::make()
                ->title(trans($replacesManual
                    ? 'minecraft-modrinth::strings.github.notifications.replaced_title'
                    : ($isUpdate ? 'minecraft-modrinth::strings.notifications.update_success' : 'minecraft-modrinth::strings.notifications.install_success')))
                ->body($body)
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title(trans($result['partial']
                    ? 'minecraft-modrinth::strings.github.notifications.old_jar_pending_title'
                    : ($replacesManual
                        ? 'minecraft-modrinth::strings.github.notifications.replace_failed'
                        : ($isUpdate ? 'minecraft-modrinth::strings.notifications.update_failed' : 'minecraft-modrinth::strings.notifications.install_failed'))))
                ->body($result['message'])
                ->status($result['partial'] ? 'warning' : 'danger')
                ->persistent()
                ->send();
        }

        $this->forgetState();
    }

    /**
     * Core of install()/updateAll(): installs or updates one plugin and reports what happened,
     * without sending a notification itself - install() sends one immediately, updateAll() folds
     * several of these into a single summary notification instead.
     *
     * An update (and a replacement of a manual copy) also replaces manual copies of the plugin
     * while "Replace manual copies" is on; a plain install doesn't (the user saw no copy).
     *
     * @return array{ok: bool, name: ?string, version: ?string, message: ?string, partial: bool, replaced: array<int, string>}
     */
    protected function attemptInstall(string $id, bool $isUpdate, bool $replacesManual = false): array
    {
        $server = static::server();
        $deletes = $isUpdate || $replacesManual;

        // Also checked here: the action's own authorization is the first line, not the only one.
        if (!static::userCan(...($deletes ? [SubuserPermission::FileCreate, SubuserPermission::FileDelete] : [SubuserPermission::FileCreate]))) {
            abort(403);
        }

        try {
            // The current state whenever the rate limit allows it: the confirmation may have been
            // open for a while. Otherwise the cached state (head <= 5 min, CI <= 10 min) is used -
            // the CI rule still holds for exactly the commit that gets installed. In updateAll()'s
            // loop, only the first plugin can actually trigger a fresh GitHub read per the
            // per-server rate limit (see mayRefreshFromGitHub()); the rest reuse that same read
            // from the service's own cache, not a stale one from before the loop started.
            if ($this->mayRefreshFromGitHub()) {
                static::service()->forgetCachedState();
            }
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

            $replaced = [];
            $entry = static::service()->installOrUpdate($server, $plugin, $snapshot, static::service()->requiresGreenCi(), $isUpdate, $deletes, $replaced);

            return [
                'ok' => true,
                'name' => $entry['name'],
                'version' => $entry['version'],
                'message' => null,
                'partial' => false,
                'replaced' => $replaced ?? [],
            ];
        } catch (GitHubSourceException $exception) {
            static::service()->reportOncePerWindow("ui:{$server->id}:$id:{$exception->reason}", $exception);

            return [
                'ok' => false,
                'name' => null,
                'version' => null,
                'message' => $exception->getUserMessage(),
                'partial' => $exception->reason === GitHubSourceException::OLD_JAR_PENDING,
                'replaced' => [],
            ];
        }
    }

    /**
     * Updates every plugin currently showing "update_available" on this page in one action (and
     * replaces every manual copy showing "replace_manual"),
     * instead of clicking each plugin's own update button in turn. Each is still updated
     * independently through attemptInstall() - one failing (a checksum mismatch, a file
     * conflict, ...) never stops the rest - and the page ends up in exactly the state it would
     * if every update had been clicked one at a time. The only difference from doing that by hand
     * is the notification: one summary here instead of one toast per plugin, which would otherwise
     * stack up for a server with several updates pending.
     */
    protected function updateAll(): void
    {
        if (!static::userCan(SubuserPermission::FileCreate, SubuserPermission::FileDelete)) {
            abort(403);
        }

        $candidates = array_values(array_filter($this->getRows(), fn (array $row) => in_array($row['state'], ['update_available', 'replace_manual'], true)));

        if (empty($candidates)) {
            $this->forgetState();

            return;
        }

        $succeededCount = 0;
        $failures = [];

        foreach ($candidates as $row) {
            $result = $row['state'] === 'replace_manual'
                ? $this->attemptInstall($row['id'], false, true)
                : $this->attemptInstall($row['id'], true);

            if ($result['ok']) {
                $succeededCount++;
            } else {
                $failures[] = "{$row['name']}: {$result['message']}";
            }
        }

        if (empty($failures)) {
            Notification::make()
                ->title(trans_choice('minecraft-modrinth::strings.github.notifications.update_all_success', $succeededCount, ['count' => $succeededCount]))
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title(trans('minecraft-modrinth::strings.github.notifications.update_all_partial', [
                    'succeeded' => $succeededCount,
                    'total' => count($candidates),
                ]))
                ->body(implode("\n", $failures))
                ->status($succeededCount > 0 ? 'warning' : 'danger')
                ->persistent()
                ->send();
        }

        $this->forgetState();
    }

    /** "Doesn't belong on this server" (hide the offer) or "Offer again". */
    protected function setDismissed(string $id, string $name, bool $dismissed): void
    {
        $server = static::server();

        if (!static::userCan(SubuserPermission::FileCreate)) {
            abort(403);
        }

        try {
            $dismissed ? static::offers()->dismiss($server, $id) : static::offers()->restore($server, $id);

            Notification::make()
                ->title(trans($dismissed ? 'minecraft-modrinth::strings.github.offers.dismissed_title' : 'minecraft-modrinth::strings.github.offers.restored_title'))
                ->body(trans($dismissed ? 'minecraft-modrinth::strings.github.offers.dismissed_body' : 'minecraft-modrinth::strings.github.offers.restored_body', ['name' => e($name)]))
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

        if (!static::userCan(SubuserPermission::FileDelete)) {
            abort(403);
        }

        try {
            static::service()->uninstall($server, $id);

            Notification::make()
                ->title(trans('minecraft-modrinth::strings.notifications.uninstall_success'))
                ->body(trans('minecraft-modrinth::strings.github.notifications.uninstalled_body', ['name' => e($name)]))
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
            Action::make('update_all')
                ->label(fn () => trans('minecraft-modrinth::strings.github.offers.update_all', ['count' => $this->countUpdateAvailable()]))
                ->icon('tabler-refresh-dot')
                ->color('warning')
                ->visible(fn () => $this->countUpdateAvailable() > 0)
                ->disabled(fn () => !$this->ciAllowsInstall())
                ->tooltip(fn () => $this->ciAllowsInstall() ? null : trans('minecraft-modrinth::strings.github.page.ci_blocks_install'))
                ->authorize(fn () => static::userCan(SubuserPermission::FileCreate, SubuserPermission::FileDelete))
                ->requiresConfirmation()
                ->modalHeading(trans('minecraft-modrinth::strings.github.modals.update_all_heading'))
                ->modalDescription(fn () => trans_choice('minecraft-modrinth::strings.github.modals.update_all_description', $this->countUpdateAvailable(), ['count' => $this->countUpdateAvailable()]))
                ->action(fn () => $this->updateAll()),
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
                    if (!$this->mayRefreshFromGitHub()) {
                        Notification::make()
                            ->title(trans('minecraft-modrinth::strings.github.page.refresh_throttled', ['seconds' => $this->refreshWaitSeconds()]))
                            ->warning()
                            ->send();

                        return;
                    }

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
                Callout::make(trans('minecraft-modrinth::strings.github.page.pending_delete'))
                    ->description(fn () => trans('minecraft-modrinth::strings.github.page.pending_delete_description', ['files' => implode(', ', $this->pendingDeletes)]))
                    ->warning()
                    ->visible(function () {
                        $this->getInstalled();

                        return !empty($this->pendingDeletes);
                    }),
                Callout::make(fn () => trans_choice('minecraft-modrinth::strings.github.offers.replace_callout_heading', count($this->replaceRows()), ['count' => count($this->replaceRows())]))
                    ->description(fn () => trans(config('minecraft-modrinth.auto_update_enabled')
                        ? 'minecraft-modrinth::strings.github.offers.replace_callout_description_auto'
                        : 'minecraft-modrinth::strings.github.offers.replace_callout_description', [
                            'files' => implode(', ', array_merge(...array_map(fn (array $row) => array_map(fn (string $file) => "plugins/$file", $row['manual_files']), $this->replaceRows()))),
                        ]))
                    ->warning()
                    ->icon('tabler-replace')
                    ->visible(fn () => $this->replaceRows() !== []),
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
