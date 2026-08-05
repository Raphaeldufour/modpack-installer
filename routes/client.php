<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\BlueprintFramework\Extensions\modpacks\Http\Controllers\ModpackController;
use Pterodactyl\Http\Middleware\Api\Client\Server\AuthenticateServerAccess;

/*
|--------------------------------------------------------------------------
| Modpacks — client API
|--------------------------------------------------------------------------
|
| Blueprint mounts this file under /api/client/extensions/<identifier>, so the
| paths below are relative to /api/client/extensions/modpacks and the group
| prefix here deliberately does not repeat "modpacks". Full shape:
|
|     /api/client/extensions/modpacks/servers/{server}/providers
|
| Confirmed with route:list on Blueprint beta-2026-06. It has moved between
| releases, and ModpacksSection.tsx builds its URLs from this assumption, so
| re-check after an upgrade before debugging a 404 any further:
|
|     php artisan route:list | grep modpacks
|
| AuthenticateServerAccess resolves {server} from its uuidShort and rejects
| users with no access to it. It is what makes $server a real, authorised
| model by the time the controller runs; every route here needs it.
|
*/

Route::group([
    'prefix' => '/servers/{server}',
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
