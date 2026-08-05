<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpacks\Services;

/**
 * Everything the installer needs to put one pack version onto a server,
 * normalised across providers.
 *
 * This is what replaces the egg's environment variables. The provider does the
 * provider-specific resolution — which file, which loader, which URL — and the
 * install service only ever sees this shape, so it stays free of per-provider
 * branching in the same way the frontend does.
 *
 * `archiveUrl` is a zip Wings can fetch and unpack on its own. When `selfContained`
 * is true that archive *is* the server: a publisher's server pack, mods and
 * configs included, nothing further to download. Otherwise it is a manifest
 * archive and the mods still have to be fetched separately.
 */
class InstallPlan
{
    public function __construct(
        public readonly string $archiveUrl,
        public readonly bool $selfContained,
        public readonly ?string $minecraftVersion = null,
        public readonly ?string $loader = null,
        public readonly ?string $loaderVersion = null,
    ) {
    }
}
