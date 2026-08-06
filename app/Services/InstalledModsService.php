<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpacks\Services;

use Throwable;
use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;

class InstalledModsService
{
    private const STATE_FILE = '.modpacks-mods-state.json';
    private const STATE_VERSION = 2;
    private const MAX_HASH_BYTES = 67108864;
    private const LOOKUP_TIMEOUT = 10;
    private const CURSEFORGE_GAME_MINECRAFT = 432;

    /**
     * Wall-clock budget for one call to list(), covering only the part that
     * scales with how many mods are unidentified: reading each one's full jar
     * through Wings (a network round trip per file, there is no bulk-content
     * endpoint) and hashing it. A single `mods/` folder for a pack the size of
     * All the Mods 10 holds a few hundred jars, and doing that for every one of
     * them in one request is exactly what blew past PHP's 30s execution limit
     * on the first scan after an install — the one moment every mod is
     * unrecognised at once.
     *
     * Kept short enough to stay well clear of that limit with margin for the
     * bulk provider lookups afterwards, which run once per call, not once per
     * mod. Whatever does not fit in the budget is reported back as still
     * pending — see the `pending_scan` handling in list() — rather than
     * skipped or guessed at.
     */
    private const SCAN_TIME_BUDGET_SECONDS = 15.0;

    public function __construct(
        private ModpackSettings $settings,
        private DaemonFileRepository $fileRepository,
    ) {
    }

    /**
     * @return array{mods: array[], scanning: bool} `scanning` is true when the
     *         time budget ran out before every mod could be identified — the
     *         caller is expected to call again, which resumes rather than
     *         restarts, since only what was actually identified is cached.
     */
    public function list(Server $server): array
    {
        $repository = $this->fileRepository->setServer($server);
        $entries = $this->modEntries($repository);
        $state = $this->readState($repository);
        $nextState = [];
        $modsByPath = [];
        $pending = [];

        foreach ($entries as $entry) {
            $path = 'mods/' . $entry['name'];
            $signature = $this->signature($entry);
            $cached = $state[$path] ?? null;

            if (is_array($cached) && ($cached['signature'] ?? null) === $signature && $this->isReusable($cached)) {
                $modsByPath[$path] = $cached['mod'];
                $nextState[$path] = $cached;
                continue;
            }

            $pending[] = ['path' => $path, 'entry' => $entry, 'signature' => $signature];
        }

        [$identified, $unreached] = $this->identifyMany($repository, $pending);

        foreach ($identified as $path => $mod) {
            $modsByPath[$path] = $mod;
            $nextState[$path] = [
                'version' => self::STATE_VERSION,
                'signature' => $mod['_signature'],
                'mod' => $this->withoutInternalKeys($mod),
            ];
        }

        // Deliberately absent from $nextState: caching a placeholder would
        // make the next call believe this mod is done rather than resuming it.
        foreach ($unreached as $item) {
            $modsByPath[$item['path']] = $this->baseMod($item['path'], $item['entry'], 'pending_scan')
                + ['_signature' => $item['signature']];
        }

        $this->writeState($repository, $nextState);

        return [
            'mods' => array_values(array_map(
                fn (array $entry) => $this->withoutInternalKeys($modsByPath['mods/' . $entry['name']]),
                $entries,
            )),
            'scanning' => $unreached !== [],
        ];
    }

    private function baseMod(string $path, array $entry, string $reason): array
    {
        return [
            'path' => $path,
            'provider' => null,
            'project_id' => null,
            'project_name' => pathinfo($entry['name'], PATHINFO_FILENAME),
            'version_id' => null,
            'version_name' => $entry['name'],
            'icon_url' => null,
            'size' => $entry['size'] ?? null,
            'recognized' => false,
            'reason' => $reason,
        ];
    }

    /**
     * @param array<int, array{path: string, entry: array, signature: string}> $pending
     *
     * @return array{0: array[], 1: array<int, array{path: string, entry: array, signature: string}>}
     *         the identified mods, keyed by path, and whichever $pending items
     *         the time budget did not reach — untouched, not half-processed,
     *         so they are exactly what the next call should retry
     */
    private function identifyMany(DaemonFileRepository $repository, array $pending): array
    {
        $hashed = [];
        $mods = [];
        $unreached = [];
        $deadline = microtime(true) + self::SCAN_TIME_BUDGET_SECONDS;

        foreach ($pending as $index => $item) {
            // Checked before any work on this item starts, so a stopped item
            // is one the next call retries from scratch — never one read
            // through Wings but then abandoned before hashing. Breaking
            // rather than returning here matters: items already hashed into
            // $hashed by earlier iterations still need the bulk provider
            // lookup below to become real results, and returning early from
            // inside this loop would strand them there, unidentified, even
            // though the (slow) part of their work was already done.
            if (microtime(true) >= $deadline) {
                $unreached = array_slice($pending, $index);
                break;
            }

            $path = $item['path'];
            $entry = $item['entry'];

            if (($entry['size'] ?? 0) > self::MAX_HASH_BYTES) {
                $mods[$path] = $this->baseMod($path, $entry, 'too_large') + ['_signature' => $item['signature']];
                continue;
            }

            try {
                $contents = $repository->getContent('/' . $path);
            } catch (DaemonConnectionException $exception) {
                Log::notice('modpacks: installed mod could not be read for fingerprinting', [
                    'path' => $path,
                    'message' => $exception->getMessage(),
                ]);

                $mods[$path] = $this->baseMod($path, $entry, 'unreadable') + ['_signature' => $item['signature']];
                continue;
            }

            $hashed[$path] = [
                'entry' => $entry,
                'signature' => $item['signature'],
                'sha1' => sha1($contents),
                'fingerprint' => $this->curseForgeFingerprint($contents),
            ];
            unset($contents);
        }

        $modrinth = $this->identifyModrinthMany(array_column($hashed, 'sha1'));
        $curseforge = $this->identifyCurseForgeMany(array_column($hashed, 'fingerprint'));

        foreach ($hashed as $path => $item) {
            $mod = $modrinth[$item['sha1']] ?? $curseforge[$item['fingerprint']] ?? null;

            if ($mod === null) {
                $mod = $this->baseMod($path, $item['entry'], 'unknown') + [
                    'sha1' => $item['sha1'],
                    'fingerprint' => $item['fingerprint'],
                ];
            } else {
                $mod = $mod + [
                    'path' => $path,
                    'size' => $item['entry']['size'] ?? null,
                    'recognized' => true,
                ];
            }

            $mods[$path] = $mod + ['_signature' => $item['signature']];
        }

        return [$mods, $unreached];
    }

    private function withoutInternalKeys(array $mod): array
    {
        unset($mod['_signature']);

        return $mod;
    }

    private function isReusable(array $cached): bool
    {
        $mod = $cached['mod'] ?? null;

        return ($cached['version'] ?? null) === self::STATE_VERSION
            && is_array($mod)
            && ($mod['reason'] ?? null) !== 'pending_scan';
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

    /** @param string[] $sha1s */
    private function identifyModrinthMany(array $sha1s): array
    {
        $sha1s = array_values(array_unique(array_filter($sha1s)));

        if ($sha1s === []) {
            return [];
        }

        try {
            $versions = Http::withHeaders([
                'User-Agent' => 'pterodactyl-modpacks/0.1.0 (your-contact@example.com)',
            ])
                ->timeout(self::LOOKUP_TIMEOUT)
                ->post('https://api.modrinth.com/v2/version_files', [
                    'hashes' => $sha1s,
                    'algorithm' => 'sha1',
                ])
                ->throw()
                ->json();
        } catch (Throwable $exception) {
            Log::debug('modpacks: bulk Modrinth mod lookup failed', [
                'message' => $exception->getMessage(),
            ]);

            return [];
        }

        if (!is_array($versions)) {
            return [];
        }

        $projectIds = [];
        foreach ($versions as $version) {
            if (is_array($version) && !empty($version['project_id'])) {
                $projectIds[] = $version['project_id'];
            }
        }

        $projects = $this->modrinthProjects($projectIds);
        $mods = [];

        foreach ($versions as $sha1 => $version) {
            if (!is_array($version)) {
                continue;
            }

            $projectId = $version['project_id'] ?? null;
            if (!is_string($projectId) || $projectId === '') {
                continue;
            }

            $project = $projects[$projectId] ?? [];

            $mods[$sha1] = [
                'provider' => 'modrinth',
                'project_id' => $projectId,
                'project_name' => $project['title'] ?? $projectId,
                'version_id' => $version['id'] ?? null,
                'version_name' => $version['version_number'] ?? $version['name'] ?? $sha1,
                'icon_url' => $project['icon_url'] ?? null,
            ];
        }

        return $mods;
    }

    /** @param string[] $projectIds */
    private function modrinthProjects(array $projectIds): array
    {
        $projectIds = array_values(array_unique(array_filter($projectIds)));

        if ($projectIds === []) {
            return [];
        }

        try {
            $projects = Http::withHeaders([
                'User-Agent' => 'pterodactyl-modpacks/0.1.0 (your-contact@example.com)',
            ])
                ->timeout(self::LOOKUP_TIMEOUT)
                ->get('https://api.modrinth.com/v2/projects', [
                    'ids' => json_encode($projectIds),
                ])
                ->throw()
                ->json();
        } catch (Throwable $exception) {
            Log::debug('modpacks: bulk Modrinth project lookup failed', [
                'message' => $exception->getMessage(),
            ]);

            return [];
        }

        $byId = [];
        foreach ($projects ?? [] as $project) {
            if (is_array($project) && !empty($project['id'])) {
                $byId[$project['id']] = $project;
            }
        }

        return $byId;
    }

    /** @param int[] $fingerprints */
    private function identifyCurseForgeMany(array $fingerprints): array
    {
        $key = $this->settings->curseForgeApiKey();

        if (empty($key)) {
            return [];
        }

        $fingerprints = array_values(array_unique(array_filter($fingerprints, fn ($fingerprint) => is_int($fingerprint))));

        if ($fingerprints === []) {
            return [];
        }

        try {
            $response = Http::withHeaders(['x-api-key' => $key])
                ->timeout(self::LOOKUP_TIMEOUT)
                ->post('https://api.curseforge.com/v1/fingerprints/' . self::CURSEFORGE_GAME_MINECRAFT, [
                    'fingerprints' => $fingerprints,
                ])
                ->throw()
                ->json();
        } catch (Throwable $exception) {
            Log::debug('modpacks: bulk CurseForge fingerprint lookup failed', [
                'message' => $exception->getMessage(),
            ]);

            return [];
        }

        $matches = $response['data']['exactMatches'] ?? [];
        $projectIds = [];
        foreach ($matches as $match) {
            $file = $match['file'] ?? [];
            if (!empty($file['modId'])) {
                $projectIds[] = (string) $file['modId'];
            }
        }

        $projects = $this->curseForgeProjects($projectIds);
        $mods = [];

        foreach ($matches as $match) {
            if (!is_array($match)) {
                continue;
            }

            $file = $match['file'] ?? [];
            $projectId = (string) ($file['modId'] ?? '');
            $fingerprint = $file['fileFingerprint'] ?? $match['fileFingerprint'] ?? null;

            if ($projectId === '' || $fingerprint === null) {
                continue;
            }

            $project = $projects[$projectId] ?? [];

            $mods[(int) $fingerprint] = [
                'provider' => 'curseforge',
                'project_id' => $projectId,
                'project_name' => $project['name'] ?? $projectId,
                'version_id' => isset($file['id']) ? (string) $file['id'] : null,
                'version_name' => $file['displayName'] ?? $file['fileName'] ?? (string) $fingerprint,
                'icon_url' => $project['logo']['thumbnailUrl'] ?? null,
            ];
        }

        return $mods;
    }

    /** @param string[] $projectIds */
    private function curseForgeProjects(array $projectIds): array
    {
        $key = $this->settings->curseForgeApiKey();

        if (empty($key)) {
            return [];
        }

        $projectIds = array_values(array_unique(array_filter($projectIds)));

        if ($projectIds === []) {
            return [];
        }

        try {
            $projects = Http::withHeaders(['x-api-key' => $key])
                ->timeout(self::LOOKUP_TIMEOUT)
                ->post('https://api.curseforge.com/v1/mods', [
                    'modIds' => array_map('intval', $projectIds),
                ])
                ->throw()
                ->json()['data'] ?? [];
        } catch (Throwable $exception) {
            Log::debug('modpacks: bulk CurseForge project lookup failed', [
                'message' => $exception->getMessage(),
            ]);

            return [];
        }

        $byId = [];
        foreach ($projects as $project) {
            if (is_array($project) && isset($project['id'])) {
                $byId[(string) $project['id']] = $project;
            }
        }

        return $byId;
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
