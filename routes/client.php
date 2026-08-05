<?php

use Illuminate\Support\Facades\Route;
use Pterodactyl\BlueprintFramework\Extensions\modpacks\Http\Controllers\ModController;
use Pterodactyl\BlueprintFramework\Extensions\modpacks\Http\Controllers\ModpackController;
use Pterodactyl\BlueprintFramework\Extensions\modpacks\Http\Controllers\VersionController;
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

    Route::get('/mods/providers', [ModController::class, 'providers'])
        ->name('api:client:server.modpacks.mods.providers');

    Route::get('/mods/installed', [ModController::class, 'installed'])
        ->name('api:client:server.modpacks.mods.installed');

    Route::get('/mods', [ModController::class, 'search'])
        ->name('api:client:server.modpacks.mods.search');

    Route::get('/mods/{mod}/versions', [ModController::class, 'versions'])
        ->where('mod', '[A-Za-z0-9._-]+')
        ->name('api:client:server.modpacks.mods.versions');

    Route::post('/mods/install', [ModController::class, 'install'])
        ->name('api:client:server.modpacks.mods.install');

    // Versions tab. Kept under /software so it cannot collide with the modpack
    // routes above, which already own /packs and /versions.
    Route::get('/software', [VersionController::class, 'software'])
        ->name('api:client:server.modpacks.software');

    Route::get('/software/versions', [VersionController::class, 'minecraftVersions'])
        ->name('api:client:server.modpacks.software.versions');

    Route::get('/software/versions/{minecraftVersion}/builds', [VersionController::class, 'builds'])
        ->where('minecraftVersion', '[A-Za-z0-9._\-]+')
        ->name('api:client:server.modpacks.software.builds');

    Route::post('/software/install', [VersionController::class, 'install'])
        ->name('api:client:server.modpacks.software.install');
});
