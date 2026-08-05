<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\BlueprintFramework\Extensions\modpacks\Http\Controllers\ModpackController;
use Pterodactyl\Http\Middleware\Api\Client\Server\AuthenticateServerAccess;

/*
|--------------------------------------------------------------------------
| Modpacks — client API
|--------------------------------------------------------------------------
|
| Blueprint merges this file into the panel's client API router, so the paths
| below are relative to whatever prefix that router carries — normally
| /api/client, giving /api/client/servers/{server}/modpacks/...
|
| Do not take that on trust. Blueprint has moved extension routes between
| releases, and ModpacksContainer.tsx builds its URLs from this assumption.
| Confirm on your panel before debugging a 404 any further:
|
|     php artisan route:list | grep modpacks
|
| AuthenticateServerAccess resolves {server} from its uuidShort and rejects
| users with no access to it. It is what makes $server a real, authorised
| model by the time the controller runs; every route here needs it.
|
*/

Route::group([
    'prefix' => '/servers/{server}/modpacks',
    'middleware' => [AuthenticateServerAccess::class],
], function () {
    Route::get('/providers', [ModpackController::class, 'providers'])
        ->name('api:client:server.modpacks.providers');

    Route::get('/packs', [ModpackController::class, 'search'])
        ->name('api:client:server.modpacks.search');

    // Provider ids are opaque to us — Modrinth uses base62 project ids, and
    // CurseForge numeric ones — but neither ever contains a slash, so keep the
    // segment from swallowing extra path components.
    Route::get('/packs/{pack}/versions', [ModpackController::class, 'versions'])
        ->where('pack', '[A-Za-z0-9._-]+')
        ->name('api:client:server.modpacks.versions');

    Route::post('/install', [ModpackController::class, 'install'])
        ->name('api:client:server.modpacks.install');
});
