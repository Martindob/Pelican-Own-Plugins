<?php

namespace Martindob\PaperVelocityUpdater\Services;

use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use Exception;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class PaperVelocityUpdateService
{
    private const STATE_FILE = '.paper-velocity-updater.json';

    private const FILL_BASE_URL = 'https://fill.papermc.io/v3';

    /** Egg variable name(s) that identify a project and hold its target version. */
    private const PROJECT_VERSION_VARIABLES = [
        'paper' => ['MINECRAFT_VERSION', 'MC_VERSION'],
        'velocity' => ['VELOCITY_VERSION'],
    ];

    /**
     * Checks whether a newer Paper/Velocity build is available for the server and,
     * if so, downloads it and replaces the server jar before it (re)starts.
     *
     * This must never throw: a failed update check should never prevent a server
     * from actually starting.
     */
    public function maybeUpdate(Server $server): void
    {
        if (!config('paper-velocity-updater.enabled', true)) {
            return;
        }

        try {
            $this->update($server);
        } catch (Exception $exception) {
            report($exception);
        }
    }

    /**
     * @throws Exception
     */
    private function update(Server $server): void
    {
        $server->loadMissing('variables');

        $project = $this->detectProject($server);
        if ($project === null) {
            return;
        }

        // Respect an explicitly pinned build number; only "latest" (the default
        // shown in the startup variables) triggers an automatic update.
        $buildVariable = $this->getVariable($server, 'BUILD_NUMBER');
        if ($buildVariable !== null && !$this->isLatest($buildVariable)) {
            return;
        }

        $jarFile = $this->getVariable($server, 'SERVER_JARFILE') ?? ($project === 'velocity' ? 'velocity.jar' : 'server.jar');

        $requestedVersion = null;
        foreach (self::PROJECT_VERSION_VARIABLES[$project] as $variableName) {
            $requestedVersion = $this->getVariable($server, $variableName);
            if ($requestedVersion !== null) {
                break;
            }
        }

        $version = $this->resolveVersion($project, $requestedVersion);
        if ($version === null) {
            return;
        }

        $build = $this->resolveLatestBuild($project, $version);
        if ($build === null) {
            return;
        }

        $download = $build['downloads']['server:default'] ?? null;
        if (!is_array($download) || !isset($download['url'])) {
            return;
        }

        $fileRepository = app(DaemonFileRepository::class)->setServer($server);

        $state = $this->readState($fileRepository);
        $upToDate = $state !== null
            && ($state['project'] ?? null) === $project
            && ($state['version'] ?? null) === $version
            && ($state['build'] ?? null) === $build['id']
            && ($state['jar'] ?? null) === $jarFile
            && $this->jarExists($fileRepository, $jarFile);

        if ($upToDate) {
            return;
        }

        $fileRepository->pull($download['url'], '/', [
            'filename' => $jarFile,
            'foreground' => true,
        ]);

        $this->writeState($fileRepository, [
            'project' => $project,
            'version' => $version,
            'build' => $build['id'],
            'jar' => $jarFile,
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    /** Detects whether this server is a Paper or Velocity server from its startup variables. */
    private function detectProject(Server $server): ?string
    {
        foreach (self::PROJECT_VERSION_VARIABLES as $project => $variableNames) {
            foreach ($variableNames as $variableName) {
                if ($server->variables->firstWhere('env_variable', $variableName)) {
                    return $project;
                }
            }
        }

        return null;
    }

    private function getVariable(Server $server, string $name): ?string
    {
        /** @var \App\Models\EggVariable|null $variable */
        $variable = $server->variables->firstWhere('env_variable', $name);
        if (!$variable) {
            return null;
        }

        $value = $variable->server_value ?? $variable->default_value;

        return $value !== null && $value !== '' ? $value : null;
    }

    private function isLatest(?string $value): bool
    {
        return $value === null || strtolower(trim($value)) === 'latest';
    }

    /**
     * Resolves the requested version to a concrete Minecraft/Velocity version string,
     * falling back to the newest available version - same behaviour as the "Leave at
     * latest"/"Invalid versions will default to latest" wording shown for these variables.
     */
    private function resolveVersion(string $project, ?string $requestedVersion): ?string
    {
        if (!$this->isLatest($requestedVersion) && $this->versionExists($project, $requestedVersion)) {
            return $requestedVersion;
        }

        return $this->resolveLatestVersion($project);
    }

    /** @return array<string, array<int, string>> */
    private function fetchVersionGroups(string $project): array
    {
        return cache()->remember(
            "paper-velocity-updater:versions:$project",
            now()->addMinutes($this->cacheMinutes()),
            function () use ($project) {
                try {
                    $versions = $this->http()->get("/projects/$project")->json('versions');

                    return is_array($versions) ? $versions : [];
                } catch (Exception $exception) {
                    report($exception);

                    return [];
                }
            }
        );
    }

    private function resolveLatestVersion(string $project): ?string
    {
        $groups = $this->fetchVersionGroups($project);
        $firstGroup = reset($groups);

        if (!is_array($firstGroup) || empty($firstGroup)) {
            return null;
        }

        return reset($firstGroup);
    }

    private function versionExists(string $project, ?string $version): bool
    {
        if ($version === null) {
            return false;
        }

        foreach ($this->fetchVersionGroups($project) as $group) {
            if (is_array($group) && in_array($version, $group, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{id: int, channel: string, downloads: array<string, array{name: string, url: string}>}|null */
    private function resolveLatestBuild(string $project, string $version): ?array
    {
        return cache()->remember(
            "paper-velocity-updater:build:$project:$version",
            now()->addMinutes($this->cacheMinutes()),
            function () use ($project, $version) {
                try {
                    $builds = $this->http()->get("/projects/$project/versions/$version/builds")->json();
                } catch (Exception $exception) {
                    report($exception);

                    return null;
                }

                if (!is_array($builds) || empty($builds)) {
                    return null;
                }

                // The API returns builds newest first; prefer a stable one but fall
                // back to the newest build of any channel (e.g. version-only-in-beta).
                $stable = array_values(array_filter($builds, fn ($build) => is_array($build) && ($build['channel'] ?? null) === 'STABLE'));
                $candidate = $stable[0] ?? $builds[0];

                if (!is_array($candidate) || !isset($candidate['id'])) {
                    return null;
                }

                return $candidate;
            }
        );
    }

    private function cacheMinutes(): int
    {
        return (int) config('paper-velocity-updater.cache_minutes', 15);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(self::FILL_BASE_URL)
            ->withHeaders(['User-Agent' => config('paper-velocity-updater.user_agent')])
            ->timeout(10)
            ->connectTimeout(5)
            ->throw();
    }

    /** @return array{project?: string, version?: string, build?: int, jar?: string}|null */
    private function readState(DaemonFileRepository $fileRepository): ?array
    {
        try {
            $content = $fileRepository->getContent(self::STATE_FILE);
        } catch (FileNotFoundException) {
            // No marker yet - first check for this server.
            return null;
        } catch (Exception $exception) {
            report($exception);

            return null;
        }

        $state = json_decode($content, true);

        return is_array($state) ? $state : null;
    }

    /** @param  array{project: string, version: string, build: int, jar: string, updated_at: string}  $state */
    private function writeState(DaemonFileRepository $fileRepository, array $state): void
    {
        $fileRepository->putContent(self::STATE_FILE, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    }

    private function jarExists(DaemonFileRepository $fileRepository, string $jarFile): bool
    {
        try {
            $files = $fileRepository->getDirectory('/');
        } catch (RequestException $exception) {
            if ($exception->response->status() === 404) {
                return false;
            }

            throw $exception;
        }

        foreach ($files as $file) {
            if (is_array($file) && ($file['name'] ?? null) === $jarFile) {
                return true;
            }
        }

        return false;
    }
}
