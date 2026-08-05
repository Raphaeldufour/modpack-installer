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
    ) {
    }
}
