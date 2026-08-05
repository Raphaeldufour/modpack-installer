<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpacks\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class ModrinthProvider implements ProviderInterface
{
    private const BASE = 'https://api.modrinth.com/v2';

    public function key(): string
    {
        return 'modrinth';
    }

    public function label(): string
    {
        return 'Modrinth';
    }

    private function client()
    {
        // Modrinth requires a descriptive User-Agent and throttles anonymous
        // hammering. Put a real contact address here before going live.
        return Http::withHeaders([
            'User-Agent' => 'pterodactyl-modpacks/0.1.0 (your-contact@example.com)',
        ])->timeout(15);
    }

    public function search(string $query, int $page, int $pageSize): array
    {
        $cacheKey = 'modpacks:modrinth:search:' . md5($query . $page . $pageSize);

        return Cache::remember($cacheKey, 300, function () use ($query, $page, $pageSize) {
            $response = $this->client()->get(self::BASE . '/search', [
                'query' => $query,
                'facets' => json_encode([['project_type:modpack']]),
                'limit' => $pageSize,
                'offset' => ($page - 1) * $pageSize,
                'index' => $query === '' ? 'downloads' : 'relevance',
            ])->throw()->json();

            return array_map(fn ($hit) => (new Pack(
                id: $hit['project_id'],
                name: $hit['title'],
                summary: $hit['description'] ?? '',
                iconUrl: $hit['icon_url'] ?? null,
                pageUrl: 'https://modrinth.com/modpack/' . ($hit['slug'] ?? $hit['project_id']),
                downloads: $hit['downloads'] ?? 0,
            ))->toArray(), $response['hits'] ?? []);
        });
    }

    public function versions(string $packId): array
    {
        return Cache::remember("modpacks:modrinth:versions:$packId", 300, function () use ($packId) {
            $versions = $this->client()
                ->get(self::BASE . "/project/{$packId}/version")
                ->throw()
                ->json();

            return array_map(fn ($v) => (new Version(
                id: $v['id'],
                name: $v['version_number'] ?? $v['name'],
                gameVersion: $v['game_versions'][0] ?? null,
                loader: $v['loaders'][0] ?? null,
            ))->toArray(), $versions);
        });
    }
}
