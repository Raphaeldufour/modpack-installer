<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpacks\Services;

use Throwable;
use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;

class ModInstallService
{
    public function __construct(
        private ModProviderRegistry $registry,
        private DaemonFileRepository $fileRepository,
    ) {
    }

    public function handle(Server $server, string $providerKey, string $modId, string $versionId): string
    {
        $provider = $this->registry->get($providerKey);

        try {
            $file = $provider->resolve($modId, $versionId);
        } catch (Throwable $exception) {
            Log::warning('modpacks: could not resolve a mod file', [
                'provider' => $providerKey,
                'mod' => $modId,
                'version' => $versionId,
                'exception' => $exception,
            ]);

            throw new DisplayException(sprintf(
                '%s could not prepare this mod for install: %s',
                $provider->label(),
                $exception->getMessage(),
            ));
        }

        $filename = $this->safeFilename($file['filename']);

        try {
            $repository = $this->fileRepository->setServer($server);

            try {
                $repository->createDirectory('mods', '/');
            } catch (DaemonConnectionException $exception) {
                Log::debug('modpacks: mods directory already exists or could not be created', [
                    'server' => $server->uuid,
                    'message' => $exception->getMessage(),
                ]);
            }

            $repository->pull(RemoteFile::resolve($file['url']), '/mods', [
                'filename' => $filename,
                'foreground' => true,
            ]);
        } catch (DaemonConnectionException $exception) {
            throw new DisplayException('Wings could not download the mod: ' . $exception->getMessage());
        }

        return $filename;
    }

    /**
     * Remove mods from the server's mods/ directory.
     *
     * The names come from the browser, so each is reduced to a basename before
     * it reaches Wings — a path is a delete target here, and `../server.jar`
     * would otherwise be a perfectly valid thing to ask for.
     *
     * The listing's state file is not touched: it is rebuilt from whatever is
     * actually in the directory on the next scan, so a removed mod drops out of
     * it on its own.
     *
     * @param string[] $filenames
     *
     * @return string[] the names actually sent for deletion
     *
     * @throws \Pterodactyl\Exceptions\DisplayException
     */
    public function delete(Server $server, array $filenames): array
    {
        $targets = [];

        foreach ($filenames as $filename) {
            if (!is_string($filename)) {
                continue;
            }

            $basename = basename(str_replace('\\', '/', $filename));

            // A leading dot would let the state file itself be deleted.
            if ($basename === '' || $basename === '.' || $basename === '..' || str_starts_with($basename, '.')) {
                continue;
            }

            // Reducing "../server.jar" to a basename already confines it to
            // mods/, but it would then delete a real file under a name nobody
            // asked for. Anything that was not already a bare name is refused
            // instead, so the request either does what it says or nothing.
            if ($basename !== $filename) {
                continue;
            }

            $targets[] = $basename;
        }

        $targets = array_values(array_unique($targets));

        if ($targets === []) {
            throw new DisplayException('No mod to delete was named.');
        }

        try {
            $this->fileRepository->setServer($server)->deleteFiles('/mods', $targets);
        } catch (DaemonConnectionException $exception) {
            throw new DisplayException('Wings could not delete the mod: ' . $exception->getMessage());
        }

        return $targets;
    }

    private function safeFilename(string $filename): string
    {
        $basename = basename(str_replace('\\', '/', $filename));

        if ($basename === '' || !str_ends_with(strtolower($basename), '.jar')) {
            return 'mod-' . bin2hex(random_bytes(4)) . '.jar';
        }

        return $basename;
    }
}
