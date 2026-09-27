# Minecraft Modrinth (by Boy132 & H1ghSyst3m, fork maintained by Martindob)

Easily download, update, and manage Minecraft mods and plugins directly from Modrinth within the server panel - and, optionally, plugins from a GitHub repository that ships pre-built jars (see [GitHub repository source](#github-repository-source)).

> Originally created by [Boy132](https://github.com/Boy132) & [H1ghSyst3m](https://github.com/H1ghSyst3m) as part of [pelican-dev/plugins](https://github.com/pelican-dev/plugins), licensed under GPLv3 (see [LICENSE](LICENSE)). Modified by Martindob since September 2026 (search/filter fixes, Czech translation, "Always Use Latest Version" setting, automatic daily updates, the GitHub repository source with offers of new plugins, and various fixes — see the commit history for full details).

## Setup

Add `modrinth_mods` and/or `modrinth_plugins` to the _features_ of your egg to enable the mod/plugins page.
Also make sure your egg has the `minecraft` _tag_ and a tag matching a Modrinth loader name. (e.g. `paper` or `neoforge`)

## Settings

- **Always Use Latest Version**: skip the Minecraft version compatibility check entirely when searching for and installing mods/plugins, always using the newest available version for the detected loader. Useful for updating mods/plugins ahead of upgrading a server to a newer Minecraft version.
- **Enable Automatic Updates**: once a day, automatically update every installed mod/plugin on every server to its latest compatible version, without needing to click update manually. A failed automatic update is logged; a successful one is not.
- **Automatic Update Time**: what time of day that runs, in your own account's time zone (captured when you save the settings page), not the panel's. Handy for scheduling it a few minutes ahead of a server's own restart schedule.
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
- **Automatic Updates**: Optionally update every installed mod/plugin on every server once a day, without manual interaction
- **GitHub Repository Source**: Install and update your own pre-built plugins from a (private) GitHub repository, gated on green CI; new plugins in the repository are offered (never installed automatically)
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
2. In the panel: *Admin → Plugins → Minecraft Modrinth → Settings → GitHub repository source*:
   enable it, enter the repository (`owner/repository`), the branch (default `main`) and the index
   path (default `minecraft/releases.json`), paste the token and save. Then use **Test connection**.
   - **Require green CI** (on by default): installing and updating is only possible from a commit
     whose GitHub Actions runs all finished successfully.
   - Automatic updates use the existing **Enable Automatic Updates** setting and time.
3. The servers need the same egg setup as the Modrinth plugins page (`modrinth_plugins` or
   `plugins` feature, `minecraft` tag) plus a loader tag: `paper` (or `purpur`, `pufferfish`,
   `leaf`) for the `paper` platform, `velocity` for the `velocity` platform. Each server only sees
   the plugins of its own platform.

### How it works

- The panel reads the head commit of the branch, the GitHub Actions runs of that commit
  (`push` event on that branch) and the index file at exactly that commit. CI counts as **green**
  only when every run has finished as success/skipped/neutral and at least one succeeded; any
  failed run makes it **red**, running ones **pending**, and a commit without runs stays
  **not verified** (not installable while green CI is required). Results are cached briefly
  (head commit 5 minutes, CI 1-10 minutes); the page's refresh button checks again.
- **The panel downloads the jar itself** (GitHub contents API), verifies its sha256 and size
  against the index, and only then writes it to the server through the Wings file API. Wings
  never gets a GitHub URL or the token.
- The jar is written under a temporary name and renamed into `plugins/`; an existing file is never
  overwritten in place (if the indexed name is already taken by the plugin's own current jar, a
  name with a hash suffix is used). On update the new jar is written first, then the metadata,
  then the old jar is deleted - with a rollback at each step, so `plugins/` never keeps two jars of
  the same plugin. If another jar that looks like the same plugin is already there (another
  version of the same file, or a jar named after the plugin, e.g. a manual install), installing is
  refused until you remove it.
- **Plugin configuration is never changed**: only the jar in `plugins/` and the metadata file
  `plugins/.github-plugins.json` (id, name, version, sha256, file name, repository, commit,
  install/update time) are written. Removing a plugin deletes its jar and metadata entry and keeps
  its folder (`plugins/<Plugin>/config.yml`, data, ...).
- The running server keeps using what it loaded: **a new or updated plugin is used after the next
  server restart**.
- **Automatic updates** (daily, at the configured time) only update plugins that are already
  installed, only to a higher version, and only from a commit with green CI - regardless of the
  "Require green CI" setting. Nothing new is ever installed automatically.
- Page access requires the subuser permission *file.read*; installing requires *file.create*,
  updating *file.create* and *file.delete*, removing *file.delete*; hiding an offer ("Doesn't
  belong on this server") and "Offer again" require *file.create*.

### New plugins

When the repository gets a plugin that a server doesn't have yet, the panel **offers** it - it is
never installed automatically.

- **What counts as new**: a plugin in the index for the server's platform (`paper` plugins only on
  Paper/Purpur/... servers, `velocity` plugins only on Velocity servers) that is not installed from
  the repository on that server and that nobody has hidden for that server. A server whose
  platform can't be determined (no loader tag, no plugins feature) is offered nothing. A plugin
  whose jar is already in `plugins/` without being installed from here (a manual install, a
  Modrinth copy - recognised like the conflict check above) shows as **Installed manually** and
  is not offered.
- **GitHub Plugins page**: new plugins are listed first with the label **New - offered for
  installation** (then available updates), with a note above the table. Next to **Install** there
  is **Doesn't belong on this server**, which hides the offer on that server only. Hidden plugins
  can be shown with **Show hidden (n)** and brought back with **Offer again**.
- **Menu badge**: the *GitHub Plugins* menu item shows how many plugins are offered (new plus
  updates) - orange when there are updates, blue when there are only new plugins; the tooltip
  splits the number. The badge only reads a cached count (written by the hourly check below and by
  every visit of the page), so drawing the menu never contacts GitHub or the server's node; while
  there is no count (e.g. right after the plugin update, until the first check) there is no badge.
- **Notifications**: an hourly check (`p:minecraft-modrinth:github-offers`, runs only while the
  GitHub source is enabled) reads the repository state once for all servers, then the metadata
  file and the jar list of `plugins/` of every Paper/Velocity server (two Wings requests per server;
  suspended and not yet installed servers are skipped). For every new plugin a server hasn't been
  told about yet, the panel sends a notification (the bell in the panel): *"On server {server},
  the new plugin {name} {version} from the repository is available for installation"*, with a
  button to the GitHub Plugins page. Each plugin is announced **once per server** - not again for
  a newer version, after a hide/offer again, or after it was removed; more than three new plugins
  at once are announced in one notification. With *Require green CI* on, nothing is announced
  while CI of the newest commit isn't green (the next check after it turns green does it).
- **Who is notified**: the server owner and every subuser with *file.create* on that server (the
  people who can install it). Panel admins are not notified for every server they can see - they
  see the badge when they open a server.
- **Where it is stored**: hidden offers and which plugins were announced are kept per server in
  the panel database (table `minecraft_modrinth_github_offers`, created by the plugin's migration
  when the plugin is installed or updated, deleted together with the server). The server's
  `plugins/.github-plugins.json` stays exactly as in 1.2.0. If the table is missing (migration not
  run), offers are still shown and counted, but hiding is not possible and nothing is announced.

### Moving manually installed plugins over

Delete the manually installed jar first (its configuration folder can stay), then install the
plugin from the GitHub Plugins page. It is picked up at the next restart with the existing
configuration.

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
  15 s (120 s for a jar download); jars are limited to 100 MB (GitHub's limit for this API).
- A fine-grained token limited to one repository with read-only Contents/Actions can't change
  anything on GitHub.
