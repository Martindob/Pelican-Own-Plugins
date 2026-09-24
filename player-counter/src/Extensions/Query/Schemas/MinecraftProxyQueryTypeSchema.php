<?php

namespace Boy132\PlayerCounter\Extensions\Query\Schemas;

use App\Models\Server;

class MinecraftProxyQueryTypeSchema extends MinecraftJavaQueryTypeSchema
{
    public function getId(): string
    {
        return 'minecraft_proxy';
    }

    public function getName(): string
    {
        return 'Minecraft (Proxy)';
    }

    /** @return ?array{hostname: string, map: string, current_players: int, max_players: int, players: array<array{id: string, name: string}>} */
    public function process(Server $server, string $ip, int $port): ?array
    {
        $ping = $this->tryPing($ip, $port);
        if ($ping) {
            return $ping;
        }

        return null;
    }
}
