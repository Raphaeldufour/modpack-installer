<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpacks\Services;

use Throwable;
use Pterodactyl\Models\User;
use Pterodactyl\Models\Server;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Repositories\Wings\DaemonPowerRepository;
use Pterodactyl\Services\Servers\StartupModificationService;
use Pterodactyl\BlueprintFramework\Extensions\modpacks\Services\Versions\SoftwareRegistry;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;

/**
 * Installs a modpack onto a server, in place.
 *
 * No installer egg, and no reinstall. The server keeps whichever egg it already
 * has; the panel resolves the pack, has Wings fetch and unpack it onto the
 * volume, puts the matching server jar next to it, and rewrites the startup
 * command. That leaves the egg as what it should be — a Java container and a
 * default command — and lets Modpacks, Versions and the tabs still to come all
 * work the same way.
 *
 * The panel still downloads nothing itself. Every transfer is a URL handed to
 * Wings, which fetches it onto the volume directly, so a 400MB pack costs the
 * panel one short API call rather than a request held open for minutes.
 */
class ModpackInstallService
{
    /**
     * Loaders whose server jar can be fetched as a plain file.
     *
     * Forge and NeoForge are absent on purpose: they publish an installer that
     * has to be executed to produce a launchable server, and the panel cannot
     * run one. Packs on those loaders rely on the publisher's server pack
     * carrying the loader, which the boot script below finishes off.
     */
    private const RESOLVABLE_LOADERS = ['fabric' => 'fabric'];

    public function __construct(
        private ProviderRegistry $registry,
        private SoftwareRegistry $software,
        private DaemonFileRepository $fileRepository,
        private DaemonPowerRepository $powerRepository,
        private StartupModificationService $startupModificationService,
    ) {
    }

    /**
     * @return string[] notes worth showing the user afterwards
     *
     * @throws \Pterodactyl\Exceptions\DisplayException
     */
    public function handle(
        Server $server,
        string $providerKey,
        string $packId,
        string $versionId,
        bool $wipe = true,
    ): array {
        $provider = $this->registry->get($providerKey);

        try {
            $plan = $provider->installPlan($packId, $versionId);
        } catch (DisplayException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('modpacks: could not resolve an install plan', [
                'provider' => $providerKey,
                'pack' => $packId,
                'exception' => $exception,
            ]);

            throw new DisplayException(sprintf(
                '%s could not prepare this pack for install: %s',
                $provider->label(),
                $exception->getMessage(),
            ));
        }

        // Refuse before touching anything rather than half-installing a pack the
        // panel cannot finish. A manifest pack needs its mods fetched one at a
        // time, which is the next piece of work; saying so beats leaving a
        // server with a loader and an empty mods directory.
        if (!$plan->selfContained) {
            throw new DisplayException(
                'This pack ships a manifest rather than a ready-made server, so its mods have to be '
                . 'fetched one by one — which this version cannot do yet. Choose a version whose '
                . 'publisher provides a server pack.'
            );
        }

        $this->killServer($server);

        $notes = $wipe ? $this->wipeServerFiles($server) : [];

        $this->unpack($server, $plan->archiveUrl);

        // The point of the exercise: whatever the pack runs on, put that exact
        // server jar in place rather than trusting the egg to have done it.
        $jar = $this->installServerJar($server, $plan, $notes);

        $this->writeStartup($server, $plan, $jar, $notes);

        return $notes;
    }

    /** Fetch and unpack the archive onto the volume. */
    private function unpack(Server $server, string $url): void
    {
        $archive = '.modpack-install.zip';

        try {
            $repository = $this->fileRepository->setServer($server);

            $repository->pull($url, '/', ['filename' => $archive, 'foreground' => true]);
            $repository->decompressFile('/', $archive);
            $repository->deleteFiles('/', [$archive]);
        } catch (DaemonConnectionException $exception) {
            throw new DisplayException(
                'Wings could not download or unpack the modpack: ' . $exception->getMessage()
            );
        }
    }

    /**
     * Put the loader's server jar in place, when one can be fetched as a file.
     *
     * Returns the jar to start, or null when the pack brought its own layout and
     * the startup script has to work it out at boot.
     */
    private function installServerJar(Server $server, InstallPlan $plan, array &$notes): ?string
    {
        $loader = $plan->loader;

        if ($loader === null || !isset(self::RESOLVABLE_LOADERS[$loader])) {
            return null;
        }

        if ($plan->minecraftVersion === null) {
            $notes[] = 'The provider did not say which Minecraft version this pack targets, '
                . 'so the server jar shipped with the pack was kept.';

            return null;
        }

        try {
            $download = $this->software
                ->get(self::RESOLVABLE_LOADERS[$loader])
                ->resolve($plan->minecraftVersion, $plan->loaderVersion);

            $this->fileRepository->setServer($server)->pull($download->url, '/', [
                'filename' => $download->filename,
                'foreground' => true,
            ]);

            return $download->filename;
        } catch (Throwable $exception) {
            Log::notice("modpacks: could not install a loader jar, keeping the pack's own", [
                'loader' => $loader,
                'minecraft' => $plan->minecraftVersion,
                'message' => $exception->getMessage(),
            ]);

            $notes[] = sprintf(
                'The %s %s server jar could not be fetched (%s), so whatever the pack shipped was kept.',
                ucfirst($loader),
                $plan->minecraftVersion,
                $exception->getMessage(),
            );

            return null;
        }
    }

    /**
     * Point the server at what was just installed.
     *
     * With a resolved jar the command is exact. Otherwise the pack brought its
     * own layout — a jar, or a loader installer plus unix_args.txt — which is
     * only knowable on the server, so a small script decides at boot.
     */
    private function writeStartup(Server $server, InstallPlan $plan, ?string $jar, array &$notes): void
    {
        if ($jar !== null) {
            $startup = sprintf(
                'java -Xms128M -XX:MaxRAMPercentage=95.0 -Dterminal.jline=false '
                . '-Dterminal.ansi=true -jar %s nogui',
                $jar,
            );
        } else {
            $this->writeBootstrap($server);
            $startup = 'bash start.sh';
        }

        $data = ['startup' => $startup];

        $major = JavaVersion::majorFor($plan->minecraftVersion);
        $image = JavaVersion::imageFor($server, $major);

        if ($image !== null) {
            $data['docker_image'] = $image;
        } else {
            $notes[] = "This server's egg offers no Java {$major} image, so the image was left alone. "
                . "This pack needs Java {$major}; change it under the server's Startup tab.";
        }

        $this->startupModificationService
            ->setUserLevel(User::USER_LEVEL_ADMIN)
            ->handle($server, $data);
    }

    /**
     * A small script the server runs instead of a fixed command.
     *
     * It exists for the one job the panel genuinely cannot do: a server pack
     * that ships a Forge or NeoForge installer rather than an installed loader
     * expects something to run `--installServer` first. Doing that on the first
     * boot puts the output in the server console, where the user is already
     * looking, and needs no install container at all.
     *
     * putContent is a small write. The rule this extension observes is that the
     * panel does not move large payloads, not that it never writes a file.
     */
    private function writeBootstrap(Server $server): void
    {
        $script = <<<'SH'
#!/bin/bash
# Generated by the Modpacks extension. Rewritten on every install.
#
# Heap is resolved here rather than fixed, so changing the memory limit in the
# panel takes effect on the next start. SERVER_MEMORY is 0 when there is no
# limit at all, and the JVM refuses to start on -Xmx0M.
HEAP="-XX:MaxRAMPercentage=95.0"
if [ "${SERVER_MEMORY:-0}" -gt 0 ] 2>/dev/null; then
    HEAP="-Xms128M -Xmx${SERVER_MEMORY}M"
fi

# A server pack may ship a loader installer instead of an installed loader.
if [ ! -d libraries ] && [ ! -f run.sh ]; then
    INSTALLER=$(ls -1 ./*nstaller*.jar 2>/dev/null | head -n1 || true)
    if [ -n "$INSTALLER" ]; then
        echo "Installing the loader shipped with this pack, one time only..."
        java -jar "$INSTALLER" --installServer && rm -f "$INSTALLER"
    fi
fi

ARGS=$(ls libraries/net/neoforged/neoforge/*/unix_args.txt 2>/dev/null | head -n1 || true)
[ -n "$ARGS" ] || ARGS=$(ls libraries/net/minecraftforge/forge/*/unix_args.txt 2>/dev/null | head -n1 || true)

if [ -n "$ARGS" ]; then
    # user_jvm_args.txt is the pack's own tuning; drop only its memory bounds.
    if [ -f user_jvm_args.txt ]; then
        grep -vE '^[[:space:]]*-Xm[sx]' user_jvm_args.txt > .ujva && mv .ujva user_jvm_args.txt
    fi
    exec java $HEAP @user_jvm_args.txt @"$ARGS" nogui
fi

if [ -f run.sh ]; then
    exec ./run.sh nogui
fi

# A matchless grep exits 1; without `|| true` that would end the script here,
# which is exactly how an earlier version left servers with nothing to start.
JAR=$(ls -S ./*.jar 2>/dev/null | grep -viE 'installer|sources' | head -n1 || true)
if [ -z "$JAR" ]; then
    echo "No server jar was found, so there is nothing to start." >&2
    ls -la >&2
    exit 1
fi

exec java $HEAP -jar "$JAR" nogui
SH;

        try {
            $this->fileRepository->setServer($server)->putContent('start.sh', $script);
        } catch (DaemonConnectionException $exception) {
            throw new DisplayException(
                'Wings would not write the startup script: ' . $exception->getMessage()
            );
        }
    }

    /**
     * Clear the previous installation, keeping what a user would not forgive us
     * for deleting.
     */
    private function wipeServerFiles(Server $server): array
    {
        $keep = ['world', 'world_nether', 'world_the_end', 'server.properties', 'ops.json', 'whitelist.json'];

        try {
            $repository = $this->fileRepository->setServer($server);

            $entries = collect($repository->getDirectory('/'))
                ->pluck('name')
                ->filter(fn ($name) => is_string($name) && $name !== '' && !in_array($name, $keep, true))
                ->values()
                ->all();

            if ($entries !== []) {
                $repository->deleteFiles('/', $entries);
            }
        } catch (DaemonConnectionException $exception) {
            Log::warning('modpacks: could not clear the server before installing', [
                'server' => $server->uuid,
                'message' => $exception->getMessage(),
            ]);

            return ['The previous installation could not be cleared, so the pack was unpacked over it.'];
        }

        return [];
    }

    /**
     * A server that is already stopped makes Wings answer with an error, which
     * is not a failure — the goal state is the one we wanted.
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
}
