#!/usr/bin/env bash
# Build the release archive dist/<slug>-<version>.zip (SPEC §15, T78).
#
# 1. `git archive HEAD` exports the committed tree, leaving out everything
#    marked export-ignore in .gitattributes (so uncommitted edits never ship).
# 2. The bundle is rebuilt from that export alone (`npm ci && npm run build`
#    inside the staging copy), which proves the shipped assets/src, package
#    files and webpack.config.js reproduce the shipped assets/build; the
#    staging node_modules/ is then removed.
# 3. Every path in the archive must match bin/archive-manifest.txt, and every
#    file the manifest marks as required must be present.
#
# The zip unpacks to a single <slug>/ directory, as wordpress.org and the
# plugin uploader expect. vendor/ never ships: the main plugin file falls back
# to src/Autoloader.php. Needs git, zip, unzip, Node 20 and npm.
#
#   bin/build-zip.sh              build, verify, print the path and size
#   BULKCAT_SKIP_BUILD=1 bin/build-zip.sh   ship the committed assets/build as is
set -euo pipefail

ROOT=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
SLUG=bulk-catalogue-manager-for-woocommerce
MAIN="$ROOT/$SLUG.php"
MANIFEST="$ROOT/bin/archive-manifest.txt"
[ -f "$MAIN" ] || { echo "main plugin file $MAIN not found" >&2; exit 1; }
[ -f "$MANIFEST" ] || { echo "archive manifest $MANIFEST not found" >&2; exit 1; }
VERSION=$(sed -nE 's/^[[:space:]]*\*?[[:space:]]*Version:[[:space:]]*([0-9][^[:space:]]*).*$/\1/p' "$MAIN" | head -n1)
[ -n "$VERSION" ] || { echo "no Version: header in $MAIN" >&2; exit 1; }

if [ -n "$(git -C "$ROOT" status --porcelain --untracked-files=no)" ]; then
	echo "note: uncommitted changes are not in the archive (it is built from HEAD)" >&2
fi

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
git -C "$ROOT" archive --worktree-attributes --format=tar --prefix="$SLUG/" HEAD | tar -x -C "$STAGE"

if [ "${BULKCAT_SKIP_BUILD:-0}" != "1" ]; then
	(
		cd "$STAGE/$SLUG"
		rm -rf assets/build
		npm ci --no-audit --no-fund --loglevel=error
		npm run build --silent
	)
	rm -rf "$STAGE/$SLUG/node_modules"
	if ! diff -rq "$ROOT/assets/build" "$STAGE/$SLUG/assets/build" > /dev/null 2>&1; then
		echo "note: the rebuilt assets/build differs from the committed one; the archive carries the rebuilt bundle" >&2
	fi
fi

# Check every archived path against the manifest.
mapfile -t ALLOW < <(grep -vE '^(#|!|[[:space:]]*$)' "$MANIFEST")
mapfile -t REQUIRED < <(sed -nE 's/^!(.*)$/\1/p' "$MANIFEST")
FILES=$(cd "$STAGE/$SLUG" && find . -type f -o -type l | sed 's|^\./||' | LC_ALL=C sort)
BAD=0
while IFS= read -r file; do
	ok=0
	for pattern in "${ALLOW[@]}"; do
		if [[ "$file" =~ $pattern ]]; then ok=1; break; fi
	done
	if [ "$ok" -ne 1 ]; then echo "not allowed in the archive: $file" >&2; BAD=1; fi
done <<< "$FILES"
for file in "${REQUIRED[@]}"; do
	[ -f "$STAGE/$SLUG/$file" ] || { echo "missing from the archive: $file" >&2; BAD=1; }
done
[ "$BAD" -eq 0 ] || { echo "archive rejected: fix .gitattributes/.distignore or bin/archive-manifest.txt" >&2; exit 1; }

mkdir -p "$ROOT/dist"
OUT="$ROOT/dist/$SLUG-$VERSION.zip"
rm -f "$OUT"
(cd "$STAGE" && find "$SLUG" -print0 | LC_ALL=C sort -z | xargs -0 touch -h -d '1980-01-01 00:00:00' && find "$SLUG" -type f -print0 | LC_ALL=C sort -z | xargs -0 zip -q -X -D "$OUT")

echo "$OUT"
echo "$(wc -l <<< "$FILES") files, $(stat -c %s "$OUT") bytes ($(du -h "$OUT" | cut -f1))"
