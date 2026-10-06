#!/usr/bin/env bash
# Native archive upgrade on an explicitly owned, already seeded local fixture.
# No reset, uninstall, reseed, build, teardown or implicit archive selection.
set -euo pipefail
exec python3 "$(dirname "${BASH_SOURCE[0]}")/native-status-upgrade.py" "$@"
