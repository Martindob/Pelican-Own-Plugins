<?php

return [
    'always_use_latest_version' => (bool) env('MINECRAFT_MODRINTH_ALWAYS_USE_LATEST_VERSION', false),
    'auto_update_enabled' => (bool) env('MINECRAFT_MODRINTH_AUTO_UPDATE_ENABLED', false),
    'auto_update_time' => env('MINECRAFT_MODRINTH_AUTO_UPDATE_TIME', '00:00'),
    // Captured from the saving admin's own account timezone, not user-editable directly -
    // see MinecraftModrinthPlugin::saveSettings(). Falls back to config('app.timezone')
    // wherever it's read, rather than here, since 'app' may not be loaded yet at this point.
    'auto_update_timezone' => env('MINECRAFT_MODRINTH_AUTO_UPDATE_TIMEZONE'),
];
