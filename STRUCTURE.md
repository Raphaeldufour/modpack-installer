# Blueprint dev folder layout

Everything lives in `/var/www/pterodactyl/.blueprint/dev/`. That directory *is*
your extension while developing; `blueprint -build` compiles it into the live
panel, `blueprint -export` packages it into a distributable `.blueprint` file.

```
/var/www/pterodactyl/.blueprint/dev/
├── conf.yml                  # REQUIRED, must be at the root
├── Components.yml            # only if dashboard.components points here
├── assets/
│   └── icon.png              # info.icon
├── admin/
│   ├── view.blade.php        # admin.view
│   └── AdminController.php   # admin.controller
├── app/                      # requests.app  -> merged into panel's app/
│   ├── Http/Controllers/Extensions/modpacks/
│   │   └── ModpackController.php
│   └── Services/Modpacks/
│       ├── Pack.php
│       ├── Version.php
│       ├── ProviderInterface.php
│       ├── ModrinthProvider.php
│       ├── CurseForgeProvider.php
│       ├── ProviderRegistry.php
│       └── ModpackInstallService.php
├── routes/
│   └── client.php            # requests.routers.client
├── resources/
│   └── scripts/
│       └── ModpacksContainer.tsx
├── database/
│   └── migrations/           # database.migrations (may be empty)
├── data/                     # data.directory
│   ├── install.sh            # runs on extension install
│   ├── update.sh
│   ├── remove.sh
│   └── export.sh
└── public/                   # data.public -> served at /extensions/modpacks/...
```

## Rules that trip people up

**Paths in conf.yml are relative to this root**, not to the panel root.

**Every path bound in conf.yml must exist.** If one doesn't, `-build` aborts
with `FATAL: Extension configuration points towards one or more files that do
not exist` and does not tell you which one. Bisect by blanking bindings.

**Unbound files are ignored.** Dropping a `.php` into `app/` does nothing
unless `requests.app` is set. There is no auto-discovery.

**The identifier is a namespace.** `info.identifier: modpacks` decides your
admin route (`/admin/extensions/modpacks`), your public asset path, your
filesystem disk (`blueprint:modpacks`), and the controller namespace. Changing
it later means renaming directories in several places, so pick it once.

**`data/` scripts are for the extension, not the modpack.** `install.sh` here
runs when a panel admin installs *your extension* — seeding config, checking
dependencies. It has nothing to do with the egg's install script.
