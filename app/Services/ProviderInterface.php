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

    /**
     * Resolve one version into something installable.
     *
     * All provider-specific knowledge about how a pack is packaged lives behind
     * this call, so the install service never learns which provider it is
     * serving.
     */
    public function installPlan(string $packId, string $versionId): InstallPlan;
}
