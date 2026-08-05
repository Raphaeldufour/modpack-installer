<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpacks\Services;

/**
 * One installable version of a Pack, normalised across providers.
 *
 * `id` is whatever the provider needs to fetch that exact build later — a
 * version id on Modrinth, a file id on CurseForge. It is passed through to the
 * egg untouched as MODPACK_VERSION; nothing between here and install.sh
 * interprets it.
 *
 * `gameVersion` and `loader` are advisory. Providers report what they know and
 * either may be null: CurseForge, for instance, has no loader field on a file
 * listing and reports 'server-pack' there instead to flag that the publisher
 * ships a ready-made server pack. Treat both as display hints — the install
 * script reads the pack's own manifest for the values it acts on.
 */
class Version
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $gameVersion = null,
        public readonly ?string $loader = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'gameVersion' => $this->gameVersion,
            'loader' => $this->loader,
        ];
    }
}
