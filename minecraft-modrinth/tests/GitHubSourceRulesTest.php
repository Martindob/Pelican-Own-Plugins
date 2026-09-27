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
require __DIR__.'/../src/Modrinth/ModrinthRules.php';

use Boy132\MinecraftModrinth\GitHub\CiStatus;
use Boy132\MinecraftModrinth\GitHub\GitHubSourceException;
use Boy132\MinecraftModrinth\GitHub\GitHubSourceRules as R;
use Boy132\MinecraftModrinth\Modrinth\ModrinthRules as M;

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

// 1.3.1: CI with branch and required workflow
$wf = fn (string $file, string $status, ?string $conclusion, array $overrides = []) => $run($status, $conclusion, array_merge(['head_branch' => 'main', 'path' => ".github/workflows/$file"], $overrides));
check(R::evaluateWorkflowRuns([$wf('build.yml', 'completed', 'success')], $sha, 'main', 'build.yml') === CiStatus::Green, 'required workflow succeeded = green');
check(R::evaluateWorkflowRuns([$wf('lint.yml', 'completed', 'success')], $sha, 'main', 'build.yml') === CiStatus::Unverified, 'required workflow missing = unverified');
check(R::evaluateWorkflowRuns([$wf('lint.yml', 'completed', 'success'), $wf('build.yml', 'in_progress', null)], $sha, 'main', 'build.yml') === CiStatus::Pending, 'required workflow running = pending');
check(R::evaluateWorkflowRuns([$wf('build.yml', 'completed', 'skipped'), $wf('lint.yml', 'completed', 'success')], $sha, 'main', 'build.yml') === CiStatus::Unverified, 'required workflow only skipped = unverified');
check(R::evaluateWorkflowRuns([$wf('build.yml', 'completed', 'success'), $wf('lint.yml', 'completed', 'failure')], $sha, 'main', 'build.yml') === CiStatus::Failed, 'other workflow failed = failed');
check(R::evaluateWorkflowRuns([$wf('BUILD.yml', 'completed', 'success')], $sha, 'main', 'build.yml') === CiStatus::Green, 'workflow file compared case-insensitively');
check(R::evaluateWorkflowRuns([$wf('build.yml', 'completed', 'success', ['path' => '.github/workflows/build.yml@refs/heads/main'])], $sha, 'main', 'build.yml') === CiStatus::Green, 'workflow path with @ref');
check(R::evaluateWorkflowRuns([$wf('build.yml', 'completed', 'success', ['head_branch' => 'other'])], $sha, 'main', 'build.yml') === CiStatus::Unverified, 'run of another branch ignored');
check(R::evaluateWorkflowRuns([$wf('x.yml', 'completed', 'success', ['head_branch' => 'other'])], $sha, 'main') === CiStatus::Unverified, 'branch checked without required workflow too');
check(R::evaluateWorkflowRuns([$wf('x.yml', 'completed', 'failure', ['head_branch' => 'other']), $wf('y.yml', 'completed', 'success')], $sha, 'main') === CiStatus::Green, 'failure on another branch ignored');
check(R::evaluateWorkflowRuns([$wf('build.yml', 'completed', 'success', ['head_sha' => str_repeat('b', 40)])], $sha, 'main', 'build.yml') === CiStatus::Unverified, 'run of another sha ignored');
check(R::evaluateWorkflowRuns([$run('completed', 'success')], $sha) === CiStatus::Green, 'old call without branch still works');
check(R::workflowFileOfRun(['path' => 'dynamic/pages/pages-build-deployment']) === null, 'dynamic workflow has no file');
check(R::workflowFileOfRun([]) === null, 'run without path');

check(R::isValidWorkflowFile('build.yml') && R::isValidWorkflowFile('ci-build.yaml'), 'workflow file ok');
check(!R::isValidWorkflowFile('.github/workflows/build.yml'), 'workflow path refused');
check(!R::isValidWorkflowFile('build') && !R::isValidWorkflowFile('../x.yml') && !R::isValidWorkflowFile(''), 'invalid workflow files');

// Settings / daemon / housekeeping helpers
check(R::isValidAutoUpdateTime('03:45') && R::isValidAutoUpdateTime('23:59:59') && R::isValidAutoUpdateTime('00:00'), 'valid times');
check(!R::isValidAutoUpdateTime('24:00') && !R::isValidAutoUpdateTime('3:45') && !R::isValidAutoUpdateTime("03:45\nX=1") && !R::isValidAutoUpdateTime('03:45 '), 'invalid times');
check(R::scheduleTime('03:45:00') === '03:45' && R::scheduleTime('bogus') === '00:00' && R::scheduleTime(null) === '00:00', 'schedule time with fallback');

check(R::isTemporaryUploadName('.github-0123456789ab.part') && R::isTemporaryUploadName('.modrinth-0123456789ab.part'), 'temporary upload names');
check(!R::isTemporaryUploadName('github-0123456789ab.part') && !R::isTemporaryUploadName('.github-0123.part') && !R::isTemporaryUploadName('x.jar'), 'not temporary upload names');
$now = strtotime('2026-09-27T12:00:00Z');
check(R::isStaleTemporaryUpload('.github-0123456789ab.part', '2026-09-27T10:00:00Z', $now), 'part older than an hour is stale');
check(!R::isStaleTemporaryUpload('.github-0123456789ab.part', '2026-09-27T11:30:00Z', $now), 'recent part is kept');
check(!R::isStaleTemporaryUpload('.github-0123456789ab.part', null, $now) && !R::isStaleTemporaryUpload('.github-0123456789ab.part', 'garbage', $now), 'unknown age is kept');
check(!R::isStaleTemporaryUpload('plugin.jar', '2020-01-01T00:00:00Z', $now), 'a jar is never a stale upload');

check(R::isUnknownServerResponse(404, '{"error":"The requested resource does not exist on this instance."}'), 'unknown server');
check(!R::isUnknownServerResponse(404, '{"error":"The requested resource was not found on the system."}'), 'missing file is not an unknown server');
check(!R::isUnknownServerResponse(500, 'does not exist on this instance'), 'unknown server only with 404');
check(R::isDiskSpaceResponse('{"error":"There is not enough disk space available to perform that action."}'), 'disk full');
check(!R::isDiskSpaceResponse('{"error":"An unexpected error was encountered."}'), 'other error is not disk full');

check(R::describeValue(2) === '2' && R::describeValue(['x']) === 'array', 'describe value');
check(strlen(R::describeValue(str_repeat('a', 500))) <= 35 && !str_contains(R::describeValue("a\nb\x1b"), "\n"), 'describe value short and printable');
check(throwsReason(fn () => R::parseIndex(json_encode(['schema' => str_repeat('x', 5000), 'plugins' => []])), GitHubSourceException::UNSUPPORTED_SCHEMA), 'long schema still unsupported');
try {
    R::parseIndex(json_encode(['schema' => str_repeat('x', 5000), 'plugins' => []]));
} catch (GitHubSourceException $exception) {
    check(strlen($exception->getMessage()) < 100, 'unsupported schema message is short');
}

check(R::validateIndexEntry($entry(['size' => R::MAX_JAR_BYTES])) !== null && R::validateIndexEntry($entry(['size' => 50 * 1024 * 1024 + 1])) === null, 'jar size limit 50 MB');

// Offers: old jars pending deletion are ours, not a manual copy
$pendingPlugin = ['id' => 'gdprerase-paper', 'name' => 'GdprErase', 'platform' => 'paper', 'version' => '1.5.0', 'filename' => 'gdprerase-paper-1.5.0.jar', 'sha256' => $jarSha];
$offers = R::offers([$pendingPlugin], 'paper', [], [], ['gdprerase-paper-1.4.0.jar']);
check($offers['new'] === [], 'unmanaged old jar: not offered (manual)');
$offers = R::offers([$pendingPlugin], 'paper', [], [], ['gdprerase-paper-1.4.0.jar'], ['gdprerase-paper-1.4.0.jar']);
check(array_column($offers['new'], 'id') === ['gdprerase-paper'], 'jar pending deletion counts as managed');

// Modrinth metadata and downloads
$mod = fn (array $overrides = []) => array_merge([
    'project_id' => 'AANobbMI',
    'project_slug' => 'sodium',
    'project_title' => 'Sodium',
    'version_id' => 'abcDEF12',
    'version_number' => '0.6.0',
    'filename' => 'sodium-fabric-0.6.0.jar',
    'installed_at' => '2026-09-01T00:00:00+00:00',
], $overrides);
$meta = fn (array $mods) => json_encode(['installed_mods' => $mods]);

check(M::isValidId('AANobbMI') && !M::isValidId('AANobbM') && !M::isValidId('AANobbM/') && !M::isValidId(['x']) && !M::isValidId(null), 'modrinth ids');
check(M::isValidJarFilename('Geyser-Spigot.jar') && M::isValidJarFilename('[1.21] My Plugin (v2).JAR'), 'modrinth jar names with spaces/brackets');
foreach (['', '.hidden.jar', 'a/b.jar', 'a\\b.jar', '../x.jar', 'x..jar', "x\0.jar", "x\n.jar", 'plugin.zip', 'folder', str_repeat('a', 253).'.jar'] as $bad) {
    check(!M::isValidJarFilename($bad), 'invalid modrinth file name: '.json_encode($bad));
}

$parsed = M::parseMetadata($meta([$mod(), $mod(['project_id' => 'P7dR8mSH', 'filename' => 'fabric-api.jar'])]));
check(count($parsed['entries']) === 2 && count($parsed['raw']) === 2, 'valid metadata');
foreach ([
    'project_id not a string' => ['project_id' => ['x']],
    'project_id wrong format' => ['project_id' => 'x'],
    'filename a folder' => ['filename' => 'config'],
    'filename with path' => ['filename' => '../server.jar'],
    'title not a string' => ['project_title' => 5],
    'version_id empty' => ['version_id' => ''],
    'string too long' => ['project_slug' => str_repeat('a', 300)],
] as $name => $override) {
    $parsed = M::parseMetadata($meta([$mod($override), $mod(['project_id' => 'P7dR8mSH'])]));
    check(count($parsed['entries']) === 1 && count($parsed['raw']) === 2, "invalid metadata entry skipped but kept raw: $name");
}
$parsed = M::parseMetadata($meta([$mod(), $mod(['version_number' => '0.7.0'])]));
check(count($parsed['entries']) === 1 && $parsed['entries'][0]['version_number'] === '0.6.0', 'first entry per project wins');
$parsed = M::parseMetadata($meta([$mod(['author' => 'jellysquid', 'updated_at' => '2026-09-02', 'extra' => 'x'])]));
check(($parsed['entries'][0]['author'] ?? null) === 'jellysquid' && !isset($parsed['entries'][0]['extra']), 'optional fields kept, unknown dropped from entries');
$parsed = M::parseMetadata($meta([$mod(['author' => ['x']])]));
check(!isset($parsed['entries'][0]['author']) && count($parsed['entries']) === 1, 'invalid optional field ignored');

$many = [];
for ($i = 0; $i < M::MAX_ENTRIES + 5; $i++) {
    $many[] = $mod(['project_id' => sprintf('A%07d', $i)]);
}
check(count(M::parseMetadata($meta($many))['entries']) === M::MAX_ENTRIES, 'entries capped');

$throwsUnexpected = function (callable $fn): bool {
    try {
        $fn();
    } catch (UnexpectedValueException) {
        return true;
    }

    return false;
};
check($throwsUnexpected(fn () => M::parseMetadata('{broken')), 'broken metadata json refused');
check($throwsUnexpected(fn () => M::parseMetadata('')), 'empty metadata refused');
check($throwsUnexpected(fn () => M::parseMetadata('{"installed_mods": {"a": 1}}')), 'installed_mods not a list refused');
check($throwsUnexpected(fn () => M::parseMetadata('[]')), 'metadata without installed_mods refused');
check($throwsUnexpected(fn () => M::parseMetadata(str_repeat(' ', M::MAX_METADATA_BYTES + 1))), 'oversized metadata refused');
check(M::parseMetadata('{"installed_mods": []}') === ['entries' => [], 'raw' => []], 'empty metadata list ok');

$raw = [$mod(), 'garbage', $mod(['project_id' => 'P7dR8mSH', 'filename' => 'fabric-api.jar'])];
$upd = M::upsertEntry($raw, $mod(['version_number' => '0.7.0']));
check(count($upd) === 3 && $upd[0]['version_number'] === '0.7.0' && $upd[1] === 'garbage', 'upsert replaces in place, keeps others verbatim');
$upd = M::upsertEntry($raw, $mod(['project_id' => 'NEWPROJ1']));
check(count($upd) === 4 && $upd[3]['project_id'] === 'NEWPROJ1', 'upsert appends new project');
$upd = M::upsertEntry([$mod(), $mod()], $mod(['version_number' => '0.8.0']));
check(count($upd) === 1, 'upsert collapses duplicate entries of the project');
$rem = M::removeEntry($raw, 'AANobbMI');
check(count($rem) === 2 && $rem[0] === 'garbage', 'remove keeps other entries');

check(M::isAllowedDownloadUrl('https://cdn.modrinth.com/data/AANobbMI/versions/abc/sodium.jar'), 'cdn url allowed');
foreach (['http://cdn.modrinth.com/x.jar', 'https://evil.com/x.jar', 'https://cdn.modrinth.com.evil.com/x.jar', 'https://user@cdn.modrinth.com/x.jar', 'https://cdn.modrinth.com:8443/x.jar', 'file:///etc/passwd', 'https://cdn.modrinth.com/x y.jar', 'https://127.0.0.1/x.jar', null] as $bad) {
    check(!M::isAllowedDownloadUrl($bad), 'download url refused: '.json_encode($bad));
}

$sha512 = hash('sha512', 'jar');
$file = ['url' => 'https://cdn.modrinth.com/data/x/sodium.jar', 'filename' => 'sodium.jar', 'size' => 3, 'hashes' => ['sha512' => strtoupper($sha512), 'sha1' => 'x'], 'primary' => true];
check(M::downloadSpec($file) === ['url' => $file['url'], 'filename' => 'sodium.jar', 'size' => 3, 'sha512' => $sha512], 'download spec');
foreach ([
    'no sha512' => ['hashes' => ['sha1' => 'x']],
    'bad url' => ['url' => 'https://github.com/x.jar'],
    'zip' => ['filename' => 'pack.zip'],
    'size missing' => ['size' => null],
    'size too big' => ['size' => M::MAX_DOWNLOAD_BYTES + 1],
] as $name => $override) {
    $problem = null;
    check(M::downloadSpec(array_merge($file, $override), $problem) === null && is_string($problem), "download spec refused: $name");
}

echo (Result::$failures === 0 ? 'OK' : 'FAILED').' ('.Result::$checks.' checks, '.Result::$failures." failed)\n";
exit(Result::$failures === 0 ? 0 : 1);
