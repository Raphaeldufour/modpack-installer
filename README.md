# Modpacks — Blueprint extension scaffold

A "Modpacks" tab for Pterodactyl server pages: browse packs from multiple
providers, pick a version, install in one click.

## Architecture

The panel resolves, Wings transfers, the server runs it:

```
React tab ──> client API ──> providers ──> Modrinth / CurseForge / Mojang / Paper / …
                                  │
                                  └─> install service
                                        ├─ power: kill
                                        ├─ DaemonFileRepository::pull()  (Wings downloads)
                                        ├─ DaemonFileRepository::decompressFile()
                                        └─ StartupModificationService (startup + image)
```

No installer egg and no reinstall. A server keeps whichever egg it has — the egg
is a Java container and a default command — and an install rewrites the startup
command instead. That is what lets the Versions and Modpacks tabs, and the
Worlds, Plugins and Mods tabs to come, share one mechanism.

The panel never moves a payload itself: it hands Wings a URL, so a 400MB pack
costs one short API call rather than a request held open for minutes.

## Repository layout

The repository root **is** the extension root: its contents map 1:1 onto
`/var/www/pterodactyl/.blueprint/dev/`.

```
conf.yml                              # extension manifest (see STRUCTURE.md)
app/Services/                         # Pack, Version, providers, registry, settings, install service
app/Http/Controllers/ModpackController.php
                                      # both namespaced Pterodactyl\BlueprintFramework\Extensions\modpacks\…
routes/client.php                     # client API endpoints — requests.routers.client
components/Components.yml             # dashboard.components points at the DIRECTORY
components/sections/ModpacksSection.tsx   # the tab itself
admin/                                # admin.view + admin.controller — CurseForge key
assets/icon.png                       # info.icon
data/*.sh                             # extension lifecycle hooks
public/ database/migrations/          # bound but empty; settings live in the panel's table
INSTALL.md STRUCTURE.md CLAUDE.md     # docs, not deployed
```

## Getting it running

**[`INSTALL.md`](INSTALL.md) is the step-by-step tutorial** (in French), and
**[`BACKLOG.md`](BACKLOG.md) is what is left to build**. The short version:

1. Install Blueprint on a **test** panel. Never develop against production.
2. Turn on developer mode at `/admin/extensions` → Blueprint → `developer: true`.
3. `blueprint -init`, pick a template, then drop this repository into `/var/www/pterodactyl/.blueprint/dev/`.
4. Check every path bound in `conf.yml` survived the copy — `-build` aborts
   without saying which one is missing. See `INSTALL.md` §B.3.
5. `blueprint -build`, then hard-refresh the panel.
6. Set a CurseForge key at **Admin → Extensions → Modpacks** if you want
   CurseForge packs. Modrinth and every server software need nothing, so you can
   skip this entirely to try it out.

## Things that will bite you

**CurseForge redistribution.** Many mods set `allowModDistribution: false` and
the download-url endpoint returns null, with no legal workaround. This is why
the installer prefers `serverPackFileId`: a publisher's server pack is one
archive that sidesteps per-mod redistribution entirely.

**Modrinth `.mrpack` is not a server pack.** It is a manifest, so its mods are
fetched one at a time after the archive is unpacked. Two rules that path has to
keep: drop anything with `env.server == "unsupported"`, or you ship client-only
mods that crash the server, and apply `server-overrides/` *after* `overrides/`.

**Rate limits.** Modrinth wants a real User-Agent and will throttle you.
CurseForge keys are per-application. The 5-minute cache in the providers is the
minimum; consider a shared cache keyed by provider rather than per-panel.

**Route prefix.** Confirm where Blueprint actually mounted your client routes
before trusting the URL hardcoded in the React component:
`php artisan route:list | grep modpacks`. On Blueprint beta-2026-06 it is
`/api/client/extensions/modpacks/servers/{server}` — extension routes are not
mounted alongside the panel's own `/api/client/servers` routes.

**Permissions.** Installing wipes the filesystem and rewrites startup. The
controller requires both `startup.update` and `file.delete`. Do not loosen this
— a subuser with console access alone should not be able to nuke a server.

**Egg detection.** The tab should hide itself on non-Minecraft eggs. Blueprint's
component placement does not respect the route-egg config on its own; the
filtering logic lives in
`resources/scripts/blueprint/extends/routers/ServerRouter.tsx` in the framework
repo and you can mirror it.

## Next providers

Each one needs `search()`, `versions()` and `installPlan()`; the install path is shared.

| Provider | API | Notes |
|---|---|---|
| Feed The Beast | `api.modpacks.ch` | Ships a server installer binary — easiest of the lot |
| Technic | `api.technicpack.net` | Solder-based; zips are usually server-ready |
| ATLauncher | `api.atlauncher.com` | JSON pack index |
| Voids Wrath | none public | The commercial addon appears to scrape; verify their terms first |
