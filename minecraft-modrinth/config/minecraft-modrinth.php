<?php

return [
    'always_use_latest_version' => (bool) env('MINECRAFT_MODRINTH_ALWAYS_USE_LATEST_VERSION', false),
    'auto_update_enabled' => (bool) env('MINECRAFT_MODRINTH_AUTO_UPDATE_ENABLED', false),
    'auto_update_time' => env('MINECRAFT_MODRINTH_AUTO_UPDATE_TIME', '00:00'),
    // Captured from the saving admin's own account timezone, not user-editable directly -
    // see MinecraftModrinthPlugin::saveSettings(). Falls back to config('app.timezone')
    // wherever it's read, rather than here, since 'app' may not be loaded yet at this point.
    'auto_update_timezone' => env('MINECRAFT_MODRINTH_AUTO_UPDATE_TIMEZONE'),

    // GitHub repository source (pre-built jars listed in an index file of a repository).
    'github' => [
        'enabled' => (bool) env('MINECRAFT_MODRINTH_GITHUB_ENABLED', false),
        'repository' => (string) env('MINECRAFT_MODRINTH_GITHUB_REPOSITORY', ''),
        'branch' => (string) env('MINECRAFT_MODRINTH_GITHUB_BRANCH', 'main'),
        'index_path' => (string) env('MINECRAFT_MODRINTH_GITHUB_INDEX_PATH', 'minecraft/releases.json'),
        // Only ever the Crypt::encryptString() ciphertext of the token (encrypted with APP_KEY),
        // never the token itself - see MinecraftModrinthPlugin::saveSettings().
        'token_encrypted' => (string) env('MINECRAFT_MODRINTH_GITHUB_TOKEN_ENCRYPTED', ''),
        'require_green_ci' => (bool) env('MINECRAFT_MODRINTH_GITHUB_REQUIRE_GREEN_CI', true),
        // Workflow file (e.g. "build.yml") that must have a successful push run on the commit
        // for CI to count as green. Empty: any successful run is enough.
        'required_workflow' => (string) env('MINECRAFT_MODRINTH_GITHUB_REQUIRED_WORKFLOW', ''),
        // The same GitHub failure is only logged once per this many minutes.
        'report_throttle_minutes' => (int) env('MINECRAFT_MODRINTH_GITHUB_REPORT_THROTTLE_MINUTES', 60),
    ],
];
