#!/bin/bash
# Modpack installer egg script.
# Runs in ghcr.io/pterodactyl/installers:debian with /mnt/server as the volume.
# Inputs: MODPACK_PROVIDER, MODPACK_ID, MODPACK_VERSION, WIPE_EXISTING

set -euo pipefail

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
        echo "If the error above was curl 23, the server ran out of disk: the archive and" >&2
        echo "its extracted contents must both fit inside this server's disk limit." >&2
        df -h /mnt/server 2>/dev/null >&2 || true
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

    local file; file=$(curl -fsSL -H "$hdr" "${api}/mods/${MODPACK_ID}/files/${MODPACK_VERSION}")
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
MEM="${SERVER_MEMORY:-2048}"

if [ -f run.sh ]; then
    # Forge 1.17+ and NeoForge ship their own run.sh plus user_jvm_args.txt.
    echo "-Xms128M -Xmx${MEM}M" > user_jvm_args.txt
    printf '#!/bin/bash\nexec ./run.sh nogui\n' > start.sh

elif ls libraries/net/minecraftforge/forge/*/unix_args.txt >/dev/null 2>&1; then
    ARGS=$(ls libraries/net/minecraftforge/forge/*/unix_args.txt | head -n1)
    printf '#!/bin/bash\nexec java -Xms128M -Xmx%sM @%s nogui\n' "$MEM" "$ARGS" > start.sh

elif ls libraries/net/neoforged/neoforge/*/unix_args.txt >/dev/null 2>&1; then
    ARGS=$(ls libraries/net/neoforged/neoforge/*/unix_args.txt | head -n1)
    printf '#!/bin/bash\nexec java -Xms128M -Xmx%sM @%s nogui\n' "$MEM" "$ARGS" > start.sh

else
    # Fabric, Quilt, old Forge, and publisher server packs: a plain jar.
    JAR=$(ls -S ./*.jar 2>/dev/null | grep -viE 'installer|sources' | head -n1)
    JAR=${JAR:-server.jar}
    printf '#!/bin/bash\nexec java -Xms128M -Xmx%sM -jar %s nogui\n' "$MEM" "$JAR" > start.sh
fi

chmod +x start.sh run.sh 2>/dev/null || true

echo "Modpack installed. Startup command written to start.sh."
