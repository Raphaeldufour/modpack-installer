<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpacks\Services;

use Throwable;
use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;

/**
 * What the last install through this extension put on a server.
 *
 * Nothing tracks this otherwise: the panel's own `egg`/`docker_image` columns
 * describe the container, not which Minecraft version or modpack is actually
 * running inside it, and both `ModpackInstallService` and `VersionInstallService`
 * change that without a reinstall — the one place that would normally count as
 * an event worth recording.
 *
 * Follows the same shape `InstalledModsService` uses for its own state file:
 * a small JSON document on the server's own volume, read with `getContent` and
 * written with `putContent`. It is bookkeeping, not the install itself, so a
 * write failure here is logged and swallowed rather than allowed to fail an
 * install that otherwise succeeded.
 */
class InstalledStateService
{
    private const STATE_FILE = '.modpacks-state.json';

    public function __construct(private DaemonFileRepository $fileRepository)
    {
    }

    /**
     * @return array|null the last recorded install, or null if none has
     *                     happened through this extension yet
     */
    public function read(Server $server): ?array
    {
        $repository = $this->fileRepository->setServer($server);

        if (!$this->fileExists($repository)) {
            return null;
        }

        try {
            $state = json_decode($repository->getContent('/' . self::STATE_FILE), true);
        } catch (Throwable $exception) {
            Log::debug('modpacks: installed state could not be read', [
                'server' => $server->uuid,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        return is_array($state) ? $state : null;
    }

    public function recordSoftware(
        Server $server,
        string $softwareKey,
        string $softwareLabel,
        string $minecraftVersion,
        ?string $build,
    ): void {
        $this->write($server, [
            'type' => 'software',
            'software' => $softwareKey,
            'softwareLabel' => $softwareLabel,
            'minecraftVersion' => $minecraftVersion,
            'build' => $build,
        ]);
    }

    /**
     * $packName and $versionName are cosmetic only — display labels the
     * frontend already has from the search it just did, passed through rather
     * than re-fetched from the provider. Nothing here treats them as anything
     * but text to show back; the ids are what every other operation still
     * keys off.
     */
    public function recordModpack(
        Server $server,
        string $providerKey,
        string $providerLabel,
        string $packId,
        ?string $packName,
        string $versionId,
        ?string $versionName,
        ?string $minecraftVersion,
        ?string $loader,
        ?string $loaderVersion,
    ): void {
        $this->write($server, [
            'type' => 'modpack',
            'provider' => $providerKey,
            'providerLabel' => $providerLabel,
            'packId' => $packId,
            'packName' => $packName,
            'versionId' => $versionId,
            'versionName' => $versionName,
            'minecraftVersion' => $minecraftVersion,
            'loader' => $loader,
            'loaderVersion' => $loaderVersion,
        ]);
    }

    private function write(Server $server, array $state): void
    {
        $state['installedAt'] = now()->toIso8601String();

        try {
            $this->fileRepository->setServer($server)->putContent(
                self::STATE_FILE,
                json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            );
        } catch (DaemonConnectionException $exception) {
            Log::notice('modpacks: installed state could not be written', [
                'server' => $server->uuid,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function fileExists(DaemonFileRepository $repository): bool
    {
        try {
            $entries = $repository->getDirectory('/');
        } catch (Throwable $exception) {
            return false;
        }

        foreach ($entries as $entry) {
            if (($entry['name'] ?? null) === self::STATE_FILE) {
                return true;
            }
        }

        return false;
    }
}
