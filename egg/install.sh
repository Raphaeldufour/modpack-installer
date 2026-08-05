#!/bin/bash
# Modpack installer egg script.
# Runs in ghcr.io/pterodactyl/installers:debian with /mnt/server as the volume.
# Inputs: MODPACK_PROVIDER, MODPACK_ID, MODPACK_VERSION, WIPE_EXISTING

set -euo pipefail

# ---------------------------------------------------------------------------
# Report and check the inputs before doing anything else.
#
# Every remote call below is built from these three values, so when one is
# wrong or blank the first failure is an opaque curl error against a URL nobody
# can see. Printing them costs one line and turns "curl: (22) 404" into an
# answerable question. They are set by the Modpacks tab; a server reinstalled
# from the panel without going through the tab keeps whatever it had, which for
# a fresh egg is nothing at all.
# ---------------------------------------------------------------------------
echo "Modpack install requested:"
echo "  provider = ${MODPACK_PROVIDER:-<empty>}"
echo "  pack     = ${MODPACK_ID:-<empty>}"
echo "  version  = ${MODPACK_VERSION:-<empty>}"
echo "  wipe     = ${WIPE_EXISTING:-1}"

for required in MODPACK_PROVIDER MODPACK_ID MODPACK_VERSION; do
    if [ -z "${!required:-}" ]; then
        echo "" >&2
        echo "FATAL: ${required} is empty." >&2
        echo "Install a pack from the server's Modpacks tab, which sets these." >&2
        echo "Reinstalling from the panel replays whatever the server already had," >&2
        echo "so it cannot work until the tab has been used at least once." >&2
        exit 1
    fi
done

apt-get update -qq
apt-get install -y -qq curl jq unzip ca-certificates >/dev/null

# A JRE is only needed to run the Forge/NeoForge installers. Fabric, Quilt and
# publisher server packs are plain downloads, so Java is fetched on demand
# rather than up front — a missing JRE must not fail an install that never
# needed one.
#
# Do not ask for openjdk-21 unconditionally: Debian stable ships 17, and
# `apt-get install openjdk-21-jre-headless` fails with "no installation
# candidate", which under `set -e` kills this script before anything is written
# and leaves the server with no start.sh at all.
ensure_java() {
    command -v java >/dev/null 2>&1 && return 0

    echo "Installing a JRE for the loader installer..."
    apt-get install -y -qq openjdk-21-jre-headless >/dev/null 2>&1 && return 0

    # 21 lives in backports on releases that predate it.
    local codename
    codename=$(. /etc/os-release && echo "${VERSION_CODENAME:-}")
    if [ -n "$codename" ]; then
        echo "deb http://deb.debian.org/debian ${codename}-backports main" \
            > /etc/apt/sources.list.d/backports.list
        apt-get update -qq >/dev/null 2>&1 || true
        apt-get install -y -qq -t "${codename}-backports" openjdk-21-jre-headless >/dev/null 2>&1 && return 0
    fi

    # Forge for Minecraft 1.20.4 and older runs fine on 17.
    apt-get install -y -qq openjdk-17-jre-headless >/dev/null 2>&1 && return 0
    apt-get install -y -qq default-jre-headless >/dev/null 2>&1 && return 0

    echo "FATAL: no JRE could be installed, and this loader needs one to run its installer." >&2
    return 1
}

cd /mnt/server

if [ "${WIPE_EXISTING:-1}" = "1" ]; then
    echo "Clearing previous installation (worlds preserved)..."
    find . -mindepth 1 -maxdepth 1 \
        ! -name 'world' ! -name 'world_nether' ! -name 'world_the_end' \
        ! -name 'server.properties' ! -name 'ops.json' ! -name 'whitelist.json' \
        -exec rm -rf {} +
fi

# Scratch space goes on the server volume, never /tmp.
#
# Wings mounts a tmpfs over /tmp in the install container, 100M by default
# (docker.tmpfs_size in its config). Modpack archives are routinely several
# hundred megabytes, so `mktemp -d` there fills up mid-download and curl aborts
# with "(23) Failure writing output to destination" — which reads like a
# permissions fault and is really just a full disk. /mnt/server is the server's
# own volume and is sized for the pack by definition.
#
# Created after the wipe so the wipe cannot delete it, and removed on any exit
# so a failed install does not leave the directory sitting in the user's files.
WORK=$(mktemp -d -p /mnt/server .modpack-install-XXXXXX)

cleanup() {
    local rc=$?
    rm -rf "$WORK"
    if [ "$rc" -ne 0 ]; then
        echo "" >&2
        echo "Install failed (exit ${rc})." >&2

        # curl's exit codes are the ones worth translating: they are what the
        # user actually sees, and each points somewhere quite different.
        case "$rc" in
            22)
                echo "A request was rejected. 404 means that pack/version pair does not exist" >&2
                echo "on the provider; 403 usually means the CurseForge API key is missing or" >&2
                echo "invalid. The ids used are printed at the top of this log." >&2
                ;;
            23)
                echo "Ran out of disk while writing. The archive and its extracted contents" >&2
                echo "must both fit inside this server's disk limit." >&2
                df -h /mnt/server 2>/dev/null >&2 || true
                ;;
            6|7|28)
                echo "The provider could not be reached, or timed out. Retry; if it persists," >&2
                echo "check outbound network access from the install container." >&2
                ;;
        esac
    fi
}
trap cleanup EXIT

# ---------------------------------------------------------------------------
# Loader installation. Shared by every provider once we know mc + loader ver.
# ---------------------------------------------------------------------------
install_loader() {
    local loader="$1" mc="$2" ver="$3"
    echo "Installing ${loader} ${ver} for Minecraft ${mc}..."

    case "$loader" in
        forge)
            ensure_java
            curl -fsSL -o installer.jar \
                "https://maven.minecraftforge.net/net/minecraftforge/forge/${mc}-${ver}/forge-${mc}-${ver}-installer.jar"
            java -jar installer.jar --installServer && rm -f installer.jar
            ;;
        neoforge)
            ensure_java
            curl -fsSL -o installer.jar \
                "https://maven.neoforged.net/releases/net/neoforged/neoforge/${ver}/neoforge-${ver}-installer.jar"
            java -jar installer.jar --installServer && rm -f installer.jar
            ;;
        fabric)
            curl -fsSL -o server.jar \
                "https://meta.fabricmc.net/v2/versions/loader/${mc}/${ver}/stable/server/jar"
            ;;
        quilt)
            echo "Quilt not implemented yet." >&2; exit 1
            ;;
        *)
            echo "Unknown loader: ${loader}" >&2; exit 1
            ;;
    esac
}

# ---------------------------------------------------------------------------
# Modrinth: .mrpack is a zip containing modrinth.index.json + overrides/
# ---------------------------------------------------------------------------
install_modrinth() {
    local url
    url=$(curl -fsSL -A "pterodactyl-modpacks/0.1.0" \
        "https://api.modrinth.com/v2/version/${MODPACK_VERSION}" \
        | jq -r '.files[] | select(.primary == true) | .url')

    echo "Downloading modpack..."
    curl -fsSL -o "${WORK}/pack.mrpack" "$url"
    unzip -q "${WORK}/pack.mrpack" -d "${WORK}/pack"
    rm -f "${WORK}/pack.mrpack"

    local index="${WORK}/pack/modrinth.index.json"
    local mc; mc=$(jq -r '.dependencies.minecraft' "$index")

    # Exactly one of these is present in a valid pack.
    for l in forge neoforge fabric-loader quilt-loader; do
        local v; v=$(jq -r --arg k "$l" '.dependencies[$k] // empty' "$index")
        if [ -n "$v" ]; then
            install_loader "${l%-loader}" "$mc" "$v"
            break
        fi
    done

    # Skip client-only files. env.server == "unsupported" means don't install.
    echo "Downloading mods..."
    jq -r '.files[] | select((.env.server // "required") != "unsupported")
           | [.path, .downloads[0]] | @tsv' "$index" \
    | while IFS=$'\t' read -r path dl; do
        mkdir -p "$(dirname "$path")"
        curl -fsSL -o "$path" "$dl" || echo "WARN: failed ${path}" >&2
    done

    # server-overrides wins over overrides where both define a file.
    [ -d "${WORK}/pack/overrides" ] && cp -rf "${WORK}/pack/overrides/." .
    [ -d "${WORK}/pack/server-overrides" ] && cp -rf "${WORK}/pack/server-overrides/." .
    true
}

# ---------------------------------------------------------------------------
# CurseForge: prefer the publisher's server pack; fall back to the manifest.
# ---------------------------------------------------------------------------
install_curseforge() {
    local api="https://api.curseforge.com/v1"
    local hdr="x-api-key: ${CURSEFORGE_API_KEY}"

    if [ -z "${CURSEFORGE_API_KEY:-}" ]; then
        echo "FATAL: no CurseForge API key. Set one under Admin -> Extensions -> Modpacks." >&2
        exit 1
    fi

    local file
    if ! file=$(curl -fsSL -H "$hdr" "${api}/mods/${MODPACK_ID}/files/${MODPACK_VERSION}"); then
        echo "" >&2
        echo "FATAL: CurseForge has no file ${MODPACK_VERSION} for project ${MODPACK_ID}." >&2
        echo "The two must belong together — a file id from a different project 404s here." >&2
        echo "Pick the version from the Modpacks tab rather than entering ids by hand." >&2
        exit 1
    fi

    local serverPack; serverPack=$(echo "$file" | jq -r '.data.serverPackFileId // empty')

    if [ -n "$serverPack" ]; then
        echo "Using publisher server pack..."
        local url; url=$(curl -fsSL -H "$hdr" \
            "${api}/mods/${MODPACK_ID}/files/${serverPack}/download-url" | jq -r '.data')
        curl -fsSL -o "${WORK}/server.zip" "$url"
        unzip -q -o "${WORK}/server.zip" -d .
        rm -f "${WORK}/server.zip"
        return
    fi

    echo "No server pack published; building from client manifest..."
    local url; url=$(curl -fsSL -H "$hdr" \
        "${api}/mods/${MODPACK_ID}/files/${MODPACK_VERSION}/download-url" | jq -r '.data')
    curl -fsSL -o "${WORK}/pack.zip" "$url"
    unzip -q "${WORK}/pack.zip" -d "${WORK}/pack"
    rm -f "${WORK}/pack.zip"

    local mf="${WORK}/pack/manifest.json"
    local mc; mc=$(jq -r '.minecraft.version' "$mf")
    local loaderId; loaderId=$(jq -r '.minecraft.modLoaders[] | select(.primary) | .id' "$mf")
    install_loader "${loaderId%%-*}" "$mc" "${loaderId#*-}"

    mkdir -p mods
    jq -r '.files[] | [.projectID, .fileID] | @tsv' "$mf" \
    | while IFS=$'\t' read -r project fileid; do
        # Authors can opt out of third-party distribution. Those return no URL
        # and legally cannot be fetched another way — report and continue.
        local dl
        dl=$(curl -fsSL -H "$hdr" "${api}/mods/${project}/files/${fileid}/download-url" | jq -r '.data // empty')
        if [ -z "$dl" ] || [ "$dl" = "null" ]; then
            echo "BLOCKED: project ${project} disallows redistribution — add it manually." >&2
            continue
        fi
        curl -fsSL -O --output-dir mods "$dl" || echo "WARN: failed ${project}" >&2
    done

    local ov; ov=$(jq -r '.overrides // "overrides"' "$mf")
    [ -d "${WORK}/pack/${ov}" ] && cp -rf "${WORK}/pack/${ov}/." .
    true
}

case "$MODPACK_PROVIDER" in
    modrinth)   install_modrinth ;;
    curseforge) install_curseforge ;;
    *) echo "Provider ${MODPACK_PROVIDER} not implemented." >&2; exit 1 ;;
esac

echo "eula=true" > eula.txt

# ---------------------------------------------------------------------------
# Write the startup command. Every loader boots differently, and the egg's
# startup line is fixed at "bash start.sh" precisely so this script can decide.
# ---------------------------------------------------------------------------
# Memory is deliberately NOT resolved here. See start.sh below.

# A server pack frequently ships a loader installer and no libraries/, leaving
# its own launcher (startserver.sh, ServerStart.sh, …) to run the installer on
# first boot. Those launchers are not usable as a Pterodactyl startup command:
# they wrap the server in their own `while true` restart loop, which fights the
# panel for process control and breaks Stop, and they prompt on stdin when
# something goes wrong. Their pack-specific env vars (ATM10_RESTART and the
# like) are no help either, being named after each pack.
#
# So run the installer here instead. It costs a minute once, at install time
# where the log is being watched, and afterwards the loader looks exactly like
# any other — the detection below finds unix_args.txt and writes a start.sh
# that runs the server directly, one process, under the panel's control.
if [ ! -d libraries ] && [ ! -f run.sh ]; then
    INSTALLER=$(ls -1 ./*nstaller*.jar 2>/dev/null | head -n1 || true)
    if [ -n "$INSTALLER" ]; then
        echo "Pack ships a loader installer; running it now..."
        ensure_java
        java -jar "$INSTALLER" --installServer && rm -f "$INSTALLER"
    fi
fi

# Drop the pack's memory bounds, keep everything else it tuned. A pack ships
# -Xmx sized for whatever machine its author had in mind, which has nothing to
# do with this server's limit.
strip_memory_flags() {
    [ -f user_jvm_args.txt ] || return 0
    local tmp="${WORK}/ujva"
    grep -vE '^[[:space:]]*-Xm[sx]' user_jvm_args.txt > "$tmp" || true
    mv "$tmp" user_jvm_args.txt
}

# Every generated start.sh opens with this.
#
# Resolving the heap at boot rather than baking it in at install time is what
# lets a memory change in the panel take effect on the next start instead of
# needing a reinstall. It also handles SERVER_MEMORY=0, which is how
# Pterodactyl spells "no limit" — a fixed value wrote -Xmx0M there, and the JVM
# refuses to start on that.
heap_preamble() {
    cat <<'PREAMBLE'
#!/bin/bash
# Generated by the Modpacks extension. Rewritten on every install.

HEAP=""
if [ "${SERVER_MEMORY:-0}" -gt 0 ] 2>/dev/null; then
    HEAP="-Xms128M -Xmx${SERVER_MEMORY}M"
fi
PREAMBLE
}

# Prefer unix_args.txt over run.sh even when both exist: run.sh is a generated
# wrapper around exactly that file, and going direct is what allows the heap to
# be passed as an argument instead of written into a file on every boot.
ARGS=$(ls libraries/net/neoforged/neoforge/*/unix_args.txt 2>/dev/null | head -n1 || true)
[ -n "$ARGS" ] || ARGS=$(ls libraries/net/minecraftforge/forge/*/unix_args.txt 2>/dev/null | head -n1 || true)

if [ -n "$ARGS" ]; then
    strip_memory_flags
    {
        heap_preamble
        cat <<'MIDDLE'

# An -Xmx you set in user_jvm_args.txt yourself wins; that file is there to be
# edited, and the install only strips what the pack shipped.
if grep -qE '^[[:space:]]*-Xmx' user_jvm_args.txt 2>/dev/null; then
    HEAP=""
fi
MIDDLE
        printf '\nexec java $HEAP @user_jvm_args.txt @%s nogui\n' "$ARGS"
    } > start.sh

elif [ -f run.sh ]; then
    strip_memory_flags
    {
        heap_preamble
        cat <<'RUNSH'

# run.sh reads its JVM flags from user_jvm_args.txt and nowhere else — anything
# passed to it lands after the main class and would be taken as a program
# argument — so the heap has to go into that file before starting.
#
# The memory lines are rewritten every boot rather than appended once, so that
# changing the limit in the panel keeps working. That does mean an -Xmx typed
# into user_jvm_args.txt by hand will not survive here; put it in this file
# instead, above the exec. (The unix_args path this pack did not take leaves
# your value alone — this branch cannot, since it has nowhere else to write.)
if [ -f user_jvm_args.txt ]; then
    grep -vE '^[[:space:]]*-Xm[sx]' user_jvm_args.txt > .user_jvm_args.new || true
    # Empty HEAP means the server has no limit, and the stale bounds from the
    # last boot have to go so the JVM can pick for itself.
    [ -n "$HEAP" ] && printf -- '%s\n' $HEAP >> .user_jvm_args.new
    mv .user_jvm_args.new user_jvm_args.txt
fi

exec ./run.sh nogui
RUNSH
    } > start.sh

else
    # Fabric, Quilt, old Forge, and vanilla-shaped server packs: a plain jar.
    #
    # The `|| true` is load-bearing. Under `set -euo pipefail` a grep that
    # matches nothing exits 1 and takes the whole script with it — which is
    # exactly what happened on a pack whose only jar was the loader installer:
    # the install finished, then died here without a word, leaving a server with
    # no start.sh and a console repeating "bash: start.sh: No such file".
    JAR=$(ls -S ./*.jar 2>/dev/null | grep -viE 'installer|sources' | head -n1 || true)

    if [ -z "$JAR" ]; then
        echo "" >&2
        echo "FATAL: no loader arguments and no server jar were found, so there is" >&2
        echo "nothing to start. The pack extracted, but not into a shape this script" >&2
        echo "recognises. Contents:" >&2
        ls -la >&2
        exit 1
    fi

    {
        heap_preamble
        printf '\nexec java $HEAP -jar %s nogui\n' "$JAR"
    } > start.sh
fi

chmod +x start.sh 2>/dev/null || true
chmod +x run.sh 2>/dev/null || true

echo ""
echo "Modpack installed. Startup command:"
grep '^exec ' start.sh | sed 's/^/  /'
if [ "${SERVER_MEMORY:-0}" -gt 0 ] 2>/dev/null; then
    echo "  heap resolved at boot from the panel's limit (currently ${SERVER_MEMORY}M)"
else
    echo "  this server has no memory limit set, so the JVM will choose its own heap"
fi
