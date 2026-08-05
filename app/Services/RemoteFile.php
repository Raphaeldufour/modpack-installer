<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpacks\Services;

use Throwable;
use GuzzleHttp\TransferStats;
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
 * and the pull fails. That rules out most CDN links as published — CurseForge
 * hands out edge.forgecdn.net URLs that redirect, and Purpur's /download does
 * the same.
 *
 * The final URL is read from Guzzle's transfer stats rather than by walking 302
 * responses by hand. An earlier version did the latter and handed Wings the
 * original URL unchanged on a live panel, without saying why — the only silent
 * path through it was a request that threw, which it swallowed. Reading where
 * the transfer actually ended up has no such branch, and every outcome here is
 * logged, so a repeat is diagnosable from the panel log rather than by
 * inference.
 *
 * The body is never downloaded. HEAD is tried first, and the streamed GET
 * fallback returns as soon as the headers are in, so the payload still goes
 * only to Wings.
 */
class RemoteFile
{
    public static function resolve(string $url): string
    {
        $effective = self::effectiveUrl($url, 'head');

        // Several CDNs answer HEAD with 403 or 405 while serving GET happily.
        if ($effective === null) {
            $effective = self::effectiveUrl($url, 'get');
        }

        if ($effective === null || $effective === '') {
            Log::warning('modpacks: could not resolve a download URL, handing Wings the original', [
                'url' => $url,
            ]);

            return $url;
        }

        if ($effective !== $url) {
            Log::info('modpacks: followed a download redirect', ['from' => $url, 'to' => $effective]);
        }

        return $effective;
    }

    /**
     * The URL the transfer actually ended at, or null when the attempt failed.
     */
    private static function effectiveUrl(string $url, string $method): ?string
    {
        $effective = null;

        try {
            $request = Http::withOptions([
                // Returns once the headers are in, so a 400MB file is never
                // pulled through the panel.
                'stream' => true,
                'on_stats' => function (TransferStats $stats) use (&$effective) {
                    $effective = (string) $stats->getEffectiveUri();
                },
            ])->withHeaders([
                'User-Agent' => 'pterodactyl-modpacks/0.1.0 (your-contact@example.com)',
            ])->timeout(20);

            $response = $method === 'head' ? $request->head($url) : $request->get($url);

            if ($response->status() >= 400) {
                return null;
            }
        } catch (Throwable $exception) {
            Log::notice('modpacks: redirect resolution attempt failed', [
                'url' => $url,
                'method' => $method,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        return $effective;
    }
}
