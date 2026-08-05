<?php

namespace Pterodactyl\Services\Modpacks;

use Pterodactyl\Contracts\Repository\SettingsRepositoryInterface;

/**
 * Where the extension's configuration comes from.
 *
 * Two sources, in order: the settings written by the admin page, then
 * config/modpacks.php. The fallback exists because hosts who installed this
 * before the admin page existed were told to create that file by hand, and
 * silently ignoring it on upgrade would break a working panel.
 *
 * Reads go through SettingsRepositoryInterface rather than Blueprint's admin
 * library on purpose: that library is admin-scoped, while these values are
 * needed from the client API and from the install service. The key layout is
 * the same either way — dbGet('modpacks', 'x') and settings 'modpacks::x' are
 * the same row.
 */
class ModpackSettings
{
    private const PREFIX = 'modpacks::';

    public function __construct(private SettingsRepositoryInterface $settings)
    {
    }

    public function curseForgeApiKey(): string
    {
        return trim((string) $this->get('curseforge_api_key', config('modpacks.curseforge_api_key')));
    }

    public function installerEggId(): int
    {
        return (int) $this->get('installer_egg_id', config('modpacks.installer_egg_id'));
    }

    public function hasCurseForgeApiKey(): bool
    {
        return $this->curseForgeApiKey() !== '';
    }

    /**
     * An unset setting and a setting cleared through the form both need to fall
     * through to the config file, so empty string counts as absent rather than
     * as a deliberate empty value.
     */
    private function get(string $key, mixed $fallback): mixed
    {
        $value = $this->settings->get(self::PREFIX . $key);

        return ($value === null || $value === '') ? $fallback : $value;
    }
}
