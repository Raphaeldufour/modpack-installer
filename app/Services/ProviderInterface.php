<?php

namespace Pterodactyl\BlueprintFramework\Extensions\modpacks\Services;

interface ProviderInterface
{
    public function key(): string;

    public function label(): string;

    /** @return array[] Pack::toArray() results */
    public function search(string $query, int $page, int $pageSize): array;

    /** @return array[] Version::toArray() results */
    public function versions(string $packId): array;
}
