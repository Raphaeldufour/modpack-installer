<?php

// Admin page for the Modpacks extension.
// Conventions here follow Blueprint's own admin controller template:
// https://blueprint.zip/guides/dev/admincontroller

namespace Pterodactyl\Http\Controllers\Admin\Extensions\modpacks;

use Illuminate\View\View;
use Pterodactyl\Models\Egg;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\Factory as ViewFactory;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Http\Requests\Admin\AdminFormRequest;
use Pterodactyl\Contracts\Repository\SettingsRepositoryInterface;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary as BlueprintExtensionLibrary;

/**
 * Two classes in one file, and a lowercase class name, both against the
 * conventions CLAUDE.md sets for app/. Blueprint's admin controller template
 * does it this way and derives the class name from info.identifier, so matching
 * it is what makes the page load at all. The PSR-4 rule still applies to app/.
 */
class modpacksExtensionController extends Controller
{
    public function __construct(
        private ViewFactory $view,
        private BlueprintExtensionLibrary $blueprint,
        private SettingsRepositoryInterface $settings,
    ) {
    }

    public function index(): View
    {
        // Read the raw stored values rather than going through ModpackSettings:
        // the form has to show what is actually saved here, not what the
        // config-file fallback would resolve to. The fallback is surfaced
        // separately below so the admin can see it is in play.
        $storedKey = (string) $this->blueprint->dbGet('modpacks', 'curseforge_api_key');
        $storedEgg = (string) $this->blueprint->dbGet('modpacks', 'installer_egg_id');

        return $this->view->make('admin.extensions.modpacks.index', [
            'curseforge_api_key' => $storedKey,
            'installer_egg_id' => $storedEgg,

            // Grouped in the view, so the host can find the imported egg by its
            // nest instead of scanning one long flat list.
            'eggs' => Egg::query()->with('nest')->orderBy('name')->get(),

            // config/modpacks.php is still honoured when a field is left blank.
            // Saying so avoids the "I cleared the key but CurseForge still
            // works" confusion.
            'legacyKey' => $storedKey === '' && !empty(config('modpacks.curseforge_api_key')),
            'legacyEgg' => $storedEgg === '' ? (int) config('modpacks.installer_egg_id') : 0,

            'root' => '/admin/extensions/modpacks',
            'blueprint' => $this->blueprint,
        ]);
    }

    /**
     * @throws \Pterodactyl\Exceptions\Model\DataValidationException
     * @throws \Pterodactyl\Exceptions\Repository\RecordNotFoundException
     */
    public function update(modpacksSettingsFormRequest $request): RedirectResponse
    {
        foreach ($request->normalize() as $key => $value) {
            $this->settings->set('modpacks::' . $key, $value);
        }

        return redirect()->route('admin.extensions.modpacks.index');
    }
}

class modpacksSettingsFormRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            // Left blank on purpose by hosts who only use Modrinth, which needs
            // no credentials at all.
            'curseforge_api_key' => 'nullable|string|max:200',

            // exists: catches the common mistake of typing an egg id that was
            // read off the wrong admin page, which otherwise only shows up as a
            // failed install much later.
            'installer_egg_id' => 'nullable|integer|exists:eggs,id',
        ];
    }

    public function attributes(): array
    {
        return [
            'curseforge_api_key' => 'CurseForge API key',
            'installer_egg_id' => 'Installer egg',
        ];
    }
}
