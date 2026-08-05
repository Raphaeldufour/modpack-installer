<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpacks\Http\Controllers;

use Throwable;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\Permission;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Exceptions\DisplayException;
use Pterodactyl\BlueprintFramework\Extensions\modpacks\Services\ProviderRegistry;
use Pterodactyl\BlueprintFramework\Extensions\modpacks\Services\ProviderInterface;
use Pterodactyl\BlueprintFramework\Extensions\modpacks\Services\ModpackInstallService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Client API for the Modpacks tab.
 *
 * All provider traffic is proxied through here rather than called from the
 * browser. That is not incidental: the CurseForge key is a server-side secret
 * and must never be shipped to a client, and proxying is also what lets the
 * 300s provider cache be shared by every user of the panel.
 *
 * The lowercase `modpacks` in this namespace matches info.identifier in
 * conf.yml and is not a typo — Blueprint derives it from the identifier.
 */
class ModpackController extends Controller
{
    /** Hard ceiling on provider page size, whatever the client asks for. */
    private const MAX_PAGE_SIZE = 50;

    public function __construct(
        private ProviderRegistry $registry,
        private ModpackInstallService $installService,
    ) {
    }

    /**
     * Providers this panel can browse. Read-only, so server access is enough.
     */
    public function providers(Request $request, Server $server): JsonResponse
    {
        return new JsonResponse(['data' => $this->registry->toArray()]);
    }

    /**
     * Search one provider for packs.
     */
    public function search(Request $request, Server $server): JsonResponse
    {
        $data = $request->validate([
            'provider' => 'required|string|max:32',
            'query' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1|max:100',
            'pageSize' => 'nullable|integer|min:1|max:' . self::MAX_PAGE_SIZE,
        ]);

        $provider = $this->registry->get($data['provider']);

        $packs = $this->callProvider(
            $provider,
            fn () => $provider->search(
                $data['query'] ?? '',
                (int) ($data['page'] ?? 1),
                (int) ($data['pageSize'] ?? 20),
            ),
        );

        // Providers do not stamp their own key onto results, so results stay
        // attributable once the frontend holds several sets at once.
        return new JsonResponse([
            'data' => array_map(
                fn (array $pack) => $pack + ['provider' => $provider->key()],
                $packs,
            ),
        ]);
    }

    /**
     * Installable versions of one pack, newest first as the provider returns them.
     */
    public function versions(Request $request, Server $server, string $pack): JsonResponse
    {
        $data = $request->validate([
            'provider' => 'required|string|max:32',
        ]);

        $provider = $this->registry->get($data['provider']);

        return new JsonResponse([
            'data' => $this->callProvider($provider, fn () => $provider->versions($pack)),
        ]);
    }

    /**
     * Install a pack onto the server, in place.
     */
    public function install(Request $request, Server $server): JsonResponse
    {
        // Installing wipes the filesystem and rewrites the startup command.
        // Both permissions are required, deliberately: a subuser with console
        // access alone must not be able to destroy a server this way.
        $this->authorizeInstall($request, $server);

        $data = $request->validate([
            'provider' => 'required|string|max:32',
            'pack' => 'required|string|max:120',
            'version' => 'required|string|max:120',
            'wipe' => 'nullable|boolean',
        ]);

        $notes = $this->installService->handle(
            $server,
            $data['provider'],
            $data['pack'],
            $data['version'],
            $request->boolean('wipe', true),
        );

        return new JsonResponse(['data' => ['status' => 'installed', 'notes' => $notes]]);
    }

    private function authorizeInstall(Request $request, Server $server): void
    {
        $required = [Permission::ACTION_STARTUP_UPDATE, Permission::ACTION_FILE_DELETE];

        foreach ($required as $permission) {
            if (!$request->user()->can($permission, $server)) {
                throw new AccessDeniedHttpException(
                    'Installing a modpack requires both the startup.update and file.delete permissions.'
                );
            }
        }
    }

    /**
     * Run a provider call, turning transport failures into something the tab
     * can display.
     *
     * A provider throws for reasons the user can act on (CurseForge has no API
     * key configured) and reasons they cannot (Modrinth is rate-limiting us).
     * Neither should reach the browser as a stack trace, and the underlying
     * error is worth keeping in the panel log either way.
     */
    private function callProvider(ProviderInterface $provider, callable $callback): array
    {
        try {
            return $callback();
        } catch (DisplayException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('modpacks: provider request failed', [
                'provider' => $provider->key(),
                'exception' => $exception,
            ]);

            throw new DisplayException(sprintf(
                '%s could not be reached right now: %s',
                $provider->label(),
                $exception->getMessage(),
            ));
        }
    }
}
