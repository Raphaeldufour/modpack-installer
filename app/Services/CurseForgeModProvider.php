<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpacks\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CurseForgeModProvider implements ModProviderInterface
{
    private const BASE = 'https://api.curseforge.com/v1';
    private const GAME_MINECRAFT = 432;
    private const CLASS_MODS = 6;
    private const LOADERS = [
        'forge' => 1,
        'fabric' => 4,
        'quilt' => 5,
        'neoforge' => 6,
    ];

    public function __construct(private ModpackSettings $settings)
    {
    }

    public function key(): string
    {
        return 'curseforge';
    }

    public function label(): string
    {
        return 'CurseForge';
    }

    private function client()
    {
        $key = $this->settings->curseForgeApiKey();

        if (empty($key)) {
            throw new \RuntimeException(
                'CurseForge is not configured. Add an API key in the panel admin area.'
            );
        }

        return Http::withHeaders(['x-api-key' => $key])->timeout(15);
    }

    public function search(string $query, int $page, int $pageSize, ?string $loader, ?string $minecraftVersion): array
    {
        $cacheKey = 'modpacks:mods:curseforge:search:' . md5(
            $query . $page . $pageSize . (string) $loader . (string) $minecraftVersion
        );

        return Cache::remember($cacheKey, 300, function () use ($query, $page, $pageSize, $loader, $minecraftVersion) {
            $params = [
                'gameId' => self::GAME_MINECRAFT,
                'classId' => self::CLASS_MODS,
                'searchFilter' => $query,
                'sortField' => 2,
                'sortOrder' => 'desc',
                'pageSize' => $pageSize,
                'index' => ($page - 1) * $pageSize,
            ];

            if ($minecraftVersion !== null && $minecraftVersion !== '') {
                $params['gameVersion'] = $minecraftVersion;
            }

            if ($loader !== null && isset(self::LOADERS[$loader])) {
                $params['modLoaderType'] = self::LOADERS[$loader];
            }

            $response = $this->client()->get(self::BASE . '/mods/search', $params)->throw()->json();

            return array_map(fn ($mod) => (new Pack(
                id: (string) $mod['id'],
                name: $mod['name'],
                summary: $mod['summary'] ?? '',
                iconUrl: $mod['logo']['thumbnailUrl'] ?? null,
                pageUrl: $mod['links']['websiteUrl'] ?? null,
                downloads: (int) ($mod['downloadCount'] ?? 0),
            ))->toArray(), $response['data'] ?? []);
        });
    }

    public function versions(string $modId, ?string $loader, ?string $minecraftVersion): array
    {
        $cacheKey = 'modpacks:mods:curseforge:versions:' . md5($modId . (string) $loader . (string) $minecraftVersion);

        return Cache::remember($cacheKey, 300, function () use ($modId, $loader, $minecraftVersion) {
            $params = ['pageSize' => 50];

            if ($minecraftVersion !== null && $minecraftVersion !== '') {
                $params['gameVersion'] = $minecraftVersion;
            }

            if ($loader !== null && isset(self::LOADERS[$loader])) {
                $params['modLoaderType'] = self::LOADERS[$loader];
            }

            $files = $this->client()
                ->get(self::BASE . "/mods/{$modId}/files", $params)
                ->throw()
                ->json()['data'] ?? [];

            return array_map(fn ($f) => (new Version(
                id: (string) $f['id'],
                name: $f['displayName'],
                gameVersion: $this->minecraftVersion($f['gameVersions'] ?? []),
                loader: $this->loader($f['gameVersions'] ?? []),
            ))->toArray(), $files);
        });
    }

    public function resolve(string $modId, string $versionId): array
    {
        $file = Cache::remember(
            "modpacks:mods:curseforge:file:{$modId}:{$versionId}",
            300,
            fn () => $this->client()->get(self::BASE . "/mods/{$modId}/files/{$versionId}")->throw()->json(),
        )['data'] ?? [];

        $url = Cache::remember(
            "modpacks:mods:curseforge:url:{$modId}:{$versionId}",
            300,
            fn () => $this->client()
                ->get(self::BASE . "/mods/{$modId}/files/{$versionId}/download-url")
                ->throw()
                ->json()['data'] ?? null,
        );

        if (empty($url)) {
            throw new \RuntimeException(
                'CurseForge returned no download URL for this file. The author may have disabled '
                . 'third-party distribution, which cannot be worked around.'
            );
        }

        return [
            'url' => $url,
            'filename' => $file['fileName'] ?? basename(parse_url($url, PHP_URL_PATH) ?: 'mod.jar'),
        ];
    }

    /** @param string[] $tags */
    private function minecraftVersion(array $tags): ?string
    {
        foreach ($tags as $tag) {
            if (preg_match('/^1\.\d+(\.\d+)?$/', $tag)) {
                return $tag;
            }
        }

        return null;
    }

    /** @param string[] $tags */
    private function loader(array $tags): ?string
    {
        foreach ($tags as $tag) {
            $lower = strtolower($tag);
            if (array_key_exists($lower, self::LOADERS)) {
                return $lower;
            }
        }

        return null;
    }
}
