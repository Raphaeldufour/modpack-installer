# Blueprint dev folder layout

Everything lives in `/var/www/pterodactyl/.blueprint/dev/`. That directory *is*
your extension while developing; `blueprint -build` compiles it into the live
panel, `blueprint -export` packages it into a distributable `.blueprint` file.

```
/var/www/pterodactyl/.blueprint/dev/
├── conf.yml                  # REQUIRED, must be at the root
├── components/               # dashboard.components -> a DIRECTORY, not a file
│   ├── Components.yml        # the component wiring itself
│   ├── tsconfig.json         # editor tooling only
│   └── sections/
│       └── ModpacksSection.tsx
├── assets/
│   └── icon.png              # info.icon
├── admin/
│   ├── view.blade.php        # admin.view
│   └── AdminController.php   # admin.controller
├── app/                      # requests.app -> symlinked to the panel as
│   │                         # app/BlueprintFramework/Extensions/modpacks
│   ├── Http/Controllers/
│   │   └── ModpackController.php
│   └── Services/
│       ├── Pack.php
│       ├── Version.php
│       ├── ProviderInterface.php
│       ├── ModrinthProvider.php
│       ├── CurseForgeProvider.php
│       ├── ProviderRegistry.php
│       ├── ModpackSettings.php
│       └── ModpackInstallService.php
├── routes/
│   └── client.php            # requests.routers.client
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

**Never put a comment at the end of a value line in conf.yml.** Blueprint parses
that file with a sed/awk helper, not a YAML parser, and it only strips a comment
that contains no quote character. A lone apostrophe (`# the panel's app tree`)
glues the entire comment onto the value, the path stops resolving, and the build
fails naming no file at all. Put comments on their own line, where they are
always stripped. The official templates use no end-of-line comments.

**`dashboard.components` names a directory, not a file.** It points at the
folder that *contains* `Components.yml`, and `Component:` values inside that
file are resolved relative to it, without a file extension — so
`sections/ModpacksSection` means `components/sections/ModpacksSection.tsx`.
Pointing the binding straight at `Components.yml` fails the build with the
usual unhelpful `FATAL`.

**The identifier is a namespace.** `info.identifier: modpacks` decides your
admin route (`/admin/extensions/modpacks`), your public asset path, your
filesystem disk (`blueprint:modpacks`), and the controller namespace. Changing
it later means renaming directories in several places, so pick it once.

**`data/` scripts are for the extension, not for a server.** `install.sh` here
runs when a panel admin installs *your extension* — seeding config, checking
dependencies. It never touches a game server.
