<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpacks\Services;

use Pterodactyl\Exceptions\DisplayException;

class ModProviderRegistry
{
    /** @var array<string, ModProviderInterface> */
    private array $providers = [];

    public function __construct(
        ModrinthModProvider $modrinth,
        CurseForgeModProvider $curseforge,
    ) {
        foreach ([$modrinth, $curseforge] as $provider) {
            $this->providers[$provider->key()] = $provider;
        }
    }

    public function get(string $key): ModProviderInterface
    {
        return $this->providers[$key]
            ?? throw new DisplayException("There is no mod provider named \"{$key}\".");
    }

    /** @return array[] */
    public function toArray(): array
    {
        return array_values(array_map(fn (ModProviderInterface $provider) => [
            'key' => $provider->key(),
            'label' => $provider->label(),
        ], $this->providers));
    }
}
