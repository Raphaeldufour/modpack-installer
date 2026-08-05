<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpacks\Services;

use Throwable;
use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;

class InstalledModsService
{
    private const STATE_FILE = '.modpacks-mods-state.json';
    private const MAX_HASH_BYTES = 67108864;
    private const CURSEFORGE_GAME_MINECRAFT = 432;

    public function __construct(
        private ModpackSettings $settings,
        private DaemonFileRepository $fileRepository,
    ) {
    }

    /** @return array[] */
    public function list(Server $server): array
    {
        $repository = $this->fileRepository->setServer($server);
        $entries = $this->modEntries($repository);
        $state = $this->readState($repository);
        $nextState = [];
        $mods = [];

        foreach ($entries as $entry) {
            $path = 'mods/' . $entry['name'];
            $signature = $this->signature($entry);
            $cached = $state[$path] ?? null;

            if (is_array($cached) && ($cached['signature'] ?? null) === $signature) {
                $mods[] = $cached['mod'];
                $nextState[$path] = $cached;
                continue;
            }

            $mod = $this->identify($repository, $path, $entry);
            $mods[] = $mod;
            $nextState[$path] = [
                'signature' => $signature,
                'mod' => $mod,
            ];
        }

        $this->writeState($repository, $nextState);

        return $mods;
    }

    /** @return array<int, array{name: string, size: int|null, modified: string|null}> */
    private function modEntries(DaemonFileRepository $repository): array
    {
        try {
            $entries = $repository->getDirectory('/mods');
        } catch (DaemonConnectionException $exception) {
            Log::debug('modpacks: mods directory could not be listed', [
                'message' => $exception->getMessage(),
            ]);

            return [];
        }

        return collect($entries)
            ->filter(fn ($entry) => $this->isJar($entry))
            ->map(fn ($entry) => [
                'name' => $entry['name'],
                'size' => $entry['size'] ?? null,
                'modified' => $entry['modified_at'] ?? $entry['modifiedAt'] ?? null,
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    private function isJar(array $entry): bool
    {
        $name = $entry['name'] ?? null;

        if (!is_string($name) || !str_ends_with(strtolower($name), '.jar')) {
            return false;
        }

        if (array_key_exists('is_file', $entry) && !$entry['is_file']) {
            return false;
        }

        return true;
    }

    private function identify(DaemonFileRepository $repository, string $path, array $entry): array
    {
        $base = [
            'path' => $path,
            'provider' => null,
            'project_id' => null,
            'project_name' => pathinfo($entry['name'], PATHINFO_FILENAME),
            'version_id' => null,
            'version_name' => $entry['name'],
            'icon_url' => null,
            'size' => $entry['size'] ?? null,
            'recognized' => false,
        ];

        if (($entry['size'] ?? 0) > self::MAX_HASH_BYTES) {
            return $base + ['reason' => 'too_large'];
        }

        try {
            $contents = $repository->getContent('/' . $path);
        } catch (DaemonConnectionException $exception) {
            Log::notice('modpacks: installed mod could not be read for fingerprinting', [
                'path' => $path,
                'message' => $exception->getMessage(),
            ]);

            return $base + ['reason' => 'unreadable'];
        }

        $sha1 = sha1($contents);
        $murmur = $this->curseForgeFingerprint($contents);
        unset($contents);

        $identified = $this->identifyModrinth($path, $sha1)
            ?? $this->identifyCurseForge($path, $murmur);

        if ($identified !== null) {
            return $identified + ['size' => $entry['size'] ?? null, 'recognized' => true];
        }

        return $base + [
            'reason' => 'unknown',
            'sha1' => $sha1,
            'fingerprint' => $murmur,
        ];
    }

    private function identifyModrinth(string $path, string $sha1): ?array
    {
        try {
            $version = Cache::remember("modpacks:mods:installed:modrinth:$sha1", 86400, fn () => Http::withHeaders([
                'User-Agent' => 'pterodactyl-modpacks/0.1.0 (your-contact@example.com)',
            ])
                ->timeout(15)
                ->get("https://api.modrinth.com/v2/version_file/{$sha1}", ['algorithm' => 'sha1'])
                ->throw()
                ->json());
        } catch (Throwable $exception) {
            return null;
        }

        $projectId = $version['project_id'] ?? null;
        if (!is_string($projectId) || $projectId === '') {
            return null;
        }

        $project = $this->modrinthProject($projectId);

        return [
            'path' => $path,
            'provider' => 'modrinth',
            'project_id' => $projectId,
            'project_name' => $project['title'] ?? $projectId,
            'version_id' => $version['id'] ?? null,
            'version_name' => $version['version_number'] ?? $version['name'] ?? basename($path),
            'icon_url' => $project['icon_url'] ?? null,
        ];
    }

    private function modrinthProject(string $projectId): array
    {
        try {
            return Cache::remember("modpacks:mods:installed:modrinth:project:$projectId", 86400, fn () => Http::withHeaders([
                'User-Agent' => 'pterodactyl-modpacks/0.1.0 (your-contact@example.com)',
            ])
                ->timeout(15)
                ->get("https://api.modrinth.com/v2/project/{$projectId}")
                ->throw()
                ->json());
        } catch (Throwable $exception) {
            return [];
        }
    }

    private function identifyCurseForge(string $path, int $fingerprint): ?array
    {
        $key = $this->settings->curseForgeApiKey();

        if (empty($key)) {
            return null;
        }

        try {
            $match = Cache::remember("modpacks:mods:installed:curseforge:$fingerprint", 86400, function () use ($key, $fingerprint) {
                $response = Http::withHeaders(['x-api-key' => $key])
                    ->timeout(15)
                    ->post('https://api.curseforge.com/v1/fingerprints/' . self::CURSEFORGE_GAME_MINECRAFT, [
                        'fingerprints' => [$fingerprint],
                    ])
                    ->throw()
                    ->json();

                return $response['data']['exactMatches'][0] ?? null;
            });
        } catch (Throwable $exception) {
            return null;
        }

        if (!is_array($match)) {
            return null;
        }

        $file = $match['file'] ?? [];
        $projectId = (string) ($file['modId'] ?? $match['id'] ?? '');

        if ($projectId === '') {
            return null;
        }

        $project = $this->curseForgeProject($projectId);

        return [
            'path' => $path,
            'provider' => 'curseforge',
            'project_id' => $projectId,
            'project_name' => $project['name'] ?? $projectId,
            'version_id' => isset($file['id']) ? (string) $file['id'] : null,
            'version_name' => $file['displayName'] ?? $file['fileName'] ?? basename($path),
            'icon_url' => $project['logo']['thumbnailUrl'] ?? null,
        ];
    }

    private function curseForgeProject(string $projectId): array
    {
        $key = $this->settings->curseForgeApiKey();

        if (empty($key)) {
            return [];
        }

        try {
            return Cache::remember("modpacks:mods:installed:curseforge:project:$projectId", 86400, fn () => Http::withHeaders([
                'x-api-key' => $key,
            ])
                ->timeout(15)
                ->get("https://api.curseforge.com/v1/mods/{$projectId}")
                ->throw()
                ->json()['data'] ?? []);
        } catch (Throwable $exception) {
            return [];
        }
    }

    private function curseForgeFingerprint(string $contents): int
    {
        $normalizedLength = 0;
        $contentsLength = strlen($contents);

        for ($i = 0; $i < $contentsLength; $i++) {
            if (!$this->isCurseForgeWhitespace(ord($contents[$i]))) {
                $normalizedLength++;
            }
        }

        $remaining = $normalizedLength;
        $hash = 1 ^ $normalizedLength;
        $chunk = [];

        for ($i = 0; $i < $contentsLength; $i++) {
            $byte = ord($contents[$i]);
            if ($this->isCurseForgeWhitespace($byte)) {
                continue;
            }

            $chunk[] = $byte;

            if (count($chunk) === 4) {
                $k = $chunk[0]
                    | ($chunk[1] << 8)
                    | ($chunk[2] << 16)
                    | ($chunk[3] << 24);

                $k = ($k * 0x5bd1e995) & 0xffffffff;
                $k ^= $this->unsignedRightShift($k, 24);
                $k = ($k * 0x5bd1e995) & 0xffffffff;

                $hash = (($hash * 0x5bd1e995) & 0xffffffff) ^ $k;
                $chunk = [];
                $remaining -= 4;
            }
        }

        switch ($remaining) {
            case 3:
                $hash ^= $chunk[2] << 16;
            case 2:
                $hash ^= $chunk[1] << 8;
            case 1:
                $hash ^= $chunk[0];
                $hash = ($hash * 0x5bd1e995) & 0xffffffff;
        }

        $hash ^= $this->unsignedRightShift($hash, 13);
        $hash = ($hash * 0x5bd1e995) & 0xffffffff;
        $hash ^= $this->unsignedRightShift($hash, 15);

        return $hash & 0xffffffff;
    }

    private function isCurseForgeWhitespace(int $byte): bool
    {
        return $byte === 9 || $byte === 10 || $byte === 13 || $byte === 32;
    }

    private function unsignedRightShift(int $value, int $shift): int
    {
        return ($value & 0xffffffff) >> $shift;
    }

    private function signature(array $entry): string
    {
        return md5(($entry['name'] ?? '') . '|' . ($entry['size'] ?? '') . '|' . ($entry['modified'] ?? ''));
    }

    private function readState(DaemonFileRepository $repository): array
    {
        if (!$this->fileExists($repository, '/', self::STATE_FILE)) {
            return [];
        }

        try {
            $state = json_decode($repository->getContent('/' . self::STATE_FILE), true);
        } catch (Throwable $exception) {
            Log::debug('modpacks: installed mods state could not be read', [
                'message' => $exception->getMessage(),
            ]);

            return [];
        }

        return is_array($state) ? $state : [];
    }

    private function fileExists(DaemonFileRepository $repository, string $directory, string $filename): bool
    {
        try {
            $entries = $repository->getDirectory($directory);
        } catch (Throwable $exception) {
            return false;
        }

        foreach ($entries as $entry) {
            if (($entry['name'] ?? null) === $filename) {
                return true;
            }
        }

        return false;
    }

    private function writeState(DaemonFileRepository $repository, array $state): void
    {
        try {
            $repository->putContent(self::STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (DaemonConnectionException $exception) {
            Log::debug('modpacks: installed mods state could not be written', [
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
