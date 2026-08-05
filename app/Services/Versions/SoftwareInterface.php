<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpacks\Services\Versions;

/**
 * One kind of server software: Vanilla, Paper, Purpur, Fabric…
 *
 * The same normalising rule the modpack providers follow applies here — every
 * implementation reshapes its own API into these three answers, so the Versions
 * tab never branches on which software it is showing. Adding software means one
 * class and one line in SoftwareRegistry.
 *
 * Only software that distributes a directly launchable server jar belongs
 * behind this interface. Forge and NeoForge ship an installer that has to be
 * executed to produce one, which the panel cannot do — that needs a separate
 * mechanism rather than a fifth implementation here.
 */
interface SoftwareInterface
{
    public function key(): string;

    public function label(): string;

    /**
     * Minecraft versions this software publishes for, newest first.
     *
     * @return string[]
     */
    public function minecraftVersions(): array;

    /**
     * Builds available for one Minecraft version, newest first.
     *
     * Empty when the software has no build concept — Vanilla publishes exactly
     * one server jar per version. The tab hides the build selector in that case.
     *
     * @return array[] Build::toArray() results
     */
    public function builds(string $minecraftVersion): array;

    /**
     * Where to get the jar. $build is null when builds() returned nothing.
     */
    public function resolve(string $minecraftVersion, ?string $build): Download;
}
