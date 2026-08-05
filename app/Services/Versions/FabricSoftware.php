<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpacks\Services\Versions;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

/**
 * Fabric, using the launcher jar meta serves directly.
 *
 * The upstream egg downloads fabric-installer.jar and runs it to produce a
 * launcher. That needs a JVM, which the panel has no way to invoke, so this
 * uses meta's own /server/jar endpoint instead — it returns the same launcher,
 * already built, as a plain download.
 *
 * A "build" here is a loader version, which is what actually varies for a given
 * Minecraft version.
 */
class FabricSoftware implements SoftwareInterface
{
    private const META = 'https://meta.fabricmc.net/v2/versions';

    public function key(): string
    {
        return 'fabric';
    }

    public function label(): string
    {
        return 'Fabric';
    }

    private function client()
    {
        return Http::timeout(15);
    }

    public function minecraftVersions(): array
    {
        return Cache::remember('modpacks:fabric:game', 300, function () {
            $data = $this->client()->get(self::META . '/game')->throw()->json();

            return array_values(array_map(
                fn (array $v) => $v['version'],
                array_filter($data, fn (array $v) => ($v['stable'] ?? false) === true),
            ));
        });
    }

    /** Loader versions are global rather than per Minecraft version. */
    public function builds(string $minecraftVersion): array
    {
        return Cache::remember('modpacks:fabric:loader', 300, function () {
            $data = $this->client()->get(self::META . '/loader')->throw()->json();

            return array_map(fn (array $l) => (new Build(
                id: $l['version'],
                name: $l['version'],
                stable: (bool) ($l['stable'] ?? false),
            ))->toArray(), $data);
        });
    }

    private function installerVersion(): string
    {
        return Cache::remember('modpacks:fabric:installer', 300, function () {
            $data = $this->client()->get(self::META . '/installer')->throw()->json();

            return $data[0]['version'] ?? throw new \RuntimeException('Fabric published no installer version.');
        });
    }

    public function resolve(string $minecraftVersion, ?string $build): Download
    {
        if ($build === null) {
            $available = $this->builds($minecraftVersion);
            $build = $available[0]['id'] ?? throw new \RuntimeException('Fabric published no loader versions.');
        }

        return new Download(sprintf(
            '%s/loader/%s/%s/%s/server/jar',
            self::META,
            rawurlencode($minecraftVersion),
            rawurlencode($build),
            rawurlencode($this->installerVersion()),
        ));
    }
}
