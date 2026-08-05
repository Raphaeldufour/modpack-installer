#!/bin/bash
# Runs when an admin updates this extension to a newer version.
#
# Settings are left untouched on purpose — an update must not make a host
# reconfigure a working panel. If a future version ever needs to rewrite a
# setting key, that migration belongs here.
#
# It must exit 0. A non-zero exit here fails the update.

echo ""
echo "Modpacks updated. Existing settings were kept."
echo ""
echo "  If the installer egg changed in this release, re-import"
echo "  egg/modpack-installer.json over the existing egg to pick up the new"
echo "  install script."
echo ""

exit 0
