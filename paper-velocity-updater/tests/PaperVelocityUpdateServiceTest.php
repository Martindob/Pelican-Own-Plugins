<?php

/*
 * Standalone tests of PaperVelocityUpdateService - no Pelican, Laravel or Composer needed: the
 * framework pieces it touches (Cache with locks, Http to PaperMC's Fill API, config(), now(),
 * the server's startup variables and the Wings file calls) are small in-memory fakes below.
 *
 *     php paper-velocity-updater/tests/PaperVelocityUpdateServiceTest.php
 *
 * Not part of the distributed plugin (dist/paper-velocity-updater.zip leaves tests/ out).
 */

declare(strict_types=1);

namespace Illuminate\Support {
    class Carbon extends \DateTimeImmutable
    {
        public static string $now = '2026-11-22T12:00:00+00:00';

        public static function current(): static
        {
            return new static(self::$now);
        }

        public static function parse(string $value): static
        {
            return new static($value);
        }

        public function toIso8601String(): string
        {
            return $this->format(DATE_ATOM);
        }

        public function addMinutes(int $n): static
        {
            return $this->modify("+$n minutes");
        }

        public function addHours(int $n): static
        {
            return $this->modify("+$n hours");
        }

        public function addDay(): static
        {
            return $this->modify('+1 day');
        }

        public function addDays(int $n): static
        {
            return $this->modify("+$n days");
        }

        public function subDays(int $n): static
        {
            return $this->modify("-$n days");
        }

        public function subHours(int $n): static
        {
            return $this->modify("-$n hours");
        }

        public function greaterThan(\DateTimeInterface $other): bool
        {
            return $this > $other;
        }

        public function lt(\DateTimeInterface $other): bool
        {
            return $this < $other;
        }
    }
}

namespace Illuminate\Contracts\Filesystem {
    class FileNotFoundException extends \Exception {}
}

namespace Illuminate\Contracts\Cache {
    class LockTimeoutException extends \Exception {}
}

namespace Illuminate\Http\Client {
    class Response
    {
        public function __construct(public int $status = 200, public mixed $body = null) {}

        public function status(): int
        {
            return $this->status;
        }

        public function json(?string $key = null): mixed
        {
            return $key === null ? $this->body : ($this->body[$key] ?? null);
        }

        public function throw(): static
        {
            if ($this->status >= 400) {
                throw new RequestException($this);
            }

            return $this;
        }
    }

    class RequestException extends \Exception
    {
        public function __construct(public Response $response)
        {
            parent::__construct("HTTP request returned status code {$response->status}");
        }
    }

    /** The Fill API: path => decoded JSON body, or an int status to fail with. */
    class PendingRequest
    {
        /** @var array<string, mixed> */
        public static array $routes = [];

        /** @var array<int, string> */
        public static array $requested = [];

        public function withHeaders(array $headers): static
        {
            return $this;
        }

        public function timeout(int $seconds): static
        {
            return $this;
        }

        public function connectTimeout(int $seconds): static
        {
            return $this;
        }

        public function throw(): static
        {
            return $this;
        }

        public function get(string $path): Response
        {
            self::$requested[] = $path;
            $route = self::$routes[$path] ?? 404;

            return (new Response(is_int($route) ? $route : 200, is_int($route) ? null : $route))->throw();
        }
    }
}

namespace Illuminate\Support\Facades {
    use Illuminate\Contracts\Cache\LockTimeoutException;
    use Illuminate\Http\Client\PendingRequest;
    use Illuminate\Support\Carbon;

    final class Cache
    {
        /** @var array<string, array{value: mixed, expires: ?Carbon}> */
        public static array $store = [];

        /** @var array<string, bool> */
        public static array $locks = [];

        public static function get(string $key, mixed $default = null): mixed
        {
            $item = self::$store[$key] ?? null;
            if ($item === null || ($item['expires'] !== null && $item['expires'] <= Carbon::current())) {
                return $default;
            }

            return $item['value'];
        }

        public static function has(string $key): bool
        {
            return self::get($key, $miss = new \stdClass()) !== $miss;
        }

        public static function put(string $key, mixed $value, ?Carbon $expires = null): void
        {
            self::$store[$key] = ['value' => $value, 'expires' => $expires];
        }

        public static function add(string $key, mixed $value, ?Carbon $expires = null): bool
        {
            if (self::has($key)) {
                return false;
            }

            self::put($key, $value, $expires);

            return true;
        }

        public static function remember(string $key, Carbon $expires, callable $callback): mixed
        {
            if (self::has($key)) {
                return self::get($key);
            }

            $value = $callback();
            self::put($key, $value, $expires);

            return $value;
        }

        public static function lock(string $key, int $seconds): object
        {
            return new class($key)
            {
                public function __construct(private string $key) {}

                public function block(int $seconds, callable $callback): mixed
                {
                    if (Cache::$locks[$this->key] ?? false) {
                        throw new LockTimeoutException('Unable to acquire lock.');
                    }

                    Cache::$locks[$this->key] = true;
                    try {
                        return $callback();
                    } finally {
                        unset(Cache::$locks[$this->key]);
                    }
                }
            };
        }
    }

    final class Http
    {
        public static function baseUrl(string $url): PendingRequest
        {
            return new PendingRequest();
        }
    }
}

namespace App\Models {
    final class EggVariable
    {
        public function __construct(public string $env_variable, public ?string $default_value, public ?string $server_value) {}
    }

    final class VariableCollection
    {
        /** @param  array<int, EggVariable>  $items */
        public function __construct(public array $items) {}

        public function firstWhere(string $key, mixed $value): ?EggVariable
        {
            foreach ($this->items as $item) {
                if ($item->$key === $value) {
                    return $item;
                }
            }

            return null;
        }
    }

    final class Server
    {
        /** @var array<int, Server> */
        public static array $all = [];

        public ?VariableCollection $variables = null;

        /** @param  array<string, array{0: ?string, 1: ?string}>  $vars  env => [egg default, server value] */
        public function __construct(public int $id, public string $name, public string $uuid, public array $vars) {}

        public function variables(): object
        {
            return new class($this->vars)
            {
                public function __construct(private array $vars) {}

                public function get(): VariableCollection
                {
                    $items = [];
                    foreach ($this->vars as $env => [$default, $value]) {
                        $items[] = new EggVariable($env, $default, $value);
                    }

                    return new VariableCollection($items);
                }
            };
        }

        public function setRelation(string $name, mixed $value): void
        {
            $this->$name = $value;
        }

        public function loadMissing(mixed $relations): never
        {
            throw new \LogicException('loadMissing() would eager-load variables without per-server values');
        }

        public static function query(): object
        {
            return new class
            {
                public function chunk(int $size, callable $callback): void
                {
                    $callback(Server::$all);
                }
            };
        }
    }
}

namespace App\Repositories\Daemon {
    use App\Models\Server;
    use Illuminate\Contracts\Filesystem\FileNotFoundException;
    use Illuminate\Http\Client\RequestException;
    use Illuminate\Http\Client\Response;

    /** A server's files on Wings: path => ['size' => int, 'content' => string]. */
    final class Wings
    {
        /** @var array<string, array<string, array{size: int, content: string}>> */
        public static array $files = [];

        /** Called during a pull, after Wings has started writing the file. */
        public static ?\Closure $duringPull = null;

        /** Size Wings ends up writing for a pull, by URL (default: the size Fill reports). */
        public static array $pullSize = [];

        public static bool $pullFails = false;

        public static ?int $getContentStatus = null;

        /** @var array<int, string> "from>to" renames that fail */
        public static array $renameFailsTo = [];

        /** @var array<int, array<string, mixed>> */
        public static array $pulls = [];

        public static function put(string $uuid, string $path, string $content = '', ?int $size = null): void
        {
            self::$files[$uuid][$path] = ['size' => $size ?? strlen($content), 'content' => $content];
        }
    }

    class DaemonFileRepository
    {
        public ?Server $server = null;

        public function setServer(Server $server): static
        {
            $this->server = $server;

            return $this;
        }

        private function &files(): array
        {
            Wings::$files[$this->server->uuid] ??= [];

            return Wings::$files[$this->server->uuid];
        }

        public function getContent(string $path, ?int $notLargerThan = null): string
        {
            if (Wings::$getContentStatus !== null) {
                throw new RequestException(new Response(Wings::$getContentStatus));
            }

            $file = $this->files()[$path] ?? null;
            if ($file === null) {
                throw new FileNotFoundException();
            }

            return $file['content'];
        }

        public function putContent(string $path, string $content): void
        {
            $this->files()[$path] = ['size' => strlen($content), 'content' => $content];
        }

        public function getDirectory(string $path): array
        {
            $list = [];
            foreach ($this->files() as $name => $file) {
                if (!str_contains($name, '/')) {
                    $list[] = ['name' => $name, 'size' => $file['size'], 'file' => true];
                }
            }

            return $list;
        }

        /** Like Wings: fails when the source is missing or the target already exists. */
        public function renameFiles(string $root, array $files): void
        {
            $all = &$this->files();
            foreach ($files as ['from' => $from, 'to' => $to]) {
                if (!isset($all[$from]) || isset($all[$to]) || in_array("$from>$to", Wings::$renameFailsTo, true)) {
                    throw new RequestException(new Response(400));
                }

                $all[$to] = $all[$from];
                unset($all[$from]);
            }
        }

        public function deleteFiles(string $root, array $names): void
        {
            $all = &$this->files();
            foreach ($names as $name) {
                if (!isset($all[$name])) {
                    throw new RequestException(new Response(404));
                }

                unset($all[$name]);
            }
        }

        public function getHttpClient(): object
        {
            $repository = $this;

            return new class($repository)
            {
                public function __construct(private DaemonFileRepository $repository) {}

                public function timeout(int $seconds): static
                {
                    return $this;
                }

                public function post(string $url, array $data): Response
                {
                    Wings::$pulls[] = $data;
                    $uuid = $this->repository->server->uuid;

                    // Wings writes straight into the target file while downloading.
                    Wings::put($uuid, $data['file_name'], '', 10);
                    if (Wings::$duringPull !== null) {
                        (Wings::$duringPull)();
                    }

                    if (Wings::$pullFails) {
                        return new Response(500);
                    }

                    Wings::put($uuid, $data['file_name'], '', Wings::$pullSize[$data['url']] ?? \FillFixture::$sizes[$data['url']]);

                    return new Response(200);
                }
            };
        }
    }

    class DaemonServerRepository {}
}

namespace {
    use App\Models\Server;
    use App\Repositories\Daemon\DaemonFileRepository;
    use App\Repositories\Daemon\Wings;
    use Illuminate\Http\Client\PendingRequest;
    use Illuminate\Support\Carbon;
    use Illuminate\Support\Facades\Cache;
    use Martindob\PaperVelocityUpdater\Services\PaperVelocityUpdateService;

    final class Config
    {
        public static array $values = [];
    }

    final class Reported
    {
        public static array $exceptions = [];
    }

    function config(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, Config::$values) ? Config::$values[$key] : $default;
    }

    function now(): Carbon
    {
        return Carbon::current();
    }

    function cache(): object
    {
        return new class
        {
            public function remember(string $key, Carbon $expires, callable $callback): mixed
            {
                return Cache::remember($key, $expires, $callback);
            }
        };
    }

    function app(string $class): object
    {
        return new $class();
    }

    function report(Throwable $exception): void
    {
        Reported::$exceptions[] = $exception;
    }

    require __DIR__.'/../src/Services/PaperVelocityUpdateService.php';

    /** Fill API responses, close to what fill.papermc.io returns. */
    final class FillFixture
    {
        /** @var array<string, int> */
        public static array $sizes = [];

        public static function build(string $project, string $version, int $id, string $channel = 'STABLE'): array
        {
            $url = "https://fill-data.papermc.io/v1/objects/".hash('sha256', "$project-$version-$id")."/$project-$version-$id.jar";
            self::$sizes[$url] = 50_000_000 + $id;

            return [
                'id' => $id,
                'channel' => $channel,
                'downloads' => ['server:default' => [
                    'name' => "$project-$version-$id.jar",
                    'checksums' => ['sha256' => hash('sha256', "$project-$version-$id")],
                    'size' => 50_000_000 + $id,
                    'url' => $url,
                ]],
            ];
        }

        public static function install(): void
        {
            PendingRequest::$routes = [
                '/projects/paper' => ['versions' => [
                    '26.3' => ['26.3', '26.3-rc-3'],
                    '26.2' => ['26.2', '26.2-rc-2'],
                    '26.1' => ['26.1.2'],
                ]],
                '/projects/paper/versions/26.3/builds' => [self::build('paper', '26.3', 257), self::build('paper', '26.3', 256)],
                '/projects/paper/versions/26.3-rc-3/builds' => [self::build('paper', '26.3-rc-3', 5, 'ALPHA')],
                '/projects/paper/versions/26.2/builds' => [self::build('paper', '26.2', 130), self::build('paper', '26.2', 129)],
                '/projects/paper/versions/26.2-rc-2/builds' => [self::build('paper', '26.2-rc-2', 3, 'ALPHA')],
                '/projects/paper/versions/26.1.2/builds' => [self::build('paper', '26.1.2', 90)],
                '/projects/velocity' => ['versions' => [
                    '4.0.0' => ['4.3.2-SNAPSHOT', '4.3.1'],
                ]],
                '/projects/velocity/versions/4.3.2-SNAPSHOT/builds' => [self::build('velocity', '4.3.2-SNAPSHOT', 40)],
                '/projects/velocity/versions/4.3.1/builds' => [self::build('velocity', '4.3.1', 30)],
            ];
        }

        public static function url(string $project, string $version, int $id): string
        {
            return self::build($project, $version, $id)['downloads']['server:default']['url'];
        }
    }

    final class Result
    {
        public static int $checks = 0;

        public static int $failures = 0;
    }

    function check(bool $condition, string $name): void
    {
        Result::$checks++;
        if (!$condition) {
            Result::$failures++;
            echo "FAIL: $name\n";
        }
    }

    function reset_world(): void
    {
        Cache::$store = [];
        Cache::$locks = [];
        Wings::$files = [];
        Wings::$duringPull = null;
        Wings::$pullSize = [];
        Wings::$pullFails = false;
        Wings::$getContentStatus = null;
        Wings::$renameFailsTo = [];
        Wings::$pulls = [];
        Reported::$exceptions = [];
        PendingRequest::$requested = [];
        Config::$values = ['paper-velocity-updater.enabled' => true];
        FillFixture::install();
        Server::$all = [];
    }

    /** A server on the official Paper egg; $values are the server's own values (egg defaults otherwise). */
    function paper_server(array $values = [], int $id = 1): Server
    {
        $defaults = ['MINECRAFT_VERSION' => 'latest', 'BUILD_NUMBER' => 'latest', 'SERVER_JARFILE' => 'server.jar', 'DL_PATH' => ''];
        $vars = [];
        foreach ($defaults as $env => $default) {
            $vars[$env] = [$default, $values[$env] ?? null];
        }

        $server = new Server($id, "Server $id", "uuid-$id", $vars);
        Server::$all[] = $server;
        Wings::put($server->uuid, 'server.jar', '', 49_000_000);

        return $server;
    }

    function velocity_server(array $values = [], int $id = 2): Server
    {
        $defaults = ['VELOCITY_VERSION' => 'latest', 'BUILD_NUMBER' => 'latest', 'SERVER_JARFILE' => 'velocity.jar'];
        $vars = [];
        foreach ($defaults as $env => $default) {
            $vars[$env] = [$default, $values[$env] ?? null];
        }

        $server = new Server($id, "Proxy $id", "uuid-$id", $vars);
        Server::$all[] = $server;
        Wings::put($server->uuid, 'velocity.jar', '', 20_000_000);

        return $server;
    }

    function state(Server $server): array
    {
        $file = Wings::$files[$server->uuid]['.paper-velocity-updater.json'] ?? null;

        return $file === null ? [] : json_decode($file['content'], true);
    }

    function set_state(Server $server, array $state): void
    {
        Wings::put($server->uuid, '.paper-velocity-updater.json', json_encode($state));
    }

    function file_size(Server $server, string $name): ?int
    {
        return Wings::$files[$server->uuid][$name]['size'] ?? null;
    }

    function log_contains(PaperVelocityUpdateService $service, string $needle, ?string $level = null): bool
    {
        foreach ($service->getActivityLog() as $entry) {
            if (str_contains($entry['message'], $needle) && ($level === null || $entry['level'] === $level)) {
                return true;
            }
        }

        return false;
    }

    function pending(string $project, string $version, int $build, string $jar = 'server.jar'): array
    {
        return [
            'project' => $project, 'version' => $version, 'build' => $build, 'jar' => $jar,
            'staged_jar' => "$jar.pending", 'sha256' => null, 'size' => 50_000_000 + $build,
            'staged_at' => '2026-11-22T10:00:00+00:00',
        ];
    }

    function installed(string $project, string $version, int $build, string $jar = 'server.jar'): array
    {
        return ['project' => $project, 'version' => $version, 'build' => $build, 'jar' => $jar, 'sha256' => null, 'updated_at' => '2026-11-20T10:00:00+00:00'];
    }

    // --- Version lock -------------------------------------------------------------------

    reset_world();
    $service = new PaperVelocityUpdateService();
    $server = paper_server(['MINECRAFT_VERSION' => '26.2']);
    $service->checkForUpdates();
    check((state($server)['pending']['version'] ?? null) === '26.2', 'pinned 26.2 stages a 26.2 build, not 26.3 (egg default is "latest")');
    check((state($server)['pending']['build'] ?? null) === 130, 'pinned 26.2 stages the newest STABLE 26.2 build');
    check(file_size($server, 'server.jar.pending') === 50_000_130, 'the staged file is the complete download');
    check(!in_array('/projects/paper/versions/26.3/builds', PendingRequest::$requested, true), 'a pinned version never looks at the newest version\'s builds');

    reset_world();
    $service = new PaperVelocityUpdateService();
    $server = paper_server(['MINECRAFT_VERSION' => ' 26.2 ']);
    $service->checkForUpdates();
    check((state($server)['pending']['version'] ?? null) === '26.2', 'a pin with surrounding spaces still means 26.2');

    reset_world();
    $service = new PaperVelocityUpdateService();
    $server = paper_server();
    $service->checkForUpdates();
    check((state($server)['pending']['version'] ?? null) === '26.3' && state($server)['pending']['build'] === 257, '"latest" stages the newest stable version and build');

    reset_world();
    PendingRequest::$routes['/projects/paper/versions/26.3/builds'] = [FillFixture::build('paper', '26.3', 12, 'ALPHA')];
    $service = new PaperVelocityUpdateService();
    $server = paper_server(['MINECRAFT_VERSION' => 'LATEST']);
    $service->checkForUpdates();
    check((state($server)['pending']['version'] ?? null) === '26.2', '"latest" skips a version that only has ALPHA builds (case-insensitive "latest")');

    reset_world();
    PendingRequest::$routes['/projects/paper/versions/26.3/builds'] = [FillFixture::build('paper', '26.3', 12, 'ALPHA')];
    $service = new PaperVelocityUpdateService();
    $server = paper_server(['MINECRAFT_VERSION' => '26.3']);
    $service->checkForUpdates();
    check((state($server)['pending']['build'] ?? null) === 12, 'a pinned version without a STABLE build gets its newest build');

    reset_world();
    $service = new PaperVelocityUpdateService();
    $server = paper_server(['MINECRAFT_VERSION' => '26.4']);
    $service->checkForUpdates();
    check(!isset(state($server)['pending']) && Wings::$pulls === [], 'a pinned version PaperMC doesn\'t list stages nothing');
    check(log_contains($service, 'Pinned version "26.4"'), 'and says why');

    reset_world();
    PendingRequest::$routes['/projects/paper'] = 503;
    $service = new PaperVelocityUpdateService();
    $server = paper_server(['MINECRAFT_VERSION' => '26.2']);
    $service->checkForUpdates();
    check(Wings::$pulls === [], 'PaperMC unreachable: a pinned server stages nothing (never falls back to latest)');

    // --- Opt-outs and invalid settings -----------------------------------------------------

    reset_world();
    $service = new PaperVelocityUpdateService();
    $server = paper_server(['BUILD_NUMBER' => '129']);
    $service->checkForUpdates();
    check(Wings::$pulls === [] && log_contains($service, 'BUILD_NUMBER is set to "129"'), 'a pinned BUILD_NUMBER stages nothing');

    reset_world();
    $service = new PaperVelocityUpdateService();
    $server = paper_server(['DL_PATH' => 'https://example.com/custom.jar']);
    $service->checkForUpdates();
    check(Wings::$pulls === [] && log_contains($service, 'DL_PATH is set'), 'DL_PATH opts the server out');

    foreach (['../server.jar', 'plugins/server.jar', 'server.zip', '.hidden.jar', 'a\\b.jar'] as $jar) {
        reset_world();
        $service = new PaperVelocityUpdateService();
        $server = paper_server(['SERVER_JARFILE' => $jar]);
        $service->checkForUpdates();
        check(Wings::$pulls === [], "SERVER_JARFILE \"$jar\" is refused");
    }

    reset_world();
    $service = new PaperVelocityUpdateService();
    $modded = new Server(3, 'Fabric', 'uuid-3', ['MINECRAFT_VERSION' => ['latest', '26.2'], 'SERVER_JARFILE' => ['server.jar', null]]);
    Server::$all[] = $modded;
    $service->checkForUpdates();
    check(Wings::$pulls === [] && PendingRequest::$requested === [], 'an egg without BUILD_NUMBER (Fabric/Forge/...) is never touched');

    reset_world();
    Config::$values['paper-velocity-updater.enabled'] = false;
    $service = new PaperVelocityUpdateService();
    $server = paper_server();
    $service->checkForUpdates();
    check(Wings::$pulls === [], 'disabled: no check');

    reset_world();
    $routes = PendingRequest::$routes['/projects/paper/versions/26.2/builds'];
    $routes[0]['downloads']['server:default']['url'] = 'https://evil.example.com/paper.jar';
    PendingRequest::$routes['/projects/paper/versions/26.2/builds'] = $routes;
    $service = new PaperVelocityUpdateService();
    $server = paper_server(['MINECRAFT_VERSION' => '26.2']);
    $service->checkForUpdates();
    check(Wings::$pulls === [] && log_contains($service, 'evil.example.com'), 'a download URL off PaperMC\'s hosts is refused');

    // --- Already done ---------------------------------------------------------------------

    reset_world();
    $service = new PaperVelocityUpdateService();
    $server = paper_server(['MINECRAFT_VERSION' => '26.2']);
    set_state($server, ['installed' => installed('paper', '26.2', 130)]);
    $service->checkForUpdates();
    check(Wings::$pulls === [] && log_contains($service, 'Already up to date'), 'the installed target is not downloaded again');

    reset_world();
    $service = new PaperVelocityUpdateService();
    $server = paper_server(['MINECRAFT_VERSION' => '26.2']);
    Wings::put($server->uuid, 'server.jar.pending', '', 50_000_130);
    set_state($server, ['installed' => installed('paper', '26.2', 129), 'pending' => pending('paper', '26.2', 130)]);
    $service->checkForUpdates();
    check(Wings::$pulls === [], 'an already staged target is not downloaded again');

    reset_world();
    $service = new PaperVelocityUpdateService();
    $server = paper_server(['MINECRAFT_VERSION' => '26.2']);
    set_state($server, ['installed' => installed('paper', '26.2', 129)]);
    $service->checkForUpdates();
    check((state($server)['pending']['build'] ?? null) === 130 && state($server)['installed']['build'] === 129, 'a newer build of the pinned version is staged, the installed record is kept');

    // --- Downgrade protection -------------------------------------------------------------

    reset_world();
    $service = new PaperVelocityUpdateService();
    $server = paper_server(['MINECRAFT_VERSION' => '26.2']);
    set_state($server, ['installed' => installed('paper', '26.3', 257)]);
    $service->checkForUpdates();
    check(Wings::$pulls === [] && log_contains($service, 'not downgrading', 'warning'), 'pinned 26.2 with 26.3 installed by the plugin: no downgrade, warning');

    reset_world();
    $service = new PaperVelocityUpdateService();
    $server = paper_server(['MINECRAFT_VERSION' => '26.3']);
    set_state($server, ['installed' => installed('paper', '26.3', 300)]);
    $service->checkForUpdates();
    check(Wings::$pulls === [], 'a lower build of the same version is not installed over a higher one');

    reset_world();
    $service = new PaperVelocityUpdateService();
    $server = paper_server(['MINECRAFT_VERSION' => '26.2']);
    Wings::put($server->uuid, '.paper/version_history.json', json_encode(['currentVersion' => '26.3-257-main@abc1234 (MC: 26.3)']));
    $service->checkForUpdates();
    check(Wings::$pulls === [] && log_contains($service, 'last ran Minecraft 26.3', 'warning'), 'pinned 26.2 on a server Paper says ran 26.3 (no state file): no downgrade');

    reset_world();
    $service = new PaperVelocityUpdateService();
    $server = paper_server(['MINECRAFT_VERSION' => '26.2']);
    Wings::put($server->uuid, 'version_history.json', json_encode(['currentVersion' => 'git-Paper-196 (MC: 26.3)']));
    $service->checkForUpdates();
    check(Wings::$pulls === [], 'the older version_history.json location in the server root is read too');

    reset_world();
    $service = new PaperVelocityUpdateService();
    $server = paper_server(['MINECRAFT_VERSION' => '26.3']);
    Wings::put($server->uuid, '.paper/version_history.json', json_encode(['currentVersion' => '26.2-130-main@abc1234 (MC: 26.2)']));
    $service->checkForUpdates();
    check((state($server)['pending']['version'] ?? null) === '26.3', 'an upgrade past the running version is staged');

    reset_world();
    $service = new PaperVelocityUpdateService();
    $server = paper_server(['MINECRAFT_VERSION' => '26.2']);
    Wings::put($server->uuid, '.paper/version_history.json', '{broken');
    $service->checkForUpdates();
    check((state($server)['pending']['version'] ?? null) === '26.2', 'an unreadable version history doesn\'t block staging');

    // --- Download integrity -----------------------------------------------------------------

    reset_world();
    $service = new PaperVelocityUpdateService();
    $server = paper_server(['MINECRAFT_VERSION' => '26.2']);
    Wings::$pullSize[FillFixture::url('paper', '26.2', 130)] = 1234;
    $service->checkForUpdates();
    check(!isset(state($server)['pending']), 'a truncated download is not marked as staged');
    check(file_size($server, 'server.jar.pending') === null, 'and is deleted');
    check(log_contains($service, '1234 bytes', 'error'), 'and the size mismatch is logged');

    reset_world();
    $service = new PaperVelocityUpdateService();
    $server = paper_server(['MINECRAFT_VERSION' => '26.2']);
    Wings::put($server->uuid, 'server.jar.pending', '', 50_000_129);
    set_state($server, ['pending' => pending('paper', '26.2', 129)]);
    PendingRequest::$routes['/projects/paper/versions/26.2/builds'] = [FillFixture::build('paper', '26.2', 130), FillFixture::build('paper', '26.2', 129)];
    Wings::$pullFails = true;
    $service->checkForUpdates();
    check(!isset(state($server)['pending']), 'a failed re-download leaves no marker for the overwritten file');
    $service->applyPendingUpdate($server);
    check(file_size($server, 'server.jar') === 49_000_000, 'so a restart after it keeps the current jar');

    reset_world();
    $service = new PaperVelocityUpdateService();
    $server = paper_server(['MINECRAFT_VERSION' => '26.2']);
    Wings::put($server->uuid, 'server.jar.pending', '', 50_000_129);
    set_state($server, ['pending' => pending('paper', '26.2', 129)]);
    Wings::$duringPull = fn () => $service->applyPendingUpdate($server);
    $service->checkForUpdates();
    check(file_size($server, 'server.jar') === 49_000_000, 'a restart while a new build downloads doesn\'t swap in the half-written file');
    check(log_contains($service, 'Swap failed', 'error'), 'the skipped swap is logged');
    check((state($server)['pending']['build'] ?? null) === 130, 'the download itself still completes and is staged');

    // --- Swapping ---------------------------------------------------------------------------

    reset_world();
    $service = new PaperVelocityUpdateService();
    $server = paper_server(['MINECRAFT_VERSION' => '26.2']);
    $service->checkForUpdates();
    $service->applyPendingUpdate($server);
    check(file_size($server, 'server.jar') === 50_000_130, 'the restart swaps the staged build in');
    check(file_size($server, 'server.jar.old') === 49_000_000, 'the previous jar is kept as server.jar.old');
    check(!isset(state($server)['pending']) && (state($server)['installed']['build'] ?? null) === 130, 'and the state records it as installed');
    $service->applyPendingUpdate($server);
    check(file_size($server, 'server.jar') === 50_000_130, 'a second restart changes nothing');

    reset_world();
    $service = new PaperVelocityUpdateService();
    $server = paper_server(['MINECRAFT_VERSION' => '26.2']);
    Wings::put($server->uuid, 'server.jar.old', '', 1);
    $service->checkForUpdates();
    $service->applyPendingUpdate($server);
    check(file_size($server, 'server.jar.old') === 49_000_000, 'an existing .old backup is replaced, not accumulated');

    reset_world();
    $service = new PaperVelocityUpdateService();
    $server = paper_server(['MINECRAFT_VERSION' => '26.2']);
    Wings::put($server->uuid, 'server.jar.pending', '', 50_000_000);
    set_state($server, ['pending' => pending('paper', '26.2', 130)]);
    $service->applyPendingUpdate($server);
    check(file_size($server, 'server.jar') === 49_000_000, 'a staged file of the wrong size is not swapped in');
    check(!isset(state($server)['pending']) && log_contains($service, 'not swapping it in', 'warning'), 'its marker is dropped and it is logged');

    reset_world();
    $service = new PaperVelocityUpdateService();
    $server = paper_server(['MINECRAFT_VERSION' => '26.2']);
    Wings::put($server->uuid, 'server.jar.pending', '', 50_000_257);
    set_state($server, ['pending' => pending('paper', '26.3', 257)]);
    $service->applyPendingUpdate($server);
    check(file_size($server, 'server.jar') === 49_000_000, 'a staged 26.3 build is not swapped in once the server is pinned to 26.2');

    reset_world();
    $service = new PaperVelocityUpdateService();
    $server = paper_server(['BUILD_NUMBER' => '129']);
    Wings::put($server->uuid, 'server.jar.pending', '', 50_000_130);
    set_state($server, ['pending' => pending('paper', '26.2', 130)]);
    $service->applyPendingUpdate($server);
    check(file_size($server, 'server.jar') === 49_000_000, 'a staged build is not swapped in once BUILD_NUMBER is pinned');

    reset_world();
    $service = new PaperVelocityUpdateService();
    $server = paper_server(['SERVER_JARFILE' => 'paper.jar']);
    Wings::put($server->uuid, 'server.jar.pending', '', 50_000_130);
    set_state($server, ['pending' => pending('paper', '26.2', 130)]);
    $service->applyPendingUpdate($server);
    check(file_size($server, 'server.jar') === 49_000_000 && file_size($server, 'paper.jar') === null, 'a staged build for another SERVER_JARFILE is not swapped in');

    reset_world();
    $service = new PaperVelocityUpdateService();
    $server = paper_server();
    Wings::put($server->uuid, 'evil.jar', '', 50_000_130);
    $tampered = pending('paper', '26.3', 130);
    $tampered['staged_jar'] = 'evil.jar';
    set_state($server, ['pending' => $tampered]);
    $service->applyPendingUpdate($server);
    check(file_size($server, 'server.jar') === 49_000_000 && file_size($server, 'evil.jar') === 50_000_130, 'an edited state file can\'t rename an arbitrary file into the jar');

    reset_world();
    $service = new PaperVelocityUpdateService();
    $server = paper_server();
    Wings::put($server->uuid, 'server.jar.pending', '', 50_000_257);
    set_state($server, ['pending' => pending('paper', '26.3', 257)]);
    Wings::$renameFailsTo = ['server.jar.pending>server.jar'];
    $service->applyPendingUpdate($server);
    check(file_size($server, 'server.jar') === 49_000_000, 'a failed swap puts the original jar back');
    check(isset(state($server)['pending']), 'and keeps the update staged for the next restart');
    check(log_contains($service, 'Swap failed', 'error'), 'and logs it');

    reset_world();
    Wings::$getContentStatus = 500;
    $service = new PaperVelocityUpdateService();
    $server = paper_server(['MINECRAFT_VERSION' => '26.2']);
    set_state($server, ['installed' => installed('paper', '26.3', 257)]);
    $service->checkForUpdates();
    Wings::$getContentStatus = null;
    check(Wings::$pulls === [] && (state($server)['installed']['version'] ?? null) === '26.3', 'an unreadable state file doesn\'t lose the installed record (and so the downgrade protection)');

    // --- Velocity ---------------------------------------------------------------------------

    reset_world();
    $service = new PaperVelocityUpdateService();
    $proxy = velocity_server();
    $service->checkForUpdates();
    check((state($proxy)['pending']['version'] ?? null) === '4.3.2-SNAPSHOT' && state($proxy)['pending']['jar'] === 'velocity.jar', 'Velocity on "latest" stages the newest build with a STABLE channel');
    $service->applyPendingUpdate($proxy);
    check(file_size($proxy, 'velocity.jar') === 50_000_040, 'and swaps it into velocity.jar');

    reset_world();
    $service = new PaperVelocityUpdateService();
    $proxy = velocity_server(['VELOCITY_VERSION' => '4.3.1']);
    $service->checkForUpdates();
    check((state($proxy)['pending']['version'] ?? null) === '4.3.1', 'a pinned Velocity version is respected');

    // --- Activity feed ----------------------------------------------------------------------

    reset_world();
    $service = new PaperVelocityUpdateService();
    $server = paper_server(['BUILD_NUMBER' => '129']);
    $service->checkForUpdates();
    $service->checkForUpdates();
    $count = count(array_filter($service->getActivityLog(), fn ($e) => str_contains($e['message'], 'BUILD_NUMBER')));
    check($count === 1, 'a permanent skip reason is logged once a day, not every check');
    check($service->getLastCheckAt() !== null, 'the last check time is recorded');

    echo Result::$failures === 0
        ? 'OK ('.Result::$checks." checks, 0 failed)\n"
        : 'FAILED ('.Result::$checks.' checks, '.Result::$failures." failed)\n";

    exit(Result::$failures === 0 ? 0 : 1);
}
