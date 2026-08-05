<?php

namespace Pterodactyl\Services\Modpacks;

use Pterodactyl\Exceptions\DisplayException;

/**
 * Resolves a provider key coming off the wire to the provider that serves it.
 *
 * Adding a provider is meant to cost one class and one line: type-hint the new
 * provider in the constructor below and Laravel resolves it. Nothing else in
 * the extension — and nothing at all in the frontend — should need touching.
 */
class ProviderRegistry
{
    /** @var array<string, ProviderInterface> */
    private array $providers = [];

    public function __construct(
        ModrinthProvider $modrinth,
        CurseForgeProvider $curseforge,
    ) {
        foreach ([$modrinth, $curseforge] as $provider) {
            $this->providers[$provider->key()] = $provider;
        }
    }

    /** @return array<string, ProviderInterface> */
    public function all(): array
    {
        return $this->providers;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->providers);
    }

    /**
     * @throws \Pterodactyl\Exceptions\DisplayException if the key is unknown
     */
    public function get(string $key): ProviderInterface
    {
        return $this->providers[$key]
            ?? throw new DisplayException("There is no modpack provider named \"{$key}\".");
    }

    /**
     * The provider list as the frontend consumes it.
     *
     * Every registered provider is listed, including ones the host has not
     * configured yet. Whether CurseForge has an API key is not knowable from
     * the interface, and hiding a provider that is merely unconfigured would
     * leave the user with no clue why it vanished. The provider raises a
     * readable error when it is actually used instead.
     *
     * @return array[]
     */
    public function toArray(): array
    {
        return array_values(array_map(fn (ProviderInterface $provider) => [
            'key' => $provider->key(),
            'label' => $provider->label(),
        ], $this->providers));
    }
}
