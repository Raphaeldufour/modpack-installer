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
| `Components.yml` | `dashboard.components` |
| `app/` | `requests.app` |
| `routes/client.php` | `requests.routers.client` |
| `resources/scripts/` | referenced from `Components.yml` |
| `data/` | `data.directory` |
| `database/migrations/` | `database.migrations` |
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

## Unverified — check before trusting

- **`Components.yml` schema.** Written from documentation, not validated
  against a running panel. The component API has moved between Blueprint
  releases. Run `blueprint -init` and pick the "Working with components"
  template, then diff.
- **Client route prefix.** The React component hardcodes
  `/api/client/servers/{uuid}/modpacks`. Confirm with `route:list`.
- **`admin/view.blade.php` and `admin/AdminController.php`** are bound in
  `conf.yml` but do not exist yet. The build will fail until they are created
  or the bindings removed.

## Known gaps

- [ ] Admin page for the CurseForge key and installer egg ID (currently
      `config/modpacks.php`, which hosts must create by hand)
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
