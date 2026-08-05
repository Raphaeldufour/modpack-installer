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

    /**
     * Turn a manifest archive's index into the files still to be fetched.
     *
     * Only called when installPlan() returned a plan that is not self-contained.
     * The index is read off the server after unpacking, so this receives its
     * contents rather than fetching anything itself.
     *
     * @return array[] each ['path' => string, 'url' => string]
     */
    public function manifestFiles(string $indexContents): array;
}
