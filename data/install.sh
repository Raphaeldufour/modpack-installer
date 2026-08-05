#!/bin/bash
# Runs when an admin installs THIS EXTENSION onto a panel.
#
# Not to be confused with egg/install.sh, which installs a modpack into a
# server. This one is a lifecycle hook for the extension itself.
#
# There is deliberately nothing to seed here: configuration lives in the
# panel's settings table and is written by the admin page, so a fresh install
# starts empty and valid. This script only tells the admin what to do next.
#
# It must exit 0. A non-zero exit here fails the extension install.

echo ""
echo "Modpacks installed."
echo ""
echo "  Next steps:"
echo "    1. Import the installer egg (egg/modpack-installer.json) under Nests."
echo "    2. Open Admin -> Extensions -> Modpacks and select that egg."
echo "    3. Add a CurseForge API key there if you want CurseForge packs."
echo "       Modrinth needs no key and works immediately."
echo ""

if [ -f "config/modpacks.php" ] || [ -f "/var/www/pterodactyl/config/modpacks.php" ]; then
    echo "  Note: config/modpacks.php exists on this panel. It still works, but"
    echo "  values saved on the admin page take precedence; the file is only"
    echo "  consulted for fields left empty."
    echo ""
fi

exit 0
