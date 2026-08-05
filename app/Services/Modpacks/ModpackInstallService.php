<?php

namespace Pterodactyl\Services\Modpacks;

use Throwable;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Repositories\Wings\DaemonPowerRepository;
use Pterodactyl\Services\Servers\ReinstallServerService;
use Pterodactyl\Services\Servers\StartupModificationService;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;

/**
 * Dispatches a modpack install. Does not perform one.
 *
 * The panel's entire job here is to stop the server, point it at the installer
 * egg with the right variables, and ask Wings to reinstall. Everything after
 * that — downloads, loader installation, writing the startup command — happens
 * inside the install container, driven by egg/install.sh.
 *
 * This split is deliberate and load-bearing. Doing the work from PHP would mean
 * an HTTP request held open for minutes on a large pack, with no way to show
 * progress; running it as an install script gives the user a live log in the
 * server console for free and makes reinstall behave exactly as they expect.
 * If you are about to reach for DaemonFileRepository::pull() here, read the
 * architecture section of CLAUDE.md first.
 */
class ModpackInstallService
{
    /**
     * Java major -> image, restricted to what the installer egg declares in its
     * docker_images map. Picking an image the egg does not offer is rejected.
     */
    private const JAVA_IMAGES = [
        21 => 'ghcr.io/pterodactyl/yolks:java_21',
        17 => 'ghcr.io/pterodactyl/yolks:java_17',
        8 => 'ghcr.io/pterodactyl/yolks:java_8',
    ];

    public function __construct(
        private ProviderRegistry $registry,
        private DaemonPowerRepository $powerRepository,
        private StartupModificationService $startupModificationService,
        private ReinstallServerService $reinstallServerService,
    ) {
    }

    /**
     * @throws \Pterodactyl\Exceptions\DisplayException
     */
    public function handle(
        Server $server,
        string $providerKey,
        string $packId,
        string $versionId,
        bool $wipe = true,
    ): Server {
        $provider = $this->registry->get($providerKey);
        $eggId = (int) config('modpacks.installer_egg_id');

        if ($eggId < 1) {
            throw new DisplayException(
                'The modpack installer egg has not been configured on this panel. An administrator '
                . 'needs to import egg/modpack-installer.json and set its id in config/modpacks.php.'
            );
        }

        $environment = [
            'MODPACK_PROVIDER' => $provider->key(),
            'MODPACK_ID' => $packId,
            'MODPACK_VERSION' => $versionId,
            'WIPE_EXISTING' => $wipe ? '1' : '0',
        ];

        // The key is written into the server's environment because install.sh
        // runs in a container that has no access to the panel's config. The egg
        // marks the variable user_viewable: false so it stays out of the UI.
        if ($provider->key() === 'curseforge') {
            $key = (string) config('modpacks.curseforge_api_key');

            if ($key === '') {
                throw new DisplayException(
                    'CurseForge is not configured on this panel, so CurseForge packs cannot be installed. '
                    . 'An administrator needs to add an API key.'
                );
            }

            $environment['CURSEFORGE_API_KEY'] = $key;
        }

        $this->killServer($server);

        // Admin level: switching the egg and writing variables the user is not
        // allowed to edit are both privileged operations, and this request has
        // already been authorised against startup.update and file.delete.
        $server = $this->startupModificationService
            ->setUserLevel(User::USER_LEVEL_ADMIN)
            ->handle($server, [
                'egg_id' => $eggId,
                'docker_image' => $this->resolveDockerImage($provider, $packId, $versionId),
                'environment' => $environment,
                'skip_scripts' => false,
            ]);

        return $this->reinstallServerService->handle($server);
    }

    /**
     * Kill the server before its filesystem is rewritten underneath it.
     *
     * A server that is already stopped makes Wings answer with an error, which
     * is not a failure of the install — the goal state (not running) is the one
     * we wanted. Anything else is worth a log line but still is not worth
     * aborting for: the reinstall stops the container regardless.
     */
    private function killServer(Server $server): void
    {
        try {
            $this->powerRepository->setServer($server)->send('kill');
        } catch (DaemonConnectionException $exception) {
            Log::debug('modpacks: kill before install was refused, continuing', [
                'server' => $server->uuid,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Choose a Java image for the pack's Minecraft version.
     *
     * The server is very likely coming from an egg with an unrelated image, and
     * the wrong Java major is one of the more confusing ways for a modpack to
     * fail — it boots, then dies on a class file version error. The provider
     * already reports a game version per Version, and versions() is cached, so
     * this costs nothing on the common path.
     *
     * Metadata lookups are best-effort: a provider hiccup here should not sink
     * an install the user has already confirmed. Java 21 is the fallback, being
     * the only one that runs current packs at all.
     */
    private function resolveDockerImage(ProviderInterface $provider, string $packId, string $versionId): string
    {
        $gameVersion = null;

        try {
            foreach ($provider->versions($packId) as $version) {
                if (($version['id'] ?? null) === $versionId) {
                    $gameVersion = $version['gameVersion'] ?? null;
                    break;
                }
            }
        } catch (Throwable $exception) {
            Log::notice('modpacks: could not resolve a game version, defaulting the Java image', [
                'provider' => $provider->key(),
                'pack' => $packId,
                'message' => $exception->getMessage(),
            ]);
        }

        return self::JAVA_IMAGES[$this->javaMajorFor($gameVersion)];
    }

    /**
     * Minecraft version -> required Java major.
     *
     * 1.20.5 is the cutover to 21, 1.17 the cutover to 17. Everything older
     * runs on 8. Unrecognised input gets the newest image rather than the
     * oldest: a modern pack on Java 8 cannot start, whereas the reverse at
     * least gets far enough to produce a legible error.
     */
    private function javaMajorFor(?string $gameVersion): int
    {
        if ($gameVersion === null || !preg_match('/^1\.(\d+)(?:\.(\d+))?/', $gameVersion, $matches)) {
            return 21;
        }

        $minor = (int) $matches[1];
        $patch = (int) ($matches[2] ?? 0);

        return match (true) {
            $minor > 20 => 21,
            $minor === 20 => $patch >= 5 ? 21 : 17,
            $minor >= 17 => 17,
            default => 8,
        };
    }
}
