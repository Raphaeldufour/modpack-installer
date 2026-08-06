<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpacks\Services\Versions;

/**
 * A resolved server jar: where to fetch it and what to call it on disk.
 *
 * The URL is handed to Wings, which downloads it onto the server volume
 * itself. It never passes through the panel, so a 200MB jar costs the panel one
 * short API call rather than a long-lived request.
 */
class Download
{
    public function __construct(
        public readonly string $url,
        public readonly string $filename = 'server.jar',

        /**
         * The build that was actually fetched, when the caller passed no build
         * and resolve() picked the latest one. Null for software with no build
         * concept (Vanilla). Exists so a caller who asked for "latest" can still
         * record what that meant, instead of writing "latest" to a state file
         * that stays true only until the next build ships.
         */
        public readonly ?string $resolvedBuild = null,
    ) {
    }
}
