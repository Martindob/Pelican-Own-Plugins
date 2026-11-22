<?php

/*
 * Standalone tests of which Modrinth version an automatic update installs - no Pelican, Laravel
 * or Composer needed:
 *
 *     php minecraft-modrinth/tests/ModrinthAutoUpdateTest.php
 *
 * Not part of the distributed plugin (dist/minecraft-modrinth.zip leaves tests/ out).
 */

declare(strict_types=1);

require __DIR__.'/../src/Modrinth/ModrinthRules.php';

use Boy132\MinecraftModrinth\Modrinth\ModrinthRules as M;

$checks = 0;
$failures = 0;

function check(bool $condition, string $name): void
{
    global $checks, $failures;
    $checks++;
    if (!$condition) {
        $failures++;
        echo "FAIL: $name\n";
    }
}

/** Versions newest first, as MinecraftModrinthService sorts them: [id, version_type]. */
function versions(array ...$list): array
{
    return array_map(fn ($v) => ['id' => $v[0], 'version_type' => $v[1], 'version_number' => $v[0]], $list);
}

$target = fn (string $installed, array $versions) => M::autoUpdateTarget($installed, $versions)['id'] ?? null;

check($target('v1', versions(['v2', 'release'], ['v1', 'release'])) === 'v2', 'a newer release replaces a release');
check($target('v1', versions(['v3', 'release'], ['v2', 'release'], ['v1', 'release'])) === 'v3', 'the newest newer release is chosen');
check($target('v2', versions(['v2', 'release'], ['v1', 'release'])) === null, 'the newest version installed: nothing to do');
check($target('v1', versions(['v3', 'beta'], ['v2', 'alpha'], ['v1', 'release'])) === null, 'a release is never replaced by a beta or alpha');
check($target('v1', versions(['v4', 'alpha'], ['v3', 'release'], ['v2', 'beta'], ['v1', 'release'])) === 'v3', 'betas/alphas are skipped for the next release');
check($target('v1', versions(['v3', 'alpha'], ['v2', 'beta'], ['v1', 'beta'])) === 'v2', 'a beta may be replaced by a newer beta');
check($target('v1', versions(['v2', 'release'], ['v1', 'beta'])) === 'v2', 'a beta may be replaced by a newer release');
check($target('v1', versions(['v2', 'alpha'], ['v1', 'alpha'])) === 'v2', 'an alpha may be replaced by a newer alpha');
check($target('old', versions(['v2', 'release'], ['v1', 'release'])) === null, 'an installed version no longer in the compatible list is left alone (no downgrade to an older compatible one)');
check($target('v1', []) === null, 'no compatible versions: nothing');
check($target('v1', versions(['v2', 'snapshot'], ['v1', 'release'])) === null, 'an unknown version_type is never installed automatically');
check($target('v1', versions(['v2', 'release'], ['v1', 'weird'])) === 'v2', 'an installed version of unknown type counts as a release');
check($target('v1', [['version_type' => 'release'], ['id' => 'v1', 'version_type' => 'release']]) === null, 'a malformed newer entry is ignored');

echo $failures === 0 ? "OK ($checks checks, 0 failed)\n" : "FAILED ($checks checks, $failures failed)\n";
exit($failures === 0 ? 0 : 1);
