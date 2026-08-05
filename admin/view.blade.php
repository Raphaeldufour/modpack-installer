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

    <div class="col-xs-12 col-md-6 col-lg-4">
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
                The key stays server-side. Provider calls are proxied through the panel, and pack
                downloads are handed to Wings as resolved URLs, so the key is never sent to a
                browser and never written into a server's environment.
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

  </div>
</form>
