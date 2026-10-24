# Minecraft Modrinth & GitHub (by Boy132 & H1ghSyst3m, fork maintained by Martindob)

Easily download, update, and manage Minecraft mods and plugins directly from Modrinth within the server panel - and, optionally, plugins from a GitHub repository that ships pre-built jars (see [GitHub repository source](#github-repository-source)) and LuckPerms straight from luckperms.net (see [LuckPerms source](#luckperms-source-luckpermsnet)).

> Originally created by [Boy132](https://github.com/Boy132) & [H1ghSyst3m](https://github.com/H1ghSyst3m) as part of [pelican-dev/plugins](https://github.com/pelican-dev/plugins), licensed under GPLv3 (see [LICENSE](LICENSE)). Modified by Martindob since September 2026 (search/filter fixes, Czech translation, "Always Use Latest Version" setting, automatic daily updates, the GitHub repository source with offers of new plugins, the LuckPerms source, and various fixes — see the commit history for full details).

## Setup

Add `modrinth_mods` and/or `modrinth_plugins` to the _features_ of your egg to enable the mod/plugins page.
Also make sure your egg has the `minecraft` _tag_ and a tag matching a Modrinth loader name. (e.g. `paper` or `neoforge`)

Subuser permissions (the same for the Modrinth pages, the GitHub Plugins page and the LuckPerms page): seeing the page
needs *file.read*, installing *file.create*, updating (or installing another version over an
installed one) *file.create* and *file.delete*, uninstalling *file.delete*. A subuser with only
console access doesn't see the pages at all.

## Installing and updating this plugin

> **Important (Pelican v1.0.0-beta38): after clicking _Update_ for this plugin in *Admin → Plugins*,
> always check its status there.** Pelican beta38's plugin update replaces the files and then fails
> (`PluginService::updatePlugin()` crashes on `$plugin->refresh()`), which leaves the plugin
> **"Not installed"**: it isn't loaded, its database migration doesn't run and its scheduled tasks
> (automatic updates, hourly offer check) stop. Click **Install** (beta38) - or **Enable** on newer
> Pelican versions that leave it disabled - to bring it back. Your settings in `.env` are kept.
>
> This is a bug in Pelican, not something this plugin can work around: a plugin that is
> "Not installed" isn't loaded at all, so none of its code can run to repair itself. The
> plugin's migration is safe to run again (it skips an existing table), and the GitHub pages keep
> working without the table (hiding offers and notifications then stay off until it exists).

## Settings

- **Always Use Latest Version**: skip the Minecraft version compatibility check entirely when searching for and installing mods/plugins, always using the newest available version for the detected loader. Useful for updating mods/plugins ahead of upgrading a server to a newer Minecraft version.
- **Enable Automatic Updates**: once a day, automatically update every installed mod/plugin on every server to its latest compatible version, without needing to click update manually - and plugins installed from the GitHub repository source (see below). A failed automatic update is logged (the same failure at most once a day); a successful one is not.
- **Automatic Update Time**: what time of day that runs, in your own account's time zone (captured when you save the settings page), not the panel's. Handy for scheduling it a few minutes ahead of a server's own restart schedule. Only `HH:MM` is accepted; an unusable value in `.env` falls back to midnight instead of breaking the panel's schedule. The [LuckPerms source](#luckperms-source-luckpermsnet) runs at the same time.
- **LuckPerms (luckperms.net)**: see [LuckPerms source](#luckperms-source-luckpermsnet).

### Modrinth installs

- A file is downloaded by the panel (streamed to a temporary file, at most 256 MB), checked
  against the size and sha512 from Modrinth, written to the server under a temporary name
  (`.modrinth-*.part`) and only then renamed into place. A file with the same name (an update that
  keeps the file name) is set aside first and only deleted once the new one is in place.
  Temporary files left behind by an interrupted install are removed at the next install into
  that folder once they are older than an hour.
- Only plain `.jar` file names are installed or deleted; a folder is never deleted, even if the
  metadata names one.
- The installed list is kept in `mods/.modrinth-metadata.json` / `plugins/.modrinth-metadata.json`.
  If it can't be read (node unreachable, file damaged or larger than 1 MB), **nothing is installed,
  updated or removed** until it can - writing it would replace every entry with a single one. To
  recover a damaged file, fix the JSON by hand, or delete it (the mods/plugins stay installed but
  are no longer listed as installed from Modrinth; install them again from the page to track them).
- **Never a second LuckPerms**: installing LuckPerms from Modrinth (or any jar named like LuckPerms)
  is refused while `plugins/` already has another LuckPerms jar - e.g. one kept up to date from
  luckperms.net by the [LuckPerms source](#luckperms-source-luckpermsnet), or one uploaded by hand.
- **GitHub repository source**: see below.

## Features

- **Browse and Search**: Access Modrinth's extensive mod library with search and pagination
- **Smart Installation**: One-click install with automatic latest version selection
- **Status Tracking**: See which mods are installed directly in the Modrinth list
- **Update Detection**: Automatic detection of available updates with one-click upgrade
- **Easy Uninstall**: Remove mods/plugins with confirmation and automatic file cleanup
- **Metadata Management**: Tracks installed versions, filenames, and installation dates
- **Version Compatibility**: Automatic filtering by Minecraft version and mod loader
- **Seamless Installation**: Downloads to the correct server directory (mods/ or plugins/)
- **Automatic Updates**: Optionally update every installed mod/plugin on every server once a day, without manual interaction (servers that are suspended, installing, being transferred or restored are skipped; one failing server never stops the run for the others)
- **Verified Downloads**: The panel downloads every Modrinth file itself, only from `https://cdn.modrinth.com`, and checks it against the size and sha512 Modrinth publishes before it is written to the server
- **GitHub Repository Source**: Install and update your own pre-built plugins from a (private) GitHub repository, gated on green CI, individually or all pending updates at once; new plugins in the repository are offered (never installed automatically); copies of them uploaded by hand are replaced with the repository version
- **LuckPerms Source**: Keep LuckPerms on Paper/Spigot/Folia/Purpur and Velocity servers on the newest build from luckperms.net (usually days ahead of Modrinth), automatically or with a notification; an existing LuckPerms jar (manual or from Modrinth) is taken over
- **Multilingual**: Supports English, German and Czech translations

## GitHub repository source

Besides Modrinth, plugins can come from a GitHub repository that contains **pre-built jars** and an
**index file** listing them - typically your own plugins, built and committed by your own CI. Paper
and Velocity servers get a **GitHub Plugins** page next to the Modrinth pages, where they can be
installed, updated and removed, and the daily automatic update keeps installed ones up to date.

### Index file

A JSON file in the repository (default `minecraft/releases.json`), for example:

```json
{
  "schema": 1,
  "plugins": [
    {
      "id": "myplugin-paper",
      "name": "MyPlugin",
      "platform": "paper",
      "version": "1.4.0",
      "path": "minecraft/MyPlugin/releases/myplugin-paper-1.4.0.jar",
      "sha256": "<64 hex characters>",
      "size": 123456,
      "frozen": false
    }
  ]
}
```

- `id` - stable identifier, used to recognise an installed plugin across versions.
- `name` - the plugin's name (as in its `plugin.yml` / Velocity `@Plugin`).
- `platform` - `paper` or `velocity`.
- `version` - `x.y.z`; an update is only offered/applied for a *higher* version.
- `path` - the jar inside the repository (a relative path ending in `.jar`); the file name in
  `plugins/` is the last part of it.
- `sha256` and `size` - of the jar; the download is checked against both before anything is written.
- `frozen` - shown as information only.

An index with an unknown `schema`, or that isn't valid JSON, installs nothing (and is logged).
Individual invalid entries are skipped and logged.

### Setup

1. Create a **fine-grained personal access token** on GitHub (*Settings → Developer settings →
   Personal access tokens → Fine-grained tokens*):
   - *Repository access*: **Only select repositories** → just the one repository.
   - *Repository permissions*: **Contents: Read-only** and **Actions: Read-only** (Metadata:
     Read-only is added automatically). Nothing else.
   - Pick an expiry you'll remember to renew; an expired token shows up as "GitHub rejected the
     token" on the page and in the log.
   A public repository works without a token (with GitHub's much lower anonymous rate limit).
2. In the panel: *Admin → Plugins → Minecraft Modrinth & GitHub → Settings → GitHub repository source*:
   enable it, enter the repository (`owner/repository`), the branch (default `main`) and the index
   path (default `minecraft/releases.json`), paste the token and save. Then use **Test connection**.
   - **Require green CI** (on by default): installing and updating is only possible from a commit
     whose GitHub Actions runs all finished successfully.
   - **Required workflow** (optional, empty by default): the file name of a workflow (e.g.
     `build.yml`) that must have a successful `push` run on exactly that commit and branch for CI
     to count as green. Without it, a quick workflow (e.g. a linter) that finishes before the
     build has even started could make CI green too early. **For Ethoria set `build.yml`.**
   - **Replace manual copies** (on by default, `MINECRAFT_MODRINTH_GITHUB_REPLACE_MANUAL`): a
     jar of a repository plugin that was put into `plugins/` by hand is replaced with the
     repository version (see [Manual copies](#manual-copies-replaced-with-the-github-version)).
     Off: such a plugin is only shown as **Installed manually**, as in 1.4.1.
   - Automatic updates use the existing **Enable Automatic Updates** setting and time.
3. The servers need the same egg setup as the Modrinth plugins page (`modrinth_plugins` or
   `plugins` feature, `minecraft` tag) plus a loader tag: `paper` (or `purpur`, `pufferfish`,
   `leaf`) for the `paper` platform, `velocity` for the `velocity` platform. Each server only sees
   the plugins of its own platform.

### How it works

- The panel reads the head commit of the branch, the GitHub Actions runs of that commit
  (`push` event on that branch - the run's `head_sha` and `head_branch` are checked too) and the
  index file at exactly that commit. CI counts as **green** only when every run has finished as
  success/skipped/neutral and at least one succeeded (and, with a *Required workflow*, that one
  succeeded); any failed run makes it **red**, running ones **pending**, and a commit without runs
  stays **not verified** (not installable while green CI is required). Results are cached briefly
  (head commit 5 minutes, CI 1-10 minutes); the page's refresh button checks again - at most once
  per 30 seconds per server and 10 times a minute for the whole panel, so the token's rate limit
  can't be used up by clicking. Installing asks GitHub again under the same limit, otherwise it
  uses the cached state (the CI rule still applies to exactly the commit that is installed).
- **The panel downloads the jar itself** (GitHub contents API), verifies its sha256 and size
  against the index, and only then writes it to the server through the Wings file API. Wings
  never gets a GitHub URL or the token.
- The jar is written under a temporary name and renamed into `plugins/`; an existing file is never
  overwritten in place (if the indexed name is already taken by the plugin's own current jar, a
  name with a hash suffix is used). On update the new jar is written first, then the metadata
  (which records the old jar as *to be deleted*), then the old jar is deleted. If the new jar or
  the metadata can't be written, the new jar is removed again and nothing changes. If only the
  **old jar can't be deleted**, the update stays (the new version is recorded) and the old jar
  stays listed as `pending_delete` in the metadata: it is deleted at the next installation, the
  hourly check or the automatic update, and the page shows a warning. Until then `plugins/` has
  two jars of the plugin - delete the old one by hand before restarting. A jar is never left
  untracked. A pending jar is only deleted if a regular file with the recorded name and size is
  still there. If another jar that looks like the same plugin is already there (another version
  of the same file, or a jar named after the plugin, e.g. a manual install), it is replaced
  while *Replace manual copies* is on and it passes the checks under
  [Manual copies](#manual-copies-replaced-with-the-github-version); otherwise installing is
  refused until you remove it.
- A full disk on the server is reported as such ("not enough disk space"), not as a conflict.
- Temporary uploads (`.github-*.part`) left behind by an interrupted install are removed by the
  hourly check once they are older than an hour.
- **Plugin configuration is never changed**: only the jar in `plugins/` and the metadata file
  `plugins/.github-plugins.json` (id, name, version, sha256, file name, repository, commit,
  install/update time, and `pending_delete` while an old jar waits for deletion) are written.
  Removing a plugin deletes its jar and metadata entry and keeps its folder
  (`plugins/<Plugin>/config.yml`, data, ...).
- **Damaged `plugins/.github-plugins.json`**: if it can't be read or isn't valid (or is larger than
  1 MB), the page says so and nothing is installed, updated or removed on that server - an empty
  list written over it would make every recorded jar untracked. To recover, fix the JSON by hand
  (the format is shown above), or delete the file and then delete the jars it listed from
  `plugins/` and install them again from the page (their configuration folders stay).
- The running server keeps using what it loaded: **a new or updated plugin is used after the next
  server restart**.
- **Update all**: shown on the GitHub Plugins page whenever at least one installed plugin has an
  update available (or a manual copy will be replaced), labelled with the count. Updates every one
  of them (and replaces those manual copies), each independently through
  the same install/update logic a single plugin's own **Update** button uses - one plugin failing
  (a checksum mismatch, a file conflict, GitHub being briefly unreachable, ...) never stops the
  rest. Ends in a single summary notification ("Updated 5 plugins", or "Updated 4 of 6 plugins"
  with what failed and why) instead of one per plugin. Subject to the same green-CI gate and rate
  limit as a single update - and the same permissions (see below).
- **Automatic updates** (daily, at the configured time) only update plugins that are already
  installed, only to a higher version, and only from a commit with green CI - regardless of the
  "Require green CI" setting - and, with *Replace manual copies* on, replace manual copies of
  repository plugins (not of plugins hidden on that server). Nothing new is ever installed
  automatically. Servers in a conflict
  state (suspended, installing, being transferred or restored, node in maintenance) and servers
  their node doesn't know are skipped; an error on one server never stops the run for the others.
- Page access requires the subuser permission *file.read*; installing requires *file.create*,
  updating *file.create* and *file.delete*, removing *file.delete*; hiding an offer ("Doesn't
  belong on this server") and "Offer again" require *file.create*; replacing a manual copy
  ("Replace with GitHub version") *file.create* and *file.delete*.

### New plugins

When the repository gets a plugin that a server doesn't have yet, the panel **offers** it - it is
never installed automatically.

- **What counts as new**: a plugin in the index for the server's platform (`paper` plugins only on
  Paper/Purpur/... servers, `velocity` plugins only on Velocity servers) that is not installed from
  the repository on that server and that nobody has hidden for that server. A server whose
  platform can't be determined (no loader tag, no plugins feature) is offered nothing. A plugin
  whose jar is already in `plugins/` without being installed from here (a manual install, a
  Modrinth copy - recognised like the conflict check above) is not offered as new: it shows as
  **Manual copy - will be replaced** (counted as an update, see
  [Manual copies](#manual-copies-replaced-with-the-github-version)) or, when it can't be
  replaced automatically (or the setting is off), as **Installed manually**.
- **GitHub Plugins page**: new plugins are listed first with the label **New - offered for
  installation** (then available updates), with a note above the table. Next to **Install** there
  is **Doesn't belong on this server**, which hides the offer on that server only. Hidden plugins
  can be shown with **Show hidden (n)** and brought back with **Offer again**.
- **Menu badge**: the *GitHub Plugins* menu item shows how many plugins are offered (new plus
  updates; a manual copy that will be replaced counts as an update) - orange when there are updates, blue when there are only new plugins; the tooltip
  splits the number. The badge only reads a cached count (written by the hourly check below and by
  every visit of the page), so drawing the menu never contacts GitHub or the server's node; while
  there is no count (e.g. right after the plugin update, until the first check) there is no badge.
- **Notifications**: an hourly check (`p:minecraft-modrinth:github-offers`, runs only while the
  GitHub source is enabled) reads the repository state once for all servers, then the metadata
  file and the jar list of `plugins/` of every Paper/Velocity server (two Wings requests per server,
  a third one - Modrinth's metadata file - only when a manual copy of a repository plugin is there;
  servers in a conflict state - suspended, installing, being transferred or restored - and servers
  their node doesn't know are skipped). For every new plugin a server hasn't been
  told about yet, the panel sends a notification (the bell in the panel): *"On server {server},
  the new plugin {name} {version} from the repository is available for installation"*, with a
  button to the GitHub Plugins page. Each plugin is announced **once per server** - not again for
  a newer version, after a hide/offer again, or after it was removed; more than three new plugins
  at once are announced in one notification. With *Require green CI* on, nothing is announced
  while CI of the newest commit isn't green (the next check after it turns green does it).
- **Delivery**: the notifications are written to the panel database directly, not through the
  queue, so they arrive even without a running queue worker.
- **Who is notified**: the server owner and every subuser with *file.create* on that server (the
  people who can install it). Panel admins are not notified for every server they can see - they
  see the badge when they open a server.
- **Where it is stored**: hidden offers and which plugins were announced are kept per server in
  the panel database (table `minecraft_modrinth_github_offers`, created by the plugin's migration
  when the plugin is installed or updated, deleted together with the server). The server's
  `plugins/.github-plugins.json` stays exactly as in 1.2.0. If the table is missing (migration not
  run), offers are still shown and counted, but hiding is not possible and nothing is announced.

### Manual copies (replaced with the GitHub version)

A jar of a repository plugin that was put into `plugins/` by hand (SFTP, file manager, an older
manual install) is **replaced with the repository version** while *Replace manual copies* is on
(the default) - no need to delete it first. Off, the plugin is only shown as **Installed
manually**, as in 1.4.1.

- **Where**: the GitHub Plugins page shows such a plugin as **Manual copy - will be replaced**
  (installed column: the version from the file name, marked "manual"; the tooltip names the
  file), with a note above the table and the button **Replace with GitHub version** (needs
  *file.create* and *file.delete*). **Update all** includes it, and the **daily automatic
  update** (with *Enable Automatic Updates* on) replaces it on every server. The hourly check
  counts it as an update in the menu badge. *Update* of an installed plugin also replaces a manual
  copy that lies next to it. A plain *Install* of a new plugin never deletes anything.
- **Keeping a manual copy**: **Doesn't belong on this server** hides the plugin on that server -
  a hidden plugin is never replaced (it shows as *Installed manually* with *Offer again*).
- **How it is replaced** (the same safe order as an update): the panel downloads and verifies the
  repository jar, checks the manual copy (below), writes the new jar (temporary name, then
  rename), saves the metadata with the new entry **and** the manual copy as `pending_delete`
  (with `replaced_by`), and only then deletes the manual copy. If the new jar or the metadata
  can't be written, the new jar is removed again and the manual copy stays. If only the manual
  copy can't be deleted, it stays recorded as pending and is deleted at the next installation,
  the hourly check or the automatic update (the page warns until then). Two jars of the plugin
  are never left behind untracked, and a manual copy is never deleted before the new jar is
  written and recorded. If the manual copy has exactly the indexed file name, the new jar gets a
  name with a hash suffix (an existing file is never overwritten).
- **What counts as a manual copy** (conservative - a foreign plugin must never be deleted):
  - recognised by file name only against the index entries **for the server's platform**:
    another version of the indexed file name (`EthoriaChat-1.8.0.jar`) or a jar named after the
    plugin (`EthoriaChat.jar`, `EthoriaChat-1.8.jar`);
  - only plain jar files directly in `plugins/` - sub folders are never looked at, symlinks and
    folders named `*.jar` are never touched, nor are jars with unusual names;
  - never a jar that matches **more than one** index entry, never a jar the **Modrinth page
    manages** (listed in `plugins/.modrinth-metadata.json`; nothing is replaced while that file
    can't be read), and not more than 3 copies of one plugin at once;
  - **checked by the plugin name inside**: before deleting it, the panel reads the manual copy
    from the node and compares the `name` of its `plugin.yml` / `paper-plugin.yml` (Paper) or the
    `id` of its `velocity-plugin.json` (Velocity) with the repository jar. Wings can't read a
    single file inside a jar, so the **whole jar is downloaded** to the panel for that - but only
    when it is about to be replaced (not for page views or the hourly check), at most 50 MB, and
    the result is remembered for a day per file name, size and modification time. A copy that
    declares another plugin, has no descriptor, isn't a jar or is larger than 50 MB is **kept**,
    the replacement is refused with an explanation and the copy is no longer offered for
    replacement (for a day, or until the file changes). If the node can't be read right now,
    nothing happens and it is tried again later.
- **Logged**: every deleted manual copy is written to the server's **activity log** ("Deleted
  /plugins/<file>", with `replaced_by`, by the user who clicked or by the system for the automatic
  update) and as one line to the panel log (`minecraft-modrinth: GitHub: deleted the manual copy
  plugins/<file> on server #<id>, replaced with <plugin> <version> from the repository`). A
  refused replacement is logged as an error (the same one at most once per hour).
- The plugin's configuration folder (`plugins/<Plugin>/`) is never touched; the new version is
  used after the next server restart.

### Security

- The token is stored **encrypted** with the panel's `APP_KEY` (`Crypt::encryptString`); `.env`
  only ever contains the ciphertext (`MINECRAFT_MODRINTH_GITHUB_TOKEN_ENCRYPTED`). The settings
  form never shows it (leave the field empty to keep it, or use *Delete the saved token*). If
  `APP_KEY` changes, save the token again.
- The token is only sent to `https://api.github.com`. Redirects to GitHub's content hosts
  (`*.githubusercontent.com`) are followed without it; redirects anywhere else are refused.
- The token is never logged: failures are reported as short messages without request details, at
  most once per hour per problem.
- Everything from the repository is validated: repository/branch/index path in the settings, the
  index (paths must be relative, without `.`/`..`, ending in `.jar`; versions `x.y.z`; sha256 64 hex
  characters), and every jar's sha256 and size. Requests to GitHub time out after 3 s (connect) /
  15 s (120 s for a jar download); jars are limited to **50 MB** (index entries with a larger
  `size` are skipped): the panel holds a jar in PHP memory while it checks and writes it.
- Names, versions and server names in notifications are escaped (Filament renders notification
  text as HTML).
- A fine-grained token limited to one repository with read-only Contents/Actions can't change
  anything on GitHub.

### Limitations

- A manually installed jar is recognised only by its file name (another version of the indexed
  file name, or a jar named after the plugin). A manual copy with an unrelated file name is not
  recognised (finding it would mean downloading every jar in `plugins/` from the node); the
  `name` inside a recognised copy is only read right before it is replaced.
- The panel keeps a jar in memory while installing it, hence the 50 MB limit.

## LuckPerms source (luckperms.net)

New LuckPerms builds appear on [luckperms.net](https://luckperms.net/download) right away, on
Modrinth often only much later. With this source the panel keeps LuckPerms up to date straight from
luckperms.net, on **Paper** (and Spigot, Bukkit, Folia, Purpur, Pufferfish, Leaf - the *Bukkit*
build of LuckPerms) and **Velocity** servers (the *Velocity* build).

### Setup

1. *Admin → Plugins → Minecraft Modrinth & GitHub → Settings → LuckPerms (luckperms.net)*:
   - **Enable LuckPerms source** (off by default).
   - **When a newer LuckPerms is out**: **Update automatically** (default) or **Only notify (bell)**.
   - **Check luckperms.net** reads the newest version right away (also with the source still off).
2. The servers need the same egg setup as the Modrinth plugins page (`modrinth_plugins` or `plugins`
   feature, `minecraft` tag) plus a loader tag: `paper`, `spigot`, `bukkit`, `folia`, `purpur`,
   `pufferfish` or `leaf` → Bukkit build; `velocity` → Velocity build (wins if both are present).
   Other servers (Fabric, Forge, BungeeCord, ...) get nothing.

### LuckPerms page

Every such server gets a **LuckPerms** page (*file.read*): the installed version and jar, the newest
version on luckperms.net, the state (*Up to date*, *Update available*, *Not installed*, *Newer than
luckperms.net*, *Needs attention*) and the latest changes from luckperms.net (each linked to its
commit on GitHub). Actions: **Install** (*file.create*; only when there is no LuckPerms at all),
**Update** (*file.create* + *file.delete*), **Remove** (*file.delete*; the jar only -
`plugins/LuckPerms/` with the configuration and data stays), **Check luckperms.net again** (at most
every 10 seconds per server) and **Open plugins folder**. A new or updated LuckPerms is used after
the **next server restart**.

### Which jar is LuckPerms (taking over an existing install)

There is no metadata file of its own: whatever LuckPerms jar is in `plugins/` is LuckPerms on that
server, however it got there.

- A jar named like a LuckPerms build (`LuckPerms-Bukkit-<x.y.z>.jar`, `LuckPerms-Velocity-<x.y.z>.jar`,
  in any letter case - this is also the name Modrinth uses) is recognised by its name.
- Any other `.jar` with `luckperms` in its name (`LuckPerms.jar`, `luckperms-5.4.jar`,
  `LuckPerms-Bukkit-Legacy-...jar`, add-ons like `LuckPermsChat.jar`, ...) is read from the node
  once (at most 30 MB; the result is remembered for a day per name/size/time) and recognised by its
  `plugin.yml` (`name: LuckPerms`) or `velocity-plugin.json` (`"id": "luckperms"`) and the version
  declared there. Add-ons are not LuckPerms and are left alone.
- **Taking over**: an update replaces that jar, whether it was uploaded by hand or installed from
  Modrinth: the new jar `LuckPerms-<Platform>-<version>.jar` is written first (temporary name
  `.luckperms-*.part`, then renamed; never over an existing file), then - if the old jar is listed
  in `plugins/.modrinth-metadata.json` - its **Modrinth entry is removed** (so the Modrinth page
  neither manages nor updates it any more), and only then is the old jar deleted. If removing the
  Modrinth entry or deleting the old jar fails, the new jar is deleted again (and the Modrinth entry
  restored): **there are never two LuckPerms jars** because of this plugin. While the source is in
  *Only notify* mode, a Modrinth install of LuckPerms stays managed by Modrinth until someone clicks
  **Update** on the LuckPerms page.
- **Needs attention** - nothing is installed or updated (also not automatically) until it is fixed
  by hand: more than one LuckPerms jar in `plugins/`, a LuckPerms jar for the other platform
  (Velocity build on a Paper server or vice versa), or a jar with `luckperms` in its name that
  couldn't be read right now. The page lists the files. Nothing is changed either while
  `plugins/.modrinth-metadata.json` can't be read (it would be unknown whether Modrinth manages the
  jar); the page shows a warning then.
- A newer version than the one on luckperms.net (e.g. a dev build) is never "updated" down.
- The Modrinth pages refuse to install LuckPerms (or any jar named like LuckPerms) next to another
  LuckPerms jar, so Modrinth can't put a second copy back.

### Automatic updates

- Part of the daily run `p:minecraft-modrinth:auto-update`, at the same **Automatic Update Time** -
  also when *Enable Automatic Updates* (Modrinth/GitHub) is off: the LuckPerms source has its own
  switch. LuckPerms is handled before Modrinth on each server.
- luckperms.net is asked once per run; each jar is downloaded and checked once and then written to
  every server that needs it.
- Only servers that **already have LuckPerms** (exactly one LuckPerms jar of their platform) and only
  to a **higher** version (x.y.z compared numerically). LuckPerms is never installed automatically.
  Servers in a conflict state (suspended, installing, being transferred or restored) and servers
  their node doesn't know are skipped; one failing server never stops the run.
- **Update automatically**: the jar is replaced as described above; the new version is used after
  the next restart (schedule the update time a few minutes before the servers' own restart).
- **Only notify (bell)**: nothing is changed; the server owner and every subuser with *file.create* +
  *file.delete* get one panel notification per server and version (*"On server X, LuckPerms 5.5.71
  can be updated to 5.5.87"*) with a button to the LuckPerms page. Sent directly, not through the
  queue.

### Security

luckperms.net publishes no checksum, so instead:

- Only `https://metadata.luckperms.net/data/all` is read (at most 256 KB, cached for 10 minutes) and
  jars are only downloaded from **`https://download.luckperms.net`** - host exactly that, no
  credentials/port/query, **no redirects followed**, and the path must be exactly the one of that
  platform and version (`/<build>/bukkit/loader/LuckPerms-Bukkit-<version>.jar`,
  `/<build>/velocity/LuckPerms-Velocity-<version>.jar`). The version must be `x.y.z`.
- The panel downloads the jar itself into a temporary file (at most **30 MB**; connect timeout 5 s,
  60 s in total), checks that it is a valid jar (zip) whose `plugin.yml` says `name: LuckPerms` resp.
  whose `velocity-plugin.json` says `"id": "luckperms"`, **with exactly the version from the
  metadata** and the right platform, and only then writes it to the server through Wings. Wings never
  gets a URL.
- Only the LuckPerms jar in `plugins/` and (when taking over) its entry in
  `plugins/.modrinth-metadata.json` are ever changed; `plugins/LuckPerms/` never.
- If luckperms.net is down, the page shows it and nothing waits for it: after a failed request the
  panel doesn't ask again for 1 minute (doubling up to 10 minutes); the daily run asks once. Failures
  are logged as a single line (`minecraft-modrinth: LuckPerms: ...`), the same problem at most once a
  day; the first success afterwards logs one "works again" line (info).
- Changelog titles are shown as plain text; names and versions in notifications are escaped.

### Limitations

- A LuckPerms jar whose file name doesn't contain `luckperms` at all is not recognised (that would
  mean downloading every jar in `plugins/` from the node). Rename it, or remove it and install
  LuckPerms from the page.
- The authenticity of the jar rests on HTTPS to `download.luckperms.net` (there is no published
  checksum or signature to compare against).
- BungeeCord/Waterfall, Fabric, Forge, NeoForge, Sponge and Nukkit builds are not offered.

## Changes

- **1.5.0** - Manual copies are replaced with the GitHub version: a jar of a repository plugin
  that was uploaded to `plugins/` by hand no longer blocks it ("remove it first"). New setting
  *Replace manual copies* (on by default): the GitHub Plugins page shows it as "Manual copy - will
  be replaced" with **Replace with GitHub version**, "Update all" and the daily automatic update
  replace it, the badge counts it as an update; the new jar is written and recorded first, the
  manual copy is deleted only then (or later, if deleting fails) and logged in the server's
  activity log. Only a jar recognised by name for exactly one plugin of the server's platform,
  directly in `plugins/`, not managed by the Modrinth page, and declaring the same plugin name
  in its `plugin.yml` / `velocity-plugin.json` is replaced; plugins hidden on a server are left
  alone. Off: as in 1.4.1.
- **1.4.1** - Fix: saving the settings failed with "Enter the time as HH:MM" even for a valid time
  (e.g. 15:55). Filament's time picker keeps the picked value as "YYYY-MM-DD HH:mm:ss", which the
  check only accepted as "HH:MM"; the date part and seconds are now dropped and only HH:MM is stored.
- **1.4.0** - New **LuckPerms source (luckperms.net)**: a LuckPerms page on Paper/Spigot/Folia/Purpur
  (Bukkit build) and Velocity servers with the installed and newest version, the latest changes and
  Install / Update / Remove; an existing LuckPerms jar (uploaded by hand or from Modrinth) is
  recognised and taken over (its Modrinth entry is removed), never leaving two LuckPerms jars;
  daily automatic update at the existing update time, or only a notification in the bell (setting);
  downloads only from `download.luckperms.net`, without redirects, checked as a jar of exactly that
  platform and version (no checksum is published). The Modrinth pages no longer install a second
  LuckPerms next to an existing one.
- **1.3.1** - Security and reliability fixes from an audit: the Modrinth pages now check subuser
  permissions like the GitHub page (seeing *file.read*, installing *file.create*, updating also
  *file.delete*, uninstalling *file.delete*); Modrinth files are downloaded by the panel only from
  `cdn.modrinth.com` and checked against their sha512; unreadable or damaged metadata is never
  overwritten; only `.jar` files are ever deleted, never folders; an old jar that can't be deleted
  after a GitHub update is recorded and deleted later instead of leaving two untracked jars; a
  broken server no longer stops the daily update or the hourly check for everyone; metadata files
  are read with a 1 MB limit; full disks and unknown servers are recognised; names in
  notifications are escaped and the loader icon is static; new *Required workflow* setting;
  GitHub refreshes are rate limited; scheduled tasks can no longer block each other forever.
