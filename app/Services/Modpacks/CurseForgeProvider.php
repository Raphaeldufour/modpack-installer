<?php

namespace Pterodactyl\Services\Modpacks;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class CurseForgeProvider implements ProviderInterface
{
    private const BASE = 'https://api.curseforge.com/v1';
    private const GAME_MINECRAFT = 432;
    private const CLASS_MODPACKS = 4471;

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
        $key = config('modpacks.curseforge_api_key');

        if (empty($key)) {
            throw new \RuntimeException(
                'CurseForge is not configured. Add an API key in the panel admin area.'
            );
        }

        // The key is a server-side secret and must never reach the browser.
        // That is the whole reason provider calls are proxied through the panel.
        return Http::withHeaders(['x-api-key' => $key])->timeout(15);
    }

    public function search(string $query, int $page, int $pageSize): array
    {
        $cacheKey = 'modpacks:curseforge:search:' . md5($query . $page . $pageSize);

        return Cache::remember($cacheKey, 300, function () use ($query, $page, $pageSize) {
            $response = $this->client()->get(self::BASE . '/mods/search', [
                'gameId' => self::GAME_MINECRAFT,
                'classId' => self::CLASS_MODPACKS,
                'searchFilter' => $query,
                'sortField' => 2,        // popularity
                'sortOrder' => 'desc',
                'pageSize' => $pageSize,
                'index' => ($page - 1) * $pageSize,
            ])->throw()->json();

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

    public function versions(string $packId): array
    {
        return Cache::remember("modpacks:curseforge:versions:$packId", 300, function () use ($packId) {
            $files = $this->client()
                ->get(self::BASE . "/mods/{$packId}/files", ['pageSize' => 50])
                ->throw()
                ->json()['data'] ?? [];

            return array_map(fn ($f) => (new Version(
                id: (string) $f['id'],
                name: $f['displayName'],
                gameVersion: $f['gameVersions'][0] ?? null,
                // serverPackFileId means the publisher ships a ready-made server
                // pack, which is far more reliable than rebuilding a client pack.
                loader: isset($f['serverPackFileId']) ? 'server-pack' : null,
            ))->toArray(), $files);
        });
    }
}
