<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpacks\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class CurseForgeProvider implements ProviderInterface
{
    private const BASE = 'https://api.curseforge.com/v1';
    private const GAME_MINECRAFT = 432;
    private const CLASS_MODPACKS = 4471;

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

    /**
     * CurseForge ships two shapes. When the publisher provides a server pack it
     * is a complete server — loader installed, mods present — and nothing else
     * is needed. Otherwise the file is a client manifest listing mods to fetch
     * one by one, each behind its own download-url call.
     */
    public function installPlan(string $packId, string $versionId): InstallPlan
    {
        $file = Cache::remember(
            "modpacks:curseforge:file:{$packId}:{$versionId}",
            300,
            fn () => $this->client()->get(self::BASE . "/mods/{$packId}/files/{$versionId}")->throw()->json(),
        )['data'] ?? [];

        $serverPackId = $file['serverPackFileId'] ?? null;
        $targetId = $serverPackId ?: $versionId;

        $url = Cache::remember(
            "modpacks:curseforge:url:{$packId}:{$targetId}",
            300,
            fn () => $this->client()
                ->get(self::BASE . "/mods/{$packId}/files/{$targetId}/download-url")
                ->throw()
                ->json()['data'] ?? null,
        );

        if (empty($url)) {
            throw new \RuntimeException(
                'CurseForge returned no download URL for this file. The author may have disabled '
                . 'third-party distribution, which cannot be worked around.'
            );
        }

        $loader = null;
        $loaderVersion = null;
        foreach ($file['gameVersions'] ?? [] as $tag) {
            $lower = strtolower($tag);
            if (in_array($lower, ['forge', 'neoforge', 'fabric', 'quilt'], true)) {
                $loader = $lower;
                break;
            }
        }

        $minecraft = null;
        foreach ($file['gameVersions'] ?? [] as $tag) {
            if (preg_match('/^1\.\d+(\.\d+)?$/', $tag)) {
                $minecraft = $tag;
                break;
            }
        }

        return new InstallPlan(
            archiveUrl: $url,
            selfContained: (bool) $serverPackId,
            minecraftVersion: $minecraft,
            loader: $loader,
            loaderVersion: $loaderVersion,
        );
    }
}
