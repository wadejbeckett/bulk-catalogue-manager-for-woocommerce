#!/usr/bin/env bash
# Gate a Plugin Check report against bin/plugin-check-allowed.tsv (T78).
#
#   wp plugin check bulk-catalogue-manager-for-woocommerce --format=json \
#     --include-experimental > plugin-check.json
#   bin/plugin-check.sh plugin-check.json
#
# The report is WP-CLI's JSON format: a "FILE: <path>" line, then a JSON
# array of rows, per file; any other line (wp-env's own output, "Success: ...
# No errors found.") is ignored. Prints a count per type and code, then fails
# on any ERROR row and on any WARNING row beyond what the allow-list accepts
# for its file and code. An allow-listed row that no longer appears is
# reported so the list can be trimmed, but does not fail the gate.
set -euo pipefail

if [ "${1:-}" = "--help" ] || [ "${1:-}" = "-h" ] || [ $# -ne 1 ]; then
	sed -n '2,13p' "$0" | sed 's/^# \{0,1\}//'
	[ $# -eq 1 ] && exit 0 || exit 2
fi
REPORT=$1
ALLOWED="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/plugin-check-allowed.tsv"
[ -f "$REPORT" ] || { echo "no report at $REPORT" >&2; exit 2; }

python3 - "$REPORT" "$ALLOWED" <<'PY'
import collections, html, json, re, sys

report, allowed_file = sys.argv[1], sys.argv[2]
rows, current = [], None
for line in open(report, encoding="utf-8"):
    line = line.rstrip("\n")
    match = re.match(r"^FILE: (.+)$", line)
    if match:
        current = match.group(1).strip()
        continue
    if current and line.startswith("["):
        for row in json.loads(line):
            rows.append((current, row.get("type", ""), row.get("code", ""), row.get("line", 0), html.unescape(row.get("message", ""))))
        current = None

allowed = {}
for line in open(allowed_file, encoding="utf-8"):
    if not line.strip() or line.startswith("#"):
        continue
    file, code, count, _why = line.rstrip("\n").split("\t", 3)
    allowed[(file, code)] = int(count)

by_code = collections.Counter((kind, code) for _f, kind, code, _l, _m in rows)
print(f"Plugin Check: {len(rows)} rows")
for (kind, code), count in sorted(by_code.items()):
    print(f"  {count:4d}  {kind:7s}  {code}")

failed = False
for file, kind, code, line, message in rows:
    if kind == "ERROR":
        failed = True
        print(f"ERROR   {file}:{line} {code}: {message}")
found = collections.Counter((f, c) for f, kind, c, _l, _m in rows if kind == "WARNING")
for key, count in sorted(found.items()):
    if count > allowed.get(key, 0):
        failed = True
        print(f"WARNING not allowed: {key[0]} {key[1]} x{count} (allowed {allowed.get(key, 0)})")
        for file, kind, code, line, message in rows:
            if (file, code) == key:
                print(f"        {file}:{line} {message}")
for key, count in sorted(allowed.items()):
    if found.get(key, 0) < count:
        print(f"note: allow-list expects {count} x {key[1]} in {key[0]}, found {found.get(key, 0)}; trim bin/plugin-check-allowed.tsv")
sys.exit(1 if failed else 0)
PY
