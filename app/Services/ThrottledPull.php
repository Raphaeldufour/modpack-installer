<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpacks\Services;

use Pterodactyl\Repositories\Wings\DaemonFileRepository;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;

/**
 * Wraps DaemonFileRepository::pull() with a retry against Wings' own
 * concurrency limit.
 *
 * Wings caps each server at 3 concurrent background downloads and rejects
 * anything past that with "reached its limit of 3 simultaneous remote file
 * downloads" rather than queueing it. A modpack install can easily issue more
 * than three pulls close together — a manifest's mods queued in a loop, or a
 * loader jar fetched right after that loop's tail end is still draining — and
 * without this, everything past the third silently became a failed mod or a
 * failed install.
 *
 * A slot frees itself as soon as one in-flight download finishes, normally
 * seconds away for a mod-sized jar, so retrying the same call is both correct
 * and the only option: Wings' rejection is the sole signal available for "try
 * again," there is no separate status endpoint to poll instead. The backoff is
 * short and bounded on purpose — this still runs inside the install request,
 * and a pack of a few hundred small files draining through 3 slots is the
 * expected cost of that limit, not something worth a long wait per file.
 */
class ThrottledPull
{
    private const MAX_ATTEMPTS = 6;
    private const RETRY_DELAYS_MS = [250, 500, 1000, 1500, 2000];

    /**
     * Only the concurrency rejection is retried. Any other failure — a 404 on
     * the file's own URL, a permissions error, Wings being unreachable — is a
     * real failure the first time and stays one; retrying it would just make a
     * genuine problem take six times as long to report.
     *
     * @throws \Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException
     *         the underlying failure, once retries are exhausted or it was
     *         never the concurrency limit to begin with
     */
    public static function pull(DaemonFileRepository $repository, string $url, string $directory, array $options): void
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $repository->pull($url, $directory, $options);

                return;
            } catch (DaemonConnectionException $exception) {
                $atConcurrencyLimit = str_contains($exception->getMessage(), 'simultaneous remote file downloads');

                if (!$atConcurrencyLimit || $attempt === self::MAX_ATTEMPTS) {
                    throw $exception;
                }

                usleep(self::RETRY_DELAYS_MS[$attempt - 1] * 1000);
            }
        }
    }
}
