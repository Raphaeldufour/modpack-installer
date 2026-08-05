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

    private function safeFilename(string $filename): string
    {
        $basename = basename(str_replace('\\', '/', $filename));

        if ($basename === '' || !str_ends_with(strtolower($basename), '.jar')) {
            return 'mod-' . bin2hex(random_bytes(4)) . '.jar';
        }

        return $basename;
    }
}
