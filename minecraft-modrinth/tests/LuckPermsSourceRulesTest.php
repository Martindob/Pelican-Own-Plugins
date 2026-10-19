<?php

/*
 * Standalone tests of the LuckPerms source rules - no Pelican, Laravel or Composer needed
 * (only the PHP zip extension, which Pelican requires anyway):
 *
 *     php minecraft-modrinth/tests/LuckPermsSourceRulesTest.php
 */

declare(strict_types=1);

require __DIR__.'/../src/GitHub/CiStatus.php';
require __DIR__.'/../src/GitHub/GitHubSourceException.php';
require __DIR__.'/../src/GitHub/GitHubSourceRules.php';
require __DIR__.'/../src/Support/DaemonFileException.php';
require __DIR__.'/../src/LuckPerms/LuckPermsSourceException.php';
require __DIR__.'/../src/LuckPerms/LuckPermsSourceRules.php';

use Boy132\MinecraftModrinth\GitHub\GitHubSourceRules as G;
use Boy132\MinecraftModrinth\LuckPerms\LuckPermsSourceException;
use Boy132\MinecraftModrinth\LuckPerms\LuckPermsSourceRules as L;

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

function throwsReason(callable $fn, string $reason): bool
{
    try {
        $fn();
    } catch (LuckPermsSourceException $exception) {
        return $exception->reason === $reason;
    }

    return false;
}

/** @param  array<string, string>  $entries */
function makeZip(array $entries): string
{
    $path = tempnam(sys_get_temp_dir(), 'lp-test-');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::OVERWRITE);
    foreach ($entries as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();

    return $path;
}

$bukkitUrl = 'https://download.luckperms.net/1674/bukkit/loader/LuckPerms-Bukkit-5.5.87.jar';
$velocityUrl = 'https://download.luckperms.net/1674/velocity/LuckPerms-Velocity-5.5.87.jar';

// Settings and platform
check(L::mode('auto') === 'auto' && L::mode('notify') === 'notify', 'modes');
check(L::mode(null) === 'auto' && L::mode('') === 'auto' && L::mode('NOTIFY') === 'auto' && L::mode(1) === 'auto', 'unknown mode falls back to auto');

foreach (['paper', 'spigot', 'bukkit', 'folia', 'purpur', 'pufferfish', 'leaf', 'Paper'] as $tag) {
    check(L::platformForLoaderTags(['minecraft', $tag]) === 'bukkit', "tag $tag -> bukkit");
}
check(L::platformForLoaderTags(['minecraft', 'velocity']) === 'velocity', 'tag velocity -> velocity');
check(L::platformForLoaderTags(['minecraft', 'paper', 'velocity']) === 'velocity', 'velocity wins over paper');
foreach (['fabric', 'forge', 'neoforge', 'bungeecord', 'waterfall', 'sponge', 'quilt', 'minecraft'] as $tag) {
    check(L::platformForLoaderTags(['minecraft', $tag]) === null, "tag $tag -> nothing offered");
}
check(L::platformForLoaderTags([]) === null, 'no tags -> nothing');
check(L::platformLabel('bukkit') === 'Bukkit' && L::platformLabel('velocity') === 'Velocity', 'platform labels');

// Versions
check(L::isValidVersion('5.5.87') && L::isValidVersion('0.0.1') && L::isValidVersion('10.0.0'), 'valid versions');
foreach (['5.5', '5.5.87.1', 'v5.5.87', '5.5.87-SNAPSHOT', '05.5.87', '', ' 5.5.87', null, 5.5, ['5.5.87']] as $bad) {
    check(!L::isValidVersion($bad), 'invalid version '.json_encode($bad));
}
check(L::compareVersions('5.5.87', '5.5.71') > 0, '5.5.87 > 5.5.71');
check(L::compareVersions('5.5.9', '5.5.10') < 0, '5.5.9 < 5.5.10 (numeric, not string)');
check(L::compareVersions('5.10.0', '5.9.99') > 0, '5.10.0 > 5.9.99');
check(L::compareVersions('5.5.87', '5.5.87') === 0, 'equal versions');
check(L::isNewerVersion('5.5.87', '5.5.86') && !L::isNewerVersion('5.5.86', '5.5.87') && !L::isNewerVersion('5.5.87', '5.5.87'), 'isNewerVersion');
check(!L::isNewerVersion('5.5.87', 'unknown') && !L::isNewerVersion('garbage', '5.5.0'), 'isNewerVersion with invalid versions');

// Download URLs
check(L::isAllowedDownloadUrl($bukkitUrl, 'bukkit', '5.5.87'), 'real bukkit url allowed');
check(L::isAllowedDownloadUrl($velocityUrl, 'velocity', '5.5.87'), 'real velocity url allowed');
foreach ([
    'http' => ['http://download.luckperms.net/1674/bukkit/loader/LuckPerms-Bukkit-5.5.87.jar', 'bukkit'],
    'other host' => ['https://evil.example/1674/bukkit/loader/LuckPerms-Bukkit-5.5.87.jar', 'bukkit'],
    'suffix host' => ['https://download.luckperms.net.evil.example/1674/bukkit/loader/LuckPerms-Bukkit-5.5.87.jar', 'bukkit'],
    'sub host' => ['https://x.download.luckperms.net/1674/bukkit/loader/LuckPerms-Bukkit-5.5.87.jar', 'bukkit'],
    'metadata host' => ['https://metadata.luckperms.net/1674/bukkit/loader/LuckPerms-Bukkit-5.5.87.jar', 'bukkit'],
    'user' => ['https://u:p@download.luckperms.net/1674/bukkit/loader/LuckPerms-Bukkit-5.5.87.jar', 'bukkit'],
    'port' => ['https://download.luckperms.net:8443/1674/bukkit/loader/LuckPerms-Bukkit-5.5.87.jar', 'bukkit'],
    'port 443' => ['https://download.luckperms.net:443/1674/bukkit/loader/LuckPerms-Bukkit-5.5.87.jar', 'bukkit'],
    'query' => [$bukkitUrl.'?x=1', 'bukkit'],
    'fragment' => [$bukkitUrl.'#x', 'bukkit'],
    'other version' => ['https://download.luckperms.net/1674/bukkit/loader/LuckPerms-Bukkit-5.5.86.jar', 'bukkit'],
    'velocity url for bukkit' => [$velocityUrl, 'bukkit'],
    'bukkit url for velocity' => [$bukkitUrl, 'velocity'],
    'legacy' => ['https://download.luckperms.net/1674/bukkit-legacy/loader/LuckPerms-Bukkit-Legacy-5.5.87.jar', 'bukkit'],
    'traversal' => ['https://download.luckperms.net/1674/../bukkit/loader/LuckPerms-Bukkit-5.5.87.jar', 'bukkit'],
    'encoded traversal' => ['https://download.luckperms.net/1674/%2e%2e/bukkit/loader/LuckPerms-Bukkit-5.5.87.jar', 'bukkit'],
    'no build' => ['https://download.luckperms.net/bukkit/loader/LuckPerms-Bukkit-5.5.87.jar', 'bukkit'],
    'build 0' => ['https://download.luckperms.net/0/bukkit/loader/LuckPerms-Bukkit-5.5.87.jar', 'bukkit'],
    'extra segment' => ['https://download.luckperms.net/1674/x/bukkit/loader/LuckPerms-Bukkit-5.5.87.jar', 'bukkit'],
    'zip' => ['https://download.luckperms.net/1674/bukkit/loader/LuckPerms-Bukkit-5.5.87.zip', 'bukkit'],
    'space' => ['https://download.luckperms.net/1674/bukkit/loader/LuckPerms-Bukkit-5.5.87 .jar', 'bukkit'],
    'velocity with loader' => ['https://download.luckperms.net/1674/velocity/loader/LuckPerms-Velocity-5.5.87.jar', 'velocity'],
    'unknown platform' => [$bukkitUrl, 'fabric'],
] as $name => [$url, $platform]) {
    check(!L::isAllowedDownloadUrl($url, $platform, '5.5.87'), "download url refused: $name");
}
check(!L::isAllowedDownloadUrl(null, 'bukkit', '5.5.87') && !L::isAllowedDownloadUrl(['x'], 'bukkit', '5.5.87'), 'download url not a string');
check(!L::isAllowedDownloadUrl($bukkitUrl, 'bukkit', 'garbage'), 'download url with invalid version');

// Metadata
$metadata = fn (array $overrides = []) => json_encode(array_replace([
    'discordUserCount' => 20657,
    'version' => '5.5.87',
    'versionTimestamp' => 1791129673030,
    'downloads' => [
        'bukkit' => $bukkitUrl,
        'bukkit-legacy' => 'https://download.luckperms.net/1674/bukkit-legacy/loader/LuckPerms-Bukkit-Legacy-5.5.87.jar',
        'velocity' => $velocityUrl,
        'fabric' => 'https://download.luckperms.net/1674/fabric/LuckPerms-Fabric-5.5.87.jar',
    ],
    'changeLog' => [
        ['version' => '5.5.87', 'timestamp' => 1791129673030, 'title' => 'Update Group getWeight javadoc comment (#4325)', 'commit' => '5fc3d583c412854e050b1e4ba8aa5036d8daf982'],
        ['version' => '5.5.86', 'timestamp' => 1791129041992, 'title' => 'Fix NeoForge NoClassDefFoundError (#4327)', 'commit' => 'aeaab669ab4086b237f24b50b15b46da6472f4d2'],
    ],
], $overrides));

$parsed = L::parseMetadata($metadata());
check($parsed['version'] === '5.5.87' && $parsed['timestamp'] === 1791129673030, 'metadata version and timestamp');
check($parsed['downloads'] === ['bukkit' => $bukkitUrl, 'velocity' => $velocityUrl], 'metadata keeps only bukkit/velocity downloads');
check(count($parsed['changelog']) === 2 && $parsed['changelog'][0]['commit'] === '5fc3d583c412854e050b1e4ba8aa5036d8daf982', 'metadata changelog');

$parsed = L::parseMetadata($metadata(['downloads' => ['bukkit' => 'https://evil.example/LuckPerms-Bukkit-5.5.87.jar', 'velocity' => $velocityUrl]]));
check($parsed['downloads'] === ['velocity' => $velocityUrl], 'invalid bukkit url drops only the bukkit download');
$parsed = L::parseMetadata($metadata(['downloads' => 'nope']));
check($parsed['downloads'] === [], 'downloads not an object -> no downloads');
$parsed = L::parseMetadata($metadata(['downloads' => ['bukkit' => 'https://download.luckperms.net/1673/bukkit/loader/LuckPerms-Bukkit-5.5.86.jar']]));
check($parsed['downloads'] === [], 'download of another version than "version" is refused');
$parsed = L::parseMetadata($metadata(['versionTimestamp' => 'yesterday']));
check($parsed['timestamp'] === null, 'invalid timestamp -> null');

check(throwsReason(fn () => L::parseMetadata('{broken'), LuckPermsSourceException::INVALID_METADATA), 'broken json refused');
check(throwsReason(fn () => L::parseMetadata('"5.5.87"'), LuckPermsSourceException::INVALID_METADATA), 'json string refused');
check(throwsReason(fn () => L::parseMetadata($metadata(['version' => '5.5.87-SNAPSHOT'])), LuckPermsSourceException::INVALID_METADATA), 'invalid version refused');
check(throwsReason(fn () => L::parseMetadata($metadata(['version' => null])), LuckPermsSourceException::INVALID_METADATA), 'missing version refused');
check(throwsReason(fn () => L::parseMetadata(str_repeat(' ', L::MAX_METADATA_BYTES + 1)), LuckPermsSourceException::INVALID_METADATA), 'oversized metadata refused');

$changelog = L::parseMetadata($metadata(['changeLog' => [
    ['version' => '5.5.87', 'timestamp' => 1791129673030, 'title' => "Line\nbreak\x07 and <b>tags</b>", 'commit' => 'NOT-A-SHA'],
    ['version' => 'x', 'title' => 'bad version'],
    ['version' => '5.5.85', 'title' => ''],
    'garbage',
    ['version' => '5.5.84', 'title' => str_repeat('a', 500), 'commit' => strtoupper('25f223317a9ec2b6e73369126b630eca07d79506')],
]]))['changelog'];
check(count($changelog) === 2, 'invalid changelog entries skipped');
check($changelog[0]['title'] === 'Line break and <b>tags</b>' && $changelog[0]['commit'] === null, 'changelog title without control characters, invalid commit dropped');
check(mb_strlen($changelog[1]['title']) === 203 && $changelog[1]['commit'] === '25f223317a9ec2b6e73369126b630eca07d79506', 'long changelog title cut, commit lower-cased');
$many = array_fill(0, 50, ['version' => '5.5.1', 'title' => 't']);
check(count(L::parseMetadata($metadata(['changeLog' => $many]))['changelog']) === L::MAX_CHANGELOG_ENTRIES, 'changelog bounded');
check(L::parseMetadata($metadata(['changeLog' => ['a' => 1]]))['changelog'] === [], 'changelog not a list ignored');

check(L::validTimestamp(1791129673030) === 1791129673030 && L::validTimestamp(1791129673) === null && L::validTimestamp('1') === null, 'timestamps in milliseconds only');
check(L::commitUrl('5fc3d583c412854e050b1e4ba8aa5036d8daf982') === 'https://github.com/LuckPerms/LuckPerms/commit/5fc3d583c412854e050b1e4ba8aa5036d8daf982', 'commit url');
check(L::commitUrl(null) === null && L::commitUrl('javascript:alert(1)') === null, 'no commit url for invalid commits');

// File names
check(L::canonicalFilename('bukkit', '5.5.87') === 'LuckPerms-Bukkit-5.5.87.jar' && L::canonicalFilename('velocity', '5.5.87') === 'LuckPerms-Velocity-5.5.87.jar', 'canonical file names');
check(G::isValidJarFilename(L::canonicalFilename('bukkit', '5.5.87')), 'canonical name is a valid plain jar name');
check(L::parseCanonicalFilename('LuckPerms-Bukkit-5.5.71.jar') === ['platform' => 'bukkit', 'version' => '5.5.71'], 'parse bukkit name');
check(L::parseCanonicalFilename('LuckPerms-Velocity-5.4.141.jar') === ['platform' => 'velocity', 'version' => '5.4.141'], 'parse velocity name');
check(L::parseCanonicalFilename('luckperms-bukkit-5.5.71.jar') === ['platform' => 'bukkit', 'version' => '5.5.71'], 'parse lower-case name');
foreach (['LuckPerms.jar', 'LuckPerms-Bukkit-Legacy-5.5.87.jar', 'LuckPerms-Bukkit-5.5.jar', 'LuckPerms-Bukkit-5.5.87-SNAPSHOT.jar', 'LuckPerms-Fabric-5.5.87.jar', 'LuckPerms-Bukkit-5.5.87.jar.disabled', 'xLuckPerms-Bukkit-5.5.87.jar', 'LuckPerms-Bukkit-5.5.87 (1).jar'] as $name) {
    check(L::parseCanonicalFilename($name) === null, "not a build name: $name");
}
foreach (['LuckPerms.jar', 'luckperms-5.4.jar', 'LuckPerms-Bukkit-Legacy-5.5.0.jar', 'LuckPermsChat.jar', 'My-LuckPerms (1).jar', 'LUCKPERMS.JAR'] as $name) {
    check(L::mayBeLuckPermsJar($name), "may be LuckPerms: $name");
}
foreach (['Vault.jar', 'LuckPerms.jar.disabled', 'LuckPerms', 'permissions.jar'] as $name) {
    check(!L::mayBeLuckPermsJar($name), "not looked into: $name");
}
foreach (['LuckPerms-Bukkit-5.5.71.jar', 'LuckPerms.jar', 'luckperms-5.4.0.jar', 'LuckPerms-Bukkit.jar', 'luckperms-velocity-5.4.jar', 'LuckPerms-Bukkit-Legacy-5.5.0.jar'] as $name) {
    check(L::looksLikeLuckPermsJarName($name), "name says LuckPerms: $name");
}
foreach (['LuckPermsChat.jar', 'LuckPerms-Placeholders.jar', 'Expansion-LuckPerms.jar', 'Vault.jar', 'LuckPerms.zip'] as $name) {
    check(!L::looksLikeLuckPermsJarName($name), "name doesn't say LuckPerms: $name");
}
check(L::MODRINTH_PROJECT_ID === 'Vebnzrzj', 'Modrinth project id of LuckPerms');

$temporary = L::temporaryUploadName('0123456789ab');
check($temporary === '.luckperms-0123456789ab.part' && G::isTemporaryUploadName($temporary), 'temporary upload name is recognised for cleanup');
check(!str_ends_with($temporary, '.jar'), 'temporary upload is never loaded as a jar');

// plugin.yml
check(L::parsePluginYml("name: LuckPerms\nversion: 5.5.87\nmain: x\n") === ['name' => 'LuckPerms', 'version' => '5.5.87'], 'plugin.yml');
check(L::parsePluginYml("name: 'LuckPerms'\r\nversion: \"5.5.87\" # comment\r\n") === ['name' => 'LuckPerms', 'version' => '5.5.87'], 'plugin.yml with quotes, comment and CRLF');
check(L::parsePluginYml("commands:\n  name: Fake\n  version: 9.9.9\nname: LuckPerms\nversion: 5.5.87\n") === ['name' => 'LuckPerms', 'version' => '5.5.87'], 'plugin.yml: nested keys ignored');
check(L::parsePluginYml("name: LuckPerms\nname: Other\nversion: 5.5.87\n")['name'] === 'LuckPerms', 'plugin.yml: first key wins');
check(L::parsePluginYml("name: LuckPerms\n") === null && L::parsePluginYml('') === null && L::parsePluginYml("name:\nversion: 1.0.0") === null, 'plugin.yml without name/version');
check(L::parsePluginYml(str_repeat('#', L::MAX_DESCRIPTOR_BYTES + 1)) === null, 'oversized plugin.yml refused');

// Jars
$bukkitYml = "name: LuckPerms\nversion: 5.5.87\nmain: me.lucko.luckperms.bukkit.loader.BukkitLoaderPlugin\n";
$velocityJson = '{"id":"luckperms","name":"LuckPerms","version":"5.5.87","main":"me.lucko.luckperms.velocity.LPVelocityBootstrap"}';
$files = [];

$files[] = $jar = makeZip(['plugin.yml' => $bukkitYml, 'me/x.class' => 'x']);
check(L::inspectJar($jar) === ['platform' => 'bukkit', 'version' => '5.5.87'], 'bukkit jar inspected');
check(L::verifyDownloadedJar($jar, 'bukkit', '5.5.87'), 'bukkit jar verified');
$problem = null;
check(!L::verifyDownloadedJar($jar, 'bukkit', '5.5.88', $problem) && str_contains((string) $problem, 'expected bukkit 5.5.88'), 'bukkit jar with another version refused');
check(!L::verifyDownloadedJar($jar, 'velocity', '5.5.87'), 'bukkit jar refused for velocity');

$files[] = $jar = makeZip(['velocity-plugin.json' => $velocityJson]);
check(L::inspectJar($jar) === ['platform' => 'velocity', 'version' => '5.5.87'], 'velocity jar inspected');
check(L::verifyDownloadedJar($jar, 'velocity', '5.5.87') && !L::verifyDownloadedJar($jar, 'bukkit', '5.5.87'), 'velocity jar verified for velocity only');

$files[] = $jar = makeZip(['plugin.yml' => "name: LuckPermsChat\nversion: 1.0.0\n"]);
check(L::inspectJar($jar) === null, 'add-on jar is not LuckPerms');
$files[] = $jar = makeZip(['plugin.yml' => "name: luckperms\nversion: 5.5.87\n"]);
check(L::inspectJar($jar) === null, 'plugin name must be exactly LuckPerms');
$files[] = $jar = makeZip(['velocity-plugin.json' => '{"id":"luckperms-addon","version":"5.5.87"}']);
check(L::inspectJar($jar) === null, 'velocity id must be exactly luckperms');
$files[] = $jar = makeZip(['plugin.yml' => "name: LuckPerms\nversion: 5.5.87-SNAPSHOT\n"]);
check(L::inspectJar($jar) === null, 'non x.y.z version refused');
$files[] = $jar = makeZip(['plugin.yml' => "name: LuckPerms\nversion: 5.5.87\n".str_repeat('#', L::MAX_DESCRIPTOR_BYTES)]);
check(L::inspectJar($jar) === null, 'oversized plugin.yml in jar refused');
$files[] = $jar = makeZip(['sub/plugin.yml' => $bukkitYml]);
check(L::inspectJar($jar) === null, 'plugin.yml must be at the root of the jar');

$files[] = $notZip = tempnam(sys_get_temp_dir(), 'lp-test-');
file_put_contents($notZip, '<html>Cloudflare error</html>');
$problem = null;
check(L::inspectJar($notZip, $problem) === null && str_contains((string) $problem, 'zip'), 'html page is not a jar');
$files[] = $empty = tempnam(sys_get_temp_dir(), 'lp-test-');
check(!L::verifyDownloadedJar($empty, 'bukkit', '5.5.87'), 'empty download refused');
check(!L::verifyDownloadedJar('/nonexistent/lp.jar', 'bukkit', '5.5.87'), 'missing download refused');

foreach ($files as $file) {
    @unlink($file);
}

// State of a server
$jarOf = fn (string $filename, string $platform, string $version) => ['filename' => $filename, 'platform' => $platform, 'version' => $version, 'size' => 1];
$bukkit71 = $jarOf('LuckPerms-Bukkit-5.5.71.jar', 'bukkit', '5.5.71');

check(L::decideState([], [], 'bukkit', '5.5.87') === ['state' => 'not_installed', 'installed' => null, 'reason' => null], 'nothing installed');
check(L::decideState([], [], 'bukkit', null)['state'] === 'not_installed', 'nothing installed, latest unknown');
check(L::decideState([$bukkit71], [], 'bukkit', '5.5.87') === ['state' => 'update_available', 'installed' => $bukkit71, 'reason' => null], 'update available');
check(L::decideState([$jarOf('LuckPerms.jar', 'bukkit', '5.5.87')], [], 'bukkit', '5.5.87')['state'] === 'up_to_date', 'up to date (manually named jar)');
check(L::decideState([$jarOf('LuckPerms-Bukkit-5.5.90.jar', 'bukkit', '5.5.90')], [], 'bukkit', '5.5.87')['state'] === 'newer_installed', 'newer installed is never downgraded');
check(L::decideState([$bukkit71], [], 'bukkit', null)['state'] === 'unknown', 'latest unknown');
$several = L::decideState([$bukkit71, $jarOf('LuckPerms-Bukkit-5.5.87.jar', 'bukkit', '5.5.87')], [], 'bukkit', '5.5.87');
check($several['state'] === 'conflict' && $several['reason'] === 'several' && $several['installed'] === null, 'two LuckPerms jars -> conflict');
$wrong = L::decideState([$jarOf('LuckPerms-Velocity-5.5.71.jar', 'velocity', '5.5.71')], [], 'bukkit', '5.5.87');
check($wrong['state'] === 'conflict' && $wrong['reason'] === 'wrong_platform', 'velocity jar on a bukkit server -> conflict');
$unverified = L::decideState([$bukkit71], ['LuckPerms (1).jar'], 'bukkit', '5.5.87');
check($unverified['state'] === 'conflict' && $unverified['reason'] === 'unverified' && $unverified['installed'] === $bukkit71, 'unverified jar -> conflict');
check(L::decideState([], ['LuckPerms.jar'], 'bukkit', '5.5.87')['state'] === 'conflict', 'unverified jar blocks an install');

echo (Result::$failures === 0 ? 'OK' : 'FAILED').' ('.Result::$checks.' checks, '.Result::$failures." failed)\n";
exit(Result::$failures === 0 ? 0 : 1);
