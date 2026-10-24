<?php

/*
 * Standalone tests of how GitHubPluginService replaces manual copies (1.5.0) - no Pelican,
 * Laravel or Composer needed: the few framework pieces the service touches (Cache, config(),
 * now(), app(), the activity log, the Wings file calls in DaemonFiles, Modrinth's metadata, the
 * stored offers) are replaced by small in-memory fakes below.
 *
 *     php minecraft-modrinth/tests/GitHubManualTakeoverTest.php
 *
 * Not part of the distributed plugin (dist/minecraft-modrinth.zip leaves tests/ out).
 */

declare(strict_types=1);

namespace App\Models {
    class Server
    {
        public function __construct(public int $id = 1, public string $uuid = 'uuid-1') {}
    }
}

namespace App\Facades {
    final class Activity
    {
        /** @var array<int, array<string, mixed>> */
        public static array $logged = [];

        /** @var array<string, mixed> */
        protected array $current = [];

        public static function event(string $event): self
        {
            $activity = new self();
            $activity->current = ['event' => $event, 'properties' => [], 'subjects' => []];

            return $activity;
        }

        public function subject(mixed ...$subjects): self
        {
            $this->current['subjects'] = $subjects;

            return $this;
        }

        public function property(string $key, mixed $value = null): self
        {
            $this->current['properties'][$key] = $value;

            return $this;
        }

        public function log(?string $description = null): null
        {
            self::$logged[] = $this->current;

            return null;
        }
    }
}

namespace Illuminate\Support\Facades {
    final class Cache
    {
        /** @var array<string, mixed> */
        public static array $store = [];

        public static function get(string $key, mixed $default = null): mixed
        {
            return self::$store[$key] ?? $default;
        }

        public static function put(string $key, mixed $value, mixed $ttl = null): bool
        {
            self::$store[$key] = $value;

            return true;
        }

        public static function add(string $key, mixed $value, mixed $ttl = null): bool
        {
            if (array_key_exists($key, self::$store)) {
                return false;
            }

            self::$store[$key] = $value;

            return true;
        }

        public static function forget(string $key): bool
        {
            unset(self::$store[$key]);

            return true;
        }

        public static function lock(string $name, int $seconds = 0): object
        {
            return new class
            {
                public function get(): bool
                {
                    return true;
                }

                public function release(): bool
                {
                    return true;
                }
            };
        }
    }

    final class Log
    {
        /** @var array<int, string> */
        public static array $lines = [];

        public static function info(string $message): void
        {
            self::$lines[] = $message;
        }
    }

    final class Crypt {}
}

namespace Boy132\MinecraftModrinth\Enums {
    enum ModrinthProjectType: string
    {
        case Mod = 'mod';
        case Plugin = 'plugin';
    }
}

namespace Boy132\MinecraftModrinth\Services {
    use App\Models\Server;
    use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
    use Boy132\MinecraftModrinth\Modrinth\ModrinthMetadataException;

    final class MinecraftModrinthService
    {
        /** @var array<int, string>|null jar names Modrinth manages; null: its metadata can't be read */
        public static ?array $filenames = [];

        public static int $reads = 0;

        /** @return array{entries: array<int, array{filename: string}>, raw: array<int, mixed>} */
        public function readModsMetadata(Server $server, ModrinthProjectType $type): array
        {
            self::$reads++;

            if (self::$filenames === null) {
                throw new ModrinthMetadataException('plugins/.modrinth-metadata.json is damaged');
            }

            return ['entries' => array_map(fn (string $name) => ['filename' => $name], self::$filenames), 'raw' => []];
        }
    }
}

namespace Boy132\MinecraftModrinth\Support {
    use App\Models\Server;

    /** In-memory stand-in for the Wings file API (same signatures as the real DaemonFiles). */
    final class DaemonFiles
    {
        /** @var array<string, array{content: string, modified: string, symlink?: bool, dir?: bool, size?: int, readFail?: bool}> */
        public static array $fs = [];

        /** @var array<int, string> */
        public static array $ops = [];

        /** @var array<string, string|null> metadata file content at the moment each jar was deleted */
        public static array $metadataAtDelete = [];

        /** @var array<int, string> */
        public static array $failDelete = [];

        /** @var array<int, string> */
        public static array $failWrite = [];

        public static function reset(): void
        {
            self::$fs = [];
            self::$ops = [];
            self::$metadataAtDelete = [];
            self::$failDelete = [];
            self::$failWrite = [];
        }

        public static function readSmallFile(Server $server, string $path, int $maxBytes): ?string
        {
            $file = self::$fs[$path] ?? null;

            if ($file === null) {
                return null;
            }

            if ($file['readFail'] ?? false) {
                throw new DaemonFileException(DaemonFileException::FAILED, "Could not read $path (HTTP 502)");
            }

            if (strlen($file['content']) > $maxBytes) {
                throw new DaemonFileException(DaemonFileException::TOO_LARGE, "$path is larger than $maxBytes bytes");
            }

            if (str_ends_with($path, '.jar')) {
                self::$ops[] = 'read '.basename($path);
            }

            return $file['content'];
        }

        /** @return array<int, array<string, mixed>>|null */
        public static function listDirectory(Server $server, string $folder): ?array
        {
            $entries = [];

            foreach (self::$fs as $path => $file) {
                if (dirname($path) === $folder) {
                    $entries[] = [
                        'name' => basename($path),
                        'file' => !($file['dir'] ?? false),
                        'directory' => $file['dir'] ?? false,
                        'symlink' => $file['symlink'] ?? false,
                        'size' => $file['size'] ?? strlen($file['content']),
                        'modified' => $file['modified'],
                    ];
                }
            }

            return $entries;
        }

        /**
         * @param  array<int, array<string, mixed>>  $files
         * @return array<string, mixed>|null
         */
        public static function find(array $files, string $name): ?array
        {
            foreach ($files as $file) {
                if (($file['name'] ?? null) === $name) {
                    return $file;
                }
            }

            return null;
        }

        /** @param  array<string, mixed>  $file */
        public static function isRegularFile(array $file): bool
        {
            return ($file['file'] ?? false) === true && ($file['directory'] ?? false) !== true && ($file['symlink'] ?? false) !== true;
        }

        public static function writeFile(Server $server, string $path, string $body, int $timeout): void
        {
            if (in_array($path, self::$failWrite, true)) {
                throw new DaemonFileException(DaemonFileException::FAILED, "Could not write $path (HTTP 500)");
            }

            self::$fs[$path] = ['content' => $body, 'modified' => '2026-10-24T10:00:00+00:00'];
            self::$ops[] = str_ends_with($path, '.github-plugins.json') ? 'metadata' : 'write '.basename($path);
        }

        public static function rename(Server $server, string $folder, string $from, string $to): void
        {
            if (isset(self::$fs["$folder/$to"])) {
                throw new DaemonFileException(DaemonFileException::CONFLICT, "$to exists");
            }

            self::$fs["$folder/$to"] = self::$fs["$folder/$from"];
            unset(self::$fs["$folder/$from"]);
            self::$ops[] = "rename -> $to";
        }

        public static function delete(Server $server, string $folder, string $name): void
        {
            if (in_array($name, self::$failDelete, true)) {
                throw new DaemonFileException(DaemonFileException::FAILED, "Could not delete $folder/$name (HTTP 500)");
            }

            self::$metadataAtDelete[$name] = self::$fs["$folder/.github-plugins.json"]['content'] ?? null;
            unset(self::$fs["$folder/$name"]);
            self::$ops[] = "delete $name";
        }
    }
}

namespace Boy132\MinecraftModrinth\GitHub {
    use App\Models\Server;

    /** Only what the service uses of the stored offers. */
    final class GitHubOffers
    {
        /** @var array<int, string> */
        public static array $dismissed = [];

        /** @return array<int, string> */
        public function dismissedIds(Server $server): array
        {
            return self::$dismissed;
        }

        public function forgetCounts(Server $server): void {}
    }
}

namespace {
    use App\Facades\Activity;
    use App\Models\Server;
    use Boy132\MinecraftModrinth\GitHub\CiStatus;
    use Boy132\MinecraftModrinth\GitHub\GitHubOffers;
    use Boy132\MinecraftModrinth\GitHub\GitHubPluginService;
    use Boy132\MinecraftModrinth\GitHub\GitHubSourceException;
    use Boy132\MinecraftModrinth\GitHub\GitHubSourceRules as R;
    use Boy132\MinecraftModrinth\Services\MinecraftModrinthService;
    use Boy132\MinecraftModrinth\Support\DaemonFiles;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\Log;

    require __DIR__.'/../src/GitHub/CiStatus.php';
    require __DIR__.'/../src/GitHub/GitHubSourceException.php';
    require __DIR__.'/../src/GitHub/GitHubSourceRules.php';
    require __DIR__.'/../src/Support/DaemonFileException.php';
    require __DIR__.'/../src/Modrinth/ModrinthMetadataException.php';
    require __DIR__.'/../src/GitHub/GitHubPluginService.php';

    final class TestState
    {
        /** @var array<string, mixed> */
        public static array $config = [];

        /** @var array<int, string> */
        public static array $reports = [];
    }

    function config(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, TestState::$config) ? TestState::$config[$key] : $default;
    }

    function now(): object
    {
        return new class
        {
            public function toIso8601String(): string
            {
                return '2026-10-24T10:00:00+00:00';
            }

            public function addHours(int $hours): self
            {
                return $this;
            }

            public function addMinutes(int $minutes): self
            {
                return $this;
            }
        };
    }

    function app(string $class): object
    {
        static $instances = [];

        return $instances[$class] ??= new $class();
    }

    function report(Throwable $exception): void
    {
        TestState::$reports[] = $exception->getMessage();
    }

    /** The service with the parts that need GitHub or Pelican's egg setup replaced. */
    final class TestService extends GitHubPluginService
    {
        public ?string $platform = 'paper';

        /** @var array<string, string> jar contents by sha256 */
        public array $jars = [];

        public function platformForServer(Server $server): ?string
        {
            return $this->platform;
        }

        public function downloadVerifiedJar(array $plugin, string $sha): string
        {
            return $this->jars[$plugin['sha256']];
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

    function throwsReason(callable $fn, string $reason, ?string &$message = null): bool
    {
        try {
            $fn();
        } catch (GitHubSourceException $exception) {
            $message = $exception->getMessage();

            return $exception->reason === $reason;
        }

        return false;
    }

    /** @param  array<string, string>  $entries */
    function jarBytes(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'gh-takeover-');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    function paperJar(string $name, string $version): string
    {
        return jarBytes(['plugin.yml' => "name: $name\nversion: $version\nmain: cz.ethoria.Main\n", 'cz/ethoria/Main.class' => "class $name $version"]);
    }

    /** @return array{id: string, name: string, platform: string, version: string, path: string, filename: string, sha256: string, size: int, frozen: bool} */
    function indexEntry(TestService $service, string $id, string $name, string $version, string $content, string $platform = 'paper'): array
    {
        $entry = R::validateIndexEntry([
            'id' => $id, 'name' => $name, 'platform' => $platform, 'version' => $version,
            'path' => "minecraft/$name/releases/$name-$version.jar", 'sha256' => hash('sha256', $content), 'size' => strlen($content),
        ]);
        assert($entry !== null);
        $service->jars[$entry['sha256']] = $content;

        return $entry;
    }

    /** @return array<string, mixed> */
    function metadata(): array
    {
        $content = DaemonFiles::$fs['plugins/.github-plugins.json']['content'] ?? null;

        return $content === null ? [] : (array) json_decode($content, true);
    }

    /** @return array<int, string> */
    function jarsOnServer(): array
    {
        $names = [];
        foreach (array_keys(DaemonFiles::$fs) as $path) {
            if (dirname($path) === 'plugins' && str_ends_with($path, '.jar')) {
                $names[] = basename($path);
            }
        }
        sort($names);

        return $names;
    }

    function putFile(string $name, string $content, array $extra = []): void
    {
        DaemonFiles::$fs["plugins/$name"] = ['content' => $content, 'modified' => '2026-10-20T08:00:00+00:00'] + $extra;
    }

    function resetAll(): TestService
    {
        DaemonFiles::reset();
        Cache::$store = [];
        Log::$lines = [];
        Activity::$logged = [];
        TestState::$reports = [];
        TestState::$config = [
            'minecraft-modrinth.github.replace_manual' => true,
            'minecraft-modrinth.github.report_throttle_minutes' => 0,
            'panel.guzzle.timeout' => 15,
        ];
        MinecraftModrinthService::$filenames = [];
        MinecraftModrinthService::$reads = 0;
        GitHubOffers::$dismissed = [];
        // The configuration folder of the plugin: never touched.
        DaemonFiles::$fs['plugins/EthoriaChat'] = ['content' => '', 'modified' => '2026-10-01T00:00:00+00:00', 'dir' => true];
        DaemonFiles::$fs['plugins/EthoriaChat/config.yml'] = ['content' => 'keep: true', 'modified' => '2026-10-01T00:00:00+00:00'];

        return new TestService();
    }

    $server = new Server();
    $newChat = paperJar('EthoriaChat', '1.8.1');
    $oldChat = paperJar('EthoriaChat', '1.8.0');
    $bagsJar = paperJar('EthoriaBags', '1.9.1');

    $setup = function () use ($newChat, $bagsJar): array {
        $service = resetAll();
        $chat = indexEntry($service, 'chat', 'EthoriaChat', '1.8.1', $newChat);
        $bags = indexEntry($service, 'bags', 'EthoriaBags', '1.9.1', $bagsJar);
        $snapshot = ['repository' => 'Martindob/Ethoria.cz', 'branch' => 'main', 'sha' => str_repeat('a', 40), 'ci' => CiStatus::Green, 'plugins' => [$chat, $bags], 'problems' => []];

        return [$service, $chat, $bags, $snapshot];
    };

    // ------------------------------------------------------------------
    // 1. A manual copy is replaced: new jar written, recorded, then the copy deleted
    // ------------------------------------------------------------------
    [$service, $chat, , $snapshot] = $setup();
    putFile('EthoriaChat-1.8.0.jar', $oldChat);
    putFile('LuckPerms-Bukkit-5.5.87.jar', 'foreign');

    check($service->manualReplacements($server, [$chat], ['installed' => [], 'pending_delete' => []], DaemonFiles::listDirectory($server, 'plugins') ?? [], []) === ['chat' => ['EthoriaChat-1.8.0.jar']], 'offered for replacement');

    $replaced = null;
    $entry = $service->installOrUpdate($server, $chat, $snapshot, true, false, true, $replaced);
    check($entry['filename'] === 'EthoriaChat-1.8.1.jar' && $entry['version'] === '1.8.1', 'new jar installed under the indexed name');
    check($replaced === ['EthoriaChat-1.8.0.jar'], 'replaced copies reported');
    check(jarsOnServer() === ['EthoriaChat-1.8.1.jar', 'LuckPerms-Bukkit-5.5.87.jar'], 'only one jar of the plugin left, foreign jar untouched');
    check((DaemonFiles::$fs['plugins/EthoriaChat-1.8.1.jar']['content'] ?? null) === $newChat, 'new jar content written');
    check(isset(DaemonFiles::$fs['plugins/EthoriaChat/config.yml']), 'configuration folder untouched');

    $ops = DaemonFiles::$ops;
    $renameAt = array_search('rename -> EthoriaChat-1.8.1.jar', $ops, true);
    $metadataAt = array_search('metadata', $ops, true);
    $deleteAt = array_search('delete EthoriaChat-1.8.0.jar', $ops, true);
    check(is_int($renameAt) && is_int($metadataAt) && is_int($deleteAt) && $renameAt < $metadataAt && $metadataAt < $deleteAt, 'order: new jar, then metadata, then delete the copy');
    check(in_array('read EthoriaChat-1.8.0.jar', array_slice($ops, 0, (int) $renameAt), true), 'copy checked (read) before anything is written');

    $atDelete = json_decode((string) DaemonFiles::$metadataAtDelete['EthoriaChat-1.8.0.jar'], true);
    check(($atDelete['installed'][0]['filename'] ?? null) === 'EthoriaChat-1.8.1.jar', 'at the time of deleting, the new jar is recorded');
    check(($atDelete['pending_delete'] ?? null) === [['filename' => 'EthoriaChat-1.8.0.jar', 'size' => strlen($oldChat), 'replaced_by' => 'EthoriaChat 1.8.1']], 'at the time of deleting, the copy is recorded as pending');

    $meta = metadata();
    check(count($meta['installed']) === 1 && !isset($meta['pending_delete']), 'afterwards: one entry, nothing pending');
    check(count(Activity::$logged) === 1 && Activity::$logged[0]['event'] === 'server:file.delete'
        && Activity::$logged[0]['properties']['files'] === ['EthoriaChat-1.8.0.jar'] && Activity::$logged[0]['properties']['directory'] === 'plugins'
        && str_contains((string) Activity::$logged[0]['properties']['replaced_by'], 'EthoriaChat 1.8.1') && Activity::$logged[0]['subjects'] === [$server], 'activity log entry with the deleted file');
    check(count(Log::$lines) === 1 && str_contains(Log::$lines[0], 'plugins/EthoriaChat-1.8.0.jar') && str_contains(Log::$lines[0], '#1'), 'panel log line with the deleted file');

    // ------------------------------------------------------------------
    // 2. The manual copy has exactly the indexed name: the new jar gets an alternative name
    // ------------------------------------------------------------------
    [$service, $chat, , $snapshot] = $setup();
    putFile('EthoriaChat-1.8.1.jar', $oldChat);
    $entry = $service->installOrUpdate($server, $chat, $snapshot, true, false, true, $replaced);
    check($entry['filename'] === R::alternativeFilename('EthoriaChat-1.8.1.jar', $chat['sha256']) && jarsOnServer() === [$entry['filename']], 'same name: written next to it under another name, copy deleted');
    check($replaced === ['EthoriaChat-1.8.1.jar'], 'same name: copy reported');

    // ------------------------------------------------------------------
    // 3. A jar named like the plugin but declaring another one is never deleted
    // ------------------------------------------------------------------
    [$service, $chat, , $snapshot] = $setup();
    putFile('EthoriaChat.jar', paperJar('SomethingElse', '1.0'));
    $message = null;
    check(throwsReason(fn () => $service->installOrUpdate($server, $chat, $snapshot, true, false, true), GitHubSourceException::MANUAL_COPY, $message), 'other plugin inside: refused');
    check(str_contains((string) $message, 'somethingelse'), 'refusal names what the jar declares');
    check(jarsOnServer() === ['EthoriaChat.jar'] && !in_array('metadata', DaemonFiles::$ops, true) && !preg_grep('/^(write|rename|delete)/', DaemonFiles::$ops), 'other plugin inside: nothing written or deleted');
    check($service->manualReplacements($server, [$chat], ['installed' => [], 'pending_delete' => []], DaemonFiles::listDirectory($server, 'plugins') ?? [], []) === [], 'refused copy no longer offered');
    DaemonFiles::$ops = [];
    check(throwsReason(fn () => $service->installOrUpdate($server, $chat, $snapshot, true, false, true), GitHubSourceException::MANUAL_COPY), 'refused copy: refused again');
    check(!in_array('read EthoriaChat.jar', DaemonFiles::$ops, true), 'refused copy is not downloaded again');
    // Replaced by hand with a different file: checked again.
    putFile('EthoriaChat.jar', $oldChat, ['modified' => '2026-10-24T09:00:00+00:00']);
    $service->installOrUpdate($server, $chat, $snapshot, true, false, true, $replaced);
    check($replaced === ['EthoriaChat.jar'] && jarsOnServer() === ['EthoriaChat-1.8.1.jar'], 'changed copy checked again and replaced');

    // A jar without plugin.yml (or not a jar at all) is not deleted either.
    [$service, $chat, , $snapshot] = $setup();
    putFile('EthoriaChat-1.0.jar', 'not a zip');
    check(throwsReason(fn () => $service->installOrUpdate($server, $chat, $snapshot, true, false, true), GitHubSourceException::MANUAL_COPY) && jarsOnServer() === ['EthoriaChat-1.0.jar'], 'unreadable copy: refused, kept');

    // ------------------------------------------------------------------
    // 4. Copies that are never replaced: Modrinth's, symlinks, ambiguous, too many, too large
    // ------------------------------------------------------------------
    [$service, $chat, , $snapshot] = $setup();
    putFile('EthoriaChat-1.8.0.jar', $oldChat);
    MinecraftModrinthService::$filenames = ['EthoriaChat-1.8.0.jar'];
    check(throwsReason(fn () => $service->installOrUpdate($server, $chat, $snapshot, true, false, true), GitHubSourceException::MANUAL_COPY) && jarsOnServer() === ['EthoriaChat-1.8.0.jar'], 'Modrinth-managed copy: refused, kept');
    check($service->manualReplacements($server, [$chat], ['installed' => [], 'pending_delete' => []], DaemonFiles::listDirectory($server, 'plugins') ?? [], []) === [], 'Modrinth-managed copy not offered');

    MinecraftModrinthService::$filenames = null;
    check(throwsReason(fn () => $service->installOrUpdate($server, $chat, $snapshot, true, false, true), GitHubSourceException::MANUAL_COPY) && jarsOnServer() === ['EthoriaChat-1.8.0.jar'], 'Modrinth metadata unreadable: refused, kept');
    check($service->manualReplacements($server, [$chat], ['installed' => [], 'pending_delete' => []], DaemonFiles::listDirectory($server, 'plugins') ?? [], []) === [], 'Modrinth metadata unreadable: nothing offered');

    [$service, $chat, , $snapshot] = $setup();
    putFile('EthoriaChat.jar', $oldChat, ['symlink' => true]);
    check(throwsReason(fn () => $service->installOrUpdate($server, $chat, $snapshot, true, false, true), GitHubSourceException::MANUAL_COPY) && jarsOnServer() === ['EthoriaChat.jar'], 'symlink: refused, kept');

    [$service, $chat, , $snapshot] = $setup();
    $chatPlus = indexEntry($service, 'chatplus', 'Other', '2.0.0', paperJar('Other', '2.0.0'));
    $chatPlus['filename'] = 'ethoriachat-2.0.0.jar'; // its file stem is the same as EthoriaChat's name
    $snapshot['plugins'][] = $chatPlus;
    putFile('EthoriaChat.jar', $oldChat);
    check(throwsReason(fn () => $service->installOrUpdate($server, $chat, $snapshot, true, false, true), GitHubSourceException::MANUAL_COPY) && jarsOnServer() === ['EthoriaChat.jar'], 'jar matching two index entries: refused, kept');

    [$service, $chat, , $snapshot] = $setup();
    foreach (['EthoriaChat.jar', 'EthoriaChat-1.jar', 'EthoriaChat-2.jar', 'EthoriaChat-3.jar'] as $name) {
        putFile($name, $oldChat);
    }
    check(throwsReason(fn () => $service->installOrUpdate($server, $chat, $snapshot, true, false, true), GitHubSourceException::MANUAL_COPY) && count(jarsOnServer()) === 4, 'four copies: refused, all kept');

    [$service, $chat, , $snapshot] = $setup();
    putFile('EthoriaChat.jar', $oldChat, ['size' => R::MAX_JAR_BYTES + 1]);
    check(throwsReason(fn () => $service->installOrUpdate($server, $chat, $snapshot, true, false, true), GitHubSourceException::MANUAL_COPY) && jarsOnServer() === ['EthoriaChat.jar'], 'copy over 50 MB: not read, refused, kept');

    // A copy that can't be read right now: refused for now, not remembered as refused.
    [$service, $chat, , $snapshot] = $setup();
    putFile('EthoriaChat.jar', $oldChat, ['readFail' => true]);
    check(throwsReason(fn () => $service->installOrUpdate($server, $chat, $snapshot, true, false, true), GitHubSourceException::DAEMON) && jarsOnServer() === ['EthoriaChat.jar'], 'node read failure: refused, kept');
    unset(DaemonFiles::$fs['plugins/EthoriaChat.jar']['readFail']);
    $service->installOrUpdate($server, $chat, $snapshot, true, false, true, $replaced);
    check($replaced === ['EthoriaChat.jar'], 'after the node works again it is replaced');

    // ------------------------------------------------------------------
    // 5. Setting off / plain "Install": the copy is a conflict as in 1.4.1
    // ------------------------------------------------------------------
    [$service, $chat, , $snapshot] = $setup();
    putFile('EthoriaChat-1.8.0.jar', $oldChat);
    check(throwsReason(fn () => $service->installOrUpdate($server, $chat, $snapshot, true, false), GitHubSourceException::FILE_CONFLICT) && jarsOnServer() === ['EthoriaChat-1.8.0.jar'], 'plain install: conflict as before');
    TestState::$config['minecraft-modrinth.github.replace_manual'] = false;
    check(throwsReason(fn () => $service->installOrUpdate($server, $chat, $snapshot, true, false, true), GitHubSourceException::FILE_CONFLICT) && jarsOnServer() === ['EthoriaChat-1.8.0.jar'], 'setting off: conflict as before');
    $reads = MinecraftModrinthService::$reads;
    check($service->manualReplacements($server, [$chat], ['installed' => [], 'pending_delete' => []], DaemonFiles::listDirectory($server, 'plugins') ?? [], []) === [] && MinecraftModrinthService::$reads === $reads, 'setting off: nothing offered, nothing read');

    // Modrinth's metadata is only read when there is a candidate at all.
    [$service, $chat] = $setup();
    $service->manualReplacements($server, [$chat], ['installed' => [], 'pending_delete' => []], DaemonFiles::listDirectory($server, 'plugins') ?? [], []);
    check(MinecraftModrinthService::$reads === 0, 'no candidate: Modrinth metadata not read');

    // ------------------------------------------------------------------
    // 6. Deleting the copy fails: recorded as pending, deleted (and logged) later
    // ------------------------------------------------------------------
    [$service, $chat, , $snapshot] = $setup();
    putFile('EthoriaChat-1.8.0.jar', $oldChat);
    DaemonFiles::$failDelete = ['EthoriaChat-1.8.0.jar'];
    check(throwsReason(fn () => $service->installOrUpdate($server, $chat, $snapshot, true, false, true), GitHubSourceException::OLD_JAR_PENDING), 'copy not deletable: reported as pending');
    $meta = metadata();
    check(($meta['installed'][0]['filename'] ?? null) === 'EthoriaChat-1.8.1.jar' && ($meta['pending_delete'][0]['filename'] ?? null) === 'EthoriaChat-1.8.0.jar', 'new jar recorded, copy pending');
    check(Activity::$logged === [], 'nothing logged as deleted yet');
    check($service->manualReplacements($server, [$chat], $service->readMetadata($server), DaemonFiles::listDirectory($server, 'plugins') ?? [], []) === [], 'installed now: not offered again');
    DaemonFiles::$failDelete = [];
    $service->cleanupServer($server);
    check(jarsOnServer() === ['EthoriaChat-1.8.1.jar'] && !isset(metadata()['pending_delete']), 'cleanup deletes the pending copy');
    check(count(Activity::$logged) === 1 && Activity::$logged[0]['properties']['files'] === ['EthoriaChat-1.8.0.jar'], 'cleanup logs the deleted copy');

    // ------------------------------------------------------------------
    // 7. The metadata can't be written: the new jar is removed, the copy stays
    // ------------------------------------------------------------------
    [$service, $chat, , $snapshot] = $setup();
    putFile('EthoriaChat-1.8.0.jar', $oldChat);
    DaemonFiles::$failWrite = ['plugins/.github-plugins.json'];
    check(throwsReason(fn () => $service->installOrUpdate($server, $chat, $snapshot, true, false, true), GitHubSourceException::METADATA), 'metadata write failure reported');
    check(jarsOnServer() === ['EthoriaChat-1.8.0.jar'] && metadata() === [], 'metadata write failure: new jar removed, copy kept, nothing recorded');
    check(Activity::$logged === [], 'metadata write failure: nothing logged');

    // ------------------------------------------------------------------
    // 8. Update of an installed plugin also replaces a manual copy next to it
    // ------------------------------------------------------------------
    [$service, $chat, , $snapshot] = $setup();
    putFile('EthoriaChat-1.8.0.jar', $oldChat);
    DaemonFiles::$fs['plugins/.github-plugins.json'] = ['content' => json_encode(['schema' => 1, 'installed' => [[
        'id' => 'chat', 'name' => 'EthoriaChat', 'platform' => 'paper', 'version' => '1.8.0', 'sha256' => hash('sha256', $oldChat), 'size' => strlen($oldChat),
        'filename' => 'EthoriaChat-1.8.0.jar', 'repository' => 'Martindob/Ethoria.cz', 'commit' => str_repeat('b', 40), 'installed_at' => '2026-10-01T00:00:00+00:00',
    ]]]), 'modified' => '2026-10-01T00:00:00+00:00'];
    putFile('EthoriaChat.jar', $oldChat);
    check(throwsReason(fn () => $service->installOrUpdate($server, $chat, $snapshot, true, true, false), GitHubSourceException::FILE_CONFLICT), 'update without replacing: copy is a conflict');
    $service->installOrUpdate($server, $chat, $snapshot, true, true, true, $replaced);
    check(jarsOnServer() === ['EthoriaChat-1.8.1.jar'] && $replaced === ['EthoriaChat.jar'], 'update: old jar and manual copy both gone');
    check(count(Activity::$logged) === 1 && Activity::$logged[0]['properties']['files'] === ['EthoriaChat.jar'], 'update: only the manual copy is logged');

    // ------------------------------------------------------------------
    // 9. Daily automatic update: replaces copies of plugins that aren't hidden
    // ------------------------------------------------------------------
    [$service, $chat, $bags, $snapshot] = $setup();
    putFile('EthoriaChat-1.8.0.jar', $oldChat);
    putFile('EthoriaBags.jar', paperJar('EthoriaBags', '1.0.0'));
    GitHubOffers::$dismissed = ['bags'];
    $done = $service->autoUpdateServer($server, $snapshot);
    check($done === ['chat (replaced: EthoriaChat-1.8.0.jar)'], 'auto update replaced the copy');
    check(jarsOnServer() === ['EthoriaBags.jar', 'EthoriaChat-1.8.1.jar'], 'auto update: hidden plugin left alone');

    [$service, , , $snapshot] = $setup();
    putFile('EthoriaChat-1.8.0.jar', $oldChat);
    $snapshot['ci'] = CiStatus::Pending;
    check($service->autoUpdateServer($server, $snapshot) === [] && jarsOnServer() === ['EthoriaChat-1.8.0.jar'], 'auto update: CI not green, nothing replaced');

    [$service, , , $snapshot] = $setup();
    putFile('EthoriaChat-1.8.0.jar', $oldChat);
    TestState::$config['minecraft-modrinth.github.replace_manual'] = false;
    check($service->autoUpdateServer($server, $snapshot) === [] && jarsOnServer() === ['EthoriaChat-1.8.0.jar'], 'auto update: setting off, nothing replaced');

    [$service, , , $snapshot] = $setup();
    putFile('EthoriaChat.jar', paperJar('SomethingElse', '1.0'));
    check($service->autoUpdateServer($server, $snapshot) === [] && jarsOnServer() === ['EthoriaChat.jar'], 'auto update: other plugin inside is kept');
    check($service->autoUpdateServer($server, $snapshot) === [], 'auto update: refused copy is not tried again');

    // ------------------------------------------------------------------
    // 10. Velocity: compared by the id in velocity-plugin.json
    // ------------------------------------------------------------------
    $service = resetAll();
    $service->platform = 'velocity';
    $velocityJar = fn (string $id, string $version) => jarBytes(['velocity-plugin.json' => json_encode(['id' => $id, 'name' => 'EthoriaMotd', 'version' => $version])]);
    $motd = indexEntry($service, 'ethoriamotd', 'EthoriaMotd', '1.2.0', $velocityJar('ethoriamotd', '1.2.0'), 'velocity');
    $vSnapshot = ['repository' => 'Martindob/Ethoria.cz', 'branch' => 'main', 'sha' => str_repeat('c', 40), 'ci' => CiStatus::Green, 'plugins' => [$motd], 'problems' => []];
    putFile('EthoriaMotd-1.1.0.jar', $velocityJar('ethoriamotd', '1.1.0'));
    $service->installOrUpdate($server, $motd, $vSnapshot, true, false, true, $replaced);
    check($replaced === ['EthoriaMotd-1.1.0.jar'] && jarsOnServer() === ['EthoriaMotd-1.2.0.jar'], 'velocity copy replaced');

    $service = resetAll();
    $service->platform = 'velocity';
    $motd = indexEntry($service, 'ethoriamotd', 'EthoriaMotd', '1.2.0', $velocityJar('ethoriamotd', '1.2.0'), 'velocity');
    putFile('EthoriaMotd.jar', $velocityJar('othermotd', '1.0.0'));
    check(throwsReason(fn () => $service->installOrUpdate($server, $motd, $vSnapshot, true, false, true), GitHubSourceException::MANUAL_COPY) && jarsOnServer() === ['EthoriaMotd.jar'], 'velocity copy with another id kept');

    // A paper plugin is never installed on (or takes over anything on) a velocity server.
    $service = resetAll();
    $service->platform = 'velocity';
    [, $chat] = [null, indexEntry($service, 'chat', 'EthoriaChat', '1.8.1', $newChat)];
    putFile('EthoriaChat-1.8.0.jar', $oldChat);
    check(throwsReason(fn () => $service->installOrUpdate($server, $chat, $snapshot, true, false, true), GitHubSourceException::INVALID_INDEX) && jarsOnServer() === ['EthoriaChat-1.8.0.jar'], 'wrong platform: nothing touched');

    echo (Result::$failures === 0 ? 'OK' : 'FAILED').' ('.Result::$checks.' checks, '.Result::$failures." failed)\n";
    exit(Result::$failures === 0 ? 0 : 1);
}
