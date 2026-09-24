# Minecraft Modrinth (by Boy132 & H1ghSyst3m, fork maintained by Martindob)

Easily download, update, and manage Minecraft mods and plugins directly from Modrinth within the server panel.

> Originally created by [Boy132](https://github.com/Boy132) & [H1ghSyst3m](https://github.com/H1ghSyst3m) as part of [pelican-dev/plugins](https://github.com/pelican-dev/plugins), licensed under GPLv3 (see [LICENSE](LICENSE)). Modified by Martindob since September 2026 (search/filter fixes, Czech translation, "Always Use Latest Version" setting, automatic daily updates, and various fixes — see the commit history for full details).

## Setup

Add `modrinth_mods` and/or `modrinth_plugins` to the _features_ of your egg to enable the mod/plugins page.
Also make sure your egg has the `minecraft` _tag_ and a tag matching a Modrinth loader name. (e.g. `paper` or `neoforge`)

## Settings

- **Always Use Latest Version**: skip the Minecraft version compatibility check entirely when searching for and installing mods/plugins, always using the newest available version for the detected loader. Useful for updating mods/plugins ahead of upgrading a server to a newer Minecraft version.
- **Enable Automatic Updates**: once a day, automatically update every installed mod/plugin on every server to its latest compatible version, without needing to click update manually. A failed automatic update is logged; a successful one is not.
- **Automatic Update Time**: what time of day that runs, in your own account's time zone (captured when you save the settings page), not the panel's. Handy for scheduling it a few minutes ahead of a server's own restart schedule.

## Features

- **Browse and Search**: Access Modrinth's extensive mod library with search and pagination
- **Smart Installation**: One-click install with automatic latest version selection
- **Status Tracking**: See which mods are installed directly in the Modrinth list
- **Update Detection**: Automatic detection of available updates with one-click upgrade
- **Easy Uninstall**: Remove mods/plugins with confirmation and automatic file cleanup
- **Metadata Management**: Tracks installed versions, filenames, and installation dates
- **Version Compatibility**: Automatic filtering by Minecraft version and mod loader
- **Seamless Installation**: Downloads to the correct server directory (mods/ or plugins/)
- **Automatic Updates**: Optionally update every installed mod/plugin on every server once a day, without manual interaction
- **Multilingual**: Supports English, German and Czech translations
