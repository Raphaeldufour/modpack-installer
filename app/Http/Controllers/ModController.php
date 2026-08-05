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
use Pterodactyl\BlueprintFramework\Extensions\modpacks\Services\ModInstallService;
use Pterodactyl\BlueprintFramework\Extensions\modpacks\Services\ModProviderInterface;
use Pterodactyl\BlueprintFramework\Extensions\modpacks\Services\ModProviderRegistry;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class ModController extends Controller
{
    private const MAX_PAGE_SIZE = 50;

    public function __construct(
        private ModProviderRegistry $registry,
        private ModInstallService $installService,
    ) {
    }

    public function providers(Request $request, Server $server): JsonResponse
    {
        return new JsonResponse(['data' => $this->registry->toArray()]);
    }

    public function search(Request $request, Server $server): JsonResponse
    {
        $data = $request->validate([
            'provider' => 'required|string|max:32',
            'query' => 'nullable|string|max:100',
            'loader' => 'nullable|string|in:forge,fabric,quilt,neoforge',
            'minecraftVersion' => 'nullable|string|max:32',
            'page' => 'nullable|integer|min:1|max:100',
            'pageSize' => 'nullable|integer|min:1|max:' . self::MAX_PAGE_SIZE,
        ]);

        $provider = $this->registry->get($data['provider']);

        $mods = $this->callProvider(
            $provider,
            fn () => $provider->search(
                $data['query'] ?? '',
                (int) ($data['page'] ?? 1),
                (int) ($data['pageSize'] ?? 20),
                $data['loader'] ?? null,
                $data['minecraftVersion'] ?? null,
            ),
        );

        return new JsonResponse([
            'data' => array_map(
                fn (array $mod) => $mod + ['provider' => $provider->key()],
                $mods,
            ),
        ]);
    }

    public function versions(Request $request, Server $server, string $mod): JsonResponse
    {
        $data = $request->validate([
            'provider' => 'required|string|max:32',
            'loader' => 'nullable|string|in:forge,fabric,quilt,neoforge',
            'minecraftVersion' => 'nullable|string|max:32',
        ]);

        $provider = $this->registry->get($data['provider']);

        return new JsonResponse([
            'data' => $this->callProvider(
                $provider,
                fn () => $provider->versions($mod, $data['loader'] ?? null, $data['minecraftVersion'] ?? null),
            ),
        ]);
    }

    public function install(Request $request, Server $server): JsonResponse
    {
        $this->authorizeInstall($request, $server);

        $data = $request->validate([
            'provider' => 'required|string|max:32',
            'mod' => 'required|string|max:120',
            'version' => 'required|string|max:120',
        ]);

        $filename = $this->installService->handle(
            $server,
            $data['provider'],
            $data['mod'],
            $data['version'],
        );

        return new JsonResponse(['data' => ['status' => 'installed', 'filename' => $filename]]);
    }

    private function authorizeInstall(Request $request, Server $server): void
    {
        foreach ([Permission::ACTION_FILE_CREATE] as $permission) {
            if (!$request->user()->can($permission, $server)) {
                throw new AccessDeniedHttpException(
                    'Installing a mod requires the file.create permission.'
                );
            }
        }
    }

    private function callProvider(ModProviderInterface $provider, callable $callback): array
    {
        try {
            return $callback();
        } catch (DisplayException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::warning('modpacks: mod provider request failed', [
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
