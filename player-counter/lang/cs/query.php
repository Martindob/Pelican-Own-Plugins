<?php

return [
    'no_queries' => 'Žádné herní dotazy',
    'query' => 'Herní dotaz|Herní dotazy',
    'type' => 'Typ',
    'port_offset' => 'Offset portu dotazu',
    'no_offset' => 'Bez offsetu',
    'port_offset_hint' => 'Offset, který bude přičten k portu alokace. Obvykle prázdné/0 nebo 1.',
    'port_variable' => 'Proměnná portu dotazu',
    'no_variable' => 'Bez proměnné',
    'port_variable_hint' => 'Název proměnné prostředí spouštěcí proměnné, která se použije k získání portu dotazu, např. "QUERY_PORT". Pokud je hodnota nastavena, offset portu dotazu bude ignorován! Ponechte prázdné pro použití portu alokace a offsetu.',
    'eggs' => 'Vejce',
    'no_eggs' => 'Žádná vejce',
    'hostname' => 'Název serveru',
    'players' => 'Hráči',
    'map' => 'Mapa',
    'unknown' => 'Neznámé',

    'kick' => 'Kicknout',
    'ban' => 'Zabanovat',

    'whitelisted' => 'Na whitelistu',
    'add_to_whitelist' => 'Přidat na whitelist',
    'remove_from_whitelist' => 'Odebrat z whitelistu',

    'op' => 'OP',
    'add_to_ops' => 'Udělit OP',
    'remove_from_ops' => 'Odebrat OP',

    'use_alias' => 'Použít alias alokace?',
    'use_alias_hint' => 'Pokud je zaškrtnuto, dotazy budou místo IP adresy používat alias alokace',

    'table' => [
        'no_players' => 'Nenalezeni žádní hráči',
        'no_players_description' => 'Buď na serveru nikdo není online, nebo je na něm vypnutý query',
        'server_offline' => 'Server je offline',
    ],

    'notifications' => [
        'settings_saved' => 'Nastavení uloženo',

        'player_kicked' => 'Hráč byl vyhozen ze serveru',
        'player_kick_failed' => 'Hráče se nepodařilo vyhodit',

        'player_banned' => 'Hráč byl na serveru zabanován',
        'player_ban_failed' => 'Hráče se nepodařilo zabanovat',

        'player_whitelist_add' => 'Hráč přidán na whitelist',
        'player_whitelist_remove' => 'Hráč odebrán z whitelistu',
        'player_whitelist_failed' => 'Whitelist se nepodařilo změnit',

        'player_op' => 'Hráči byl udělen OP',
        'player_deop' => 'Hráči byl odebrán OP',
        'player_op_failed' => 'OP se nepodařilo změnit',
    ],
];
