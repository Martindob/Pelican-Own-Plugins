<?php

/*
 * Standalone tests of the GitHub repository source rules - no Pelican, Laravel or Composer needed:
 *
 *     php minecraft-modrinth/tests/GitHubSourceRulesTest.php
 *
 * Not part of the distributed plugin (dist/minecraft-modrinth.zip leaves tests/ out).
 */

declare(strict_types=1);

require __DIR__.'/../src/GitHub/CiStatus.php';
require __DIR__.'/../src/GitHub/GitHubSourceException.php';
require __DIR__.'/../src/GitHub/GitHubSourceRules.php';

use Boy132\MinecraftModrinth\GitHub\CiStatus;
use Boy132\MinecraftModrinth\GitHub\GitHubSourceException;
use Boy132\MinecraftModrinth\GitHub\GitHubSourceRules as R;

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
    } catch (GitHubSourceException $exception) {
        return $exception->reason === $reason;
    }

    return false;
}

$sha = str_repeat('a', 40);
$jarSha = hash('sha256', 'jar');

$entry = fn (array $overrides = []) => array_merge([
    'id' => 'gdprerase-paper',
    'name' => 'GdprErase',
    'platform' => 'paper',
    'version' => '1.4.0',
    'path' => 'minecraft/GdprErase/paper/releases/gdprerase-paper-1.4.0.jar',
    'sha256' => $jarSha,
    'size' => 3,
    'frozen' => false,
], $overrides);

$index = fn (array $plugins, mixed $schema = 1) => json_encode(['schema' => $schema, 'plugins' => $plugins]);

// Repository / branch / index path settings
check(R::isValidRepository('Martindob/Ethoria.cz'), 'repository ok');
check(R::isValidRepository('a-b/c_d.e-f'), 'repository with allowed characters');
check(!R::isValidRepository('owner/..'), 'repository ..');
check(!R::isValidRepository('owner/.'), 'repository .');
check(!R::isValidRepository('owner'), 'repository without name');
check(!R::isValidRepository('owner/repo/extra'), 'repository with extra segment');
check(!R::isValidRepository('own_er/repo'), 'owner with underscore');
check(!R::isValidRepository(str_repeat('a', 40).'/repo'), 'owner too long');
check(!R::isValidRepository('owner/re po'), 'repository with space');
check(!R::isValidRepository('https://github.com/owner/repo'), 'repository URL');

check(R::isValidBranch('main'), 'branch main');
check(R::isValidBranch('release/1.x'), 'branch with slash');
check(!R::isValidBranch('../main'), 'branch ..');
check(!R::isValidBranch('/main'), 'branch leading slash');
check(!R::isValidBranch('main/'), 'branch trailing slash');
check(!R::isValidBranch('a//b'), 'branch double slash');
check(!R::isValidBranch('-x'), 'branch leading dash');
check(!R::isValidBranch('x.lock'), 'branch .lock');
check(!R::isValidBranch('a/.hidden'), 'branch hidden segment');
check(!R::isValidBranch('main?x=1'), 'branch with query');
check(!R::isValidBranch(''), 'empty branch');

check(R::isValidIndexPath('minecraft/releases.json'), 'index path default');
check(!R::isValidIndexPath('/minecraft/releases.json'), 'index path absolute');
check(!R::isValidIndexPath('minecraft/../releases.json'), 'index path ..');
check(!R::isValidIndexPath('minecraft/./releases.json'), 'index path .');
check(!R::isValidIndexPath('minecraft//releases.json'), 'index path //');
check(!R::isValidIndexPath('minecraft\\releases.json'), 'index path backslash');
check(!R::isValidIndexPath('minecraft/releases.yml'), 'index path not json');
check(!R::isValidIndexPath('minecraft/releases.json?ref=x'), 'index path with query');

check(R::isPlausibleToken('github_pat_11ABCDEFG0123456789_abcdefghijklmnopqrstuvwxyz'), 'fine-grained token');
check(R::isPlausibleToken('ghp_'.str_repeat('a', 36)), 'classic token');
check(!R::isPlausibleToken('short'), 'short token');
check(!R::isPlausibleToken("ghp_abc\nX-Other: 1".str_repeat('a', 20)), 'token with newline');

// Index validation
$parsed = R::parseIndex($index([$entry()]));
check(count($parsed['plugins']) === 1 && $parsed['problems'] === [], 'valid index');
check($parsed['plugins'][0]['filename'] === 'gdprerase-paper-1.4.0.jar', 'filename is basename of path');

check(throwsReason(fn () => R::parseIndex($index([$entry()], 2)), GitHubSourceException::UNSUPPORTED_SCHEMA), 'unknown schema');
check(throwsReason(fn () => R::parseIndex($index([$entry()], '1')), GitHubSourceException::UNSUPPORTED_SCHEMA), 'schema as string');
check(throwsReason(fn () => R::parseIndex(json_encode(['plugins' => []])), GitHubSourceException::UNSUPPORTED_SCHEMA), 'missing schema');
check(throwsReason(fn () => R::parseIndex('{not json'), GitHubSourceException::INVALID_INDEX), 'invalid json');
check(throwsReason(fn () => R::parseIndex(json_encode(['schema' => 1])), GitHubSourceException::INVALID_INDEX), 'missing plugins');
check(throwsReason(fn () => R::parseIndex(json_encode(['schema' => 1, 'plugins' => ['a' => $entry()]])), GitHubSourceException::INVALID_INDEX), 'plugins not a list');
check(throwsReason(fn () => R::parseIndex(str_repeat(' ', R::MAX_INDEX_BYTES + 1)), GitHubSourceException::INVALID_INDEX), 'index too large');

$invalid = [
    'path traversal' => ['path' => 'minecraft/../../etc/passwd.jar'],
    'absolute path' => ['path' => '/minecraft/x.jar'],
    'not a jar' => ['path' => 'minecraft/x.zip'],
    'hidden jar' => ['path' => 'minecraft/.x.jar'],
    'path with backslash' => ['path' => 'minecraft\\x.jar'],
    'bad version' => ['version' => '1.4'],
    'version with suffix' => ['version' => '1.4.0-SNAPSHOT'],
    'version leading zero' => ['version' => '01.4.0'],
    'bad sha' => ['sha256' => 'xyz'],
    'short sha' => ['sha256' => str_repeat('a', 63)],
    'bad platform' => ['platform' => 'fabric'],
    'size zero' => ['size' => 0],
    'size string' => ['size' => '3'],
    'size too big' => ['size' => R::MAX_JAR_BYTES + 1],
    'bad id' => ['id' => '../x'],
    'bad name' => ['name' => '<script>'],
];
foreach ($invalid as $name => $override) {
    $result = R::parseIndex($index([$entry($override)]));
    check($result['plugins'] === [] && count($result['problems']) === 1, "invalid entry skipped: $name");
}

$result = R::parseIndex($index([$entry(), $entry(['name' => 'Other'])]));
check(count($result['plugins']) === 1 && count($result['problems']) === 1, 'duplicate id skipped');

$result = R::parseIndex($index([$entry(), $entry(['id' => 'other', 'path' => 'x/gdprerase-paper-1.4.0.jar'])]));
check(count($result['plugins']) === 1 && count($result['problems']) === 1, 'duplicate file name on the same platform skipped');

$result = R::parseIndex($index([$entry(), $entry(['id' => 'gdprerase-velocity', 'platform' => 'velocity', 'path' => 'x/gdprerase-paper-1.4.0.jar'])]));
check(count($result['plugins']) === 2, 'same file name on different platforms allowed');

$result = R::parseIndex($index([$entry(['sha256' => strtoupper($jarSha), 'frozen' => true])]));
check($result['plugins'][0]['sha256'] === $jarSha && $result['plugins'][0]['frozen'] === true, 'sha256 lower-cased, frozen kept');

$result = R::parseIndex($index([$entry(['frozen' => 'yes'])]));
check($result['plugins'][0]['frozen'] === false, 'frozen only true when boolean true');

// CI evaluation
$run = fn (string $status, ?string $conclusion, array $overrides = []) => array_merge(['head_sha' => $sha, 'event' => 'push', 'status' => $status, 'conclusion' => $conclusion], $overrides);

check(R::evaluateWorkflowRuns([], $sha) === CiStatus::Unverified, 'no runs = unverified');
check(R::evaluateWorkflowRuns([$run('completed', 'success')], $sha) === CiStatus::Green, 'one success = green');
check(R::evaluateWorkflowRuns([$run('completed', 'success'), $run('completed', 'skipped'), $run('completed', 'neutral')], $sha) === CiStatus::Green, 'success + skipped + neutral = green');
check(R::evaluateWorkflowRuns([$run('completed', 'skipped')], $sha) === CiStatus::Unverified, 'only skipped = unverified');
check(R::evaluateWorkflowRuns([$run('completed', 'success'), $run('in_progress', null)], $sha) === CiStatus::Pending, 'running = pending');
check(R::evaluateWorkflowRuns([$run('completed', 'success'), $run('queued', null)], $sha) === CiStatus::Pending, 'queued = pending');
check(R::evaluateWorkflowRuns([$run('completed', 'failure'), $run('in_progress', null)], $sha) === CiStatus::Failed, 'failure beats pending');
foreach (['failure', 'cancelled', 'timed_out', 'action_required', 'startup_failure', 'stale', null] as $conclusion) {
    check(R::evaluateWorkflowRuns([$run('completed', 'success'), $run('completed', $conclusion)], $sha) === CiStatus::Failed, 'conclusion '.var_export($conclusion, true).' = failed');
}
check(R::evaluateWorkflowRuns([$run('completed', 'success', ['head_sha' => str_repeat('b', 40)])], $sha) === CiStatus::Unverified, 'runs of other commits ignored');
check(R::evaluateWorkflowRuns([$run('completed', 'success', ['event' => 'pull_request'])], $sha) === CiStatus::Unverified, 'non-push runs ignored');
check(R::evaluateWorkflowRuns([$run('completed', 'failure', ['event' => 'schedule']), $run('completed', 'success')], $sha) === CiStatus::Green, 'failed non-push run ignored');

// Versions
check(R::compareVersions('1.10.0', '1.9.9') > 0, '1.10.0 > 1.9.9');
check(R::compareVersions('2.0.0', '10.0.0') < 0, '2.0.0 < 10.0.0');
check(R::compareVersions('1.4.0', '1.4.0') === 0, 'equal versions');
check(R::isNewerVersion('1.4.1', '1.4.0'), 'newer patch');
check(!R::isNewerVersion('1.4.0', '1.4.0'), 'same version not newer');
check(!R::isNewerVersion('1.3.9', '1.4.0'), 'older not newer');
check(!R::isNewerVersion('1.5.0', 'garbage'), 'unparsable installed version is never auto-replaced');

// File names
check(R::jarStem('gdprerase-paper-1.4.0.jar') === 'gdprerase-paper', 'stem strips version');
check(R::jarStem('GdprErase-Paper-2.0.jar') === 'gdprerase-paper', 'stem lower-cases');
check(R::jarStem('EthoriaLeaderboard-1.0.0-SNAPSHOT.jar') === 'ethorialeaderboard', 'stem strips suffix');
check(R::jarStem('plugin.jar') === 'plugin', 'stem without version');
check(R::jarStem(R::alternativeFilename('gdprerase-paper-1.4.0.jar', $jarSha)) === 'gdprerase-paper', 'alternative name has the same stem');
check(R::belongsToSamePlugin('gdprerase-paper-1.3.0.jar', 'gdprerase-paper-1.4.0.jar'), 'other version = same plugin');
check(!R::belongsToSamePlugin('gdprerase-velocity-1.4.0.jar', 'gdprerase-paper-1.4.0.jar'), 'other platform = different plugin');
check(!R::belongsToSamePlugin('gdprerase-paper-addon-1.0.0.jar', 'gdprerase-paper-1.4.0.jar'), 'addon = different plugin');
check(!R::belongsToSamePlugin('gdprerase-paper-1.3.0.jar.part', 'gdprerase-paper-1.4.0.jar'), 'non-jar ignored');
check(R::looksLikeSamePlugin('GdprErase.jar', 'gdprerase-paper-1.4.0.jar', 'GdprErase'), 'manual jar named after the plugin');
check(R::looksLikeSamePlugin('GdprErase-1.3.jar', 'gdprerase-paper-1.4.0.jar', 'GdprErase'), 'manual versioned jar named after the plugin');
check(R::looksLikeSamePlugin('gdprerase-paper-1.3.0.jar', 'gdprerase-paper-1.4.0.jar', 'GdprErase'), 'other version of the file');
check(!R::looksLikeSamePlugin('LuckPerms-Bukkit-5.4.jar', 'gdprerase-paper-1.4.0.jar', 'GdprErase'), 'unrelated jar');
check(!R::looksLikeSamePlugin('GdprErase', 'gdprerase-paper-1.4.0.jar', 'GdprErase'), 'folder of the plugin is not a jar');
check(R::alternativeFilename('a-1.0.0.jar', $jarSha) === 'a-1.0.0-'.substr($jarSha, 0, 12).'.jar', 'alternative file name');
check(R::isValidJarFilename('gdprerase-paper-1.4.0.jar'), 'valid jar name');
check(!R::isValidJarFilename('.hidden.jar'), 'hidden jar name');
check(!R::isValidJarFilename('a/b.jar'), 'jar name with slash');
check(!R::isValidJarFilename('a..b.jar'), 'jar name with ..');
check(!R::isValidJarFilename('config.yml'), 'not a jar');

// Platform mapping
check(R::platformForLoaderTags(['minecraft', 'paper']) === 'paper', 'paper tag');
check(R::platformForLoaderTags(['minecraft', 'purpur']) === 'paper', 'purpur runs paper plugins');
check(R::platformForLoaderTags(['minecraft', 'velocity', 'proxy']) === 'velocity', 'velocity tag');
check(R::platformForLoaderTags(['minecraft', 'fabric']) === null, 'fabric has no platform');
check(R::platformForLoaderTags(['minecraft', 'folia']) === null, 'folia is not assumed to run paper plugins');

// Checksum / size
check(R::verifyJar('jar', 3, $jarSha), 'matching jar');
check(R::verifyJar('jar', 3, strtoupper($jarSha)), 'upper-case sha accepted');
check(!R::verifyJar('jar', 4, $jarSha), 'size mismatch');
check(!R::verifyJar('jaR', 3, $jarSha), 'hash mismatch');

// Rate limits and redirects
check(R::retryAfterSeconds(['retry-after' => '30'], 1000) === 30, 'retry-after');
check(R::retryAfterSeconds(['x-ratelimit-reset' => '1100'], 1000) === 100, 'rate limit reset');
check(R::retryAfterSeconds([], 1000) === 60, 'default back-off');
check(R::retryAfterSeconds(['retry-after' => '999999'], 1000) === 3600, 'back-off capped');
check(R::isAllowedRedirectHost('raw.githubusercontent.com'), 'raw host');
check(R::isAllowedRedirectHost('objects.githubusercontent.com'), 'objects host');
check(!R::isAllowedRedirectHost('evil.com'), 'foreign host');
check(!R::isAllowedRedirectHost('raw.githubusercontent.com.evil.com'), 'suffix trick');
check(!R::isAllowedRedirectHost('api.github.com.evil.com'), 'api suffix trick');

// Redaction
$token = 'github_pat_11ABCDEFG0123456789_abcdefghijklmnopqrstuvwxyz';
check(!str_contains(R::redact("failed with $token"), 'abcdefghijklmnop'), 'fine-grained token redacted');
check(!str_contains(R::redact('ghp_'.str_repeat('x', 36)), str_repeat('x', 36)), 'classic token redacted');
check(!str_contains((new GitHubSourceException('network', "Bearer $token"))->getMessage(), 'abcdefghijklmnop'), 'exception message redacted');

// Offers: new / dismissed / manual / updates, per platform
$paper = fn (string $id, string $name, string $version = '1.0.0') => R::validateIndexEntry($entry([
    'id' => $id, 'name' => $name, 'version' => $version, 'path' => "minecraft/$name/$id-$version.jar",
]));
$velocity = fn (string $id, string $name, string $version = '1.0.0') => R::validateIndexEntry($entry([
    'id' => $id, 'name' => $name, 'platform' => 'velocity', 'version' => $version, 'path' => "minecraft/$name/$id-$version.jar",
]));
$installedEntry = fn (array $plugin, string $version, ?string $filename = null) => [
    'id' => $plugin['id'], 'version' => $version, 'sha256' => $plugin['sha256'], 'filename' => $filename ?? $plugin['filename'],
];

$pNew = $paper('newpaper', 'NewPaper');
$pUpd = $paper('updpaper', 'UpdPaper', '2.0.0');
$pManual = $paper('manualpaper', 'ManualPaper');
$pSame = $paper('samepaper', 'SamePaper');
$vNew = $velocity('newvelo', 'NewVelo');
$all = [$pNew, $pUpd, $pManual, $pSame, $vNew];
$installedList = [$installedEntry($pUpd, '1.0.0', 'updpaper-1.0.0.jar'), $installedEntry($pSame, '1.0.0')];
$jars = ['updpaper-1.0.0.jar', $pSame['filename'], 'ManualPaper.jar', 'LuckPerms-Bukkit-5.4.jar'];

check(R::pluginsForPlatform($all, 'paper') === [$pNew, $pUpd, $pManual, $pSame], 'paper server sees only paper plugins');
check(R::pluginsForPlatform($all, 'velocity') === [$vNew], 'velocity server sees only velocity plugins');
check(R::pluginsForPlatform($all, null) === [], 'server without platform sees nothing');
check(R::pluginsForPlatform($all, 'fabric') === [], 'unknown platform sees nothing');

check(R::pluginState($pNew, null, false, false) === 'new', 'not installed = new');
check(R::pluginState($pNew, null, true, false) === 'dismissed', 'dismissed');
check(R::pluginState($pNew, null, true, true) === 'manual', 'manual copy wins over dismissed');
check(R::pluginState($pNew, null, false, true) === 'manual', 'manual copy is not new');
check(R::pluginState($pUpd, $installedEntry($pUpd, '1.0.0'), true, false) === 'update_available', 'dismissal does not hide an update');
check(R::pluginState($pSame, $installedEntry($pSame, '1.0.0'), false, false) === 'up_to_date', 'up to date');
check(R::pluginState($pSame, ['version' => '1.0.0', 'sha256' => str_repeat('b', 64)], false, false) === 'modified', 'modified');
check(R::pluginState($pSame, $installedEntry($pSame, '2.0.0'), false, false) === 'repo_older', 'repo older');

check(R::hasUnmanagedCopy($pManual, $jars, ['updpaper-1.0.0.jar']), 'manual jar named after the plugin');
check(!R::hasUnmanagedCopy($pNew, $jars, ['updpaper-1.0.0.jar']), 'no copy of a new plugin');
check(!R::hasUnmanagedCopy($pSame, [$pSame['filename']], [$pSame['filename']]), 'our own jar is not an unmanaged copy');
check(R::hasUnmanagedCopy($pNew, ['newpaper-0.9.0.jar'], []), 'older version of the file is a copy');

$offers = R::offers($all, 'paper', $installedList, [], $jars);
check(array_column($offers['new'], 'id') === ['newpaper'], 'paper: only the really new plugin is offered');
check(array_column($offers['updates'], 'id') === ['updpaper'], 'paper: update offered');

$offers = R::offers($all, 'paper', $installedList, ['newpaper'], $jars);
check($offers['new'] === [] && count($offers['updates']) === 1, 'dismissed plugin not offered');

$offers = R::offers($all, 'velocity', [], [], []);
check(array_column($offers['new'], 'id') === ['newvelo'] && $offers['updates'] === [], 'velocity: only the velocity plugin is offered');

$offers = R::offers($all, null, [], [], []);
check($offers === ['new' => [], 'updates' => []], 'no platform: nothing offered');

// A velocity server with a paper plugin in its (odd) metadata still gets no paper offers.
$offers = R::offers($all, 'velocity', [$installedEntry($pUpd, '1.0.0')], [], []);
check($offers['updates'] === [] && array_column($offers['new'], 'id') === ['newvelo'], 'velocity: no paper update either');

check(array_column(R::pluginsToAnnounce([$pNew, $vNew], ['newpaper']), 'id') === ['newvelo'], 'already announced plugin skipped');
check(R::pluginsToAnnounce([$pNew], ['newpaper', 'other']) === [], 'nothing left to announce');
check(R::pluginsToAnnounce([$pNew], []) === [$pNew], 'announce new plugin');

check(R::navigationBadge(null) === null, 'no cache, no badge');
check(R::navigationBadge(['new' => 0, 'updates' => 0]) === null, 'nothing offered, no badge');
check(R::navigationBadge(['new' => 2, 'updates' => 0]) === ['label' => '2', 'color' => 'info', 'new' => 2, 'updates' => 0], 'only new = info');
check(R::navigationBadge(['new' => 1, 'updates' => 2])['color'] === 'warning' && R::navigationBadge(['new' => 1, 'updates' => 2])['label'] === '3', 'updates = warning, total');
check(R::navigationBadge(['new' => '1', 'updates' => 0]) === null, 'garbage in cache, no badge');

echo (Result::$failures === 0 ? 'OK' : 'FAILED').' ('.Result::$checks.' checks, '.Result::$failures." failed)\n";
exit(Result::$failures === 0 ? 0 : 1);
