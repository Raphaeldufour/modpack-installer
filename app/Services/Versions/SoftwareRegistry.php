<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpacks\Services\Versions;

use Pterodactyl\Exceptions\DisplayException;

/**
 * Resolves a software key coming off the wire to the implementation behind it.
 *
 * Paper's API serves several projects, so those are constructed here rather
 * than each getting a near-empty subclass.
 */
class SoftwareRegistry
{
    /** @var array<string, SoftwareInterface> */
    private array $software = [];

    public function __construct(
        VanillaSoftware $vanilla,
        PaperSoftware $paper,
        PurpurSoftware $purpur,
        FabricSoftware $fabric,
    ) {
        $all = [
            $vanilla,
            $paper,
            $fabric,
            $purpur,
            new PaperSoftware('folia', 'Folia'),
            new PaperSoftware('velocity', 'Velocity'),
        ];

        foreach ($all as $entry) {
            $this->software[$entry->key()] = $entry;
        }
    }

    /**
     * @throws \Pterodactyl\Exceptions\DisplayException if the key is unknown
     */
    public function get(string $key): SoftwareInterface
    {
        return $this->software[$key]
            ?? throw new DisplayException("There is no server software named \"{$key}\".");
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->software);
    }

    /** @return array[] */
    public function toArray(): array
    {
        return array_values(array_map(fn (SoftwareInterface $entry) => [
            'key' => $entry->key(),
            'label' => $entry->label(),
        ], $this->software));
    }
}
