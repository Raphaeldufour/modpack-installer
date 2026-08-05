<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpacks\Services\Versions;

use Throwable;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Repositories\Wings\DaemonPowerRepository;
use Pterodactyl\Services\Servers\StartupModificationService;
use Pterodactyl\BlueprintFramework\Extensions\modpacks\Services\JavaVersion;
use Pterodactyl\BlueprintFramework\Extensions\modpacks\Services\RemoteFile;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;

/**
 * Swaps the server jar, without reinstalling the server.
 *
 * A version change is one jar. Wings fetches it onto the volume itself, so the
 * panel's part is a single short API call, and nothing else on the server is
 * touched: worlds, configs, plugins and mods all survive.
 */
class VersionInstallService
{
    /**
     * Memory is expressed as a percentage of the container's limit rather than
     * a fixed -Xmx.
     *
     * It tracks a limit changed in the panel with no reinstall and no rewrite,
     * and it is correct for a server with no limit at all — where
     * -Xmx{{SERVER_MEMORY}}M expands to -Xmx0M and the JVM refuses to start.
     * 95% matches what the maintained upstream NeoForge egg ships.
     */
    private const STARTUP = 'java -Xms128M -XX:MaxRAMPercentage=95.0 '
        . '-Dterminal.jline=false -Dterminal.ansi=true -jar %s nogui';

    public function __construct(
        private SoftwareRegistry $registry,
        private DaemonFileRepository $fileRepository,
        private DaemonPowerRepository $powerRepository,
        private StartupModificationService $startupModificationService,
    ) {
    }

    /**
     * @return string[] human-readable notes about what was and was not changed
     *
     * @throws \Pterodactyl\Exceptions\DisplayException
     */
    public function handle(Server $server, string $softwareKey, string $minecraftVersion, ?string $build): array
    {
        $software = $this->registry->get($softwareKey);

        try {
            $download = $software->resolve($minecraftVersion, $build);
        } catch (DisplayException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('modpacks: could not resolve a server jar', [
                'software' => $softwareKey,
                'exception' => $exception,
            ]);

            throw new DisplayException(sprintf(
                '%s could not provide a download for Minecraft %s: %s',
                $software->label(),
                $minecraftVersion,
                $exception->getMessage(),
            ));
        }

        $this->killServer($server);

        // Wings downloads onto the volume; the file never passes through the
        // panel. Foreground so a failure is reported here rather than vanishing
        // into a background job the user cannot see.
        try {
            // Wings' downloader treats a redirect as a failure, and Purpur (among
            // others) serves its jar through one.
            $this->fileRepository->setServer($server)->pull(RemoteFile::resolve($download->url), '/', [
                'filename' => $download->filename,
                'foreground' => true,
            ]);
        } catch (DaemonConnectionException $exception) {
            throw new DisplayException(
                'Wings could not download the server jar: ' . $exception->getMessage()
            );
        }

        $notes = [];
        $data = ['startup' => sprintf(self::STARTUP, $download->filename)];

        // The image has to be one the server's own egg declares, so when the egg
        // has nothing for the Java this version needs, say so rather than
        // writing an image the panel will reject or that cannot run the jar.
        $major = JavaVersion::majorFor($minecraftVersion);
        $image = JavaVersion::imageFor($server, $major);

        if ($image !== null) {
            $data['docker_image'] = $image;
        } else {
            $notes[] = "This server's egg offers no Java {$major} image, so the image was left alone. "
                . "Minecraft {$minecraftVersion} needs Java {$major}; change it under the server's Startup tab.";
        }

        $this->startupModificationService
            ->setUserLevel(User::USER_LEVEL_ADMIN)
            ->handle($server, $data);

        return $notes;
    }

    /**
     * A server that is already stopped makes Wings answer with an error, which
     * is not a failure — the goal state is the one we wanted. Overwriting a jar
     * under a running JVM is the thing worth avoiding.
     */
    private function killServer(Server $server): void
    {
        try {
            $this->powerRepository->setServer($server)->send('kill');
        } catch (DaemonConnectionException $exception) {
            Log::debug('modpacks: kill before a version change was refused, continuing', [
                'server' => $server->uuid,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
