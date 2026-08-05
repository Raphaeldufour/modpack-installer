#!/usr/bin/env python3
"""Regenerate modpack-installer.json from install.sh + egg.template.json.

install.sh is the source of truth for the installation script. The egg JSON
embeds it as a single escaped string, which is unreadable and unreviewable by
hand, so it is generated instead:

    bash -n egg/install.sh          # syntax-check first
    python3 egg/build_egg.py        # then regenerate

Never hand-edit modpack-installer.json; this script overwrites it.
"""

import json
import sys
from pathlib import Path

HERE = Path(__file__).resolve().parent
TEMPLATE = HERE / "egg.template.json"
SCRIPT = HERE / "install.sh"
OUTPUT = HERE / "modpack-installer.json"

PLACEHOLDER = "@@INSTALL_SH@@"


def main() -> int:
    egg = json.loads(TEMPLATE.read_text())

    installation = egg["scripts"]["installation"]
    if installation["script"] != PLACEHOLDER:
        print(
            f"{TEMPLATE.name}: scripts.installation.script must be exactly "
            f"{PLACEHOLDER!r}",
            file=sys.stderr,
        )
        return 1

    installation["script"] = SCRIPT.read_text()

    OUTPUT.write_text(json.dumps(egg, indent=4) + "\n")
    print(f"wrote {OUTPUT.relative_to(HERE.parent)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
