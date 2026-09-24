<?php

return [
    'enabled' => (bool) env('PAPER_VELOCITY_UPDATER_ENABLED', true),

    // How long resolved "latest version"/"latest build" lookups are cached, in minutes.
    'cache_minutes' => (int) env('PAPER_VELOCITY_UPDATER_CACHE_MINUTES', 15),

    // Request headers require a contact per https://docs.papermc.io/misc/downloads-service/.
    'user_agent' => env('PAPER_VELOCITY_UPDATER_USER_AGENT', 'pelican-paper-velocity-updater-plugin (+https://github.com/Martindob/Pelican-Own-Plugins)'),
];
