#!/bin/bash
# Runs when an admin removes this extension from a panel.
#
# Nothing is deleted here, and that is a decision rather than an omission:
#
#   - Saved settings (modpacks::*) stay, so removing and reinstalling — which
#     is how a manual upgrade is done — does not silently discard a host's
#     CurseForge key.
#   - The installer egg stays, because servers may currently be running on it.
#     Deleting an egg out from under live servers breaks them.
#
# Both are cheap to clean up by hand and expensive to get wrong automatically.
#
# It must exit 0. A non-zero exit here fails the removal.

echo ""
echo "Modpacks removed."
echo ""
echo "  Left in place on purpose:"
echo "    - Saved settings, so a reinstall keeps your configuration."
echo "    - The installer egg, and any server currently using it. Those servers"
echo "      keep running; they just cannot be switched to another pack from the"
echo "      panel any more."
echo ""
echo "  To clear the settings as well:"
echo "    DELETE FROM settings WHERE \`key\` LIKE 'modpacks::%';"
echo ""

exit 0
