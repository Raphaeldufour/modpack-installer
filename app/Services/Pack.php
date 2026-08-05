<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpacks\Services;

/**
 * A modpack, normalised across providers.
 *
 * Providers reshape their own API responses into this before anything else
 * sees them, which is what keeps provider-specific branching out of the
 * frontend. The keys returned by toArray() are a public contract: they go to
 * the browser verbatim, so renaming one is a frontend change too.
 *
 * The provider a pack came from is not stored here — a provider does not need
 * to know its own key to describe a pack. The controller tags results with it
 * as it merges them.
 */
class Pack
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $summary = '',
        public readonly ?string $iconUrl = null,
        public readonly ?string $pageUrl = null,
        public readonly int $downloads = 0,
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'summary' => $this->summary,
            'iconUrl' => $this->iconUrl,
            'pageUrl' => $this->pageUrl,
            'downloads' => $this->downloads,
        ];
    }
}
