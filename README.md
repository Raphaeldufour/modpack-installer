# Modpacks — Blueprint extension scaffold

A "Modpacks" tab for Pterodactyl server pages: browse packs from multiple
providers, pick a version, install in one click.

## Architecture

The panel does **no file work**. It is a browser and a dispatcher:

```
React tab ──> client API ──> ProviderRegistry ──> Modrinth / CurseForge API
                                  │
                                  └─> ModpackInstallService
                                        ├─ power: kill
                                        ├─ StartupModificationService (egg + vars)
                                        └─ DaemonServerRepository::reinstall()
                                                    │
                                                    ▼
                                          egg/install.sh in the
                                          install container does
                                          the actual download,
                                          loader install, unpack
```

This is the same approach the commercial addons take, and it buys you a lot:
no Wings modifications, install progress streams to the server console for
free, reinstall/rebuild behave as users already expect, and no PHP request
ever has to stay open for a 3 GB pack.

## Repository layout

The repository root **is** the extension root: its contents map 1:1 onto
`/var/www/pterodactyl/.blueprint/dev/`. `egg/` is the exception — it is imported
separately by an admin and is not shipped with the extension.

```
conf.yml                              # extension manifest (see STRUCTURE.md)
app/Services/Modpacks/                # Pack, Version, providers, registry, install service
app/Http/Controllers/Extensions/modpacks/ModpackController.php
routes/client.php                     # client API endpoints — requests.routers.client
egg/install.sh                        # source of truth for the egg script
egg/egg.template.json                 # egg metadata; script slot is a placeholder
egg/build_egg.py                      # install.sh + template -> modpack-installer.json
egg/modpack-installer.json            # GENERATED — never hand-edit
INSTALL.md STRUCTURE.md CLAUDE.md     # docs, not deployed
```

## Getting it running

**[`INSTALL.md`](INSTALL.md) is the step-by-step tutorial** (in French), including
the missing `conf.yml` bindings that currently break the build. The short version:

1. Install Blueprint on a **test** panel. Never develop against production.
2. Turn on developer mode at `/admin/extensions` → Blueprint → `developer: true`.
3. `blueprint -init`, pick a template, then drop this repository (minus `egg/`)
   into `/var/www/pterodactyl/.blueprint/dev/`.
4. Create the files bound in `conf.yml` that don't exist yet, or blank those
   bindings — `-build` aborts otherwise. See `INSTALL.md` §B.3.
5. Import `egg/modpack-installer.json` in the admin area, note its egg ID.
6. Create `config/modpacks.php`:
   ```php
   <?php
   return [
       'curseforge_api_key' => env('CURSEFORGE_API_KEY'),
       'installer_egg_id' => (int) env('MODPACK_INSTALLER_EGG_ID'),
   ];
   ```
   Long term, move both into a Blueprint admin config so hosts can set them
   in the UI instead of editing files.
7. `blueprint -build`, then hard-refresh the panel.

## Things that will bite you

**CurseForge redistribution.** Many mods set `allowModDistribution: false`;
the download-url endpoint returns null and there is no legal workaround. The
script logs `BLOCKED:` and continues, which produces a subtly broken pack.
Prefer `serverPackFileId` when the publisher provides one, and surface blocked
mods in the UI rather than failing silently.

**Modrinth `.mrpack` is not a server pack.** It is a manifest. You must filter
`env.server != "unsupported"` or you will ship client-only mods that crash the
server on boot, and `server-overrides/` must be applied *after* `overrides/`.

**Rate limits.** Modrinth wants a real User-Agent and will throttle you.
CurseForge keys are per-application. The 5-minute cache in the providers is the
minimum; consider a shared cache keyed by provider rather than per-panel.

**Route prefix.** Confirm where Blueprint actually mounted your client routes
before trusting the URL hardcoded in the React component:
`php artisan route:list | grep modpacks`.

**Permissions.** Installing wipes the filesystem and rewrites startup. The
controller requires both `startup.update` and `file.delete`. Do not loosen this
— a subuser with console access alone should not be able to nuke a server.

**Egg detection.** The tab should hide itself on non-Minecraft eggs. Blueprint's
component placement does not respect the route-egg config on its own; the
filtering logic lives in
`resources/scripts/blueprint/extends/routers/ServerRouter.tsx` in the framework
repo and you can mirror it.

## Next providers

Each one only needs `search()` and `versions()`; the install path is shared.

| Provider | API | Notes |
|---|---|---|
| Feed The Beast | `api.modpacks.ch` | Ships a server installer binary — easiest of the lot |
| Technic | `api.technicpack.net` | Solder-based; zips are usually server-ready |
| ATLauncher | `api.atlauncher.com` | JSON pack index |
| Voids Wrath | none public | The commercial addon appears to scrape; verify their terms first |
