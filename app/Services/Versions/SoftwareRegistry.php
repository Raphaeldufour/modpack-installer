<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpacks\Services\Versions;

use Throwable;
use Illuminate\Support\Facades\Log;
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

    /**
     * Editorial grouping for the browse grid — the same three-tier scheme
     * mcjars.app uses: defaults most servers want, alternatives worth
     * knowing about, and things that can break on you. There is no signal
     * in a SoftwareInterface itself this could be derived from, so it is a
     * plain lookup here; a key this misses falls back to 'established' in
     * toArray() below rather than being dropped from the grid.
     */
    private const CATEGORIES = [
        'vanilla' => 'recommended',
        'paper' => 'recommended',
        'fabric' => 'recommended',
        'velocity' => 'recommended',
        'purpur' => 'established',
        'folia' => 'experimental',
    ];

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

    /**
     * `minecraftVersionCount` costs one upstream call per software the first
     * time it is asked for — every implementation already caches its
     * minecraftVersions() for 300s, so this is only ever slow once per cache
     * window, not once per page load. A total build count (the way
     * mcjars.app's cards show it) is deliberately not attempted: that would
     * mean summing per-version build lists, which for Paper alone is one
     * upstream call per Minecraft version it has ever published — cheap to
     * cache, too expensive to compute on demand.
     *
     * @return array[]
     */
    public function toArray(): array
    {
        return array_values(array_map(fn (SoftwareInterface $entry) => [
            'key' => $entry->key(),
            'label' => $entry->label(),
            'category' => self::CATEGORIES[$entry->key()] ?? 'established',
            'minecraftVersionCount' => $this->versionCount($entry),
        ], $this->software));
    }

    /**
     * Best-effort: one struggling upstream should cost that one card its
     * count, not take the whole grid down with a 500.
     */
    private function versionCount(SoftwareInterface $entry): ?int
    {
        try {
            return count($entry->minecraftVersions());
        } catch (Throwable $exception) {
            Log::debug('modpacks: could not count minecraft versions for a software card', [
                'software' => $entry->key(),
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
