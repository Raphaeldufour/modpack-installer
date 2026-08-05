<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpacks\Services\Versions;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

/**
 * PaperMC, and the other projects served by the same API.
 *
 * This talks to fill.papermc.io/v3, not api.papermc.io/v2. The v2 API is
 * deprecated and its build listings have already stopped being updated for
 * newer versions; the maintained upstream egg moved to v3.
 *
 * Folia and Velocity are the same shape, which is why the project name is a
 * constructor argument rather than hardcoded — subclassing for each would buy
 * nothing.
 */
class PaperSoftware implements SoftwareInterface
{
    protected const BASE = 'https://fill.papermc.io/v3/projects';

    public function __construct(
        private string $project = 'paper',
        private string $displayName = 'Paper',
    ) {
    }

    public function key(): string
    {
        return $this->project;
    }

    public function label(): string
    {
        return $this->displayName;
    }

    private function client()
    {
        return Http::withHeaders([
            'User-Agent' => 'pterodactyl-modpacks/0.1.0 (your-contact@example.com)',
        ])->timeout(15);
    }

    public function minecraftVersions(): array
    {
        return Cache::remember("modpacks:paper:{$this->project}:versions", 300, function () {
            $data = $this->client()->get(self::BASE . "/{$this->project}")->throw()->json();

            // v3 groups versions by minor line — {"1.21": ["1.21.4", "1.21.3"], …} —
            // newest group first, newest version first inside it. Flattening
            // keeps that order, so the newest release stays at the top.
            $versions = [];
            foreach ($data['versions'] ?? [] as $group) {
                foreach ((array) $group as $version) {
                    $versions[] = $version;
                }
            }

            return $versions;
        });
    }

    public function builds(string $minecraftVersion): array
    {
        return Cache::remember(
            "modpacks:paper:{$this->project}:builds:{$minecraftVersion}",
            300,
            function () use ($minecraftVersion) {
                $data = $this->client()
                    ->get(self::BASE . "/{$this->project}/versions/{$minecraftVersion}")
                    ->throw()
                    ->json();

                $builds = array_reverse(array_map('strval', $data['builds'] ?? []));

                return array_map(
                    fn (string $build) => (new Build(id: $build, name: "#{$build}"))->toArray(),
                    $builds,
                );
            },
        );
    }

    public function resolve(string $minecraftVersion, ?string $build): Download
    {
        if ($build === null) {
            $available = $this->builds($minecraftVersion);
            $build = $available[0]['id'] ?? throw new \RuntimeException(
                "{$this->displayName} has no builds for Minecraft {$minecraftVersion}."
            );
        }

        $data = Cache::remember(
            "modpacks:paper:{$this->project}:build:{$minecraftVersion}:{$build}",
            300,
            fn () => $this->client()
                ->get(self::BASE . "/{$this->project}/versions/{$minecraftVersion}/builds/{$build}")
                ->throw()
                ->json(),
        );

        $url = $data['downloads']['server:default']['url'] ?? null;

        if ($url === null) {
            throw new \RuntimeException("{$this->displayName} build {$build} publishes no server download.");
        }

        return new Download($url);
    }
}
