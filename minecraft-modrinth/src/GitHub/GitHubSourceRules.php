<?php

namespace Boy132\MinecraftModrinth\GitHub;

use ZipArchive;

/**
 * Pure validation/decision logic for the GitHub repository source.
 *
 * Deliberately free of any Laravel/Pelican dependency, so every rule that decides what gets
 * installed on a server (index validation, CI evaluation, version comparison, file names) can
 * be unit tested on its own.
 */
final class GitHubSourceRules
{
    /** The only index format this plugin understands. Anything else installs nothing. */
    public const INDEX_SCHEMA = 1;

    /**
     * Largest jar accepted from the index. The panel holds a jar in PHP memory while it checks
     * and writes it (GitHub's raw contents API would allow 100 MB, a PHP worker usually not).
     */
    public const MAX_JAR_BYTES = 50 * 1024 * 1024;

    public const MAX_INDEX_BYTES = 1024 * 1024;

    public const PLATFORMS = ['paper', 'velocity'];

    /** Server loaders (egg tags) that can run plugins built for the "paper" platform. */
    public const PAPER_LOADERS = ['paper', 'purpur', 'pufferfish', 'leaf'];

    public const VELOCITY_LOADERS = ['velocity'];

    /** Most manual copies of one plugin that are replaced at once; more is left to a human. */
    public const MAX_TAKEOVER_COPIES = 3;

    /** plugin.yml / paper-plugin.yml / velocity-plugin.json are a few KB at most. */
    public const MAX_DESCRIPTOR_BYTES = 64 * 1024;

    public static function isValidRepository(string $repository): bool
    {
        if (!preg_match('/^[A-Za-z0-9-]{1,39}\/[A-Za-z0-9._-]{1,100}$/', $repository)) {
            return false;
        }

        [, $name] = explode('/', $repository, 2);

        return $name !== '.' && $name !== '..';
    }

    /**
     * A conservative subset of what git allows in a branch name - enough for "main",
     * "release/1.x" and the like, but nothing that could change the meaning of a URL path.
     */
    public static function isValidBranch(string $branch): bool
    {
        if (!preg_match('/^[A-Za-z0-9._\/-]{1,100}$/', $branch)) {
            return false;
        }

        if (str_starts_with($branch, '/') || str_ends_with($branch, '/') || str_starts_with($branch, '-')
            || str_contains($branch, '//') || str_contains($branch, '..') || str_ends_with($branch, '.lock')) {
            return false;
        }

        foreach (explode('/', $branch) as $segment) {
            if ($segment === '' || str_starts_with($segment, '.')) {
                return false;
            }
        }

        return true;
    }

    /** A safe path relative to the repository root: no absolute path, no "." or ".." segments. */
    public static function isSafeRelativePath(string $path, ?string $requiredExtension = null): bool
    {
        if ($path === '' || strlen($path) > 512 || str_starts_with($path, '/')) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || !preg_match('/^[A-Za-z0-9._+-]+$/', $segment)) {
                return false;
            }
        }

        return $requiredExtension === null || str_ends_with(strtolower($path), '.'.strtolower($requiredExtension));
    }

    public static function isValidIndexPath(string $path): bool
    {
        return self::isSafeRelativePath($path, 'json');
    }

    /**
     * Fine-grained tokens start with "github_pat_", classic ones with "ghp_" - both are plain
     * [A-Za-z0-9_]. Only the character set is checked, not the prefix, so a future token format
     * with the same alphabet keeps working.
     */
    public static function isPlausibleToken(string $token): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_]{20,255}$/', $token);
    }

    public static function isValidSha(string $sha): bool
    {
        return (bool) preg_match('/^[0-9a-f]{40}$/', $sha);
    }

    public static function isValidVersion(string $version): bool
    {
        return (bool) preg_match('/^(0|[1-9]\d{0,8})\.(0|[1-9]\d{0,8})\.(0|[1-9]\d{0,8})$/', $version);
    }

    /** Semver-style comparison of two x.y.z versions: <0, 0 or >0. */
    public static function compareVersions(string $a, string $b): int
    {
        $pa = array_map('intval', explode('.', $a));
        $pb = array_map('intval', explode('.', $b));

        for ($i = 0; $i < 3; $i++) {
            $cmp = ($pa[$i] ?? 0) <=> ($pb[$i] ?? 0);
            if ($cmp !== 0) {
                return $cmp;
            }
        }

        return 0;
    }

    public static function isNewerVersion(string $candidate, string $installed): bool
    {
        if (!self::isValidVersion($candidate)) {
            return false;
        }

        // An installed entry with an unparsable version can only be replaced by hand.
        if (!self::isValidVersion($installed)) {
            return false;
        }

        return self::compareVersions($candidate, $installed) > 0;
    }

    /** File name a plugin is stored under in plugins/: must be a plain, visible .jar name. */
    public static function isValidJarFilename(string $filename): bool
    {
        return strlen($filename) <= 132 && (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]*\.jar$/', $filename) && !str_contains($filename, '..');
    }

    /**
     * The file name without ".jar" and without a trailing version, e.g.
     * "gdprerase-paper-1.4.0.jar" -> "gdprerase-paper". Used to recognise every jar that belongs
     * to the same plugin regardless of its version, so plugins/ never ends up with two of them.
     */
    public static function jarStem(string $filename): string
    {
        $stem = preg_replace('/\.jar$/i', '', $filename) ?? $filename;
        // A separator, an optional "v", then a dotted version and any "-SNAPSHOT"/"+build"/"-<hash>" suffixes.
        $withoutVersion = preg_replace('/[-_]v?\d+(\.\d+)*([-+][A-Za-z0-9.]+)*$/', '', $stem);

        return strtolower($withoutVersion !== null && $withoutVersion !== '' ? $withoutVersion : $stem);
    }

    /** Whether $filename looks like another version (or a copy) of the plugin stored as $reference. */
    public static function belongsToSamePlugin(string $filename, string $reference): bool
    {
        if (!preg_match('/\.jar$/i', $filename)) {
            return false;
        }

        return self::jarStem($filename) === self::jarStem($reference);
    }

    /**
     * Whether a jar already in plugins/ looks like a copy of the plugin with this file name and
     * plugin name: another version of the same file ("gdprerase-paper-1.3.0.jar") or a jar
     * named after the plugin itself ("GdprErase.jar", "GdprErase-1.3.jar" - a typical manual
     * install). Two copies would make Paper/Velocity refuse to load either.
     */
    public static function looksLikeSamePlugin(string $existing, string $filename, string $pluginName): bool
    {
        if (self::belongsToSamePlugin($existing, $filename)) {
            return true;
        }

        $name = strtolower(preg_replace('/[^A-Za-z0-9]+/', '', $pluginName) ?? '');
        $stem = preg_replace('/[^a-z0-9]+/', '', self::jarStem($existing)) ?? '';

        return $name !== '' && preg_match('/\.jar$/i', $existing) === 1 && $stem === $name;
    }

    /**
     * An alternative name for when the indexed file name is already taken in plugins/ - an
     * existing file is never overwritten in place, since that is exactly what a running server
     * has open (and what a failed write would leave broken).
     */
    public static function alternativeFilename(string $filename, string $sha256): string
    {
        $stem = preg_replace('/\.jar$/i', '', $filename) ?? $filename;

        return $stem.'-'.substr($sha256, 0, 12).'.jar';
    }

    /** @param  array<mixed>  $tags */
    public static function platformForLoaderTags(array $tags): ?string
    {
        $tags = array_map(fn ($tag) => strtolower((string) $tag), $tags);

        if (array_intersect($tags, self::VELOCITY_LOADERS)) {
            return 'velocity';
        }

        if (array_intersect($tags, self::PAPER_LOADERS)) {
            return 'paper';
        }

        return null;
    }

    public static function verifyJar(string $content, int $expectedSize, string $expectedSha256): bool
    {
        return strlen($content) === $expectedSize && hash_equals(strtolower($expectedSha256), hash('sha256', $content));
    }

    /**
     * Validate a decoded releases index.
     *
     * Returns the valid plugin entries plus a list of problems for entries that were skipped.
     * Throws when the index as a whole can't be trusted (not JSON, unknown schema, ...).
     *
     * @return array{plugins: array<int, array{id: string, name: string, platform: string, version: string, path: string, filename: string, sha256: string, size: int, frozen: bool}>, problems: array<int, string>}
     *
     * @throws GitHubSourceException
     */
    public static function parseIndex(string $json): array
    {
        if (strlen($json) > self::MAX_INDEX_BYTES) {
            throw new GitHubSourceException(GitHubSourceException::INVALID_INDEX, 'Index is larger than '.self::MAX_INDEX_BYTES.' bytes');
        }

        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new GitHubSourceException(GitHubSourceException::INVALID_INDEX, 'Index is not valid JSON');
        }

        if (($data['schema'] ?? null) !== self::INDEX_SCHEMA) {
            $schema = $data['schema'] ?? null;

            throw new GitHubSourceException(GitHubSourceException::UNSUPPORTED_SCHEMA, 'Unsupported index schema: '.self::describeValue($schema));
        }

        if (!isset($data['plugins']) || !is_array($data['plugins']) || !array_is_list($data['plugins'])) {
            throw new GitHubSourceException(GitHubSourceException::INVALID_INDEX, 'Index has no "plugins" list');
        }

        $plugins = [];
        $problems = [];
        $seenIds = [];
        $seenFilenames = [];

        foreach ($data['plugins'] as $position => $entry) {
            $problem = null;
            $plugin = self::validateIndexEntry($entry, $problem);

            if ($plugin === null) {
                $problems[] = "plugins[$position]: $problem";

                continue;
            }

            if (isset($seenIds[$plugin['id']])) {
                $problems[] = "plugins[$position]: duplicate id {$plugin['id']}";

                continue;
            }

            $filenameKey = $plugin['platform'].':'.strtolower($plugin['filename']);
            if (isset($seenFilenames[$filenameKey])) {
                $problems[] = "plugins[$position]: file name {$plugin['filename']} is already used by {$seenFilenames[$filenameKey]}";

                continue;
            }

            $seenIds[$plugin['id']] = true;
            $seenFilenames[$filenameKey] = $plugin['id'];
            $plugins[] = $plugin;
        }

        return ['plugins' => $plugins, 'problems' => $problems];
    }

    /**
     * @return array{id: string, name: string, platform: string, version: string, path: string, filename: string, sha256: string, size: int, frozen: bool}|null
     */
    public static function validateIndexEntry(mixed $entry, ?string &$problem = null): ?array
    {
        if (!is_array($entry)) {
            $problem = 'not an object';

            return null;
        }

        $id = $entry['id'] ?? null;
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $id)) {
            $problem = 'invalid id';

            return null;
        }

        $name = $entry['name'] ?? null;
        if (!is_string($name) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.-]{0,63}$/', $name)) {
            $problem = "invalid name ($id)";

            return null;
        }

        $platform = $entry['platform'] ?? null;
        if (!is_string($platform) || !in_array($platform, self::PLATFORMS, true)) {
            $problem = "invalid platform ($id)";

            return null;
        }

        $version = $entry['version'] ?? null;
        if (!is_string($version) || !self::isValidVersion($version)) {
            $problem = "invalid version ($id)";

            return null;
        }

        $path = $entry['path'] ?? null;
        if (!is_string($path) || !self::isSafeRelativePath($path, 'jar')) {
            $problem = "invalid path ($id)";

            return null;
        }

        $filename = basename($path);
        if (!self::isValidJarFilename($filename)) {
            $problem = "invalid file name ($id)";

            return null;
        }

        $sha256 = $entry['sha256'] ?? null;
        if (!is_string($sha256) || !preg_match('/^[0-9a-fA-F]{64}$/', $sha256)) {
            $problem = "invalid sha256 ($id)";

            return null;
        }

        $size = $entry['size'] ?? null;
        if (!is_int($size) || $size < 1 || $size > self::MAX_JAR_BYTES) {
            $problem = "invalid size ($id)";

            return null;
        }

        $frozen = $entry['frozen'] ?? false;

        return [
            'id' => $id,
            'name' => $name,
            'platform' => $platform,
            'version' => $version,
            'path' => $path,
            'filename' => $filename,
            'sha256' => strtolower($sha256),
            'size' => $size,
            'frozen' => $frozen === true,
        ];
    }

    /**
     * Decide the CI state of a commit from its GitHub Actions workflow runs
     * (GET /repos/{repo}/actions/runs?head_sha=...&event=push&branch=...).
     *
     * Only runs of a push of exactly $sha (and, when given, to exactly $branch) count:
     *
     * - any finished run that didn't succeed -> failed (definitive, even if others still run)
     * - anything still queued/running        -> pending
     * - everything success/skipped/neutral and at least one success -> green
     * - no runs at all, or nothing actually succeeded -> unverified
     *
     * With $requiredWorkflow (a workflow file name such as "build.yml"), green additionally
     * needs a successful run of exactly that workflow: while it hasn't run yet the commit is
     * unverified, while it runs pending - so a quick, unrelated workflow finishing first can't
     * turn CI green before the build itself has finished.
     *
     * @param  array<int, mixed>  $runs
     */
    public static function evaluateWorkflowRuns(array $runs, string $sha, ?string $branch = null, ?string $requiredWorkflow = null): CiStatus
    {
        $relevant = array_values(array_filter($runs, fn ($run) => is_array($run)
            && ($run['head_sha'] ?? null) === $sha
            && ($run['event'] ?? null) === 'push'
            && ($branch === null || ($run['head_branch'] ?? null) === $branch)));

        if (empty($relevant)) {
            return CiStatus::Unverified;
        }

        $pending = false;
        $succeeded = false;
        $requiredSucceeded = false;

        foreach ($relevant as $run) {
            $isRequired = $requiredWorkflow !== null && $requiredWorkflow !== ''
                && self::workflowFileOfRun($run) === strtolower($requiredWorkflow);

            if (($run['status'] ?? null) !== 'completed') {
                $pending = true;

                continue;
            }

            $conclusion = $run['conclusion'] ?? null;

            if ($conclusion === 'success') {
                $succeeded = true;
                $requiredSucceeded = $requiredSucceeded || $isRequired;
            } elseif (!in_array($conclusion, ['skipped', 'neutral'], true)) {
                return CiStatus::Failed;
            }
        }

        if ($pending) {
            return CiStatus::Pending;
        }

        if ($requiredWorkflow !== null && $requiredWorkflow !== '' && !$requiredSucceeded) {
            // Not run (yet), or only skipped: nothing proves the required build passed.
            return CiStatus::Unverified;
        }

        return $succeeded ? CiStatus::Green : CiStatus::Unverified;
    }

    /**
     * File name (lower case) of the workflow a run belongs to, from its "path"
     * (".github/workflows/build.yml", sometimes with an "@ref" suffix), or null.
     *
     * @param  array<mixed>  $run
     */
    public static function workflowFileOfRun(array $run): ?string
    {
        $path = $run['path'] ?? null;

        if (!is_string($path) || $path === '') {
            return null;
        }

        $path = explode('@', $path, 2)[0];

        if (!str_starts_with($path, '.github/workflows/')) {
            return null;
        }

        return strtolower(basename($path));
    }

    /** A workflow file name as the "Required workflow" setting takes it, e.g. "build.yml". */
    public static function isValidWorkflowFile(string $file): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,99}\.ya?ml$/', $file) && !str_contains($file, '..');
    }

    /**
     * Seconds to wait before talking to GitHub again after a rate limit response.
     *
     * @param  array<string, string>  $headers  lower-cased header names
     */
    public static function retryAfterSeconds(array $headers, int $now): int
    {
        $retryAfter = $headers['retry-after'] ?? null;
        if ($retryAfter !== null && ctype_digit(trim($retryAfter))) {
            return max(1, min(3600, (int) trim($retryAfter)));
        }

        $reset = $headers['x-ratelimit-reset'] ?? null;
        if ($reset !== null && ctype_digit(trim($reset))) {
            return max(1, min(3600, (int) trim($reset) - $now));
        }

        return 60;
    }

    // ------------------------------------------------------------------
    // Offers: new plugins and updates for one server
    // ------------------------------------------------------------------

    /**
     * The index entries meant for a server of this platform. A server whose platform couldn't be
     * determined (null) gets nothing at all - no Paper plugin on a Velocity proxy or vice versa.
     *
     * @template T of array{platform: string}
     *
     * @param  array<int, T>  $plugins
     * @return array<int, T>
     */
    public static function pluginsForPlatform(array $plugins, ?string $platform): array
    {
        if ($platform === null || !in_array($platform, self::PLATFORMS, true)) {
            return [];
        }

        return array_values(array_filter($plugins, fn (array $plugin) => $plugin['platform'] === $platform));
    }

    /**
     * Whether plugins/ holds a jar that looks like this plugin but isn't one this plugin manages
     * (a manual install, a Modrinth copy, ...). Such a plugin is not offered as new: it's there.
     *
     * @param  array{filename: string, name: string}  $plugin
     * @param  array<int, string>  $jarNames  jar names currently in plugins/
     * @param  array<int, string>  $managedFilenames  jar names recorded in the metadata file
     */
    public static function hasUnmanagedCopy(array $plugin, array $jarNames, array $managedFilenames): bool
    {
        return self::unmanagedCopies($plugin, $jarNames, $managedFilenames) !== [];
    }

    /**
     * The jars in plugins/ that look like this plugin but aren't managed by this plugin.
     *
     * @param  array{filename: string, name: string}  $plugin
     * @param  array<int, string>  $jarNames  jar names currently in plugins/
     * @param  array<int, string>  $managedFilenames  jar names recorded in the metadata file
     * @return array<int, string>
     */
    public static function unmanagedCopies(array $plugin, array $jarNames, array $managedFilenames): array
    {
        $copies = [];

        foreach ($jarNames as $name) {
            if (in_array($name, $managedFilenames, true)) {
                continue;
            }

            if (self::looksLikeSamePlugin($name, $plugin['filename'], $plugin['name'])) {
                $copies[] = $name;
            }
        }

        return $copies;
    }

    /**
     * Ids of the index entries (of one platform) that a jar in plugins/ looks like.
     *
     * @param  array<int, array{id: string, filename: string, name: string}>  $platformPlugins
     * @return array<int, string>
     */
    public static function pluginsMatchingJar(string $jarName, array $platformPlugins): array
    {
        $ids = [];

        foreach ($platformPlugins as $plugin) {
            if (self::looksLikeSamePlugin($jarName, $plugin['filename'], $plugin['name'])) {
                $ids[] = $plugin['id'];
            }
        }

        return $ids;
    }

    /**
     * The manual copies of $plugin that installing it from the repository may delete ("take
     * over"), or null when there are copies that must not be touched automatically:
     *
     * - a copy that matches more than one index entry of the platform (whose is it?),
     * - a copy that isn't a plain jar name directly in plugins/ (sub folders are never looked at),
     * - a protected copy ($protectedNames: managed by the Modrinth page, not a regular file,
     *   or refused earlier because its plugin.yml names another plugin),
     * - more than MAX_TAKEOVER_COPIES copies at once.
     *
     * An empty list means there is no copy at all. Recognition is by name only - the caller still
     * has to compare the plugin descriptors before deleting anything.
     *
     * @param  array{id: string, filename: string, name: string}  $plugin
     * @param  array<int, array{id: string, filename: string, name: string}>  $platformPlugins  the index entries of the server's platform
     * @param  array<int, string>  $jarNames  jar names currently in plugins/
     * @param  array<int, string>  $managedFilenames  jar names this plugin manages (installed and pending deletion)
     * @param  array<int, string>  $protectedNames  jar names that are never taken over
     * @return array<int, string>|null
     */
    public static function replaceableCopies(array $plugin, array $platformPlugins, array $jarNames, array $managedFilenames, array $protectedNames): ?array
    {
        $copies = self::unmanagedCopies($plugin, $jarNames, $managedFilenames);

        if ($copies === []) {
            return [];
        }

        if (count($copies) > self::MAX_TAKEOVER_COPIES) {
            return null;
        }

        foreach ($copies as $name) {
            if (!self::isValidJarFilename($name) || in_array($name, $protectedNames, true)
                || self::pluginsMatchingJar($name, $platformPlugins) !== [$plugin['id']]) {
                return null;
            }
        }

        return $copies;
    }

    /** The version in a jar's file name ("EthoriaChat-1.8.0.jar" -> "1.8.0"), or null. */
    public static function versionFromJarName(string $filename): ?string
    {
        if (!preg_match('/[-_]v?(\d+(?:\.\d+)*)(?:[-+][A-Za-z0-9.]+)*\.jar$/i', $filename, $match)) {
            return null;
        }

        return strlen($match[1]) <= 32 ? $match[1] : null;
    }

    /**
     * The plugin name declared at the top level of a plugin.yml / paper-plugin.yml ("name: X",
     * optionally quoted), or null. No YAML parser: only that one key is read.
     */
    public static function pluginYmlName(string $yaml): ?string
    {
        if (strlen($yaml) > self::MAX_DESCRIPTOR_BYTES) {
            return null;
        }

        foreach (preg_split('/\r\n|\r|\n/', $yaml) ?: [] as $line) {
            if (!preg_match('/^name:[ \t]*(.*?)[ \t]*$/', $line, $match)) {
                continue;
            }

            $value = preg_replace('/[ \t]+#.*$/', '', $match[1]) ?? $match[1];

            if (preg_match('/^([\'"])(.*)\1$/', $value, $quoted)) {
                $value = $quoted[2];
            }

            $value = trim($value);

            return $value !== '' && strlen($value) <= 128 && preg_match('/^[\x21-\x7E][\x20-\x7E]*$/', $value) ? $value : null;
        }

        return null;
    }

    /**
     * The names a jar declares itself under, lower case: for "paper" the `name` of plugin.yml
     * and paper-plugin.yml, for "velocity" the `id` of velocity-plugin.json. Paper/Velocity
     * refuse to load two jars with the same name, so the same name means the same plugin.
     * Null (with $problem) when the jar can't be read or declares nothing.
     *
     * @return array<int, string>|null
     */
    public static function jarDescriptorNames(string $path, string $platform, ?string &$problem = null): ?array
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

        $names = [];

        try {
            if ($platform === 'velocity') {
                $json = self::readZipEntry($zip, 'velocity-plugin.json');
                $data = $json !== null ? json_decode($json, true) : null;
                $id = is_array($data) ? ($data['id'] ?? null) : null;

                if (is_string($id) && preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $id)) {
                    $names[] = $id;
                }
            } elseif ($platform === 'paper') {
                foreach (['paper-plugin.yml', 'plugin.yml'] as $descriptor) {
                    $yaml = self::readZipEntry($zip, $descriptor);
                    $name = $yaml !== null ? self::pluginYmlName($yaml) : null;

                    if ($name !== null) {
                        $names[] = strtolower($name);
                    }
                }
            }
        } finally {
            $zip->close();
        }

        $names = array_values(array_unique($names));

        if ($names === []) {
            $problem = $platform === 'velocity' ? 'no velocity-plugin.json with an id' : 'no plugin.yml / paper-plugin.yml with a name';

            return null;
        }

        return $names;
    }

    /**
     * Whether two jars declare the same plugin (see jarDescriptorNames()).
     *
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     */
    public static function sameDescriptor(array $a, array $b): bool
    {
        return $a !== [] && $b !== [] && array_intersect($a, $b) !== [];
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

    /**
     * State of one index entry on one server.
     *
     * - new: not installed, not dismissed for this server, no unmanaged copy -> offered
     * - dismissed: not installed, an admin said it doesn't belong on this server -> hidden
     * - replace_manual: not installed from here, a jar of it is already in plugins/ and may be
     *   replaced with the repository version (setting on, copies replaceable, not dismissed)
     * - manual: not installed from here, but a jar of it is already in plugins/
     * - update_available / up_to_date / repo_older / modified: installed from here
     *
     * @param  array{version: string, sha256: string}  $plugin
     * @param  array{version: string, sha256: string}|null  $installed
     */
    public static function pluginState(array $plugin, ?array $installed, bool $dismissed, bool $unmanagedCopy, bool $replaceable = false): string
    {
        if ($installed === null) {
            return match (true) {
                $unmanagedCopy && $replaceable && !$dismissed => 'replace_manual',
                $unmanagedCopy => 'manual',
                $dismissed => 'dismissed',
                default => 'new',
            };
        }

        if (self::isNewerVersion($plugin['version'], $installed['version'])) {
            return 'update_available';
        }

        if (!self::isValidVersion($installed['version']) || self::compareVersions($plugin['version'], $installed['version']) < 0) {
            return 'repo_older';
        }

        return $installed['sha256'] === $plugin['sha256'] ? 'up_to_date' : 'modified';
    }

    /**
     * What the repository offers one server: plugins to install (new) and to update. A manual
     * copy that will be replaced with the repository version (see replaceableCopies()) counts as
     * an update.
     *
     * @template T of array{id: string, name: string, platform: string, version: string, filename: string, sha256: string}
     *
     * @param  array<int, T>  $plugins  the whole index
     * @param  array<int, array{id: string, version: string, sha256: string, filename: string}>  $installed
     * @param  array<int, string>  $dismissedIds
     * @param  array<int, string>  $jarNames
     * @param  array<int, string>  $alsoManaged  further jar names of this plugin (old jars waiting to be deleted)
     * @param  array<int, string>  $replaceIds  plugins whose manual copies will be replaced
     * @return array{new: array<int, T>, updates: array<int, T>}
     */
    public static function offers(array $plugins, ?string $platform, array $installed, array $dismissedIds, array $jarNames, array $alsoManaged = [], array $replaceIds = []): array
    {
        $byId = [];
        foreach ($installed as $entry) {
            $byId[$entry['id']] = $entry;
        }
        $managed = array_merge(array_column($installed, 'filename'), $alsoManaged);

        $offers = ['new' => [], 'updates' => []];

        foreach (self::pluginsForPlatform($plugins, $platform) as $plugin) {
            $state = self::pluginState(
                $plugin,
                $byId[$plugin['id']] ?? null,
                in_array($plugin['id'], $dismissedIds, true),
                !isset($byId[$plugin['id']]) && self::hasUnmanagedCopy($plugin, $jarNames, $managed),
                in_array($plugin['id'], $replaceIds, true),
            );

            if ($state === 'new') {
                $offers['new'][] = $plugin;
            } elseif ($state === 'update_available' || $state === 'replace_manual') {
                $offers['updates'][] = $plugin;
            }
        }

        return $offers;
    }

    /**
     * The new plugins a server hasn't been told about yet (each plugin is announced once per
     * server, whatever its version).
     *
     * @template T of array{id: string}
     *
     * @param  array<int, T>  $new
     * @param  array<int, string>  $notifiedIds
     * @return array<int, T>
     */
    public static function pluginsToAnnounce(array $new, array $notifiedIds): array
    {
        return array_values(array_filter($new, fn (array $plugin) => !in_array($plugin['id'], $notifiedIds, true)));
    }

    /**
     * Navigation badge for cached offer counts: the total, or null when there is nothing
     * (or nothing known) to offer. Updates make it "warning", only new plugins "info".
     *
     * @param  mixed  $counts  whatever the cache returned
     * @return array{label: string, color: string, new: int, updates: int}|null
     */
    public static function navigationBadge(mixed $counts): ?array
    {
        if (!is_array($counts) || !is_int($counts['new'] ?? null) || !is_int($counts['updates'] ?? null)) {
            return null;
        }

        $new = max(0, $counts['new']);
        $updates = max(0, $counts['updates']);

        if ($new + $updates === 0) {
            return null;
        }

        return [
            'label' => (string) ($new + $updates),
            'color' => $updates > 0 ? 'warning' : 'info',
            'new' => $new,
            'updates' => $updates,
        ];
    }

    /** Hosts a GitHub API response may redirect a download to (never sent the token). */
    public static function isAllowedRedirectHost(string $host): bool
    {
        $host = strtolower($host);

        return $host === 'raw.githubusercontent.com'
            || $host === 'objects.githubusercontent.com'
            || $host === 'codeload.github.com'
            || (bool) preg_match('/^[a-z0-9-]+\.githubusercontent\.com$/', $host);
    }

    // ------------------------------------------------------------------
    // Settings, daemon responses, housekeeping
    // ------------------------------------------------------------------

    /** The automatic update time as it may go into .env and Schedule::dailyAt(): HH:MM[:SS]. */
    public static function isValidAutoUpdateTime(string $time): bool
    {
        return (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time);
    }

    /**
     * The time from the settings form as "HH:MM", or null when it isn't a time of day.
     * Filament's (non-native) time picker keeps its state as a full "YYYY-MM-DD HH:mm:ss"
     * once a time is picked, so the date part (and seconds) are dropped here; a plain
     * "HH:MM[:SS]" (the value loaded from .env) is accepted as well.
     */
    public static function normalizeAutoUpdateTime(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        if (!preg_match('/^(?:\d{4}-\d{2}-\d{2}[ T])?(([01]\d|2[0-3]):[0-5]\d)(?::[0-5]\d(?:\.\d{1,6})?)?$/', trim($value), $m)) {
            return null;
        }

        return $m[1];
    }

    /** "HH:MM" for Schedule::dailyAt(), or the fallback when the saved value is unusable. */
    public static function scheduleTime(mixed $time, string $fallback = '00:00'): string
    {
        return is_string($time) && self::isValidAutoUpdateTime($time) ? substr($time, 0, 5) : $fallback;
    }

    /** Temporary name a jar is written under before it is renamed into place. */
    public static function isTemporaryUploadName(string $name): bool
    {
        return (bool) preg_match('/^\.(github|modrinth|luckperms)-[0-9a-f]{12}\.part$/', $name);
    }

    /**
     * Whether a left-over temporary upload may be removed: only when its modification time
     * is known and older than $maxAgeSeconds (an upload running right now is never touched).
     */
    public static function isStaleTemporaryUpload(string $name, mixed $modified, int $now, int $maxAgeSeconds = 3600): bool
    {
        if (!self::isTemporaryUploadName($name) || !is_string($modified) || $modified === '') {
            return false;
        }

        $time = strtotime($modified);

        return $time !== false && $time < $now - $maxAgeSeconds;
    }

    /** Wings' answer when the node itself doesn't know the server (as opposed to a missing file). */
    public static function isUnknownServerResponse(int $status, string $body): bool
    {
        return $status === 404 && str_contains(strtolower($body), 'does not exist on this instance');
    }

    /** Wings' answer when the server's disk quota is used up. */
    public static function isDiskSpaceResponse(string $body): bool
    {
        $body = strtolower($body);

        return str_contains($body, 'not enough disk space') || (str_contains($body, 'disk space') && str_contains($body, 'exceed'));
    }

    /** A short, printable description of an untrusted value for a log line. */
    public static function describeValue(mixed $value, int $maxLength = 32): string
    {
        if (!is_scalar($value)) {
            return gettype($value);
        }

        $text = preg_replace('/[^\x20-\x7E]/', '?', var_export($value, true)) ?? '';

        return strlen($text) > $maxLength ? substr($text, 0, $maxLength).'...' : $text;
    }

    /** Remove anything token-shaped from a string that might end up in a log or notification. */
    public static function redact(string $text, ?string $token = null): string
    {
        if ($token !== null && $token !== '') {
            $text = str_replace($token, '[redacted]', $text);
        }

        return preg_replace('/\b(github_pat_|gh[pousr]_)[A-Za-z0-9_]+/', '$1[redacted]', $text) ?? $text;
    }
}
