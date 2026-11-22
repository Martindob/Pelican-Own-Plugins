<?php

return [
    'staged' => 'Staged :project build :build (:version) for server #:server (:name) - applied on its next start/restart.',
    'updated' => 'Updated server #:server (:name) to :project build :build (:version).',
    'up_to_date' => 'Server #:server (:name): already up to date on :project build :build (:version) - nothing to stage.',
    'check_failed' => 'Check failed for server #:server (:name): :error',
    'swap_failed' => 'Swap failed for server #:server (:name): :error',
    'no_downgrade_installed' => 'Server #:server (:name): the target is :project :version build :build, but :installed_version build :installed_build is already installed - not downgrading automatically. Either set the version variable to :installed_version, or restore a world backup together with :jar.old (the jar from before the last update) and delete :state_file.',
    'no_downgrade_running' => 'Server #:server (:name): the target is paper :version, but the server last ran Minecraft :running - not downgrading automatically. Either set the version variable to :running, or restore a world backup that was made on :version together with a :version jar.',
    'staged_mismatch' => 'Server #:server (:name): not applying the staged :project build :build (:version) - it no longer matches this server\'s BUILD_NUMBER/version settings.',
    'staged_incomplete' => 'Server #:server (:name): the staged :file is :size bytes instead of :expected - not swapping it in; it will be downloaded again.',
    'build_pinned' => 'Server #:server (:name): BUILD_NUMBER is set to ":value", not "latest" - leave it at "latest" if you want this server to auto-update.',
    'dl_path' => 'Server #:server (:name): DL_PATH is set - this server has opted out of PaperMC-based updates.',
    'invalid_jarfile' => 'Server #:server (:name): SERVER_JARFILE (":value") isn\'t a plain .jar filename - refusing to touch it.',
    'latest_unresolved' => 'Server #:server (:name): could not resolve a "latest" stable build right now (PaperMC unreachable, or no stable build found in the newest :count versions) - will retry on the next check.',
    'pinned_unknown' => 'Server #:server (:name): pinned version ":version" doesn\'t match any version PaperMC lists for :project - check for a typo.',
    'pinned_no_build' => 'Server #:server (:name): no build found for pinned version ":version".',
    'no_download' => 'Server #:server (:name): PaperMC\'s build :build for :project :version has no downloadable file.',
    'bad_download_url' => 'Server #:server (:name): the resolved download URL failed validation (host ":host", scheme ":scheme") - refusing it for safety.',
];
