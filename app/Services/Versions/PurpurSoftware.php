<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpacks\Services\Versions;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class PurpurSoftware implements SoftwareInterface
{
    private const BASE = 'https://api.purpurmc.org/v2/purpur';

    public function key(): string
    {
        return 'purpur';
    }

    public function label(): string
    {
        return 'Purpur';
    }

    private function client()
    {
        return Http::timeout(15);
    }

    public function minecraftVersions(): array
    {
        return Cache::remember('modpacks:purpur:versions', 300, function () {
            $data = $this->client()->get(self::BASE)->throw()->json();

            // Purpur lists oldest first — the upstream egg reads the newest as
            // `.versions[-1]` — and every other software here lists newest
            // first, so reverse it to keep the tab consistent.
            return array_reverse($data['versions'] ?? []);
        });
    }

    public function builds(string $minecraftVersion): array
    {
        return Cache::remember("modpacks:purpur:builds:{$minecraftVersion}", 300, function () use ($minecraftVersion) {
            $data = $this->client()->get(self::BASE . "/{$minecraftVersion}")->throw()->json();

            $builds = array_reverse(array_map('strval', $data['builds']['all'] ?? []));

            return array_map(
                fn (string $build) => (new Build(id: $build, name: "#{$build}"))->toArray(),
                $builds,
            );
        });
    }

    public function resolve(string $minecraftVersion, ?string $build): Download
    {
        if ($build === null) {
            $available = $this->builds($minecraftVersion);
            $build = $available[0]['id'] ?? throw new \RuntimeException(
                "Purpur has no builds for Minecraft {$minecraftVersion}."
            );
        }

        // Purpur serves the jar straight off this path rather than handing back
        // a URL to follow, so there is nothing further to resolve.
        return new Download(self::BASE . "/{$minecraftVersion}/{$build}/download");
    }
}
