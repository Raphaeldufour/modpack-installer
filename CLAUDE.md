# CLAUDE.md

Blueprint extension for Pterodactyl adding a **Modpacks** tab to server pages:
browse packs from several providers, pick a version, install in one click.

## Non-negotiable architecture

The panel does **no file work**. It is a browser and a dispatcher.

```
React tab -> client API -> ProviderRegistry -> Modrinth / CurseForge REST
                              |
                              +-> ModpackInstallService
                                    |- power: kill
                                    |- StartupModificationService (egg + variables)
                                    +- DaemonServerRepository::reinstall()
                                                |
                                                v
                                    egg/install.sh runs in the install
                                    container and does the real work
```

If you find yourself reaching for `DaemonFileRepository::pull()` or
`decompress()` to install a pack, stop. That path was considered and rejected:
a modpack is a manifest, not a zip, so it needs per-mod downloads, a loader
install and a rewritten startup command. Doing that from PHP means minutes-long
HTTP requests with no progress output. Doing it in the install container gives
the user a live install log in the server console for free.

`DaemonFileRepository` is still fine for small reads/writes (checking whether
`mods/` exists, reading a manifest) — just not for installing.

## Layout

Paths are relative to `/var/www/pterodactyl/.blueprint/dev/`. Every path used
here must also be bound in `conf.yml` or the build silently ignores it.

| Path | conf.yml binding |
|---|---|
| `conf.yml` | — (required, root) |
| `components/` | `dashboard.components` (a directory holding `Components.yml`) |
| `components/sections/` | referenced from `Components.yml`, extensionless |
| `app/` | `requests.app` |
| `routes/client.php` | `requests.routers.client` |
| `admin/` | `admin.view` + `admin.controller` |
| `assets/icon.png` | `info.icon` |
| `data/` | `data.directory` — extension lifecycle hooks, must exit 0 |
| `public/` | `data.public` — bound but empty |
| `database/migrations/` | `database.migrations` — empty; config lives in `settings` |
| `egg/` | **not part of the extension** — imported separately by the admin |

## Conventions

- PHP namespace is `Pterodactyl\Services\Modpacks` and
  `Pterodactyl\Http\Controllers\Extensions\modpacks`. One class per file,
  PSR-4. The lowercase `modpacks` in the controller namespace matches
  `info.identifier` and is not a typo.
- Providers normalise everything to `Pack` and `Version`. The frontend must
  never contain provider-specific branching. Adding a provider means one new
  class implementing `ProviderInterface` plus a line in `ProviderRegistry`.
- All provider HTTP goes through the panel. API keys never reach the browser.
- Provider responses are cached 300s. Keep it.
- Configuration is read through `ModpackSettings`, never with `config()` at the
  call site. It resolves the admin page's settings first and falls back to
  `config/modpacks.php` for panels configured before that page existed.
- Edit `egg/install.sh` and run `python3 egg/build_egg.py` to regenerate
  `egg/modpack-installer.json`. Never hand-edit the JSON.

## Build loop

```bash
cd /var/www/pterodactyl
blueprint -build          # compile dev extension into the live panel
blueprint -export         # produce a distributable .blueprint file
php artisan route:list | grep modpacks   # verify the client route prefix
bash -n egg/install.sh    # syntax-check before regenerating the egg
```

`FATAL: Extension configuration points towards one or more files that do not
exist` means a `conf.yml` binding is wrong. It does not say which one — bisect
by blanking bindings.

The trap: an **omitted** key is not the same as an empty one. Blueprint resolves
every key it knows about, and a missing one comes back as the string `null`,
which then fails a file test for a path nobody wrote. Declare the full key set
and leave unused entries as `''`, the way the official templates do. A `conf.yml`
whose every declared path exists can still fail this way.

## Unverified — check before trusting

- **`Components.yml` schema.** Resolved: diffed against the official
  "Working with components" template (`BlueprintFramework/templates`, folder
  `3`). The earlier guess was wrong in a way that would have failed the build —
  `dashboard.components` names a *directory* containing `Components.yml`, not
  the file. `Component:` values are relative to that directory and drop the
  extension. Still worth re-diffing after a Blueprint upgrade: this API has
  moved between releases before.
- **Client route prefix.** The React component hardcodes
  `/api/client/servers/{uuid}/modpacks`. Confirm with `route:list`.
- **The admin controller's class name.** `modpacksExtensionController` and the
  two-classes-in-one-file layout come from Blueprint's admin template, which
  substitutes `info.identifier` into both. If the page 500s on load, that
  derivation is the first thing to check against your Blueprint version.

## Known gaps

- [ ] FTB, Technic, ATLauncher providers
- [ ] Surface CurseForge `allowModDistribution: false` blocks in the UI —
      currently only logged as `BLOCKED:` in the install log, which silently
      yields a broken pack
- [ ] Hide the tab on non-Minecraft eggs (mirror the framework's
      `ServerRouter.tsx` egg-filtering logic)
- [ ] Poll `/state` during install so the tab reflects progress
- [ ] Offer a backup before wiping

## Testing

Never develop against a production panel — `blueprint -build` writes directly
into the live install and a bad build leaves the panel broken. Use a throwaway
VM or the Blueprint Docker image.

Start with Modrinth: no API key needed, so it works immediately. Fabric packs
are the fastest to verify end to end; Forge 1.20+ exercises the `run.sh`
branch of the startup logic; a CurseForge pack without a published server pack
exercises the manifest fallback and the redistribution blocks.
