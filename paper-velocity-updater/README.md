# Paper & Velocity Updater (by Martindob)

Automatically keeps Paper and Velocity servers up to date: every time a server is started or
restarted, the plugin checks [PaperMC's downloads service](https://docs.papermc.io/misc/downloads-service/)
for a newer build, downloads it and replaces the server jar *before* the server actually starts.

## Setup

No configuration needed. The plugin recognizes a server as Paper or Velocity from its own
startup variables (the ones the official Paper/Velocity eggs already ship with):

| Project  | Version variable                    | Build variable | Jar file variable |
|----------|--------------------------------------|-----------------|--------------------|
| Paper    | `MINECRAFT_VERSION` (or `MC_VERSION`) | `BUILD_NUMBER`  | `SERVER_JARFILE`   |
| Velocity | `VELOCITY_VERSION`                    | `BUILD_NUMBER`  | `SERVER_JARFILE`   |

A server without any of these variables is left untouched.

## How it works

1. When a `start` or `restart` power action is sent to a server (from the console, the client
   API, or a scheduled task), the plugin intercepts it before it reaches Wings.
2. If `BUILD_NUMBER` is pinned to a specific number (not `latest`), the server is left alone -
   the admin explicitly chose that build.
3. Otherwise, the plugin resolves the target Minecraft/Velocity version (or the newest one, if
   `latest` or invalid) and asks PaperMC for the newest `STABLE` build for it.
4. If that build differs from the one last installed (tracked in a small `.paper-velocity-updater.json`
   marker file in the server's root), the jar is downloaded straight into the server directory
   (via the daemon's file-pull API) and the marker is updated - all before the power signal is
   forwarded, so the server always boots on the version it just downloaded.

## Limitations

- This only runs for power actions sent through the panel (console, client API, scheduled
  tasks). If Wings itself restarts a crashed server without asking the panel, this hook is not
  triggered.
- Only `STABLE` channel builds are used for automatic updates. If a pinned version only has
  `BETA`/`ALPHA` builds, the newest available build is used instead.
- A failed update check (e.g. PaperMC being unreachable) is logged but never blocks the server
  from starting - it just starts on the previously installed jar.
