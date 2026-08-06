<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpacks\Http\Controllers;

use Illuminate\Http\Request;
use Pterodactyl\Models\Server;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\BlueprintFramework\Extensions\modpacks\Services\InstalledStateService;

/**
 * What was last installed through this extension — shared by the Versions and
 * Modpacks tabs rather than owned by either, since either one can be the most
 * recent install and both want to show it.
 */
class StateController extends Controller
{
    public function __construct(private InstalledStateService $installedState)
    {
    }

    public function show(Request $request, Server $server): JsonResponse
    {
        return new JsonResponse(['data' => $this->installedState->read($server)]);
    }
}
