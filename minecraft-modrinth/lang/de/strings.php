<?php

return [
    'plugin_name' => 'Modrinth',
    'minecraft_mods' => 'Minecraft Mods',
    'minecraft_plugins' => 'Minecraft Plugins',

    'settings' => [
        'always_use_latest_version' => 'Immer neueste Version verwenden',
        'always_use_latest_version_hint' => 'Überspringt die Prüfung der Minecraft-Versionskompatibilität bei der Suche und Installation von Mods/Plugins und verwendet immer die neueste verfügbare Version für den erkannten Loader. Nützlich, um Mods/Plugins schon vor einem Upgrade des Servers auf eine neuere Minecraft-Version zu aktualisieren, basierend auf deren üblicher Abwärtskompatibilität. Die Loader-Kompatibilität (Paper/Spigot/Fabric/...) wird weiterhin geprüft.',
        'auto_update_enabled' => 'Automatische Updates aktivieren',
        'auto_update_enabled_hint' => 'Aktualisiert einmal täglich automatisch jedes installierte Mod/Plugin auf jedem Server auf die neueste kompatible Version, ohne manuell auf Aktualisieren klicken zu müssen. Gilt unabhängig davon, ob der Server online ist. Ein fehlgeschlagenes automatisches Update wird protokolliert, ein erfolgreiches nicht.',
        'auto_update_time' => 'Uhrzeit für automatische Updates',
        'auto_update_time_hint' => 'Die Uhrzeit, zu der automatische Updates ausgeführt werden, in der Zeitzone deines eigenen Kontos (aktuell :timezone), erfasst beim Speichern dieser Seite - nicht in der Zeitzone des Panels (oft UTC). Wird nur verwendet, wenn "Automatische Updates aktivieren" eingeschaltet ist. Nützlich, um sie ein paar Minuten vor dem eigenen Neustart-Zeitplan eines Servers einzuplanen, damit die Updates schon vorhanden sind, wenn er wieder hochfährt.',
        'settings_saved' => 'Einstellungen gespeichert',
    ],

    'page' => [
        'open_folder' => ':folder-Ordner öffnen',
        'minecraft_version' => 'Minecraft-Version',
        'loader' => 'Loader',
        'installed' => 'Installiert :type',
        'unknown' => 'Unbekannt',
        'view_all' => 'Alle',
        'view_installed' => 'Installiert',
        'mod_unavailable' => 'Dieser Mod/Plugin ist auf Modrinth nicht mehr verfügbar',
    ],

    'table' => [
        'columns' => [
            'title' => 'Titel',
            'author' => 'Autor',
            'downloads' => 'Downloads',
            'date_modified' => 'Geändert',
        ],
    ],

    'version' => [
        'type' => 'Typ',
        'downloads' => 'Downloads',
        'published' => 'Veröffentlicht',
        'changelog' => 'Änderungsprotokoll',
        'no_file_found' => 'Keine Datei gefunden',
    ],

    'actions' => [
        'install_latest' => 'Neueste Version installieren',
        'install' => 'Installieren',
        'installed' => 'Installiert',
        'update' => 'Aktualisieren',
        'uninstall' => 'Deinstallieren',
        'versions' => 'Versionsauswahl',
    ],

    'modals' => [
        'update_heading' => 'Mod/Plugin aktualisieren',
        'update_description' => 'Dies ersetzt Version :old_version durch Version :new_version. Die alte Datei wird gelöscht.',
        'uninstall_heading' => 'Mod/Plugin deinstallieren',
        'uninstall_description' => 'Möchtest du :name wirklich deinstallieren? Dies wird die Datei dauerhaft von deinem Server löschen.',
    ],

    'notifications' => [
        'install_success' => 'Installation abgeschlossen',
        'install_success_body' => ':name Version :version erfolgreich installiert',
        'install_failed' => 'Installation fehlgeschlagen',
        'install_failed_body' => 'Bei der Installation ist ein Fehler aufgetreten. Bitte versuche es erneut oder wende dich an den Support, wenn das Problem weiterhin besteht.',
        'update_success' => 'Aktualisierung abgeschlossen',
        'update_success_body' => 'Erfolgreich auf Version :version aktualisiert',
        'update_failed' => 'Aktualisierung fehlgeschlagen',
        'update_failed_body' => 'Bei der Aktualisierung ist ein Fehler aufgetreten. Bitte versuche es erneut oder wende dich an den Support, wenn das Problem weiterhin besteht.',
        'uninstall_success' => 'Deinstallation abgeschlossen',
        'uninstall_success_body' => ':name erfolgreich deinstalliert',
        'uninstall_partial' => 'Deinstallation unvollständig',
        'uninstall_partial_body' => 'Die Datei von :name wurde gelöscht, konnte aber nicht aus der Liste der installierten Mods/Plugins entfernt werden. Sie wird eventuell weiterhin als installiert angezeigt.',
        'uninstall_failed' => 'Deinstallation fehlgeschlagen',
        'uninstall_failed_body' => 'Bei der Deinstallation ist ein Fehler aufgetreten. Bitte versuche es erneut oder wende dich an den Support, wenn das Problem weiterhin besteht.',
    ],
];
