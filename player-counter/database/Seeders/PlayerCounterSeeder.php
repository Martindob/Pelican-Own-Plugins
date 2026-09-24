<?php

namespace Database\Seeders;

use App\Models\Egg;
use Boy132\PlayerCounter\Models\EggGameQuery;
use Boy132\PlayerCounter\Models\GameQuery;
use Exception;
use Illuminate\Database\Seeder;

class PlayerCounterSeeder extends Seeder
{
    public const MAPPINGS = [
        [
            'names' => 'Squad',
            'query_type' => 'source',
            'query_port_offset' => 19378,
            'query_port_variable' => null,
        ],
        [
            'names' => 'Barotrauma',
            'query_type' => 'source',
            'query_port_offset' => 1,
            'query_port_variable' => null,
        ],
        [
            'names' => 'Valheim',
            'query_type' => 'source',
            'query_port_offset' => 1,
            'query_port_variable' => null,
        ],
        [
            'names' => ['V Rising', 'V-Rising', 'VRising'],
            'query_type' => 'source',
            'query_port_offset' => 1,
            'query_port_variable' => null,
        ],
        [
            'names' => ['The Forest', 'TheForest'],
            'query_type' => 'source',
            'query_port_offset' => 1,
            'query_port_variable' => null,
        ],
        [
            'names' => ['Arma 3', 'Arma3'],
            'query_type' => 'source',
            'query_port_offset' => 1,
            'query_port_variable' => null,
        ],
        [
            'names' => ['Arma Reforger', 'ArmaReforger'],
            'query_type' => 'source',
            'query_port_offset' => 15776,
            'query_port_variable' => null,
        ],
        [
            'names' => ['ARK: Survival Evolved', 'ARK: SurvivalEvolved', 'ARK Survival Evolved', 'ARK SurvivalEvolved', 'ARKSurvivalEvolved'],
            'query_type' => 'source',
            'query_port_offset' => 19238,
            'query_port_variable' => null,
        ],
        [
            'names' => 'Unturned',
            'query_type' => 'source',
            'query_port_offset' => 1,
            'query_port_variable' => null,
        ],
        [
            'names' => ['Insurgency: Sandstorm', 'Insurgency Sandstorm', 'InsurgencySandstorm'],
            'query_type' => 'source',
            'query_port_offset' => 29,
            'query_port_variable' => null,
        ],
        [
            'names' => 'Palworld',
            'query_type' => 'palworld',
            'query_port_offset' => null,
            'query_port_variable' => null,
        ],
        [
            'tag' => 'bedrock',
            'query_type' => 'minecraft_bedrock',
            'query_port_offset' => null,
            'query_port_variable' => null,
        ],
        // Proxy mappings must come before the generic 'minecraft' tag mapping below: an egg
        // can carry both tags (e.g. a Velocity egg also tagged 'minecraft'), and only the
        // first match in this list is applied per egg, so the more specific proxy mapping
        // has to win the tie instead of being shadowed by the generic Java one.
        [
            'names' => 'Velocity',
            'tag' => 'velocity',
            'query_type' => 'minecraft_proxy',
            'query_port_offset' => null,
            'query_port_variable' => null,
        ],
        [
            'names' => 'BungeeCord',
            'tag' => 'bungeecord',
            'query_type' => 'minecraft_proxy',
            'query_port_offset' => null,
            'query_port_variable' => null,
        ],
        [
            'names' => 'Waterfall',
            'tag' => 'waterfall',
            'query_type' => 'minecraft_proxy',
            'query_port_offset' => null,
            'query_port_variable' => null,
        ],
        [
            'tag' => 'minecraft',
            'query_type' => 'minecraft_java',
            'query_port_offset' => null,
            'query_port_variable' => null,
        ],
        [
            'tag' => 'source',
            'query_type' => 'source',
            'query_port_offset' => null,
            'query_port_variable' => null,
        ],
    ];

    public function run(): void
    {
        foreach (Egg::all() as $egg) {
            $tags = $egg->tags ?? [];

            // Only the first (highest-priority) match in MAPPINGS applies per egg: an egg can
            // match more than one mapping (e.g. a Velocity egg also tagged 'minecraft'), and
            // MAPPINGS is ordered so the more specific one wins that tie.
            $mapping = null;
            foreach (self::MAPPINGS as $candidate) {
                if ((array_key_exists('names', $candidate) && in_array($egg->name, array_wrap($candidate['names']))) || (array_key_exists('tag', $candidate) && in_array($candidate['tag'], $tags))) {
                    $mapping = $candidate;

                    break;
                }
            }

            if (!$mapping) {
                continue;
            }

            try {
                $query = GameQuery::firstOrCreate([
                    'query_type' => $mapping['query_type'],
                    'query_port_offset' => $mapping['query_port_offset'],
                    'query_port_variable' => $mapping['query_port_variable'],
                ]);

                /** @var ?EggGameQuery $existing */
                $existing = EggGameQuery::where('egg_id', $egg->id)->first();

                if ($existing) {
                    // Correct the one known bad state an older version of this seeder could
                    // produce: a proxy egg (also tagged 'minecraft') mis-assigned minecraft_java
                    // because the generic mapping used to be checked before the proxy ones.
                    // Any other existing association is left alone, so manual admin changes
                    // to unrelated eggs survive a re-run of this seeder.
                    if ($mapping['query_type'] === 'minecraft_proxy' && GameQuery::find($existing->game_query_id)?->query_type === 'minecraft_java') {
                        $existing->update(['game_query_id' => $query->id]);
                    }

                    continue;
                }

                EggGameQuery::create([
                    'egg_id' => $egg->id,
                    'game_query_id' => $query->id,
                ]);
            } catch (Exception) {
            }
        }

        // @phpstan-ignore if.alwaysTrue
        if ($this->command) {
            $this->command->info('Created game query types for existing eggs');
        }
    }
}
