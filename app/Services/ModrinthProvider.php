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

    /**
     * A .mrpack is a zip holding modrinth.index.json plus overrides — a
     * manifest, not a server. The mods it names are fetched afterwards.
     */
    public function installPlan(string $packId, string $versionId): InstallPlan
    {
        $version = Cache::remember("modpacks:modrinth:version:$versionId", 300, fn () => $this->client()
            ->get(self::BASE . "/version/{$versionId}")
            ->throw()
            ->json());

        $url = null;
        foreach ($version['files'] ?? [] as $file) {
            if ($file['primary'] ?? false) {
                $url = $file['url'];
                break;
            }
        }

        if ($url === null) {
            throw new \RuntimeException('This Modrinth version publishes no primary file.');
        }

        // The loader is whichever dependency key is present; Modrinth names them
        // fabric-loader and quilt-loader, forge and neoforge plain.
        $loader = null;
        $loaderVersion = null;
        foreach (['fabric-loader', 'quilt-loader', 'neoforge', 'forge'] as $key) {
            if (!empty($version['dependencies'][$key] ?? null)) {
                $loader = str_replace('-loader', '', $key);
                $loaderVersion = $version['dependencies'][$key];
                break;
            }
        }

        return new InstallPlan(
            archiveUrl: $url,
            selfContained: false,
            indexPath: 'modrinth.index.json',
            // server-overrides is applied last on purpose: where both define the
            // same file, the server variant is the one that must win.
            overrideDirs: ['overrides', 'server-overrides'],
            minecraftVersion: $version['dependencies']['minecraft'] ?? ($version['game_versions'][0] ?? null),
            loader: $loader,
            loaderVersion: $loaderVersion,
        );
    }

    /**
     * Mods listed in modrinth.index.json, with the client-only ones dropped.
     *
     * `env.server` is the pack author's own statement about each file. Shipping
     * the "unsupported" ones is not a cosmetic mistake — client-only mods crash
     * a server on boot. Anything not marked is required, per the format.
     */
    public function manifestFiles(string $indexContents): array
    {
        $index = json_decode($indexContents, true);

        if (!is_array($index)) {
            throw new \RuntimeException('modrinth.index.json could not be read.');
        }

        $files = [];

        foreach ($index['files'] ?? [] as $file) {
            if (($file['env']['server'] ?? 'required') === 'unsupported') {
                continue;
            }

            $path = $file['path'] ?? null;
            $url = $file['downloads'][0] ?? null;

            if (!is_string($path) || !is_string($url) || $path === '' || $url === '') {
                continue;
            }

            // A path is relative to the server root by the format's definition;
            // refuse anything trying to climb out of it.
            if (str_starts_with($path, '/') || str_contains($path, '..')) {
                continue;
            }

            $sha1 = $file['hashes']['sha1'] ?? null;

            $files[] = [
                'path' => $path,
                'url' => $url,
                'sha1' => is_string($sha1) && $sha1 !== '' ? $sha1 : null,
            ];
        }

        return $files;
    }
}
