<?php

namespace Boy132\MinecraftModrinth\Modrinth;

use UnexpectedValueException;

/**
 * Pure validation logic for Modrinth installs: the metadata file on the server
 * (mods|plugins/.modrinth-metadata.json), file names and download URLs.
 *
 * Deliberately free of any Laravel/Pelican dependency so it can be unit tested on its own
 * (tests/ModrinthRulesTest.php).
 *
 * @phpstan-type ModEntry array{project_id: string, project_slug: string, project_title: string, version_id: string, version_number: string, filename: string, installed_at: string, updated_at?: string, author?: string}
 */
final class ModrinthRules
{
    /** The metadata file is read into PHP memory: anything larger is refused, not read. */
    public const MAX_METADATA_BYTES = 1024 * 1024;

    /** Entries beyond this are ignored when reading (a real server has a few dozen). */
    public const MAX_ENTRIES = 1000;

    /** Largest file the panel downloads from Modrinth (streamed to a temporary file). */
    public const MAX_DOWNLOAD_BYTES = 256 * 1024 * 1024;

    /** The only host a Modrinth file is downloaded from. */
    public const DOWNLOAD_HOST = 'cdn.modrinth.com';

    protected const REQUIRED_STRING_KEYS = ['project_id', 'project_slug', 'project_title', 'version_id', 'version_number', 'filename', 'installed_at'];

    protected const MAX_STRING_LENGTH = 256;

    /** Modrinth project/version ids: 8 base62 characters. */
    public static function isValidId(mixed $id): bool
    {
        return is_string($id) && (bool) preg_match('/^[A-Za-z0-9]{8}$/', $id);
    }

    /**
     * A plain .jar file name directly inside mods/ or plugins/: no path, no hidden file, no
     * control characters. Looser than the GitHub rule on purpose - Modrinth authors use spaces,
     * brackets and the like in their file names.
     */
    public static function isValidJarFilename(mixed $filename): bool
    {
        return is_string($filename)
            && $filename !== ''
            && strlen($filename) <= 255
            && !str_starts_with($filename, '.')
            && !str_contains($filename, '/')
            && !str_contains($filename, '\\')
            && !str_contains($filename, '..')
            && !preg_match('/[\x00-\x1F\x7F]/', $filename)
            && str_ends_with(strtolower($filename), '.jar');
    }

    /**
     * One entry of the metadata file, or null when it can't be trusted.
     *
     * @return ModEntry|null
     */
    public static function validateEntry(mixed $entry): ?array
    {
        if (!is_array($entry)) {
            return null;
        }

        foreach (self::REQUIRED_STRING_KEYS as $key) {
            if (!is_string($entry[$key] ?? null) || strlen($entry[$key]) > self::MAX_STRING_LENGTH) {
                return null;
            }
        }

        if (!self::isValidId($entry['project_id']) || $entry['version_id'] === '' || !self::isValidJarFilename($entry['filename'])) {
            return null;
        }

        $valid = [
            'project_id' => $entry['project_id'],
            'project_slug' => $entry['project_slug'],
            'project_title' => $entry['project_title'],
            'version_id' => $entry['version_id'],
            'version_number' => $entry['version_number'],
            'filename' => $entry['filename'],
            'installed_at' => $entry['installed_at'],
        ];

        foreach (['updated_at', 'author'] as $optional) {
            if (is_string($entry[$optional] ?? null) && strlen($entry[$optional]) <= self::MAX_STRING_LENGTH) {
                $valid[$optional] = $entry[$optional];
            }
        }

        return $valid;
    }

    /**
     * Parse the metadata file. Returns the trusted entries (first one per project, at most
     * MAX_ENTRIES) and the raw list as stored, so a write can keep entries it doesn't
     * understand instead of dropping them.
     *
     * @return array{entries: array<int, ModEntry>, raw: array<int, mixed>}
     *
     * @throws UnexpectedValueException when the file as a whole is not a metadata file
     */
    public static function parseMetadata(string $json): array
    {
        if (strlen($json) > self::MAX_METADATA_BYTES) {
            throw new UnexpectedValueException('Metadata file is larger than '.self::MAX_METADATA_BYTES.' bytes');
        }

        $data = json_decode($json, true);

        if (!is_array($data) || !isset($data['installed_mods']) || !is_array($data['installed_mods']) || !array_is_list($data['installed_mods'])) {
            throw new UnexpectedValueException('Metadata file is damaged (no "installed_mods" list)');
        }

        $entries = [];
        foreach ($data['installed_mods'] as $entry) {
            if (count($entries) >= self::MAX_ENTRIES) {
                break;
            }

            $valid = self::validateEntry($entry);

            if ($valid !== null && !isset($entries[$valid['project_id']])) {
                $entries[$valid['project_id']] = $valid;
            }
        }

        return ['entries' => array_values($entries), 'raw' => $data['installed_mods']];
    }

    /**
     * The raw list with $entry stored for its project: replaces the project's entries in place
     * (keeping the row's position), otherwise appends it. Every other entry is kept verbatim.
     *
     * @param  array<int, mixed>  $raw
     * @param  ModEntry  $entry
     * @return array<int, mixed>
     */
    public static function upsertEntry(array $raw, array $entry): array
    {
        $result = [];
        $placed = false;

        foreach ($raw as $item) {
            if (is_array($item) && ($item['project_id'] ?? null) === $entry['project_id']) {
                if (!$placed) {
                    $result[] = $entry;
                    $placed = true;
                }

                continue;
            }

            $result[] = $item;
        }

        if (!$placed) {
            $result[] = $entry;
        }

        return $result;
    }

    /**
     * @param  array<int, mixed>  $raw
     * @return array<int, mixed>
     */
    public static function removeEntry(array $raw, string $projectId): array
    {
        return array_values(array_filter($raw, fn ($item) => !(is_array($item) && ($item['project_id'] ?? null) === $projectId)));
    }

    /** Only https://cdn.modrinth.com/... (no credentials, no other port) is downloaded. */
    public static function isAllowedDownloadUrl(mixed $url): bool
    {
        if (!is_string($url) || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F]/', $url)) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && strtolower($parts['host'] ?? '') === self::DOWNLOAD_HOST
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && (!isset($parts['port']) || $parts['port'] === 443);
    }

    public static function isValidSha512(mixed $hash): bool
    {
        return is_string($hash) && (bool) preg_match('/^[0-9a-fA-F]{128}$/', $hash);
    }

    /**
     * What to download for a Modrinth file object ({url, filename, size, hashes: {sha512}}),
     * or null (with $problem) when it doesn't meet the rules.
     *
     * @param  array<mixed>  $file
     * @return array{url: string, filename: string, size: int, sha512: string}|null
     */
    public static function downloadSpec(array $file, ?string &$problem = null): ?array
    {
        if (!self::isAllowedDownloadUrl($file['url'] ?? null)) {
            $problem = 'download URL is not on https://'.self::DOWNLOAD_HOST;

            return null;
        }

        if (!self::isValidJarFilename($file['filename'] ?? null)) {
            $problem = 'file name is not a plain .jar name';

            return null;
        }

        $size = $file['size'] ?? null;
        if (!is_int($size) || $size < 1 || $size > self::MAX_DOWNLOAD_BYTES) {
            $problem = 'file size is missing or larger than allowed';

            return null;
        }

        $sha512 = $file['hashes']['sha512'] ?? null;
        if (!self::isValidSha512($sha512)) {
            $problem = 'file has no sha512 hash';

            return null;
        }

        return ['url' => $file['url'], 'filename' => $file['filename'], 'size' => $size, 'sha512' => strtolower($sha512)];
    }
}
