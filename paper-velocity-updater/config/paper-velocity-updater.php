<?php

return [
    'enabled' => (bool) env('PAPER_VELOCITY_UPDATER_ENABLED', true),

    // How long resolved "latest version"/"latest build" lookups are cached, in minutes.
    'cache_minutes' => (int) env('PAPER_VELOCITY_UPDATER_CACHE_MINUTES', 15),

    // The same failure (e.g. PaperMC or the daemon being unreachable) for a given
    // server is only logged once per this many minutes, no matter how many times
    // that server is restarted in the meantime. Set to 0 to log every occurrence.
    'report_throttle_minutes' => (int) env('PAPER_VELOCITY_UPDATER_REPORT_THROTTLE_MINUTES', 30),

    // How long to wait for the daemon to download and write the jar file, in
    // seconds. This overrides the daemon client's normal 15 second default
    // (config('panel.guzzle.timeout')), which is far too short for a ~50-60MB
    // Paper/Velocity jar on anything but a very fast connection.
    'download_timeout_seconds' => (int) env('PAPER_VELOCITY_UPDATER_DOWNLOAD_TIMEOUT_SECONDS', 300),

    // Request headers require a contact per https://docs.papermc.io/misc/downloads-service/.
    'user_agent' => env('PAPER_VELOCITY_UPDATER_USER_AGENT', 'pelican-paper-velocity-updater-plugin (+https://github.com/Martindob/Pelican-Own-Plugins)'),
];
