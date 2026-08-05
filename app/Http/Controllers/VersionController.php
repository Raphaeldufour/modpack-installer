<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpacks\Http\Controllers;

use Throwable;
use Illuminate\Http\Request;
use Pterodactyl\Models\Server;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Models\Permission;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\BlueprintFramework\Extensions\modpacks\Services\Versions\SoftwareRegistry;
use Pterodactyl\BlueprintFramework\Extensions\modpacks\Services\Versions\SoftwareInterface;
use Pterodactyl\BlueprintFramework\Extensions\modpacks\Services\Versions\VersionInstallService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Client API for the Versions tab.
 *
 * Software APIs are proxied through the panel for the same reasons the modpack
 * providers are: one shared cache for every user of the panel, and no CORS
 * problem to work around in the browser.
 */
class VersionController extends Controller
{
    public function __construct(
        private SoftwareRegistry $registry,
        private VersionInstallService $installService,
    ) {
    }

    public function software(Request $request, Server $server): JsonResponse
    {
        return new JsonResponse(['data' => $this->registry->toArray()]);
    }

    public function minecraftVersions(Request $request, Server $server): JsonResponse
    {
        $data = $request->validate(['software' => 'required|string|max:32']);
        $software = $this->registry->get($data['software']);

        return new JsonResponse([
            'data' => $this->call($software, fn () => $software->minecraftVersions()),
        ]);
    }

    public function builds(Request $request, Server $server, string $minecraftVersion): JsonResponse
    {
        $data = $request->validate(['software' => 'required|string|max:32']);
        $software = $this->registry->get($data['software']);

        return new JsonResponse([
            'data' => $this->call($software, fn () => $software->builds($minecraftVersion)),
        ]);
    }

    public function install(Request $request, Server $server): JsonResponse
    {
        // Replacing the server jar rewrites the startup command and overwrites a
        // file, so it needs the same two permissions a modpack install does.
        // Worlds and configs are untouched, but an unstartable server is still
        // not something console access alone should be able to produce.
        $this->authorizeInstall($request, $server);

        $data = $request->validate([
            'software' => 'required|string|max:32',
            'minecraftVersion' => 'required|string|max:32',
            'build' => 'nullable|string|max:64',
        ]);

        $notes = $this->installService->handle(
            $server,
            $data['software'],
            $data['minecraftVersion'],
            $data['build'] ?? null,
        );

        return new JsonResponse(['data' => ['status' => 'installed', 'notes' => $notes]]);
    }

    private function authorizeInstall(Request $request, Server $server): void
    {
        foreach ([Permission::ACTION_STARTUP_UPDATE, Permission::ACTION_FILE_DELETE] as $permission) {
            if (!$request->user()->can($permission, $server)) {
                throw new AccessDeniedHttpException(
                    'Changing the server version requires both the startup.update and file.delete permissions.'
                );
            }
        }
    }

    /**
     * Turn a transport failure into something the tab can display, keeping the
     * underlying error in the panel log either way.
     */
    private function call(SoftwareInterface $software, callable $callback): array
    {
        try {
            return $callback();
        } catch (DisplayException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('modpacks: server software request failed', [
                'software' => $software->key(),
                'exception' => $exception,
            ]);

            throw new DisplayException(sprintf(
                '%s could not be reached right now: %s',
                $software->label(),
                $exception->getMessage(),
            ));
        }
    }
}
