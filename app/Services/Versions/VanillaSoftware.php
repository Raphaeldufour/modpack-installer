<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpacks\Services\Versions;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

/**
 * Mojang's own server jars, read from the launcher manifest.
 *
 * Endpoints and field paths follow the maintained upstream vanilla egg, so they
 * stay in step with whatever Mojang is actually serving.
 */
class VanillaSoftware implements SoftwareInterface
{
    private const MANIFEST = 'https://launchermeta.mojang.com/mc/game/version_manifest.json';

    public function key(): string
    {
        return 'vanilla';
    }

    public function label(): string
    {
        return 'Vanilla';
    }

    private function client()
    {
        return Http::timeout(15);
    }

    private function manifest(): array
    {
        return Cache::remember('modpacks:vanilla:manifest', 300, fn () => $this->client()
            ->get(self::MANIFEST)
            ->throw()
            ->json());
    }

    public function minecraftVersions(): array
    {
        // Snapshots outnumber releases several times over and are almost never
        // what someone picking a server version wants; the manifest marks them.
        return array_values(array_map(
            fn (array $v) => $v['id'],
            array_filter($this->manifest()['versions'] ?? [], fn (array $v) => ($v['type'] ?? '') === 'release'),
        ));
    }

    public function builds(string $minecraftVersion): array
    {
        // Mojang publishes exactly one server jar per version.
        return [];
    }

    public function resolve(string $minecraftVersion, ?string $build): Download
    {
        $entry = null;
        foreach ($this->manifest()['versions'] ?? [] as $candidate) {
            if (($candidate['id'] ?? null) === $minecraftVersion) {
                $entry = $candidate;
                break;
            }
        }

        if ($entry === null) {
            throw new \RuntimeException("Minecraft {$minecraftVersion} is not in Mojang's manifest.");
        }

        $detail = Cache::remember(
            'modpacks:vanilla:version:' . md5($entry['url']),
            300,
            fn () => $this->client()->get($entry['url'])->throw()->json(),
        );

        $url = $detail['downloads']['server']['url'] ?? null;

        if ($url === null) {
            // Everything before 1.2.5 is client-only.
            throw new \RuntimeException("Minecraft {$minecraftVersion} has no server download.");
        }

        return new Download($url);
    }
}
