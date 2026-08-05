<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpacks\Services;

interface ModProviderInterface
{
    public function key(): string;

    public function label(): string;

    /** @return array[] Pack::toArray() results */
    public function search(string $query, int $page, int $pageSize, ?string $loader, ?string $minecraftVersion): array;

    /** @return array[] Version::toArray() results */
    public function versions(string $modId, ?string $loader, ?string $minecraftVersion): array;

    /**
     * Resolve one version into a direct mod jar download.
     *
     * @return array{url: string, filename: string}
     */
    public function resolve(string $modId, string $versionId): array;
}
