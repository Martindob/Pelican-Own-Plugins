<?php

return [
    'plugin_name' => 'Modrinth',
    'minecraft_mods' => 'Minecraft Mods',
    'minecraft_plugins' => 'Minecraft Plugins',

    'settings' => [
        'always_use_latest_version' => 'Always Use Latest Version',
        'always_use_latest_version_hint' => 'Skip the Minecraft version compatibility check when searching for and installing mods/plugins, always using the newest available version for the detected loader. Useful for updating mods/plugins ahead of upgrading the server to a newer Minecraft version, relying on their usual backwards compatibility. Loader compatibility (Paper/Spigot/Fabric/...) is still enforced.',
        'auto_update_enabled' => 'Enable Automatic Updates',
        'auto_update_enabled_hint' => 'Once a day, automatically update every installed mod/plugin on every server to its latest compatible version, without needing to click update manually. Applies regardless of whether the server is online. A failed automatic update is logged for you to look into; a successful one is not.',
        'auto_update_time' => 'Automatic Update Time',
        'auto_update_time_hint' => 'The time of day automatic updates run, in your account\'s own time zone (currently :timezone), captured when you save this page - not the panel\'s time zone (often UTC). Only used while "Enable Automatic Updates" is on. Useful to schedule it a few minutes before a server\'s own restart schedule, so updates are already in place by the time it comes back up.',
        'settings_saved' => 'Settings saved',
    ],

    'page' => [
        'open_folder' => 'Open :folder folder',
        'minecraft_version' => 'Minecraft Version',
        'loader' => 'Loader',
        'installed' => 'Installed :type',
        'unknown' => 'Unknown',
        'view_all' => 'All',
        'view_installed' => 'Installed',
        'mod_unavailable' => 'This mod/plugin is no longer available on Modrinth',
    ],

    'table' => [
        'columns' => [
            'title' => 'Title',
            'author' => 'Author',
            'downloads' => 'Downloads',
            'date_modified' => 'Modified',
        ],
    ],

    'version' => [
        'type' => 'Type',
        'downloads' => 'Downloads',
        'published' => 'Published',
        'changelog' => 'Changelog',
        'no_file_found' => 'No file found',
    ],

    'actions' => [
        'install_latest' => 'Install latest version',
        'install' => 'Install',
        'installed' => 'Installed',
        'update' => 'Update',
        'uninstall' => 'Uninstall',
        'versions' => 'Version Selection',
    ],

    'modals' => [
        'update_heading' => 'Update Mod/Plugin',
        'update_description' => 'This will replace version :old_version with version :new_version. The old file will be deleted.',
        'uninstall_heading' => 'Uninstall Mod/Plugin',
        'uninstall_description' => 'Are you sure you want to uninstall :name? This will permanently delete the file from your server.',
    ],

    'notifications' => [
        'install_success' => 'Installation completed',
        'install_success_body' => 'Successfully installed :name version :version',
        'install_failed' => 'Installation failed',
        'install_failed_body' => 'An error occurred during installation. Please try again or contact support if the issue persists.',
        'update_success' => 'Update completed',
        'update_success_body' => 'Successfully updated to version :version',
        'update_failed' => 'Update failed',
        'update_failed_body' => 'An error occurred during the update. Please try again or contact support if the issue persists.',
        'uninstall_success' => 'Uninstall completed',
        'uninstall_success_body' => 'Successfully uninstalled :name',
        'uninstall_partial' => 'Uninstall incomplete',
        'uninstall_partial_body' => 'The file for :name was deleted, but it could not be removed from the installed list. It may still appear as installed.',
        'uninstall_failed' => 'Uninstall failed',
        'uninstall_failed_body' => 'An error occurred during uninstallation. Please try again or contact support if the issue persists.',
    ],
];
