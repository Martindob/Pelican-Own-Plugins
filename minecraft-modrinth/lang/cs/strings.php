<?php

return [
    'plugin_name' => 'Modrinth',
    'minecraft_mods' => 'Minecraft Módy',
    'minecraft_plugins' => 'Minecraft Pluginy',

    'settings' => [
        'always_use_latest_version' => 'Vždy použít nejnovější verzi',
        'always_use_latest_version_hint' => 'Přeskočí kontrolu kompatibility s verzí Minecraftu při hledání a instalaci modů/pluginů a vždy použije nejnovější dostupnou verzi pro detekovaný loader. Užitečné pro aktualizaci modů/pluginů ještě před povýšením serveru na novější verzi Minecraftu, protože se spoléhá na jejich obvyklou zpětnou kompatibilitu. Kompatibilita loaderu (Paper/Spigot/Fabric/...) se pořád kontroluje.',
        'auto_update_enabled' => 'Povolit automatické aktualizace',
        'auto_update_enabled_hint' => 'Jednou denně automaticky aktualizuje každý nainstalovaný mod/plugin na každém serveru na jeho nejnovější kompatibilní verzi, bez nutnosti ručně kliknout na aktualizaci. Platí bez ohledu na to, jestli je server online. Neúspěšná automatická aktualizace se zaznamená do logu, úspěšná ne.',
        'auto_update_time' => 'Čas automatické aktualizace',
        'auto_update_time_hint' => 'Čas, kdy se spustí automatická aktualizace, v časové zóně tvého účtu (aktuálně :timezone), zachycené při uložení této stránky - ne v časové zóně panelu (často UTC). Použije se jen když je zapnuté "Povolit automatické aktualizace". Užitečné pro naplánování pár minut před vlastním rozvrhem restartu serveru, aby byly aktualizace hotové, než se server zase spustí.',
        'settings_saved' => 'Nastavení uloženo',
    ],

    'page' => [
        'open_folder' => 'Otevřít složku :folder',
        'minecraft_version' => 'Verze Minecraftu',
        'loader' => 'Loader',
        'installed' => 'Nainstalováno :type',
        'unknown' => 'Neznámé',
        'view_all' => 'Vše',
        'view_installed' => 'Nainstalované',
        'mod_unavailable' => 'Tento mod/plugin už není na Modrinthu dostupný',
    ],

    'table' => [
        'columns' => [
            'title' => 'Název',
            'author' => 'Autor',
            'downloads' => 'Stažení',
            'date_modified' => 'Upraveno',
        ],
    ],

    'version' => [
        'type' => 'Typ',
        'downloads' => 'Stažení',
        'published' => 'Publikováno',
        'changelog' => 'Seznam změn',
        'no_file_found' => 'Soubor nenalezen',
    ],

    'actions' => [
        'install_latest' => 'Nainstalovat nejnovější verzi',
        'install' => 'Nainstalovat',
        'installed' => 'Nainstalováno',
        'update' => 'Aktualizovat',
        'uninstall' => 'Odinstalovat',
        'versions' => 'Výběr verze',
    ],

    'modals' => [
        'update_heading' => 'Aktualizovat mod/plugin',
        'update_description' => 'Tímto se verze :old_version nahradí verzí :new_version. Starý soubor bude smazán.',
        'uninstall_heading' => 'Odinstalovat mod/plugin',
        'uninstall_description' => 'Opravdu chcete odinstalovat :name? Tímto se soubor trvale smaže z vašeho serveru.',
    ],

    'notifications' => [
        'install_success' => 'Instalace dokončena',
        'install_success_body' => 'Úspěšně nainstalováno :name verze :version',
        'install_failed' => 'Instalace se nezdařila',
        'install_failed_body' => 'Při instalaci došlo k chybě. Zkuste to prosím znovu nebo kontaktujte podporu, pokud problém přetrvává.',
        'update_success' => 'Aktualizace dokončena',
        'update_success_body' => 'Úspěšně aktualizováno na verzi :version',
        'update_failed' => 'Aktualizace se nezdařila',
        'update_failed_body' => 'Při aktualizaci došlo k chybě. Zkuste to prosím znovu nebo kontaktujte podporu, pokud problém přetrvává.',
        'uninstall_success' => 'Odinstalace dokončena',
        'uninstall_success_body' => 'Úspěšně odinstalováno :name',
        'uninstall_partial' => 'Odinstalace neúplná',
        'uninstall_partial_body' => 'Soubor :name byl smazán, ale nepodařilo se ho odebrat ze seznamu nainstalovaných. Stále se může zobrazovat jako nainstalovaný.',
        'uninstall_failed' => 'Odinstalace se nezdařila',
        'uninstall_failed_body' => 'Při odinstalaci došlo k chybě. Zkuste to prosím znovu nebo kontaktujte podporu, pokud problém přetrvává.',
    ],
];
