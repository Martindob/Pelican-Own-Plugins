<?php

namespace Boy132\PlayerCounter\Filament\Server\Pages;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Traits\Filament\BlockAccessInConflict;
use Boy132\PlayerCounter\Filament\Server\Widgets\ServerPlayerWidget;
use Boy132\PlayerCounter\Models\GameQuery;
use Carbon\CarbonInterval;
use Exception;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentView;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Url;

class PlayersPage extends Page implements HasTable
{
    use BlockAccessInConflict;
    use HasTabs;
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'tabler-users-group';

    protected static ?string $slug = 'players';

    protected static ?int $navigationSort = 30;

    #[Url(as: 'tab')]
    public ?string $activeTab = null;

    public bool $isMinecraft = false;

    public bool $isProxy = false;

    /** @var array<string, mixed> */
    public array $players = [];

    /** @var string[] */
    public array $whitelist = [];

    /** @var string[] */
    public array $ops = [];

    public static function canAccess(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        if (!GameQuery::canRunQuery($server->allocation)) {
            return false;
        }

        // @phpstan-ignore method.notFound
        if (!$server->egg->gameQuery()->exists()) {
            return false;
        }

        return parent::canAccess();
    }

    public static function getNavigationLabel(): string
    {
        return trans('player-counter::query.players');
    }

    public static function getModelLabel(): string
    {
        return static::getNavigationLabel();
    }

    public static function getPluralModelLabel(): string
    {
        return static::getNavigationLabel();
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        $this->loadPlayersData();

        $this->loadDefaultActiveTab();
    }

    protected function loadPlayersData(): void
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        /** @var ?GameQuery $gameQuery */
        $gameQuery = $server->egg->gameQuery; // @phpstan-ignore property.notFound

        $this->isMinecraft = $gameQuery?->query_type === 'minecraft_java';
        $this->isProxy = $gameQuery?->query_type === 'minecraft_proxy';

        $this->whitelist = [];
        $this->ops = [];

        if ($this->isMinecraft) {
            $fileRepository = (new DaemonFileRepository())->setServer($server);

            try {
                $whitelist = json_decode($fileRepository->getContent('whitelist.json'), true, 512, JSON_THROW_ON_ERROR);
                $this->whitelist = array_unique(array_map(fn ($data) => $data['name'], $whitelist));
            } catch (Exception $exception) {
                report($exception);
            }

            try {
                $ops = json_decode($fileRepository->getContent('ops.json'), true, 512, JSON_THROW_ON_ERROR);
                $this->ops = array_unique(array_map(fn ($data) => $data['name'], $ops));
            } catch (Exception $exception) {
                report($exception);
            }
        }

        $this->players = [];

        if ($gameQuery) {
            $data = $gameQuery->runQuery($server);

            if ($data) {
                $this->players = $data['players'] ?? [];
            }
        }
    }

    /**
     * @throws Exception
     */
    public function table(Table $table): Table
    {
        return $table
            ->records(function (?string $search, int $page, int $recordsPerPage) {
                $players = match ($this->activeTab) {
                    'whitelist' => array_map(fn ($player) => ['name' => $player], $this->whitelist),
                    'ops' => array_map(fn ($player) => ['name' => $player], $this->ops),
                    default => $this->players,
                };

                if ($search) {
                    $players = array_filter($players, fn ($player) => str($player['name'])->contains($search, true));
                }

                return new LengthAwarePaginator(array_slice($players, ($page - 1) * $recordsPerPage, $recordsPerPage), count($players), $recordsPerPage, $page);
            })
            ->paginated([30, 60])
            ->contentGrid([
                'default' => 1,
                'lg' => 2,
                'xl' => $this->isMinecraft ? 2 : 3,
            ])
            ->columns([
                Split::make([
                    ImageColumn::make('avatar')
                        ->visible(fn () => $this->isMinecraft)
                        ->state(fn (array $record) => 'https://cravatar.eu/helmhead/' . (array_key_exists('id', $record) ? $record['id'] : $record['name']) . '/256.png')
                        ->grow(false),
                    TextColumn::make('name')
                        ->label('Name')
                        ->tooltip(fn (array $record) => array_key_exists('id', $record) ? $record['id'] : null)
                        ->searchable(),
                    TextColumn::make('is_whitelisted')
                        ->visible(fn () => $this->isMinecraft)
                        ->badge()
                        ->grow(false)
                        ->state(fn (array $record) => in_array($record['name'], $this->whitelist) ? trans('player-counter::query.whitelisted') : null),
                    TextColumn::make('is_op')
                        ->visible(fn () => $this->isMinecraft)
                        ->badge()
                        ->grow(false)
                        ->state(fn (array $record) => in_array($record['name'], $this->ops) ? trans('player-counter::query.op') : null),
                    TextColumn::make('time')
                        ->hidden(fn () => $this->isMinecraft || $this->isProxy)
                        ->badge()
                        ->grow(false)
                        ->formatStateUsing(fn ($state) => $state ? CarbonInterval::seconds($state)->cascade()->forHumans() : null),
                ]),
            ])
            ->recordActions([
                Action::make('exclude_kick')
                    ->visible(fn () => (!$this->activeTab || $this->activeTab === 'online') && !$this->isProxy)
                    ->label(trans('player-counter::query.kick'))
                    ->icon('tabler-door-exit')
                    ->color('danger')
                    ->action(function (array $record) {
                        /** @var Server $server */
                        $server = Filament::getTenant();

                        try {
                            $server->send('kick ' . $record['name']);

                            Notification::make()
                                ->title(trans('player-counter::query.notifications.player_kicked'))
                                ->body($record['name'])
                                ->success()
                                ->send();

                            $this->refreshPage();
                        } catch (Exception $exception) {
                            report($exception);

                            Notification::make()
                                ->title(trans('player-counter::query.notifications.player_kick_failed'))
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('exclude_ban')
                    ->visible(fn () => (!$this->activeTab || $this->activeTab === 'online') && !$this->isProxy)
                    ->label(trans('player-counter::query.ban'))
                    ->icon('tabler-hammer')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (array $record) {
                        /** @var Server $server */
                        $server = Filament::getTenant();

                        try {
                            $server->send('ban ' . $record['name']);

                            Notification::make()
                                ->title(trans('player-counter::query.notifications.player_banned'))
                                ->body($record['name'])
                                ->success()
                                ->send();

                            $this->refreshPage();
                        } catch (Exception $exception) {
                            report($exception);

                            Notification::make()
                                ->title(trans('player-counter::query.notifications.player_ban_failed'))
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('exclude_whitelist')
                    ->visible(fn () => $this->isMinecraft)
                    ->label(fn (array $record) => in_array($record['name'], $this->whitelist) ? trans('player-counter::query.remove_from_whitelist') : trans('player-counter::query.add_to_whitelist'))
                    ->icon(fn (array $record) => in_array($record['name'], $this->whitelist) ? 'tabler-playlist-x' : 'tabler-playlist-add')
                    ->color(fn (array $record) => in_array($record['name'], $this->whitelist) ? 'danger' : 'success')
                    ->action(function (array $record) {
                        /** @var Server $server */
                        $server = Filament::getTenant();

                        try {
                            $action = in_array($record['name'], $this->whitelist) ? 'remove' : 'add';

                            $server->send('whitelist ' . $action . ' ' . $record['name']);

                            Notification::make()
                                ->title(trans('player-counter::query.notifications.player_whitelist_' . $action))
                                ->body($record['name'])
                                ->success()
                                ->send();

                            $this->refreshPage();
                        } catch (Exception $exception) {
                            report($exception);

                            Notification::make()
                                ->title(trans('player-counter::query.notifications.player_whitelist_failed'))
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('exclude_op')
                    ->visible(fn () => $this->isMinecraft)
                    ->label(fn (array $record) => in_array($record['name'], $this->ops) ? trans('player-counter::query.remove_from_ops') : trans('player-counter::query.add_to_ops'))
                    ->icon(fn (array $record) => in_array($record['name'], $this->ops) ? 'tabler-shield-minus' : 'tabler-shield-plus')
                    ->color(fn (array $record) => in_array($record['name'], $this->ops) ? 'warning' : 'success')
                    ->action(function (array $record) {
                        /** @var Server $server */
                        $server = Filament::getTenant();

                        try {
                            $action = in_array($record['name'], $this->ops) ? 'deop' : 'op';

                            $server->send($action  . ' ' . $record['name']);

                            Notification::make()
                                ->title(trans('player-counter::query.notifications.player_' . $action))
                                ->body($record['name'])
                                ->success()
                                ->send();

                            $this->refreshPage();
                        } catch (Exception $exception) {
                            report($exception);

                            Notification::make()
                                ->title(trans('player-counter::query.notifications.player_op_failed'))
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->emptyStateHeading(function () {
                if ($this->activeTab && $this->activeTab !== 'online') {
                    return trans('player-counter::query.table.no_players');
                }

                /** @var Server $server */
                $server = Filament::getTenant();

                if ($server->retrieveStatus()->isOffline()) {
                    return trans('player-counter::query.table.server_offline');
                }

                return trans('player-counter::query.table.no_players');
            })
            ->emptyStateDescription(function () {
                if ($this->activeTab && $this->activeTab !== 'online') {
                    return null;
                }

                /** @var Server $server */
                $server = Filament::getTenant();

                if ($server->retrieveStatus()->isOffline()) {
                    return null;
                }

                return trans('player-counter::query.table.no_players_description');
            });
    }

    /** @return array<string|int, Tab> */
    public function getTabs(): array
    {
        if (!$this->isMinecraft) {
            return [];
        }

        return [
            'online' => Tab::make('online')
                ->label('Online')
                ->badge(fn () => count($this->players)),

            'whitelist' => Tab::make('whitelist')
                ->label('Whitelist')
                ->badge(fn () => count($this->whitelist)),

            'ops' => Tab::make('ops')
                ->label('OPs')
                ->badge(fn () => count($this->ops)),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getTabsContentComponent(),
                EmbeddedTable::make(),
            ]);
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ServerPlayerWidget::class,
        ];
    }

    private function refreshPage(): void
    {
        $url = self::getUrl();
        $this->redirect($url, FilamentView::hasSpaMode($url));
    }
}
