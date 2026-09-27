# Paper & Velocity Updater (by Martindob)

Automatically keeps Paper and Velocity servers up to date. A scheduled background check downloads
newer builds from [PaperMC's downloads service](https://docs.papermc.io/misc/downloads-service/)
ahead of time, and the next `start`/`restart` swaps the already-downloaded jar into place - a fast,
local rename, not a fresh multi-megabyte download - right before the power signal reaches Wings.

> Original work by Martindob, first created September 2026. Licensed under GPLv3 (see [LICENSE](LICENSE)), same as the other plugins in this repository.

## Setup

No configuration needed. The plugin recognizes a server as Paper or Velocity from its own
startup variables (the ones the official Paper/Velocity eggs already ship with):

| Project  | Version variable                    | Build variable | Jar file variable |
|----------|--------------------------------------|-----------------|--------------------|
| Paper    | `MINECRAFT_VERSION` (or `MC_VERSION`) | `BUILD_NUMBER`  | `SERVER_JARFILE`   |
| Velocity | `VELOCITY_VERSION`                    | `BUILD_NUMBER`  | `SERVER_JARFILE`   |

A server is only recognized as Paper/Velocity if its egg defines **both** the version variable
and `BUILD_NUMBER` - a version variable alone is not enough, since e.g. Fabric/Forge/Quilt eggs
(handled by the sibling `minecraft-modrinth` plugin, if installed) can define the same
`MINECRAFT_VERSION`/`MC_VERSION` variable without any concept of a PaperMC build number. Any
other server (no matching variables at all) is left completely untouched, with no overhead.
(Checked directly against the official
[Paper](https://github.com/pelican-eggs/minecraft/blob/main/java/paper/egg-paper.yaml) and
[Velocity](https://github.com/pelican-eggs/minecraft/blob/main/proxy/java/velocity/egg-velocity.json)
egg definitions - these are the exact variable names, defaults and egg tags they use.)

If the egg also defines `DL_PATH` (both official eggs do, hidden/non-user-viewable by default)
and it's been set to something, this plugin leaves the server alone entirely. `DL_PATH` tells the
egg's own install script to download from a custom URL instead of resolving anything through
PaperMC - used for mirrors, patched/forked builds, or a private build server - and this plugin has
no way to know what that custom URL should resolve to. Overwriting it with a stock PaperMC jar on
every restart would silently undo that choice.

## How it works

This runs in two independent phases, on purpose: a restart should never have to wait on a
multi-megabyte download, and a once-a-day restart should never miss a build just because it
happened to fire before a slow download finished.

**Phase 1 - staging (background, hourly):**

1. For every server whose egg defines both a version variable and `BUILD_NUMBER` (see Setup
   above), a scheduled command checks whether a newer build is available.
2. If `BUILD_NUMBER` is pinned to a specific number (not `latest`), the server is left alone -
   the admin explicitly chose that build.
3. Otherwise, the plugin resolves the target Minecraft/Velocity version and asks PaperMC for the
   newest `STABLE` build for it. **The version and build are resolved independently**: pinning
   `MINECRAFT_VERSION` to e.g. `26.2` with `BUILD_NUMBER` left at `latest` keeps the server on new
   `26.2` builds forever - it never jumps to `26.3` just because that becomes the newest version.
   A pinned version is a hard lock: if it can't be positively confirmed against PaperMC's own
   version list (typo, or PaperMC/the cache being temporarily unavailable), the update is skipped
   for that cycle rather than falling back to the latest version - a pinned server can only ever
   move to a version you explicitly asked for. Only leaving the variable at `latest` (or empty)
   resolves to the newest version - which is resolved by actual build availability, not by version
   *name*: names alone don't reliably say what's current. Verified live against the Fill API,
   Paper's own `26.3` is a perfectly clean version string with no snapshot/rc/pre suffix, yet every
   one of its builds is currently channel `ALPHA`, while every `26.2` build is `STABLE` - matching
   [papermc.io/downloads/paper](https://papermc.io/downloads/paper)'s own "Latest Stable Version:
   Paper 26.2". Conversely Velocity's `4.2.1-SNAPSHOT` - which *does* look like a pre-release by
   name - has a `STABLE` build and is exactly what
   [papermc.io/downloads/velocity](https://papermc.io/downloads/velocity) itself presents as the
   current download. So `latest` walks versions newest-first and picks the first one that actually
   has a `STABLE` build, checking up to 10 versions before giving up - matching what PaperMC's own
   downloads pages show for both projects, not a naming guess.
4. If that build isn't already installed (tracked in a small `.paper-velocity-updater.json` marker
   file in the server's root) or already staged, it's downloaded straight into the server directory
   as `<jarfile>.pending` - a *new* file, next to but never overwriting the live jar - via the
   daemon's file-pull API, with a generous timeout (see below). This is completely safe to do while
   the server is running, for both Paper and Velocity: the live jar isn't touched at all in this
   phase.

**Phase 2 - swapping (on the next `start`/`restart`):**

5. When a `start` or `restart` power action is sent to a server through the panel's own API - a
   Schedule's "Power Action" task, or the client API - the plugin intercepts it before it reaches
   Wings and checks whether anything is staged for it. **Not** the Console page's own buttons - see
   the note on this under Limitations below.
6. If so, the existing jar is renamed to `<jarfile>.old` (best-effort, mirroring what the official
   install scripts themselves do before installing a new jar - an easy manual recovery path if a
   downloaded jar ever turns out to be bad; only a single rolling `.old` backup is ever kept, so
   this never grows disk usage over time) and the staged `<jarfile>.pending` is renamed into the
   live jar's place. Both are fast, local renames on the daemon - no network call to PaperMC and no
   multi-megabyte transfer happens here, so this adds no meaningful delay to the power action. The
   marker file is updated last, all before the power signal is forwarded, so the server always
   boots on the build that was just swapped in.

This two-phase split is also why Velocity no longer needs its jar swap gated on the server being
confirmed stopped, unlike some other Paper/Velocity auto-updaters. Only a *rename* ever touches the
live jar - never an in-place overwrite - and a rename doesn't affect a process that already has the
old file open (see the note on this in the Limitations section below), so it's safe to do even while
the proxy is still running, right up to the moment the restart actually happens.

## Configuration

Under **Admin → Plugins → Paper & Velocity Updater → Settings** in the panel:

- **Enabled** - turn the whole plugin on/off.
- **Version/build cache (minutes)** - how long a resolved "latest version"/"latest build" lookup
  is cached before PaperMC is asked again (default `15`).
- **Download timeout (seconds)** - overrides the daemon client's normal 15 second timeout
  (`panel.guzzle.timeout`) for the actual jar download/write, which is far too short for a
  ~50-60MB Paper/Velocity jar. Raise it if your nodes have a slow link to PaperMC's CDN
  (default `300`, minimum `60` - enforced even if set directly in `.env` - so it can't be set
  back down to something too short for a real download).
- **Failure log throttle (minutes)** - the same failure (PaperMC or the daemon being
  unreachable, etc.) for a given server is only logged once per this many minutes, no matter how
  many times you restart the server in the meantime (e.g. while still configuring it). Set to `0`
  to log every occurrence (default `30`).

These are stored as environment variables (`PAPER_VELOCITY_UPDATER_ENABLED`,
`PAPER_VELOCITY_UPDATER_CACHE_MINUTES`, `PAPER_VELOCITY_UPDATER_DOWNLOAD_TIMEOUT_SECONDS`,
`PAPER_VELOCITY_UPDATER_REPORT_THROTTLE_MINUTES`) and can be set directly in `.env` instead if
you prefer - the settings page just writes to the same place.

## Checking that it's actually working

The same settings dialog also shows:

- **Run check now** - runs the hourly background check immediately instead of waiting for the
  scheduler, exactly as if the scheduled check had just fired. Useful right after installing the
  plugin, or any time you want to confirm it's still working without waiting up to an hour. Its
  result only shows up in the two fields below once you close and reopen this dialog - a
  Notification confirms it ran, but the dialog itself isn't rebuilt in place.
- **Last check** - when the background check last ran, regardless of what it found. If this says
  "Never run yet" after using "Run check now" above, or it stays more than 2 hours old, the hourly
  scheduler itself isn't running - almost always because `php artisan schedule:run` isn't wired
  into cron on the panel's host (a single line, typically added once when the panel is first set
  up: `* * * * * php /path/to/panel/artisan schedule:run >> /dev/null 2>&1`). This is a normal
  Laravel requirement, not specific to this plugin - if it's missing, every other scheduled task on
  the panel (including e.g. the sibling `minecraft-modrinth` plugin's own daily auto-update, if you
  use it) isn't running either.
- **Recent activity** - the last ~30 events from the last 7 days, newest first, in plain language:
  staged updates and applied swaps (green), failures (red), and *why* a recognized Paper/Velocity
  server didn't get an update staged (gray) - already up to date, `BUILD_NUMBER` pinned to a
  specific number instead of `latest`, `DL_PATH` set, an invalid `SERVER_JARFILE`, or a PaperMC
  lookup that failed. That last category is throttled to once a day per server/reason (a pinned
  build would otherwise repeat every single hourly check forever), so don't expect an entry on
  every "Run check now". An empty feed after using it means no Paper/Velocity servers were found at
  all (double check the egg's startup variables - see Setup above), not that something is broken.

This feed is a lightweight diagnostic aid (kept in cache, not written to any file on a server), not
a replacement for the panel's own log if you need full exception detail - the same events are also
reported there, just throttled per **Failure log throttle (minutes)** above so a persistent problem
doesn't spam it. `php artisan p:paper-velocity-updater:check` on the panel host does exactly what
"Run check now" does, if you'd rather trigger it from the command line.

## Performance

The hourly background check only ever does work for servers whose egg defines both a version
variable and `BUILD_NUMBER` (see Setup above) - every other server is skipped with effectively zero
overhead (a single cheap in-memory check against the server's already-loaded variables, nothing
more). For a Paper/Velocity server itself, the steady state (server already on the target build,
nothing newer available) costs nothing beyond re-checking PaperMC once per **Version/build cache
(minutes)** window (default `15`) - only an actual available update touches the daemon's file API
at all, to write the `.pending` file.

The `start`/`restart` hook itself never touches PaperMC or the network: it only reads the small
marker file and, if something is staged, does two renames on the daemon. This adds no meaningful
delay to a power action regardless of how large the jar is or how slow the connection to PaperMC's
CDN is, since that download already happened, on its own schedule, before the restart ever occurred.

## Limitations

- Firing power actions in quick succession (double-clicking restart, hitting start right after a
  restart, ...) is safe: a second `start`/`restart` that arrives while the first is still applying
  its swap waits briefly (up to 5 seconds) for it to finish rather than racing ahead, so the two
  renames (backup, then swap) can't interleave with another swap on the same server. If that short
  wait isn't enough, this restart's swap is simply skipped (the pending update stays staged and is
  retried on the *next* restart) - it never delays or fails the actual power action over this.
  `stop`/`kill` are untouched by this plugin entirely (it only hooks `start`/`restart`).
- **The Console page's own Start/Restart/Stop/Kill buttons do NOT trigger a swap.** Checked directly
  against the panel's own source: those buttons send the signal over the browser's own WebSocket
  connection straight to Wings (`resources/views/filament/components/server-console.blade.php`,
  `socket.send({'event': 'set state', ...})`) - there is no panel-side PHP code in that path at all
  for this plugin (or any plugin) to hook into. This is a hard protocol limitation Wings itself would
  have to address, not something a Pelican plugin can intercept.
  A restart **does** go through this plugin, and does swap in a staged update, when it's sent through
  a panel API instead of the browser's console - which is exactly how the two ways you'd realistically
  automate a restart already work:
  - A **Schedule** with a "Power Action" task (checked directly against the panel's own
    `TaskServiceProvider`: `PowerActionSchema` is constructed with the same `DaemonServerRepository`
    this plugin decorates) - this is what a scheduled daily restart already uses, so it needs no
    changes to pick up staged updates.
  - The **client API** (`POST /api/client/servers/{id}/power` with an API key) - what an external
    script or the "restart my server" button in some third-party integrations would use.
  To manually verify a swap without waiting for a scheduled restart, use one of these two instead of
  the Console page's buttons - e.g. a one-off Schedule task set to run in a minute, or a client API
  call with `curl`.
- Only `STABLE` channel builds are used for automatic updates. If a pinned version only has
  `BETA`/`ALPHA` builds, the newest available build is used instead.
- A failed *check* (e.g. PaperMC being unreachable, or a pinned version that can't be verified)
  never blocks anything and never logs anything beyond the usual throttled failure entry (see
  above) - the next hourly check just tries again. A pinned version that can't be confirmed is
  treated exactly the same way: staging is silently skipped for that cycle, never substituted with
  a different version.
- A failed *swap* (the daemon being briefly unreachable, the staged file having been deleted
  manually, ...) never blocks or delays the actual start/restart either - the server just starts on
  its current jar, and the update stays staged for the next restart to retry.
- If you change `SERVER_JARFILE` or the project's version variable (or switch on `DL_PATH`) between
  a check staging an update and the next restart, that stale staged update is discarded rather than
  swapped in - the next hourly check stages the right thing for the new configuration instead (or
  nothing, if you opted out via `DL_PATH`).
- This plugin only hooks `start`/`restart` power actions - a reinstall runs the egg's own install
  script as usual, unaffected by this plugin.
- **Only a rename ever touches the live jar - never an in-place overwrite - which is what makes it
  safe to swap in a build for a still-running server, Velocity included.** Wings' file-pull API
  writes a new file's bytes by truncating and overwriting an *existing* filename's same inode
  (checked directly against Wings' own `Filesystem.Write()`), which is why the staged download in
  phase 1 always goes to a new `<jarfile>.pending` name rather than the live jar directly - so it
  can never corrupt whatever the currently-running process still has open. The phase 2 swap itself
  is a plain rename, which only changes which path points at which inode; it has no effect on a
  process that already has the old jar open by file descriptor (Velocity's classloader keeps its
  jar's `ZipFile`/`JarFile` handle open for the process's whole lifetime and can lazily load a class
  from it at any time, but it never re-opens the jar by path while running) - so there's nothing for
  the rename to corrupt, unlike overwriting the same inode in place would risk.
- On `latest`, this plugin can resolve to a different version than a manual **Reinstall** would at
  the same moment. Checked directly against the official install scripts: they resolve "latest
  version" as simply the first entry PaperMC's API returns, with no channel check at all, so if the
  newest version listed currently only has `ALPHA`/`BETA` builds (as Paper's `26.3` does at the time
  of writing), a reinstall would install that. This plugin deliberately does not: since it runs
  automatically and unattended against servers that are already up, it verifies a `STABLE` build
  actually exists first (see above) rather than taking whatever's newest at face value - the same
  standard PaperMC's own downloads pages and downloads-service docs hold themselves to, just not
  (yet) reflected in the install scripts.

> [!NOTE]
> PaperMC's own [downloads service documentation](https://docs.papermc.io/misc/downloads-service/)
> states: *"We emphatically do not recommend using unstable builds or auto-updaters within
> production environments."* This plugin only ever installs `STABLE`-channel builds and resolves
> "latest" using the exact algorithm PaperMC's own docs describe (walk versions newest-first until
> one with a stable build is found), but it *is* an auto-updater running against production
> servers. Use the version lock (pin `MINECRAFT_VERSION`/`VELOCITY_VERSION`) if you want updates
> confined to a version you've already tested, rather than leaving servers on `latest`.
