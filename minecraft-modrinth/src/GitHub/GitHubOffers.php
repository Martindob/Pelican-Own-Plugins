<?php

namespace Boy132\MinecraftModrinth\GitHub;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Models\User;
use Boy132\MinecraftModrinth\Filament\Server\Pages\GitHubPluginsPage;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Plugins from the GitHub repository that are offered to a server (see README "New plugins"):
 * new ones (in the index for the server's platform, not installed, not dismissed) and updates.
 *
 * - Per-server decisions ("doesn't belong on this server") and which new plugins a server was
 *   already told about live in the panel database (table minecraft_modrinth_github_offers,
 *   removed together with the server), not in the server's files: the metadata file of 1.2.0
 *   stays exactly as it was.
 * - The navigation badge only ever reads the cached counts written by the hourly offer check
 *   or by a visit of the page - rendering the menu never talks to GitHub or Wings.
 * - Nothing is ever installed here: new plugins are only offered.
 *
 * @phpstan-import-type IndexPlugin from GitHubPluginService
 * @phpstan-import-type Snapshot from GitHubPluginService
 */
class GitHubOffers
{
    public const TABLE = 'minecraft_modrinth_github_offers';

    /** The hourly check refreshes the counts; without it they vanish rather than go stale. */
    protected const COUNTS_CACHE_MINUTES = 150;

    /** More new plugins than this in one check are announced in a single notification. */
    protected const MAX_SINGLE_NOTIFICATIONS = 3;

    protected ?bool $storeAvailable = null;

    protected function service(): GitHubPluginService
    {
        return app(GitHubPluginService::class);
    }

    // ------------------------------------------------------------------
    // Stored decisions
    // ------------------------------------------------------------------

    /** Whether the migration of this plugin has run (it does on install/update of the plugin). */
    public function isStoreAvailable(): bool
    {
        if ($this->storeAvailable === null) {
            try {
                $this->storeAvailable = Schema::hasTable(self::TABLE);
            } catch (Throwable) {
                $this->storeAvailable = false;
            }
        }

        return $this->storeAvailable;
    }

    /**
     * @return array<int, string>
     *
     * @throws GitHubSourceException
     */
    public function dismissedIds(Server $server): array
    {
        return $this->pluckIds($server, 'dismissed_at');
    }

    /**
     * @return array<int, string>
     *
     * @throws GitHubSourceException
     */
    public function notifiedIds(Server $server): array
    {
        return $this->pluckIds($server, 'notified_at');
    }

    /** @throws GitHubSourceException */
    public function dismiss(Server $server, string $pluginId): void
    {
        $this->write($server, $pluginId, ['dismissed_at' => now()]);
        $this->forgetCounts($server);
    }

    /** @throws GitHubSourceException */
    public function restore(Server $server, string $pluginId): void
    {
        $this->write($server, $pluginId, ['dismissed_at' => null]);
        $this->forgetCounts($server);
    }

    /**
     * @return array<int, string>
     *
     * @throws GitHubSourceException
     */
    protected function pluckIds(Server $server, string $column): array
    {
        if (!$this->isStoreAvailable()) {
            return [];
        }

        try {
            return DB::table(self::TABLE)
                ->where('server_id', $server->id)
                ->whereNotNull($column)
                ->pluck('plugin_id')
                ->map(fn ($id) => (string) $id)
                ->all();
        } catch (QueryException) {
            throw new GitHubSourceException(GitHubSourceException::OFFER_STORE, 'Could not read the stored plugin offers of server #'.$server->id);
        }
    }

    /**
     * @param  array<string, mixed>  $values
     *
     * @throws GitHubSourceException
     */
    protected function write(Server $server, string $pluginId, array $values): void
    {
        if (!$this->isStoreAvailable()) {
            throw new GitHubSourceException(GitHubSourceException::OFFER_STORE, 'Table '.self::TABLE.' is missing - the plugin\'s migration has not run');
        }

        try {
            $key = ['server_id' => $server->id, 'plugin_id' => $pluginId];
            $exists = DB::table(self::TABLE)->where($key)->exists();

            if ($exists) {
                DB::table(self::TABLE)->where($key)->update($values + ['updated_at' => now()]);
            } else {
                DB::table(self::TABLE)->insert($key + $values + ['created_at' => now(), 'updated_at' => now()]);
            }
        } catch (QueryException) {
            throw new GitHubSourceException(GitHubSourceException::OFFER_STORE, "Could not store the plugin offer $pluginId of server #{$server->id}");
        }
    }

    // ------------------------------------------------------------------
    // Cached counts (navigation badge)
    // ------------------------------------------------------------------

    protected function countsKey(Server $server): string
    {
        return 'minecraft-modrinth:github:offers:'.$server->id;
    }

    /** @param  array{new: array<int, mixed>, updates: array<int, mixed>}  $offers */
    public function rememberCounts(Server $server, array $offers): void
    {
        Cache::put($this->countsKey($server), [
            'new' => count($offers['new']),
            'updates' => count($offers['updates']),
        ], now()->addMinutes(self::COUNTS_CACHE_MINUTES));
    }

    public function cachedCounts(Server $server): mixed
    {
        return Cache::get($this->countsKey($server));
    }

    public function forgetCounts(Server $server): void
    {
        Cache::forget($this->countsKey($server));
    }

    // ------------------------------------------------------------------
    // Offer check (hourly command)
    // ------------------------------------------------------------------

    /**
     * Count what the repository offers this server, cache it for the badge and - when
     * $announce - tell the server's users once about every new plugin.
     *
     * @param  Snapshot  $snapshot
     * @return array{new: int, updates: int, announced: array<int, string>}|null null when the server has no platform or can't be checked now
     */
    public function checkServer(Server $server, array $snapshot, bool $announce): ?array
    {
        $platform = $this->service()->platformForServer($server);

        // Unknown platform (no loader tag, no plugins feature, ...): no offers, no notifications.
        if ($platform === null || !$server->isInstalled() || $server->isSuspended()) {
            $this->forgetCounts($server);

            return null;
        }

        try {
            $installed = $this->service()->getInstalled($server);
            $jarNames = $this->service()->listJarNames($server);
        } catch (GitHubSourceException $exception) {
            $this->service()->reportOncePerWindow("offers:{$server->id}:{$exception->reason}", $exception);
            $this->forgetCounts($server);

            return null;
        }

        try {
            $dismissed = $this->dismissedIds($server);
            $notified = $this->notifiedIds($server);
        } catch (GitHubSourceException $exception) {
            $this->service()->reportOncePerWindow("offers:store:{$exception->reason}", $exception);
            $dismissed = [];
            $notified = null;
        }

        $offers = GitHubSourceRules::offers($snapshot['plugins'], $platform, $installed, $dismissed, $jarNames);
        $this->rememberCounts($server, $offers);

        $announced = [];

        // Only with a working store: without the record of what was announced, every check
        // would announce the same plugins again.
        if ($announce && $notified !== null && $this->isStoreAvailable()) {
            $toAnnounce = GitHubSourceRules::pluginsToAnnounce($offers['new'], $notified);

            if (!empty($toAnnounce)) {
                $this->announce($server, $toAnnounce);
                $announced = array_column($toAnnounce, 'id');
            }
        }

        return ['new' => count($offers['new']), 'updates' => count($offers['updates']), 'announced' => $announced];
    }

    /**
     * Send the database notifications and record them, so each new plugin is announced once per
     * server. Recorded even when nobody could be notified (a failing notification is reported
     * and not retried every hour).
     *
     * @param  array<int, IndexPlugin>  $plugins
     */
    protected function announce(Server $server, array $plugins): void
    {
        foreach ($this->recipients($server) as $user) {
            try {
                $this->notify($user, $server, $plugins);
            } catch (Throwable $exception) {
                $this->service()->reportOncePerWindow("offers:{$server->id}:notify", new GitHubSourceException(GitHubSourceException::OFFER_STORE, "Could not notify user #{$user->id} about new plugins on server #{$server->id}: ".get_class($exception)));
            }
        }

        foreach ($plugins as $plugin) {
            try {
                $this->write($server, $plugin['id'], ['notified_at' => now(), 'notified_version' => $plugin['version']]);
            } catch (GitHubSourceException $exception) {
                $this->service()->reportOncePerWindow("offers:store:{$exception->reason}", $exception);
            }
        }
    }

    /**
     * Who is told about new plugins: the server owner and every subuser allowed to install
     * them (file.create on this server). Panel admins are not notified for every server they
     * can see; they get the badge on the page instead.
     *
     * @return Collection<int, User>
     */
    public function recipients(Server $server): Collection
    {
        $server->loadMissing(['user', 'subusers.user']);

        /** @var Collection<int, User> $candidates */
        $candidates = collect([$server->user])
            ->concat($server->subusers->map(fn ($subuser) => $subuser->user))
            ->filter(fn ($user) => $user instanceof User)
            ->unique('id')
            ->values();

        return $candidates
            ->filter(fn (User $user) => $user->id === $server->owner_id || $user->can(SubuserPermission::FileCreate, $server))
            ->values();
    }

    /** @param  array<int, IndexPlugin>  $plugins */
    protected function notify(User $user, Server $server, array $plugins): void
    {
        $locale = $user->language ?? 'en';

        $notifications = count($plugins) > self::MAX_SINGLE_NOTIFICATIONS
            ? [[
                'title' => trans('minecraft-modrinth::strings.github.offers.notification_title_many', [], $locale),
                'body' => trans('minecraft-modrinth::strings.github.offers.notification_body_many', [
                    'server' => $server->name,
                    'plugins' => implode(', ', array_map(fn (array $plugin) => $plugin['name'].' '.$plugin['version'], $plugins)),
                ], $locale),
            ]]
            : array_map(fn (array $plugin) => [
                'title' => trans('minecraft-modrinth::strings.github.offers.notification_title', [], $locale),
                'body' => trans('minecraft-modrinth::strings.github.offers.notification_body', [
                    'server' => $server->name,
                    'name' => $plugin['name'],
                    'version' => $plugin['version'],
                ], $locale),
            ], $plugins);

        $url = $this->pageUrl($server);

        foreach ($notifications as $notification) {
            Notification::make()
                ->info()
                ->icon('tabler-brand-github')
                ->title($notification['title'])
                ->body($notification['body'])
                ->actions($url === null ? [] : [
                    Action::make('open_github_plugins')
                        ->button()
                        ->label(trans('minecraft-modrinth::strings.github.offers.notification_action', [], $locale))
                        ->markAsRead()
                        ->url($url),
                ])
                ->sendToDatabase($user);
        }
    }

    protected function pageUrl(Server $server): ?string
    {
        try {
            return GitHubPluginsPage::getUrl(panel: 'server', tenant: $server);
        } catch (Throwable) {
            return null;
        }
    }
}
