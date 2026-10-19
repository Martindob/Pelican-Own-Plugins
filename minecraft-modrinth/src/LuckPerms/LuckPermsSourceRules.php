<?php

namespace Boy132\MinecraftModrinth\LuckPerms;

use Boy132\MinecraftModrinth\GitHub\GitHubSourceRules;
use ZipArchive;

/**
 * Pure validation/decision logic for the LuckPerms source (luckperms.net).
 *
 * Deliberately free of any Laravel/Pelican dependency, so every rule that decides what gets
 * downloaded and written to a server (metadata, download URL, file name, version, platform,
 * jar contents, which jar in plugins/ is LuckPerms) can be unit tested on its own
 * (tests/LuckPermsSourceRulesTest.php).
 *
 * @phpstan-type Changelog array{version: string, timestamp: int|null, title: string, commit: string|null}
 * @phpstan-type Latest array{version: string, timestamp: int|null, downloads: array<string, string>, changelog: array<int, Changelog>}
 * @phpstan-type DetectedJar array{filename: string, platform: string, version: string, size: int}
 */
final class LuckPermsSourceRules
{
    /** The only place the newest version is read from (no redirects are followed). */
    public const METADATA_URL = 'https://metadata.luckperms.net/data/all';

    /** The only host a LuckPerms jar is downloaded from (no redirects are followed). */
    public const DOWNLOAD_HOST = 'download.luckperms.net';

    /** The metadata response is a few KB; anything larger is refused. */
    public const MAX_METADATA_BYTES = 256 * 1024;

    /** A LuckPerms jar is ~1.5 MB; anything larger is refused (download and reading from a server). */
    public const MAX_JAR_BYTES = 30 * 1024 * 1024;

    /** plugin.yml / velocity-plugin.json are a few hundred bytes. */
    public const MAX_DESCRIPTOR_BYTES = 64 * 1024;

    /** Changelog entries kept from the metadata (the page shows the newest few). */
    public const MAX_CHANGELOG_ENTRIES = 20;

    public const PLATFORMS = ['bukkit', 'velocity'];

    /** Server loaders (egg tags) that run the Bukkit build of LuckPerms. */
    public const BUKKIT_LOADERS = ['paper', 'spigot', 'bukkit', 'folia', 'purpur', 'pufferfish', 'leaf'];

    public const VELOCITY_LOADERS = ['velocity'];

    public const MODES = ['auto', 'notify'];

    /** LuckPerms on Modrinth (https://modrinth.com/plugin/luckperms). */
    public const MODRINTH_PROJECT_ID = 'Vebnzrzj';

    // ------------------------------------------------------------------
    // Settings and platform
    // ------------------------------------------------------------------

    /** "auto" (update automatically, the default) or "notify" (only a notification in the bell). */
    public static function mode(mixed $mode): string
    {
        return is_string($mode) && in_array($mode, self::MODES, true) ? $mode : 'auto';
    }

    /**
     * "bukkit" or "velocity" for a server with these egg tags, otherwise null (nothing is offered).
     *
     * @param  array<mixed>  $tags
     */
    public static function platformForLoaderTags(array $tags): ?string
    {
        $tags = array_map(fn ($tag) => strtolower((string) $tag), $tags);

        if (array_intersect($tags, self::VELOCITY_LOADERS)) {
            return 'velocity';
        }

        if (array_intersect($tags, self::BUKKIT_LOADERS)) {
            return 'bukkit';
        }

        return null;
    }

    /** "Bukkit" / "Velocity" as in LuckPerms' own file names. */
    public static function platformLabel(string $platform): string
    {
        return $platform === 'velocity' ? 'Velocity' : 'Bukkit';
    }

    // ------------------------------------------------------------------
    // Versions
    // ------------------------------------------------------------------

    public static function isValidVersion(mixed $version): bool
    {
        return is_string($version) && GitHubSourceRules::isValidVersion($version);
    }

    /** Semver-style comparison of two x.y.z versions: <0, 0 or >0. */
    public static function compareVersions(string $a, string $b): int
    {
        return GitHubSourceRules::compareVersions($a, $b);
    }

    public static function isNewerVersion(string $candidate, string $installed): bool
    {
        return GitHubSourceRules::isNewerVersion($candidate, $installed);
    }

    // ------------------------------------------------------------------
    // Metadata (GET https://metadata.luckperms.net/data/all)
    // ------------------------------------------------------------------

    /**
     * Validate the metadata response. Only what this plugin uses is kept: the version, its
     * timestamp, the bukkit/velocity download URLs (only valid ones - an invalid URL makes that
     * platform unavailable, not the whole source) and the newest changelog entries.
     *
     * @return Latest
     *
     * @throws LuckPermsSourceException when the response as a whole can't be used
     */
    public static function parseMetadata(string $json): array
    {
        if (strlen($json) > self::MAX_METADATA_BYTES) {
            throw new LuckPermsSourceException(LuckPermsSourceException::INVALID_METADATA, 'LuckPerms metadata is larger than '.self::MAX_METADATA_BYTES.' bytes');
        }

        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new LuckPermsSourceException(LuckPermsSourceException::INVALID_METADATA, 'LuckPerms metadata is not valid JSON');
        }

        $version = $data['version'] ?? null;
        if (!self::isValidVersion($version)) {
            throw new LuckPermsSourceException(LuckPermsSourceException::INVALID_METADATA, 'LuckPerms metadata has no valid version: '.GitHubSourceRules::describeValue($version));
        }

        $downloads = [];
        foreach (self::PLATFORMS as $platform) {
            $url = $data['downloads'][$platform] ?? null;

            if (self::isAllowedDownloadUrl($url, $platform, $version)) {
                $downloads[$platform] = $url;
            }
        }

        $changelog = [];
        foreach (is_array($data['changeLog'] ?? null) && array_is_list($data['changeLog']) ? $data['changeLog'] : [] as $item) {
            if (count($changelog) >= self::MAX_CHANGELOG_ENTRIES) {
                break;
            }

            $entry = self::validateChangelogEntry($item);
            if ($entry !== null) {
                $changelog[] = $entry;
            }
        }

        return [
            'version' => $version,
            'timestamp' => self::validTimestamp($data['versionTimestamp'] ?? null),
            'downloads' => $downloads,
            'changelog' => $changelog,
        ];
    }

    /** @return Changelog|null */
    public static function validateChangelogEntry(mixed $item): ?array
    {
        if (!is_array($item) || !self::isValidVersion($item['version'] ?? null) || !is_string($item['title'] ?? null)) {
            return null;
        }

        // Plain text only: control characters removed, length bounded (it is shown on the page).
        $title = trim(preg_replace('/[\s\x00-\x1F\x7F]+/', ' ', $item['title']) ?? '');
        if ($title === '') {
            return null;
        }
        if (mb_strlen($title) > 200) {
            $title = mb_substr($title, 0, 200).'...';
        }

        $commit = $item['commit'] ?? null;

        return [
            'version' => $item['version'],
            'timestamp' => self::validTimestamp($item['timestamp'] ?? null),
            'title' => $title,
            'commit' => is_string($commit) && GitHubSourceRules::isValidSha(strtolower($commit)) ? strtolower($commit) : null,
        ];
    }

    /** A Unix timestamp in milliseconds between 2015 and 2100, or null. */
    public static function validTimestamp(mixed $value): ?int
    {
        return is_int($value) && $value >= 1_420_070_400_000 && $value <= 4_102_444_800_000 ? $value : null;
    }

    public static function commitUrl(?string $commit): ?string
    {
        return $commit !== null && GitHubSourceRules::isValidSha($commit) ? 'https://github.com/LuckPerms/LuckPerms/commit/'.$commit : null;
    }

    /**
     * The download URL of exactly this platform and version: https, host exactly
     * download.luckperms.net, no credentials/port/query/fragment, and the path LuckPerms uses
     * ("/<build>/bukkit/loader/LuckPerms-Bukkit-<version>.jar", "/<build>/velocity/LuckPerms-Velocity-<version>.jar").
     */
    public static function isAllowedDownloadUrl(mixed $url, string $platform, string $version): bool
    {
        if (!is_string($url) || strlen($url) > 512 || preg_match('/[\x00-\x20\x7F]/', $url)
            || !in_array($platform, self::PLATFORMS, true) || !self::isValidVersion($version)) {
            return false;
        }

        $parts = parse_url($url);

        if (!is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || strtolower($parts['host'] ?? '') !== self::DOWNLOAD_HOST
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }

        $file = preg_quote(self::canonicalFilename($platform, $version), '#');
        $pattern = $platform === 'velocity'
            ? '#^/[1-9]\d{0,8}/velocity/'.$file.'$#'
            : '#^/[1-9]\d{0,8}/bukkit/loader/'.$file.'$#';

        return (bool) preg_match($pattern, $parts['path'] ?? '');
    }

    // ------------------------------------------------------------------
    // Jars in plugins/
    // ------------------------------------------------------------------

    /** "LuckPerms-Bukkit-5.5.87.jar" / "LuckPerms-Velocity-5.5.87.jar" - the name a new jar gets. */
    public static function canonicalFilename(string $platform, string $version): string
    {
        return 'LuckPerms-'.self::platformLabel($platform).'-'.$version.'.jar';
    }

    /**
     * Platform and version of a jar named like LuckPerms names its builds (also in any letter
     * case, as some hosts lower-case uploads), or null.
     *
     * @return array{platform: string, version: string}|null
     */
    public static function parseCanonicalFilename(string $filename): ?array
    {
        if (!preg_match('/^LuckPerms-(Bukkit|Velocity)-((?:0|[1-9]\d{0,8})\.(?:0|[1-9]\d{0,8})\.(?:0|[1-9]\d{0,8}))\.jar$/i', $filename, $match)) {
            return null;
        }

        return ['platform' => strtolower($match[1]), 'version' => $match[2]];
    }

    /**
     * Whether a jar that isn't named like a LuckPerms build may still be LuckPerms and has to be
     * looked into ("LuckPerms.jar", "luckperms-5.4.jar", "LuckPerms-Bukkit-Legacy-5.5.0.jar",
     * "LuckPerms (1).jar"). Add-ons such as "LuckPermsChat.jar" are looked into as well and
     * turn out not to be LuckPerms by their plugin.yml.
     */
    public static function mayBeLuckPermsJar(string $filename): bool
    {
        return str_ends_with(strtolower($filename), '.jar') && str_contains(strtolower($filename), 'luckperms');
    }

    /**
     * Whether a file name alone says "LuckPerms itself" (a build name, or a plain name such as
     * "LuckPerms.jar" / "luckperms-bukkit-5.4.0.jar") - used where nothing can be downloaded
     * to check, e.g. before Modrinth puts a second copy next to it.
     */
    public static function looksLikeLuckPermsJarName(string $filename): bool
    {
        if (self::parseCanonicalFilename($filename) !== null) {
            return true;
        }

        if (!str_ends_with(strtolower($filename), '.jar')) {
            return false;
        }

        return in_array(GitHubSourceRules::jarStem($filename), ['luckperms', 'luckperms-bukkit', 'luckperms-velocity', 'luckperms-bukkit-legacy'], true);
    }

    /** Temporary name a LuckPerms jar is written under before it is renamed into place. */
    public static function temporaryUploadName(string $randomHex): string
    {
        return '.luckperms-'.$randomHex.'.part';
    }

    /**
     * Name and version from a plugin.yml. Only the two top-level keys are read (no YAML parser
     * needed): `name: LuckPerms`, `version: 5.5.87`, optionally quoted.
     *
     * @return array{name: string, version: string}|null
     */
    public static function parsePluginYml(string $yaml): ?array
    {
        if (strlen($yaml) > self::MAX_DESCRIPTOR_BYTES) {
            return null;
        }

        $values = [];
        foreach (preg_split('/\r\n|\r|\n/', $yaml) ?: [] as $line) {
            if (preg_match('/^(name|version):[ \t]*(.*?)[ \t]*$/', $line, $match) && !isset($values[$match[1]])) {
                $value = preg_replace('/[ \t]+#.*$/', '', $match[2]) ?? $match[2];

                if (preg_match('/^([\'"])(.*)\1$/', $value, $quoted)) {
                    $value = $quoted[2];
                }

                $values[$match[1]] = trim($value);
            }
        }

        if (!isset($values['name'], $values['version']) || $values['name'] === '' || $values['version'] === '') {
            return null;
        }

        return ['name' => $values['name'], 'version' => $values['version']];
    }

    /**
     * What a jar file is, judged by its descriptor: LuckPerms for Bukkit (plugin.yml with
     * name "LuckPerms") or for Velocity (velocity-plugin.json with id "luckperms"), with the
     * version it declares - or null when it isn't a readable jar of LuckPerms.
     *
     * @return array{platform: string, version: string}|null
     */
    public static function inspectJar(string $path, ?string &$problem = null): ?array
    {
        if (!class_exists(ZipArchive::class)) {
            $problem = 'the PHP zip extension is missing';

            return null;
        }

        $zip = new ZipArchive();

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            $problem = 'not a valid jar (zip) file';

            return null;
        }

        try {
            $velocity = self::readZipEntry($zip, 'velocity-plugin.json');
            if ($velocity !== null) {
                $data = json_decode($velocity, true);

                if (is_array($data) && ($data['id'] ?? null) === 'luckperms' && self::isValidVersion($data['version'] ?? null)) {
                    return ['platform' => 'velocity', 'version' => $data['version']];
                }
            }

            $pluginYml = self::readZipEntry($zip, 'plugin.yml');
            if ($pluginYml !== null) {
                $descriptor = self::parsePluginYml($pluginYml);

                if ($descriptor !== null && $descriptor['name'] === 'LuckPerms' && self::isValidVersion($descriptor['version'])) {
                    return ['platform' => 'bukkit', 'version' => $descriptor['version']];
                }
            }

            $problem = 'no plugin.yml with name "LuckPerms" or velocity-plugin.json with id "luckperms" and an x.y.z version';

            return null;
        } finally {
            $zip->close();
        }
    }

    /**
     * Whether a downloaded file is exactly the LuckPerms build that was asked for.
     */
    public static function verifyDownloadedJar(string $path, string $platform, string $version, ?string &$problem = null): bool
    {
        clearstatcache(true, $path);
        $size = @filesize($path);

        if ($size === false || $size < 1 || $size > self::MAX_JAR_BYTES) {
            $problem = 'file is empty or larger than '.self::MAX_JAR_BYTES.' bytes';

            return false;
        }

        $jar = self::inspectJar($path, $problem);

        if ($jar === null) {
            return false;
        }

        if ($jar['platform'] !== $platform || $jar['version'] !== $version) {
            $problem = "jar is LuckPerms {$jar['platform']} {$jar['version']}, expected $platform $version";

            return false;
        }

        return true;
    }

    protected static function readZipEntry(ZipArchive $zip, string $name): ?string
    {
        $stat = $zip->statName($name);

        if ($stat === false || $stat['size'] > self::MAX_DESCRIPTOR_BYTES) {
            return null;
        }

        $content = $zip->getFromName($name, self::MAX_DESCRIPTOR_BYTES);

        return is_string($content) ? $content : null;
    }

    // ------------------------------------------------------------------
    // State of one server
    // ------------------------------------------------------------------

    /**
     * What the LuckPerms source can do on a server, from the LuckPerms jars found in plugins/.
     *
     * - not_installed: no LuckPerms jar -> Install
     * - update_available / up_to_date / newer_installed: exactly one LuckPerms jar of this platform
     * - conflict: more than one LuckPerms jar, a LuckPerms jar of the other platform, or a jar
     *   that may be LuckPerms but couldn't be checked: nothing is done until it is resolved by hand
     *   (two LuckPerms jars in plugins/ must never be the result of anything this plugin does)
     * - unknown: one LuckPerms jar, but the newest version isn't known right now
     *
     * @param  array<int, DetectedJar>  $jars  LuckPerms jars found in plugins/
     * @param  array<int, string>  $unverified  jars that may be LuckPerms but couldn't be checked
     * @return array{state: string, installed: DetectedJar|null, reason: string|null}
     */
    public static function decideState(array $jars, array $unverified, string $platform, ?string $latestVersion): array
    {
        if (!empty($unverified)) {
            return ['state' => 'conflict', 'installed' => count($jars) === 1 ? $jars[0] : null, 'reason' => 'unverified'];
        }

        if (count($jars) > 1) {
            return ['state' => 'conflict', 'installed' => null, 'reason' => 'several'];
        }

        if (count($jars) === 0) {
            return ['state' => 'not_installed', 'installed' => null, 'reason' => null];
        }

        $jar = $jars[0];

        if ($jar['platform'] !== $platform) {
            return ['state' => 'conflict', 'installed' => $jar, 'reason' => 'wrong_platform'];
        }

        if ($latestVersion === null) {
            return ['state' => 'unknown', 'installed' => $jar, 'reason' => null];
        }

        $comparison = self::compareVersions($latestVersion, $jar['version']);

        return [
            'state' => $comparison > 0 ? 'update_available' : ($comparison === 0 ? 'up_to_date' : 'newer_installed'),
            'installed' => $jar,
            'reason' => null,
        ];
    }
}
