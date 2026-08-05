{{-- Admin page for the Modpacks extension.

     Blueprint supplies the surrounding admin layout, so this file starts at the
     form and never extends a layout of its own. The save button is hidden until
     something changes and submits via _method=PATCH, matching the pattern used
     by Blueprint's admin template. --}}

<form id="config-form" action="" method="POST">
  <script>
    document.addEventListener("DOMContentLoaded", function () {showSaveButton()});
    function showSaveButton() {
      const modpacks_configForm = document.getElementById("config-form");
      const modpacks_saveOverlay = document.getElementById("save-overlay");

      modpacks_configForm.addEventListener("change", function () {
        modpacks_saveOverlay.style.display = "inline";
        setTimeout(() => {
          modpacks_saveOverlay.style.bottom = "10px";
        }, 100)
      });
    }
  </script>

  <!-- Save button overlay. (appears when form content is changed) -->
  <div id="save-overlay">{{ csrf_field() }}<button type="submit" name="_method" value="PATCH" style="transition: background-color .3s;" class="btn btn-primary btn-sm">Apply Changes</button></div>
  <style>#save-overlay {display: none;position: fixed;transition: bottom 1s;bottom: -200px;z-index: 500;}</style>

  <div class="row">

    <div class="col-xs-12 col-md-6 col-lg-5">
      <div class="box box-primary">
        <div class="box-header with-border">

          <h3 class="box-title">
            CurseForge
          </h3>

        </div>
        <div class="box-body">

            <div class="col-xs-12">
              <label class="control-label text-truncate">
                API key
              </label>

              <input
                type="password"
                name="curseforge_api_key"
                id="curseforge_api_key"
                value="{{ $curseforge_api_key }}"
                autocomplete="off"
                placeholder="$2a$10$..."
                class="form-control"
              />

              <p class="text-muted small">
                Required only for CurseForge packs. Modrinth needs no key and works without
                anything on this page. Generate one at
                <a href="https://console.curseforge.com/" target="_blank" rel="noreferrer noopener">console.curseforge.com</a>.
              </p>

              <p class="text-muted small">
                The key stays server-side: provider calls are proxied through the panel so it is
                never sent to a browser. It is written into the installer egg's environment because
                the install script runs in a container that cannot read the panel's settings.
              </p>

              @if($legacyKey)
                <p class="text-yellow small">
                  A key is currently coming from <code>config/modpacks.php</code>. Anything saved
                  here takes precedence; leave this field empty to keep using the file.
                </p>
              @endif
            </div>

        </div>
      </div>
    </div>

    <div class="col-xs-12 col-md-6 col-lg-5">
      <div class="box box-warning">
        <div class="box-header with-border">

          <h3 class="box-title">
            Installer egg
          </h3>

        </div>
        <div class="box-body">

            <div class="col-xs-12">
              <label class="control-label text-truncate">
                Egg used to install modpacks
              </label>

              <select
                class="form-control"
                name="installer_egg_id"
                id="installer_egg_id"
              >
                <option value="">— not configured —</option>

                @foreach($eggs->groupBy(fn ($egg) => $egg->nest->name ?? 'Ungrouped') as $nest => $group)
                  <optgroup label="{{ $nest }}">
                    @foreach($group as $egg)
                      <option
                        value="{{ $egg->id }}"
                        @if((string) $egg->id === (string) $installer_egg_id)
                          selected
                        @endif
                      >
                        {{ $egg->name }} (#{{ $egg->id }})
                      </option>
                    @endforeach
                  </optgroup>
                @endforeach

              </select>

              <p class="text-muted small">
                Import <code>egg/modpack-installer.json</code> under Nests first, then pick it here.
                Servers are switched onto this egg and reinstalled when a user installs a pack, so
                choosing the wrong egg will wipe servers into something unusable.
              </p>

              @if($legacyEgg > 0)
                <p class="text-yellow small">
                  Egg <code>#{{ $legacyEgg }}</code> is currently coming from
                  <code>config/modpacks.php</code>. Selecting one here overrides it.
                </p>
              @endif

              @if($eggs->isEmpty())
                <p class="text-red small">
                  No eggs exist on this panel yet. Import the installer egg before configuring this.
                </p>
              @endif
            </div>

        </div>
      </div>
    </div>

  </div>
</form>
