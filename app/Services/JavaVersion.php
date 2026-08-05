<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpacks\Services;

use Pterodactyl\Models\Server;

/**
 * Which Java a given Minecraft version needs, and which of an egg's images
 * provides it.
 *
 * Shared by the modpack and version installers: both change what the server
 * runs, and the wrong Java major is one of the more confusing ways for that to
 * fail — the server boots, then dies on a class file version error.
 */
class JavaVersion
{
    /**
     * Minecraft version -> required Java major.
     *
     * 1.20.5 is the cutover to 21, 1.17 the cutover to 17. Everything older
     * runs on 8. Unrecognised input gets the newest rather than the oldest: a
     * modern server on Java 8 cannot start at all, whereas the reverse at least
     * gets far enough to produce a legible error.
     */
    public static function majorFor(?string $minecraftVersion): int
    {
        if ($minecraftVersion === null || !preg_match('/^1\.(\d+)(?:\.(\d+))?/', $minecraftVersion, $matches)) {
            return 21;
        }

        $minor = (int) $matches[1];
        $patch = (int) ($matches[2] ?? 0);

        return match (true) {
            $minor > 20 => 21,
            $minor === 20 => $patch >= 5 ? 21 : 17,
            $minor >= 17 => 17,
            default => 8,
        };
    }

    /**
     * Pick the egg image that carries a given Java major.
     *
     * Returns null rather than guessing when the egg offers nothing suitable.
     * A server's image has to be one its egg declares — writing an arbitrary
     * one is rejected — so the caller keeps the current image and says so
     * instead of producing an unstartable server.
     *
     * Image names are matched rather than trusted to be in any particular
     * order: eggs label them "Java 21", "java_21", "openjdk-21" and worse.
     */
    public static function imageFor(Server $server, int $major): ?string
    {
        $images = $server->egg?->docker_images ?? [];

        foreach ($images as $name => $image) {
            if (preg_match('/(?<!\d)' . $major . '(?!\d)/', (string) $image)
                || preg_match('/(?<!\d)' . $major . '(?!\d)/', (string) $name)) {
                return $image;
            }
        }

        return null;
    }
}
