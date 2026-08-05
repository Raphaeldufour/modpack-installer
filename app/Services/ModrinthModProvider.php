<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpacks\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ModrinthModProvider implements ModProviderInterface
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
        return Http::withHeaders([
            'User-Agent' => 'pterodactyl-modpacks/0.1.0 (your-contact@example.com)',
        ])->timeout(15);
    }

    public function search(string $query, int $page, int $pageSize, ?string $loader, ?string $minecraftVersion): array
    {
        $cacheKey = 'modpacks:mods:modrinth:search:' . md5(
            $query . $page . $pageSize . (string) $loader . (string) $minecraftVersion
        );

        return Cache::remember($cacheKey, 300, function () use ($query, $page, $pageSize, $loader, $minecraftVersion) {
            $facets = [['project_type:mod']];

            if ($loader !== null && $loader !== '') {
                $facets[] = ['categories:' . $loader];
            }

            if ($minecraftVersion !== null && $minecraftVersion !== '') {
                $facets[] = ['versions:' . $minecraftVersion];
            }

            $response = $this->client()->get(self::BASE . '/search', [
                'query' => $query,
                'facets' => json_encode($facets),
                'limit' => $pageSize,
                'offset' => ($page - 1) * $pageSize,
                'index' => $query === '' ? 'downloads' : 'relevance',
            ])->throw()->json();

            return array_map(fn ($hit) => (new Pack(
                id: $hit['project_id'],
                name: $hit['title'],
                summary: $hit['description'] ?? '',
                iconUrl: $hit['icon_url'] ?? null,
                pageUrl: 'https://modrinth.com/mod/' . ($hit['slug'] ?? $hit['project_id']),
                downloads: $hit['downloads'] ?? 0,
            ))->toArray(), $response['hits'] ?? []);
        });
    }

    public function versions(string $modId, ?string $loader, ?string $minecraftVersion): array
    {
        $cacheKey = 'modpacks:mods:modrinth:versions:' . md5($modId . (string) $loader . (string) $minecraftVersion);

        return Cache::remember($cacheKey, 300, function () use ($modId, $loader, $minecraftVersion) {
            $params = [];

            if ($loader !== null && $loader !== '') {
                $params['loaders'] = json_encode([$loader]);
            }

            if ($minecraftVersion !== null && $minecraftVersion !== '') {
                $params['game_versions'] = json_encode([$minecraftVersion]);
            }

            $versions = $this->client()
                ->get(self::BASE . "/project/{$modId}/version", $params)
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

    public function resolve(string $modId, string $versionId): array
    {
        $version = Cache::remember("modpacks:mods:modrinth:version:$versionId", 300, fn () => $this->client()
            ->get(self::BASE . "/version/{$versionId}")
            ->throw()
            ->json());

        $file = null;
        foreach ($version['files'] ?? [] as $candidate) {
            if (($candidate['primary'] ?? false) && ($candidate['file_type'] ?? 'required') !== 'optional') {
                $file = $candidate;
                break;
            }
        }

        $file ??= $version['files'][0] ?? null;

        if (!is_array($file) || empty($file['url'])) {
            throw new \RuntimeException('This Modrinth version publishes no downloadable file.');
        }

        return [
            'url' => $file['url'],
            'filename' => $file['filename'] ?? basename(parse_url($file['url'], PHP_URL_PATH) ?: 'mod.jar'),
        ];
    }
}
