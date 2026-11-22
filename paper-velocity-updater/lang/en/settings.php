<?php

return [
    'enabled' => 'Enabled',
    'enabled_helper' => 'Automatically stage and install the newest Paper/Velocity build. Checked hourly in the background; applied on the next server start/restart.',
    'cache_minutes' => 'Version/build cache (minutes)',
    'cache_minutes_helper' => 'How long a resolved "latest version"/"latest build" lookup is cached before PaperMC is asked again.',
    'download_timeout' => 'Download timeout (seconds)',
    'download_timeout_helper' => 'How long the hourly background check waits for the daemon to download and stage a new jar. Raise this if your nodes have a slow link to PaperMC\'s CDN. Kept well above the daemon client\'s own 15 second default on purpose - a ~50-60MB jar realistically needs more than that. Never affects a server start/restart itself, which only ever swaps in what was already staged.',
    'report_throttle' => 'Failure log throttle (minutes)',
    'report_throttle_helper' => 'The same failure for a given server is only logged once per this many minutes, no matter how many times the hourly check hits it in the meantime. Set to 0 to log every occurrence.',
    'check_now' => 'Run check now',
    'check_complete' => 'Check complete',
    'check_complete_body' => 'Close and reopen this settings dialog to see the result below.',
    'last_check' => 'Last check',
    'last_check_never' => 'Never run yet. Use "Run check now" above, then reopen this dialog - if it still says this afterwards, the hourly scheduler likely isn\'t running (verify `php artisan schedule:run` is wired into cron on the panel host).',
    'last_check_stale' => ' - this is more than 2 hours ago, which usually means the hourly scheduled check has stopped running (verify `php artisan schedule:run` is wired into cron on the panel host).',
    'recent_activity' => 'Recent activity',
    'no_activity' => 'No activity recorded yet.',
    'saved' => 'Settings saved',
];
