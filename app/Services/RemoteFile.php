<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpacks\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

/**
 * Resolves a download URL to the one Wings can actually fetch.
 *
 * Wings' downloader refuses anything that is not a direct 200: a redirect comes
 * back as
 *
 *     downloader: got bad response status from endpoint: 302 Found
 *
 * and the pull fails. That rules out most CDN links as-is — CurseForge hands
 * out edge.forgecdn.net URLs that redirect, and Purpur's /download endpoint
 * does the same. Following the chain here costs one tiny request and keeps the
 * payload itself off the panel: what is handed to Wings is still just a URL.
 *
 * The hops are walked with a one-byte ranged GET rather than HEAD, because
 * several of these CDNs answer HEAD with 403 while serving GET happily.
 */
class RemoteFile
{
    private const MAX_HOPS = 5;

    public static function resolve(string $url): string
    {
        $current = $url;

        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            try {
                $response = Http::withOptions(['allow_redirects' => false])
                    ->withHeaders([
                        'Range' => 'bytes=0-0',
                        'User-Agent' => 'pterodactyl-modpacks/0.1.0 (your-contact@example.com)',
                    ])
                    ->timeout(15)
                    ->get($current);
            } catch (\Throwable $exception) {
                // A resolver failure is not worth sinking the install: hand Wings
                // the URL we have and let its own error be the one reported.
                Log::notice('modpacks: could not follow a download redirect', [
                    'url' => $current,
                    'message' => $exception->getMessage(),
                ]);

                return $current;
            }

            $status = $response->status();

            if ($status < 300 || $status >= 400) {
                return $current;
            }

            $location = (string) $response->header('Location');

            if ($location === '') {
                return $current;
            }

            $current = self::absolute($current, $location);
        }

        Log::notice('modpacks: gave up following redirects', ['url' => $url, 'hops' => self::MAX_HOPS]);

        return $current;
    }

    /**
     * A Location header is allowed to be relative, and some CDNs use that for
     * the final hop.
     */
    public static function absolute(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return $location;
        }

        $root = $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (str_starts_with($location, '/')) {
            return $root . $location;
        }

        $path = $parts['path'] ?? '/';
        $directory = substr($path, 0, strrpos($path, '/') + 1) ?: '/';

        return $root . $directory . $location;
    }
}
