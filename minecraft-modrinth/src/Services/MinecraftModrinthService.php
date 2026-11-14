<?php

namespace Boy132\MinecraftModrinth\Services;

use App\Models\Server;
use Boy132\MinecraftModrinth\Enums\ModrinthProjectType;
use Boy132\MinecraftModrinth\GitHub\GitHubSourceRules;
use Boy132\MinecraftModrinth\Modrinth\ModrinthMetadataException;
use Boy132\MinecraftModrinth\Modrinth\ModrinthRules;
use Boy132\MinecraftModrinth\Support\DaemonFileException;
use Boy132\MinecraftModrinth\Support\DaemonFiles;
use Exception;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

class MinecraftModrinthService
{
    // Seconds a download from Modrinth, and the upload of it to the daemon, may take.
    protected const DOWNLOAD_TIMEOUT = 120;

    // Version lists fetched from Modrinth at the same time (a full page has 20 projects).
    protected const POOL_CONCURRENCY = 5;

    // Version list cache TTL (minutes); kept short so a transient empty Modrinth response can't hide updates for long.
    protected const VERSIONS_CACHE_MINUTES = 30;

    protected const EMPTY_VERSIONS_CACHE_MINUTES = 5;

    public function getMinecraftVersion(Server $server): ?string
    {
        return $this->getConfiguredMinecraftVersion($server) ?? $this->getLatestMinecraftVersion();
    }

    public function getLatestMinecraftVersion(): ?string
    {
        return cache()->remember('modrinth:latest_minecraft_version', now()->addHour(), function () {
            try {
                /** @var array<int, mixed> $versions */
                $versions = Http::asJson()
                    ->timeout(5)
                    ->connectTimeout(5)
                    ->throw()
                    ->get('https://api.modrinth.com/v2/tag/game_version')
                    ->json();

                return collect($versions)->filter(fn ($version) => $version['version_type'] === 'release')->first()['version'] ?? null;
            } catch (Exception $exception) {
                report($exception);

                return null;
            }
        });
    }

    /**
     * The most recent Modrinth release tags, newest first. Used instead of a single exact
     * "latest" version when a server has no explicit Minecraft version configured: plugin
     * authors often lag behind re-tagging support for the very newest release even though
     * nothing in the plugin actually changed, so a single exact match hides plugins that
     * work fine on it. This has no effect once a server sets an explicit version, since that
     * is treated as an exact requirement instead.
     *
     * @return string[]
     */
    protected function getRecentMinecraftVersions(): array
    {
        return cache()->remember('modrinth:recent_minecraft_versions', now()->addHour(), function () {
            try {
                /** @var array<int, mixed> $versions */
                $versions = Http::asJson()
                    ->timeout(5)
                    ->connectTimeout(5)
                    ->throw()
                    ->get('https://api.modrinth.com/v2/tag/game_version')
                    ->json();

                return collect($versions)
                    ->filter(fn ($version) => $version['version_type'] === 'release')
                    ->take(5)
                    ->pluck('version')
                    ->all();
            } catch (Exception $exception) {
                report($exception);

                return [];
            }
        });
    }

    protected function getConfiguredMinecraftVersion(Server $server): ?string
    {
        $version = $server->variables()->where(fn ($builder) => $builder->where('env_variable', 'MINECRAFT_VERSION')->orWhere('env_variable', 'MC_VERSION'))->first()?->server_value;

        return ($version && $version !== 'latest') ? $version : null;
    }

    /**
     * Minecraft versions to filter search/version-list results by. An explicit server
     * version is an exact requirement (a single value), but a server without one falls
     * back to a small window of the most recent releases rather than only the single
     * newest, for the reason explained on getRecentMinecraftVersions().
     *
     * @return string[]
     */
    protected function getMinecraftVersionsForFiltering(Server $server): array
    {
        $configured = $this->getConfiguredMinecraftVersion($server);
        if ($configured) {
            return [$configured];
        }

        $recent = $this->getRecentMinecraftVersions();
        if (!empty($recent)) {
            return $recent;
        }

        return array_filter([$this->getLatestMinecraftVersion()]);
    }

    /** @return array{icon: string, name: string, supported_project_types: string[], display_name: string}|null */
    public function getLoaderFromServer(Server $server): ?array
    {
        $server->loadMissing('egg');

        $tags = $server->egg->tags ?? [];

        if (!in_array('minecraft', $tags)) {
            return null;
        }

        $projectTypes = array_map(fn (ModrinthProjectType $projectType) => $projectType->value, ModrinthProjectType::fromServer($server));
        if (empty($projectTypes)) {
            return null;
        }

        $loaders = $this->getLoaders();
        foreach ($loaders as $loader) {
            if (!array_intersect($loader['supported_project_types'], $projectTypes)) {
                continue;
            }

            if (in_array($loader['name'], $tags)) {
                return array_merge($loader, ['display_name' => str($loader['name'])->title()->toString()]);
            }
        }

        return null;
    }

    /** @return array<int, array{icon: string, name: string, supported_project_types: string[]}> */
    public function getLoaders(): array
    {
        return cache()->remember('modrinth:loaders', now()->addHour(), function () {
            try {
                return Http::asJson()
                    ->timeout(5)
                    ->connectTimeout(5)
                    ->throw()
                    ->get('https://api.modrinth.com/v2/tag/loader')
                    ->json();
            } catch (Exception $exception) {
                report($exception);

                return [];
            }
        });
    }

    /**
     * Loaders that can also run plugins/mods published only for an upstream loader they
     * are backwards-compatible with, e.g. a Paper server can run plain Spigot/Bukkit plugins.
     * Only one direction: a plugin published for the fork isn't guaranteed to run on the
     * upstream loader, so the reverse mapping is intentionally not added.
     *
     * @return string[]
     */
    protected function getCompatibleLoaders(string $loader): array
    {
        return match ($loader) {
            'spigot' => ['spigot', 'bukkit'],
            'paper' => ['paper', 'spigot', 'bukkit'],
            'purpur' => ['purpur', 'paper', 'spigot', 'bukkit'],
            'folia' => ['folia', 'paper', 'spigot', 'bukkit'],
            'waterfall' => ['waterfall', 'bungeecord'],
            'quilt' => ['quilt', 'fabric'],
            default => [$loader],
        };
    }

    /**
     * Proxy loaders aren't tied to a specific Minecraft version the way a server is: they
     * relay the protocol for whatever version the backend servers run, so a plugin's declared
     * Minecraft game versions on Modrinth mostly just reflect whenever it was last published,
     * not what it's actually compatible with. Filtering those by an exact game version hides
     * older but still working proxy plugins, so the version filter is skipped for them.
     */
    protected function isProxyLoader(string $loader): bool
    {
        return in_array($loader, ['velocity', 'bungeecord', 'waterfall'], true);
    }

    /**
     * Whether search/version-list results should be restricted to a Minecraft version at
     * all. Always false for proxy loaders (see isProxyLoader()), and also false when the
     * "Always Use Latest Version" plugin setting is on, for admins who'd rather always get
     * the newest available mod/plugin version - e.g. to update everything ahead of upgrading
     * the server itself to a newer Minecraft version - and accept the (usual) backwards
     * compatibility risk themselves instead of waiting on Modrinth authors to re-tag support.
     */
    protected function shouldFilterByMinecraftVersion(string $loader): bool
    {
        return !$this->isProxyLoader($loader) && !(bool) config('minecraft-modrinth.always_use_latest_version');
    }

    /**
     * Cache keys below only vary by loader/version/project, so without this, flipping the
     * "Always Use Latest Version" setting would keep serving whatever was cached under the
     * same key before the flip for up to its TTL, since the setting itself isn't part of it.
     */
    protected function versionFilterCacheSuffix(string $loader): string
    {
        return $this->shouldFilterByMinecraftVersion($loader) ? 'exact' : 'latest';
    }

    /**
     * Modrinth's own "project_type" field (mod/plugin/resourcepack/...) is whatever the
     * author picked when they first created the project; a Bukkit-family project can be
     * stored as "mod" even though it only has paper/spigot/purpur versions and Modrinth's
     * own site lists it under /plugin/ (it decides that split by loader, not this field).
     * Filtering strictly by our own Mod/Plugin enum value against that field hid projects
     * like https://modrinth.com/plugin/excellenteconomy this way. The loader/category facet
     * already discriminates mod-loader projects (fabric/forge/...) from plugin-loader ones
     * (paper/spigot/...), so project_type is only kept as a loose safety net excluding
     * unrelated types like resourcepacks/shaders/datapacks, not as the mod/plugin split.
     *
     * @return array{hits: array<int, array<string, mixed>>, total_hits: int}
     */
    public function getProjects(Server $server, ModrinthProjectType $modrinthProjectType, int $page = 1, ?string $search = null): array
    {
        $modrinthProjectType = $modrinthProjectType->value;
        $minecraftLoader = $this->getLoaderFromServer($server);

        if (!$minecraftLoader) {
            return [
                'hits' => [],
                'total_hits' => 0,
            ];
        }

        $minecraftVersion = $this->getMinecraftVersion($server);
        $minecraftLoader = $minecraftLoader['name'];

        $loaderFacets = implode(',', array_map(fn ($loader) => "\"categories:$loader\"", $this->getCompatibleLoaders($minecraftLoader)));

        $facetGroups = ["[$loaderFacets]"];
        if ($this->shouldFilterByMinecraftVersion($minecraftLoader)) {
            $versionFacets = implode(',', array_map(fn ($version) => "\"versions:$version\"", $this->getMinecraftVersionsForFiltering($server)));
            $facetGroups[] = "[$versionFacets]";
        }
        $facetGroups[] = '["project_type:mod","project_type:plugin"]';

        $data = [
            'offset' => ($page - 1) * 20,
            'limit' => 20,
            'facets' => '['.implode(',', $facetGroups).']',
        ];

        $key = "modrinth_projects:{$modrinthProjectType}:$minecraftVersion:$minecraftLoader:".$this->versionFilterCacheSuffix($minecraftLoader).":$page";

        if ($search) {
            $data['query'] = $search;

            $key .= ':'.md5($search);
        }

        $cached = cache()->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = Http::asJson()
                ->timeout(5)
                ->connectTimeout(5)
                ->throw()
                ->get('https://api.modrinth.com/v2/search', $data)
                ->json();
        } catch (Exception $exception) {
            report($exception);

            // not caching on purpose since caching a failure would keep the page empty for 30 minutes
            return [
                'hits' => [],
                'total_hits' => 0,
            ];
        }

        cache()->put($key, $response, now()->addMinutes(30));

        return $response;
    }

    /**
     * @param  array<int, array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}>  $installedMods
     * @return array<int, array<string, mixed>>
     */
    public function getInstalledModsFromModrinth(array $installedMods, int $page = 1): array
    {
        if (empty($installedMods)) {
            return [];
        }

        $installedModsById = [];
        foreach ($installedMods as $mod) {
            if (!isset($installedModsById[$mod['project_id']])) {
                $installedModsById[$mod['project_id']] = $mod;
            }
        }

        $projectIds = array_keys($installedModsById);

        $perPage = 20;
        $offset = ($page - 1) * $perPage;
        $pageIds = array_slice($projectIds, $offset, $perPage);

        if (empty($pageIds)) {
            return [];
        }

        $idsParam = json_encode($pageIds, JSON_THROW_ON_ERROR);
        $cacheKey = 'modrinth_bulk:'.md5($idsParam);

        $modrinthProjects = cache()->get($cacheKey);

        if (!is_array($modrinthProjects)) {
            try {
                $modrinthProjects = Http::asJson()
                    ->timeout(10)
                    ->connectTimeout(5)
                    ->throw()
                    ->get('https://api.modrinth.com/v2/projects', [
                        'ids' => $idsParam,
                    ])
                    ->json();

                if (!is_array($modrinthProjects)) {
                    $modrinthProjects = [];
                }

                cache()->put($cacheKey, $modrinthProjects, now()->addMinutes(30));
            } catch (Exception $exception) {
                report($exception);

                $modrinthProjects = [];
            }
        }

        $modrinthMap = [];
        foreach ($modrinthProjects as $project) {
            if (isset($project['id'])) {
                $modrinthMap[$project['id']] = $project;
            }
        }

        $results = [];
        foreach ($pageIds as $projectId) {
            $installedMod = $installedModsById[$projectId];

            if (isset($modrinthMap[$projectId])) {
                $project = $modrinthMap[$projectId];
                $project['project_id'] = $project['id'];
                if (isset($project['updated']) && !isset($project['date_modified'])) {
                    $project['date_modified'] = $project['updated'];
                }
                if (isset($installedMod['author']) && !isset($project['author'])) {
                    $project['author'] = $installedMod['author'];
                }
                $results[] = $project;
            } else {
                $results[] = [
                    'project_id' => $installedMod['project_id'],
                    'slug' => $installedMod['project_slug'],
                    'title' => $installedMod['project_title'],
                    'description' => trans('minecraft-modrinth::strings.page.mod_unavailable'),
                    'icon_url' => null,
                    'author' => $installedMod['author'] ?? '',
                    'downloads' => 0,
                    'date_modified' => $installedMod['installed_at'],
                    'project_type' => '',
                    'unavailable' => true,
                ];
            }
        }

        return $results;
    }

    protected function getVersionsCacheKey(string $projectId, ?string $minecraftVersion, string $minecraftLoader): string
    {
        return "modrinth_versions:$projectId:$minecraftVersion:$minecraftLoader:".$this->versionFilterCacheSuffix($minecraftLoader);
    }

    /** @return array{game_versions?: string, loaders: string} */
    protected function getVersionsQuery(Server $server, string $minecraftLoader): array
    {
        $loaders = implode(',', array_map(fn ($loader) => "\"$loader\"", $this->getCompatibleLoaders($minecraftLoader)));

        $query = [
            'loaders' => "[$loaders]",
        ];

        if ($this->shouldFilterByMinecraftVersion($minecraftLoader)) {
            $versions = implode(',', array_map(fn ($version) => "\"$version\"", $this->getMinecraftVersionsForFiltering($server)));
            $query['game_versions'] = "[$versions]";
        }

        return $query;
    }

    /** @param  array<int, mixed>  $versions */
    protected function cacheVersions(string $key, array $versions): void
    {
        cache()->put($key, $versions, now()->addMinutes(empty($versions) ? self::EMPTY_VERSIONS_CACHE_MINUTES : self::VERSIONS_CACHE_MINUTES));
    }

    /**
     * @param  array<int, mixed>  $versions
     * @return array<int, mixed>
     */
    protected function sortVersions(array $versions): array
    {
        usort($versions, fn ($a, $b) => strcmp($b['date_published'] ?? '', $a['date_published'] ?? ''));

        return $versions;
    }

    /** @return array<array{name: string, version_number: string, changelog: ?string, dependencies: array<mixed>, game_version: string[], version_type: string, loaders: string[], featured: bool, status: string, requested_status: ?string, id: string, project_id: string, author_id: string, date_published: string, downloads: int, changelog_url: ?string, files: array<mixed>}> */
    public function getProjectVersions(string $projectId, Server $server): array
    {
        $minecraftLoader = $this->getLoaderFromServer($server);

        if (!$minecraftLoader) {
            return [];
        }

        $minecraftVersion = $this->getMinecraftVersion($server);
        $minecraftLoader = $minecraftLoader['name'];

        $key = $this->getVersionsCacheKey($projectId, $minecraftVersion, $minecraftLoader);

        $cached = cache()->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $versions = Http::asJson()
                ->timeout(5)
                ->connectTimeout(5)
                ->throw()
                ->get('https://api.modrinth.com/v2/project/'.rawurlencode($projectId).'/version', $this->getVersionsQuery($server, $minecraftLoader))
                ->json();
        } catch (Exception $exception) {
            report($exception);

            // not cached on purpose since an empty list would hide the update action for 30 minutes.
            return [];
        }

        $versions = is_array($versions) ? $this->sortVersions($versions) : [];

        $this->cacheVersions($key, $versions);

        return $versions;
    }

    /**
     * fetch compatible versions of several projects at once, a full installed
     * tab used to cost up to 20 sequential Modrinth round trips whilst this fetches in bulk.
     *
     * @param  array<int, string>  $projectIds
     * @return array<string, array<int, mixed>>
     */
    public function getProjectVersionsBulk(array $projectIds, Server $server): array
    {
        $projectIds = array_values(array_unique(array_filter($projectIds)));

        if (empty($projectIds)) {
            return [];
        }

        $minecraftLoader = $this->getLoaderFromServer($server);

        if (!$minecraftLoader) {
            return array_fill_keys($projectIds, []);
        }

        $minecraftVersion = $this->getMinecraftVersion($server);
        $minecraftLoader = $minecraftLoader['name'];
        $query = $this->getVersionsQuery($server, $minecraftLoader);

        $results = [];
        $missing = [];

        foreach ($projectIds as $projectId) {
            $cached = cache()->get($this->getVersionsCacheKey($projectId, $minecraftVersion, $minecraftLoader));

            if (is_array($cached)) {
                $results[$projectId] = $cached;
            } else {
                $missing[] = $projectId;
            }
        }

        if (empty($missing)) {
            return $results;
        }

        // In small batches: a pool of every project at once opens that many connections to
        // Modrinth at the same moment.
        $responses = [];
        foreach (array_chunk($missing, self::POOL_CONCURRENCY) as $batch) {
            try {
                $responses += Http::pool(fn (Pool $pool) => array_map(
                    fn (string $projectId) => $pool->as($projectId)
                        ->asJson()
                        ->timeout(10)
                        ->connectTimeout(5)
                        ->get('https://api.modrinth.com/v2/project/'.rawurlencode($projectId).'/version', $query),
                    $batch
                ));
            } catch (Exception $exception) {
                report($exception);
            }
        }

        foreach ($missing as $projectId) {
            $response = $responses[$projectId] ?? null;

            if ($response instanceof Response && $response->successful()) {
                $versions = $response->json();
                $versions = is_array($versions) ? $this->sortVersions($versions) : [];

                $this->cacheVersions($this->getVersionsCacheKey($projectId, $minecraftVersion, $minecraftLoader), $versions);

                $results[$projectId] = $versions;

                continue;
            }

            if ($response instanceof Exception) {
                report($response);
            }

            $results[$projectId] = [];
        }

        return $results;
    }

    /**
     * @throws Exception
     */
    protected function getMetadataFilePath(ModrinthProjectType $modrinthProjectType): string
    {
        return join_paths($modrinthProjectType->getFolder(), '.modrinth-metadata.json');
    }

    /**
     * Installed mods/plugins for display and automatic updates. Anything that can't be read
     * right now counts as nothing installed here - never use this list to write the file back,
     * see readModsMetadata().
     *
     * @return array<int, array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}>
     */
    public function getInstalledModsMetadata(Server $server, ModrinthProjectType $modrinthProjectType): array
    {
        try {
            return $this->readModsMetadata($server, $modrinthProjectType)['entries'];
        } catch (ModrinthMetadataException $exception) {
            if (!$exception->getPrevious() instanceof DaemonFileException || $exception->getPrevious()->reason !== DaemonFileException::UNKNOWN_SERVER) {
                report($exception);
            }

            return [];
        }
    }

    /**
     * The metadata file, strictly: a missing file is an empty list, but a file that can't be
     * read (daemon unreachable, too large) or isn't valid throws - so nothing is ever written
     * over it with only the entry at hand.
     *
     * @return array{entries: array<int, array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}>, raw: array<int, mixed>}
     *
     * @throws ModrinthMetadataException
     */
    public function readModsMetadata(Server $server, ModrinthProjectType $modrinthProjectType): array
    {
        $metadataPath = $this->getMetadataFilePath($modrinthProjectType);

        try {
            $content = DaemonFiles::readSmallFile($server, $metadataPath, ModrinthRules::MAX_METADATA_BYTES);
        } catch (DaemonFileException $exception) {
            throw new ModrinthMetadataException("Could not read $metadataPath of server #{$server->id}: {$exception->getMessage()}", 0, $exception);
        }

        if ($content === null) {
            return ['entries' => [], 'raw' => []];
        }

        try {
            return ModrinthRules::parseMetadata($content);
        } catch (UnexpectedValueException $exception) {
            throw new ModrinthMetadataException("$metadataPath of server #{$server->id} can't be used: {$exception->getMessage()}", 0, $exception);
        }
    }

    /**
     * @param  array<int, mixed>  $raw
     *
     * @throws Exception
     */
    protected function writeModsMetadata(Server $server, ModrinthProjectType $modrinthProjectType, array $raw): void
    {
        try {
            DaemonFiles::writeFile(
                $server,
                $this->getMetadataFilePath($modrinthProjectType),
                json_encode(['installed_mods' => array_values($raw)], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                (int) config('panel.guzzle.timeout', 15)
            );
        } catch (DaemonFileException $exception) {
            throw new Exception($exception->getMessage());
        }
    }

    public function saveModMetadata(
        Server $server,
        ModrinthProjectType $modrinthProjectType,
        string $projectId,
        string $projectSlug,
        string $projectTitle,
        string $versionId,
        string $versionNumber,
        string $filename,
        ?string $author = null
    ): bool {
        try {
            return Cache::lock("modrinth_metadata:{$server->id}", 10)->block(5, function () use ($server, $modrinthProjectType, $projectId, $projectSlug, $projectTitle, $versionId, $versionNumber, $filename, $author) {
                // Strict: if the file can't be read, nothing is written (see readModsMetadata()).
                $metadata = $this->readModsMetadata($server, $modrinthProjectType);

                $existing = null;
                foreach ($metadata['entries'] as $mod) {
                    if ($mod['project_id'] === $projectId) {
                        $existing = $mod;

                        break;
                    }
                }

                $modEntry = [
                    'project_id' => $projectId,
                    'project_slug' => $projectSlug,
                    'project_title' => $projectTitle,
                    'version_id' => $versionId,
                    'version_number' => $versionNumber,
                    'filename' => $filename,
                    'installed_at' => $existing['installed_at'] ?? now()->toIso8601String(),
                ];

                if ($existing !== null) {
                    $modEntry['updated_at'] = now()->toIso8601String();
                }

                if ($author !== null) {
                    $modEntry['author'] = $author;
                }

                // Replaced in place (the row keeps its position); every other entry is kept as stored.
                $this->writeModsMetadata($server, $modrinthProjectType, ModrinthRules::upsertEntry($metadata['raw'], $modEntry));

                return true;
            }) === true;
        } catch (Exception $exception) {
            report($exception);

            return false;
        }
    }

    public function removeModMetadata(Server $server, ModrinthProjectType $modrinthProjectType, string $projectId): bool
    {
        try {
            return Cache::lock("modrinth_metadata:{$server->id}", 10)->block(5, function () use ($server, $modrinthProjectType, $projectId) {
                $metadata = $this->readModsMetadata($server, $modrinthProjectType);

                $this->writeModsMetadata($server, $modrinthProjectType, ModrinthRules::removeEntry($metadata['raw'], $projectId));

                return true;
            }) === true;
        } catch (Exception $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * Download a file from Modrinth into a temporary file of the panel, streamed and checked
     * against the size and sha512 Modrinth publishes for it. Only https://cdn.modrinth.com is
     * ever contacted, without following redirects.
     *
     * @param  array{url: string, filename: string, size: int, sha512: string}  $spec
     * @return string path of the verified temporary file (the caller deletes it)
     *
     * @throws Exception
     */
    protected function downloadVerified(array $spec): string
    {
        if (!ModrinthRules::isAllowedDownloadUrl($spec['url'])) {
            throw new Exception('Refusing to download from outside https://'.ModrinthRules::DOWNLOAD_HOST);
        }

        $temporary = tempnam(sys_get_temp_dir(), 'minecraft-modrinth-');
        if ($temporary === false) {
            throw new Exception('Could not create a temporary file for the download');
        }

        $maxBytes = $spec['size'];
        $abortIfLarger = function (int $bytes) use ($maxBytes): void {
            if ($bytes > $maxBytes) {
                throw new RuntimeException('minecraft-modrinth: download larger than announced');
            }
        };

        try {
            $response = Http::withUserAgent('pelican-minecraft-modrinth')
                ->withOptions([
                    'allow_redirects' => false,
                    'on_headers' => function (ResponseInterface $response) use ($abortIfLarger) {
                        $length = $response->getHeaderLine('Content-Length');
                        if ($length !== '' && ctype_digit($length)) {
                            $abortIfLarger((int) $length);
                        }
                    },
                    'progress' => fn ($downloadTotal, $downloaded) => $abortIfLarger((int) $downloaded),
                ])
                ->connectTimeout(3)
                ->timeout(self::DOWNLOAD_TIMEOUT)
                ->sink($temporary)
                ->get($spec['url']);

            if ($response->status() !== 200) {
                throw new Exception("Downloading {$spec['filename']} from Modrinth failed (HTTP {$response->status()})");
            }

            clearstatcache(true, $temporary);

            if (filesize($temporary) !== $spec['size'] || !hash_equals($spec['sha512'], (string) hash_file('sha512', $temporary))) {
                throw new Exception("{$spec['filename']} from Modrinth does not match the size/sha512 Modrinth published for it");
            }

            return $temporary;
        } catch (Throwable $exception) {
            @unlink($temporary);

            throw $exception instanceof Exception ? $exception : new Exception($exception->getMessage());
        }
    }

    /**
     * Put a verified file into the server's folder: written under a temporary name that no
     * loader picks up, then renamed into place. A file that already has the name (the old
     * version of an update with the same file name) is set aside first and only deleted once
     * the new one is in place, so a failure never leaves the folder without it.
     *
     * @throws Exception
     */
    protected function placeFile(Server $server, string $folder, string $filename, string $localFile, int $size): void
    {
        $files = $this->listFolder($server, $folder);

        // Temporary uploads left behind by an interrupted install (older than an hour).
        foreach ($files as $file) {
            if (is_string($file['name'] ?? null) && DaemonFiles::isRegularFile($file)
                && GitHubSourceRules::isStaleTemporaryUpload($file['name'], $file['modified'] ?? null, time())) {
                $this->deleteQuietly($server, $folder, $file['name']);
            }
        }

        $existing = DaemonFiles::find($files, $filename);
        if ($existing !== null && !DaemonFiles::isRegularFile($existing)) {
            throw new Exception("$folder/$filename exists and is not a file");
        }

        $temporary = '.modrinth-'.bin2hex(random_bytes(6)).'.part';
        $stream = fopen($localFile, 'rb');
        if ($stream === false) {
            throw new Exception('Could not open the downloaded file');
        }

        try {
            DaemonFiles::writeFile($server, "$folder/$temporary", Utils::streamFor($stream), self::DOWNLOAD_TIMEOUT);
        } catch (DaemonFileException $exception) {
            $this->deleteQuietly($server, $folder, $temporary);

            throw new Exception($exception->reason === DaemonFileException::DISK_FULL
                ? "Not enough disk space on server #{$server->id} for $filename"
                : $exception->getMessage());
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        $backup = null;

        try {
            if ($existing !== null) {
                $backup = '.modrinth-'.bin2hex(random_bytes(6)).'.part';
                DaemonFiles::rename($server, $folder, $filename, $backup);
            }

            DaemonFiles::rename($server, $folder, $temporary, $filename);
        } catch (DaemonFileException $exception) {
            $this->deleteQuietly($server, $folder, $temporary);

            if ($backup !== null) {
                try {
                    DaemonFiles::rename($server, $folder, $backup, $filename);
                } catch (DaemonFileException $restoreException) {
                    report($restoreException);
                }
            }

            throw new Exception($exception->getMessage());
        }

        $placed = DaemonFiles::find($this->listFolder($server, $folder), $filename);
        if ($placed === null || (int) ($placed['size'] ?? -1) !== $size) {
            throw new Exception("$folder/$filename on server #{$server->id} is missing or has the wrong size after writing it");
        }

        if ($backup !== null) {
            $this->deleteQuietly($server, $folder, $backup);
        }
    }

    protected function deleteQuietly(Server $server, string $folder, string $name): void
    {
        try {
            DaemonFiles::delete($server, $folder, $name);
        } catch (DaemonFileException $exception) {
            report($exception);
        }
    }

    /**
     * Delete a mod/plugin file. Only a regular file is ever deleted - never a folder, even if
     * the metadata names one; a file that is already gone counts as deleted.
     *
     * @throws Exception
     */
    public function deleteFile(Server $server, string $folder, string $filename): void
    {
        $filename = $this->validateFilename($filename);
        $file = DaemonFiles::find($this->listFolder($server, $folder), $filename);

        if ($file === null) {
            // Already gone, e.g. deleted by hand outside the panel. The desired end state (the
            // file isn't there) already holds, so treat it as success instead of leaving the
            // caller stuck: an uninstall that can never get past "delete the file" would keep
            // the metadata entry around forever, permanently stuck as "installed".
            return;
        }

        if (!DaemonFiles::isRegularFile($file)) {
            throw new Exception("Refusing to delete $folder/$filename: it is not a file");
        }

        try {
            DaemonFiles::delete($server, $folder, $filename);
        } catch (DaemonFileException $exception) {
            throw new Exception($exception->getMessage());
        }
    }

    /**
     * A plain .jar file name directly in mods/ or plugins/ (see ModrinthRules::isValidJarFilename()).
     *
     * @throws Exception
     */
    public function validateFilename(string $filename): string
    {
        if (!ModrinthRules::isValidJarFilename($filename)) {
            throw new Exception('Invalid filename: only a plain .jar file name is allowed');
        }

        return $filename;
    }

    /**
     * @param  array<int, mixed>  $files
     * @return array<string, mixed>|null
     */
    public function getPrimaryFile(array $files): ?array
    {
        foreach ($files as $file) {
            if (is_array($file) && !empty($file['primary'])) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @param  array{project_id: string, slug: string, title: string, author?: ?string}  $record
     * @param  array<string, mixed>  $versionData
     * @param  array<string, mixed>  $primaryFile  Modrinth file object (url, filename, size, hashes)
     * @param  array<string, mixed>|null  $installedMod
     *
     * @throws Exception
     */
    public function performInstallOrUpdate(
        Server $server,
        ModrinthProjectType $modrinthProjectType,
        array $record,
        array $versionData,
        array $primaryFile,
        ?array $installedMod = null
    ): void {
        $problem = null;
        $spec = ModrinthRules::downloadSpec($primaryFile, $problem);

        if ($spec === null || !ModrinthRules::isValidId($record['project_id'] ?? null) || !is_string($versionData['id'] ?? null) || !is_string($versionData['version_number'] ?? null)) {
            throw new Exception('Refusing to install from Modrinth: '.($problem ?? 'invalid project or version data'));
        }

        $safeNewFilename = $spec['filename'];
        $oldFilename = $installedMod ? $this->validateFilename($installedMod['filename']) : null;

        $folder = $modrinthProjectType->getFolder();

        // Before downloading anything: with metadata that can't be trusted nothing is installed,
        // since it couldn't be recorded without losing every other entry.
        $this->readModsMetadata($server, $modrinthProjectType);

        $localFile = $this->downloadVerified($spec);

        try {
            $this->placeFile($server, $folder, $safeNewFilename, $localFile, $spec['size']);
        } finally {
            @unlink($localFile);
        }

        $saved = $this->saveModMetadata(
            $server,
            $modrinthProjectType,
            $record['project_id'],
            $record['slug'],
            $record['title'],
            $versionData['id'],
            $versionData['version_number'],
            $safeNewFilename,
            $record['author'] ?? null
        );

        if (!$saved) {
            if (!$oldFilename || $oldFilename !== $safeNewFilename) {
                try {
                    $this->deleteFile($server, $folder, $safeNewFilename);
                } catch (Exception $rollbackException) {
                    report($rollbackException);
                }
            }

            throw new Exception('Failed to save mod metadata');
        }

        if ($oldFilename && $oldFilename !== $safeNewFilename) {
            try {
                $this->deleteFile($server, $folder, $oldFilename);
            } catch (Exception $deleteException) {
                try {
                    $this->deleteFile($server, $folder, $safeNewFilename);
                } catch (Exception $rollbackException) {
                    report($rollbackException);
                }

                if ($installedMod && !$this->saveModMetadata(
                    $server,
                    $modrinthProjectType,
                    $record['project_id'],
                    $installedMod['project_slug'],
                    $installedMod['project_title'],
                    $installedMod['version_id'],
                    $installedMod['version_number'],
                    $oldFilename,
                    $installedMod['author'] ?? null
                )) {
                    report(new Exception('Failed to restore old mod metadata during rollback'));
                }

                throw $deleteException;
            }
        }
    }

    public function fileExists(Server $server, string $folder, string $filename): bool
    {
        foreach ($this->listFolder($server, $folder) as $file) {
            if (is_array($file) && ($file['name'] ?? null) === $filename) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws Exception
     */
    public function listFolder(Server $server, string $folder): array
    {
        try {
            // null: the folder simply doesn't exist yet.
            return DaemonFiles::listDirectory($server, $folder) ?? [];
        } catch (DaemonFileException $exception) {
            throw new Exception($exception->getMessage());
        }
    }

    /** @return array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, author?: string}|null */
    public function getInstalledMod(Server $server, ModrinthProjectType $modrinthProjectType, string $projectId): ?array
    {
        $installedMods = $this->getInstalledModsMetadata($server, $modrinthProjectType);

        foreach ($installedMods as $mod) {
            if ($mod['project_id'] === $projectId) {
                return $mod;
            }
        }

        return null;
    }

    /**
     * @param  array{version_id: string, version_number: string}  $installedMod
     * @param  array<int, array{id: string, version_number: string}>  $availableVersions
     */
    public function isUpdateAvailable(array $installedMod, array $availableVersions): bool
    {
        if (empty($availableVersions)) {
            return false;
        }

        $latestVersion = $availableVersions[0];

        return $installedMod['version_id'] !== $latestVersion['id'];
    }

    /**
     * @return array<string>
     */
    public function getInstalledMods(Server $server, ModrinthProjectType $modrinthProjectType): array
    {
        $metadata = $this->getInstalledModsMetadata($server, $modrinthProjectType);

        return collect($metadata)
            ->pluck('filename')
            ->map(fn ($name) => strtolower($name))
            ->toArray();
    }
}
