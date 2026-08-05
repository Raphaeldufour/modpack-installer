<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpacks\Services\Versions;

/**
 * One published build of a Minecraft version, normalised across software.
 *
 * `id` is whatever that software needs to fetch this exact build again — a
 * build number on Paper and Purpur, a loader version on Fabric. It is opaque
 * to everything above the provider.
 */
class Build
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly bool $stable = true,
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'stable' => $this->stable,
        ];
    }
}
