#!/usr/bin/env bash
# Build the release archive: dist/<slug>-<version>.zip from the committed tree
# (git archive HEAD), with everything marked export-ignore in .gitattributes
# left out. The zip unpacks to a single <slug>/ directory, as wordpress.org
# and the WordPress plugin uploader expect. vendor/ is never included: the
# main plugin file falls back to src/Autoloader.php when it is absent.
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
SLUG=$(basename "$ROOT")
MAIN="$ROOT/$SLUG.php"
[ -f "$MAIN" ] || { echo "main plugin file $MAIN not found" >&2; exit 1; }
VERSION=$(sed -nE 's/^[[:space:]]*\*?[[:space:]]*Version:[[:space:]]*([0-9][^[:space:]]*).*$/\1/p' "$MAIN" | head -n1)
[ -n "$VERSION" ] || { echo "no Version: header in $MAIN" >&2; exit 1; }

mkdir -p "$ROOT/dist"
OUT="$ROOT/dist/$SLUG-$VERSION.zip"
rm -f "$OUT"
git -C "$ROOT" archive --worktree-attributes --format=zip --prefix="$SLUG/" -o "$OUT" HEAD
echo "$OUT"
unzip -l "$OUT" | tail -n1
