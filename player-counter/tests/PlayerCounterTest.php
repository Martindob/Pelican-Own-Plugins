<?php

/*
 * Standalone tests of the player-counter logic that needs no Pelican, Laravel or Composer:
 *
 *     php player-counter/tests/PlayerCounterTest.php
 *
 * Not part of the distributed plugin (dist/player-counter.zip leaves tests/ out).
 */

declare(strict_types=1);

require __DIR__.'/../src/Support/PlayerCommand.php';
require __DIR__.'/../src/Extensions/Query/QueryTypeSchemaInterface.php';
require __DIR__.'/../src/Extensions/Query/Schemas/MinecraftJavaQueryTypeSchema.php';

use Boy132\PlayerCounter\Extensions\Query\Schemas\MinecraftJavaQueryTypeSchema as Java;
use Boy132\PlayerCounter\Support\PlayerCommand;

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

// Console commands built from player names
check(PlayerCommand::build('kick', 'Notch') === 'kick Notch', 'a Minecraft name');
check(PlayerCommand::build('whitelist add', 'some_player_99') === 'whitelist add some_player_99', 'underscores and digits');
check(PlayerCommand::build('kick', '.BedrockPlayer') === 'kick .BedrockPlayer', 'a Floodgate (Bedrock) name with its "." prefix');
check(PlayerCommand::build('kick', 'Jiří') === 'kick Jiří', 'letters outside ASCII (Source games)');
foreach ([
    'semicolon (second command on Source)' => 'x;rcon_password hacked',
    'newline (second command)' => "x\nop attacker",
    'carriage return' => "x\rop attacker",
    'space (extra argument)' => 'x attacker',
    'double quote' => 'x"y',
    'single quote' => "x'y",
    'leading dash (an option)' => '-x',
    'empty' => '',
    'NUL byte' => "x\0y",
    'too long' => str_repeat('a', 65),
    'tab' => "x\ty",
] as $case => $name) {
    check(PlayerCommand::build('kick', $name) === null, "refused: $case");
}

// MOTD text from the server list ping
check(Java::plainText('A Minecraft Server') === 'A Minecraft Server', 'a plain string MOTD');
check(Java::plainText('§aGreen §lBold') === 'Green Bold', 'legacy colour codes removed');
check(Java::plainText(['text' => 'Ethoria']) === 'Ethoria', 'a component with text only');
check(Java::plainText(['text' => '', 'extra' => [['text' => 'Ethoria', 'color' => 'gold'], ' ', ['text' => 'Network']]]) === 'Ethoria Network', 'a component with extra parts (what Paper sends for a coloured MOTD)');
check(Java::plainText(['extra' => [['text' => 'No text key']]]) === 'No text key', 'a component without a top-level text');
check(Java::plainText([['text' => 'A'], ['text' => 'B']]) === 'AB', 'a list of components');
check(Java::plainText(['text' => 'a', 'extra' => [['text' => 'b', 'extra' => [['text' => 'c']]]]]) === 'abc', 'nested components');
check(Java::plainText(null) === '' && Java::plainText(42) === '', 'anything else is empty');
$deep = ['text' => 'x'];
for ($i = 0; $i < 50; $i++) {
    $deep = ['text' => '', 'extra' => [$deep]];
}
check(Java::plainText($deep) === '', 'absurd nesting is cut off');

echo $failures === 0 ? "OK ($checks checks, 0 failed)\n" : "FAILED ($checks checks, $failures failed)\n";
exit($failures === 0 ? 0 : 1);
