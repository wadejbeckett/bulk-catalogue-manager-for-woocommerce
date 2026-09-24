#!/usr/bin/env python3
"""Live probe of Bulk Catalogue Manager on a seeded WordPress + WooCommerce site.

Run ``tests/probe/seed.sh`` first; it writes ``tests/probe/seed.json`` (the
fixture ids, the three probe users and their password), which this probe
reads. The probe logs in with a cookie jar per user, takes the ``wp_rest``
nonce from the admin page (falling back to core's ``admin-ajax.php?action=
rest-nonce`` where the admin page carries none, as for a subscriber), and
prints one PASS, FAIL or SKIP row per surface. It exits 1 on any FAIL, 0
otherwise.

Chapters (``--chapters``, comma separated, default all, run in this order):

  screen     T8. The Products -> Bulk Catalogue Manager screen renders its
             one mount element, the localised settings, the bundle and its
             style for an administrator and a shop manager, refuses a
             subscriber 403, and loads none of it on other admin pages.
  read       T23. Every read route (/fields, /products with filters, search,
             sort and paging, /products/{id}/variations, /selection,
             /categories, /export) answered four ways: anonymous 401,
             subscriber 403, shop manager 200 with the documented shape, and
             the shop manager's cookie with a stale nonce 403
             rest_cookie_invalid_nonce (core) while the same cookie with no
             nonce is anonymous to core and refused 401. Id sets, totals and
             the export are checked against WooCommerce itself under wp eval.
             T52/T53: a saved view created, marked default and read back
             unchanged (page aside), unreachable to another user (404), the
             schema refusals, deleted; the export with the nonce as a
             ``_wpnonce`` query argument instead of the header.
  write      The engine live: selection -> preview (nothing saved) -> batch
             (apply, replay) -> Action Scheduler run through ``wp
             action-scheduler run`` -> progress until complete -> values
             checked with wc_get_product() -> undo preview -> undo apply ->
             run -> values restored; a skip-already batch leaving
             post_modified_gmt alone; PATCH /products/{id}; category create,
             edit and move; the write routes' refusals.
  access     The administrators-only setting flips a shop manager to 403
             while an administrator keeps 200.
  variations T72. A variable product with 60 variations (upserted by the
             chapter through WP-CLI): /products/{id}/variations read past the
             50-row grid cap in menu order; Variations only filtered to out of
             stock against WooCommerce; a 10 per cent increase with Also apply
             to variations on previewed (60 variations, the parent left out,
             nothing saved), applied and run, each variation's stored price and
             the parent's wc_product_meta_lookup min/max checked; the undo run
             and the prices and lookup row compared with the snapshot; the
             variations list, preview and batches refuse anonymous 401 and a
             subscriber 403.
  lifecycle  Activation created the three tables with every documented
             index; WooCommerce lists the plugin compatible with HPOS and the
             Cart and Checkout Blocks; a bulkcat_db_version bump re-runs dbDelta on
             admin_init keeping every row; deactivate/activate is idempotent;
             ``wp plugin uninstall --deactivate --skip-delete`` keeps every
             bulkcat_ row with "Remove all data" off and leaves none with it on;
             the plugin is activated again after each uninstall. Destructive:
             run it last, and re-run seed.sh afterwards.

Every ``wp`` call goes through ``--wp-cmd`` (default: $WP_CMD, else ``wp``),
word-split like seed.sh's. ``--no-wp`` turns every row needing WP-CLI into a
documented SKIP. ``uninstall`` always passes ``--skip-delete``: in wp-env and
the docker stack the plugin directory is the repository itself.

Adding a surface: write a function ``def check_<name>(p: Probe) -> None``
that calls ``p.row(chapter, surface, ok, detail)`` (or ``p.skip(...)``) once
per surface, using ``p.http(user, method, path, ...)`` for REST calls
(``user`` is None, "subscriber", "manager" or "admin"; ``nonce`` is "valid",
"stale" or "none") and ``p.wp_eval(php)`` / ``p.wp(*args)`` for ground truth;
then append it to its chapter's list in ``CHAPTERS``. A new chapter is a new
key there and a line in this text. Keep SURFACE names stable
(``chapter:route:leg``): CI and the ticket notes quote them.

Usage:
    WP_CMD="wp" python3 tests/probe/probe.py --base-url http://localhost:8888
    python3 tests/probe/probe.py --base-url URL [--seed tests/probe/seed.json]
        [--wp-cmd "CMD"] [--no-wp] [--chapters read,write] [--verbose]

Standard library only, on purpose: it has to run anywhere with Python 3.
"""

from __future__ import annotations

import argparse
import csv
import http.cookiejar
import io
import json
import os
import re
import shlex
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from dataclasses import dataclass
from typing import Any, Callable

TIMEOUT_SECONDS = 60
NS = "/wp-json/bulkcat/v1"
PLUGIN = "bulk-catalogue-manager-for-woocommerce"
STALE_NONCE = "0123456789"


@dataclass
class Response:
    status: int
    body: bytes
    headers: dict[str, str]

    def json(self) -> Any:
        try:
            return json.loads(self.body.decode("utf-8"))
        except ValueError:
            return None

    def code(self) -> str:
        data = self.json()
        return data.get("code", "") if isinstance(data, dict) else ""

    def brief(self) -> str:
        if self.code() or self.status < 300:
            return f"{self.status} {self.code()}".rstrip()
        return f"{self.status} {self.body[:120].decode('utf-8', 'replace')}"


class WpCliError(RuntimeError):
    pass


class Probe:
    def __init__(self, args: argparse.Namespace) -> None:
        self.base = args.base_url.rstrip("/")
        self.verbose = args.verbose
        self.wp_cmd = None if args.no_wp else shlex.split(args.wp_cmd)
        with open(args.seed, encoding="utf-8") as handle:
            self.seed = json.load(handle)
        self.rows: list[tuple[str, str, str, str]] = []
        self.sessions: dict[str, tuple[urllib.request.OpenerDirector, str]] = {}

    # ---- reporting -------------------------------------------------------

    def row(self, chapter: str, surface: str, ok: bool, detail: str = "") -> bool:
        self.rows.append((chapter, surface, "PASS" if ok else "FAIL", detail))
        return ok

    def skip(self, chapter: str, surface: str, reason: str) -> None:
        self.rows.append((chapter, surface, "SKIP", reason))

    def guard(self, chapter: str, surface: str, fn: Callable[[], None]) -> None:
        """Runs fn; an exception becomes a FAIL row for the surface."""
        try:
            fn()
        except WpCliError as error:
            self.row(chapter, surface, False, f"wp-cli: {error}")
        except (AssertionError, KeyError, TypeError, ValueError, IndexError) as error:
            self.row(chapter, surface, False, f"{type(error).__name__}: {error}")

    # ---- HTTP -------------------------------------------------------------

    def _opener(self, jar: http.cookiejar.CookieJar | None) -> urllib.request.OpenerDirector:
        handlers: list[Any] = [NoRedirect()]
        if jar is not None:
            handlers.append(urllib.request.HTTPCookieProcessor(jar))
        return urllib.request.build_opener(*handlers)

    def session(self, user: str) -> tuple[urllib.request.OpenerDirector, str]:
        """A logged-in opener and its wp_rest nonce, per probe user."""
        if user in self.sessions:
            return self.sessions[user]
        login = self.seed["users"][user]["login"]
        jar = http.cookiejar.CookieJar()
        opener = self._opener(jar)
        self._raw(opener, "GET", "/wp-login.php")
        form = urllib.parse.urlencode(
            {"log": login, "pwd": self.seed["password"], "wp-submit": "Log In", "testcookie": "1"}
        ).encode()
        self._raw(opener, "POST", "/wp-login.php", form, {"Content-Type": "application/x-www-form-urlencoded"})
        if not any(c.name.startswith("wordpress_logged_in_") for c in jar):
            raise RuntimeError(f"could not log in as {login}; re-run seed.sh")
        page = self._raw(opener, "GET", "/wp-admin/").body.decode("utf-8", "replace")
        match = re.search(r'createNonceMiddleware\(\s*"([0-9a-f]{10})"', page) or re.search(
            r'wpApiSettings\s*=\s*\{[^}]*"nonce":"([0-9a-f]{10})"', page
        )
        nonce = match.group(1) if match else ""
        if not nonce:
            nonce = self._raw(opener, "GET", "/wp-admin/admin-ajax.php?action=rest-nonce").body.decode().strip()
        if not re.fullmatch(r"[0-9a-f]{10}", nonce):
            raise RuntimeError(f"no wp_rest nonce for {login}")
        self.sessions[user] = (opener, nonce)
        return self.sessions[user]

    def _raw(self, opener, method: str, path: str, data: bytes | None = None, headers: dict | None = None) -> Response:
        request = urllib.request.Request(self.base + path, data=data, method=method, headers=headers or {})
        try:
            with opener.open(request, timeout=TIMEOUT_SECONDS) as reply:
                return Response(reply.status, reply.read(), dict(reply.headers))
        except urllib.error.HTTPError as error:
            return Response(error.code, error.read(), dict(error.headers or {}))

    def http(
        self,
        user: str | None,
        method: str,
        route: str,
        params: dict | list | None = None,
        body: Any = None,
        nonce: str = "valid",
    ) -> Response:
        """A REST call under bulkcat/v1 as a probe user (None: anonymous)."""
        path = NS + route
        if params:
            path += "?" + urllib.parse.urlencode(params, doseq=True)
        headers = {"Accept": "application/json"}
        data = None
        if body is not None:
            data = json.dumps(body).encode()
            headers["Content-Type"] = "application/json"
        if user is None:
            opener = self._opener(None)
        else:
            opener, valid = self.session(user)
            if nonce == "valid":
                headers["X-WP-Nonce"] = valid
            elif nonce == "stale":
                headers["X-WP-Nonce"] = STALE_NONCE
        reply = self._raw(opener, method, path, data, headers)
        if self.verbose:
            print(f"  {user or 'anonymous'} {method} {path} -> {reply.brief()}", file=sys.stderr)
        return reply

    # ---- WP-CLI -------------------------------------------------------------

    def wp(self, *args: str, check: bool = True) -> str:
        if self.wp_cmd is None:
            raise WpCliError("--no-wp given")
        done = subprocess.run([*self.wp_cmd, *args], capture_output=True, text=True, timeout=600)
        if check and done.returncode != 0:
            raise WpCliError(f"{' '.join(args[:3])} exited {done.returncode}: {done.stderr.strip()[-400:]}")
        return done.stdout

    def wp_eval(self, php: str) -> Any:
        """Runs PHP under wp eval; the PHP echoes one JSON value via bulkcat_out()."""
        out = self.wp("eval", "function bulkcat_out( $v ) { echo \"\\nBULKCAT_OUT \" . wp_json_encode( $v ) . \"\\n\"; } " + php)
        for line in out.splitlines():
            if line.startswith("BULKCAT_OUT "):
                return json.loads(line[len("BULKCAT_OUT "):])
        raise WpCliError(f"no BULKCAT_OUT in: {out.strip()[-400:]}")

    def run_queue(self, batch_id: int, rounds: int = 20) -> dict:
        """Drives Action Scheduler until the batch leaves queued/running/undoing."""
        report: dict = {}
        for _ in range(rounds):
            # --hooks, not --group: on a fresh site Action Scheduler's hybrid store
            # asks the old post store for the group first and refuses it.
            self.wp("action-scheduler", "run", "--hooks=bulkcat_run_chunk", "--batch-size=25", check=False)
            report = self.http("manager", "GET", f"/batches/{batch_id}").json() or {}
            if report.get("status") not in ("queued", "running"):
                return report
            time.sleep(1)
        return report

    # ---- shared fixtures ---------------------------------------------------------

    def simple(self, n: int) -> dict:
        return self.seed["simple"][n - 1]

    def prices(self, ids: list[int]) -> dict[str, str]:
        return self.wp_eval(
            "$o = array(); foreach ( " + php_list(ids) + " as $id ) { $p = wc_get_product( $id ); "
            "$o[ $id ] = $p ? $p->get_regular_price( 'edit' ) : null; } bulkcat_out( $o );"
        )

    def modified(self, ids: list[int]) -> dict[str, str]:
        return self.wp_eval(
            "$o = array(); foreach ( " + php_list(ids) + " as $id ) { $o[ $id ] = get_post( $id )->post_modified_gmt; } bulkcat_out( $o );"
        )


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):  # noqa: D401 - keep the 302 visible
        return None


def php_list(ids: list[int]) -> str:
    return "array( " + ", ".join(str(int(i)) for i in ids) + " )"


def filter_params(field: str, operator: str, value: Any) -> list[tuple[str, str]]:
    """A {operator, value} filter as PHP-style query parameters."""
    out = [(f"{field}[operator]", operator)]
    for item in value if isinstance(value, list) else [value]:
        out.append((f"{field}[value][]" if isinstance(value, list) else f"{field}[value]", str(item)))
    return out


# ---------------------------------------------------------------------------
# screen (T10)
# ---------------------------------------------------------------------------


SCREEN_PATH = "/wp-admin/edit.php?post_type=product&page=bulk-catalogue-manager-for-woocommerce"
SCRIPT_PATH = "/wp-content/plugins/bulk-catalogue-manager-for-woocommerce/assets/build/index.js"


def check_screen(p: Probe) -> None:
    """The admin screen (T8): its mount element, localised settings and bundle for
    the people it is for, a refusal for anyone else, and no asset off the screen."""

    def page(user: str, path: str = SCREEN_PATH) -> tuple[Response, str]:
        opener, _nonce = p.session(user)
        reply = p._raw(opener, "GET", path)
        return reply, reply.body.decode("utf-8", "replace")

    def mounts(user: str) -> tuple[bool, str]:
        reply, html = page(user)
        ok = (
            reply.status == 200
            and html.count('<div id="bulkcat-app"></div>') == 1
            and "window.bulkcatSettings = " in html
            and SCRIPT_PATH in html
            and "/assets/build/style-index.css" in html
            and "notice-error" not in html.split('<div id="bulkcat-app">')[0].split("<h1>Bulk Catalogue Manager</h1>")[-1]
        )
        count = html.count('id="bulkcat-app"')
        return ok, f"{reply.status}, mount x{count}, script {'yes' if SCRIPT_PATH in html else 'no'}"

    def admin_mount() -> None:
        ok, detail = mounts("admin")
        p.row("screen", "screen:admin-mount", ok, detail)

    def manager_mount() -> None:
        ok, detail = mounts("manager")
        p.row("screen", "screen:manager-mount", ok, detail)

    def subscriber_refused() -> None:
        reply, html = page("subscriber")
        p.row("screen", "screen:subscriber-refused", reply.status == 403 and "bulkcat-app" not in html and SCRIPT_PATH not in html,
              f"{reply.status}, mount {'present' if 'bulkcat-app' in html else 'absent'}")

    def assets_only_here() -> None:
        found = []
        for path in ("/wp-admin/", "/wp-admin/edit.php?post_type=product", "/wp-admin/admin.php?page=wc-settings"):
            reply, html = page("admin", path)
            if SCRIPT_PATH in html or "bulkcatSettings" in html or "bulkcat-app" in html:
                found.append(f"{path} ({reply.status})")
        p.row("screen", "screen:assets-only-here", not found, "none elsewhere" if not found else "loaded on " + ", ".join(found))

    for name, fn in (("admin-mount", admin_mount), ("manager-mount", manager_mount),
                     ("subscriber-refused", subscriber_refused), ("assets-only-here", assets_only_here)):
        p.guard("screen", f"screen:{name}", fn)


# ---------------------------------------------------------------------------
# read (T23)
# ---------------------------------------------------------------------------


def read_routes(p: Probe) -> list[tuple[str, str, str, Any, Any, Callable[[Response], str]]]:
    """(surface, method, route, params, body, shape check returning '' when good)."""
    root = p.seed["categories"]["root"]
    variable = p.seed["variable"]["id"]
    simple = p.simple(1)["id"]
    cols = ["id", "sku", "name", "regular_price"]

    def fields_shape(r: Response) -> str:
        d = r.json()
        if not isinstance(d, dict) or set(d) != {"fields", "actions", "groups"}:
            return f"keys {sorted(d) if isinstance(d, dict) else type(d)}"
        keys = [f["key"] for f in d["fields"]]
        return "" if "regular_price" in keys and len(d["actions"]) >= 10 else f"fields {keys[:5]}.. actions {len(d['actions'])}"

    def list_shape(r: Response) -> str:
        d = r.json()
        if not isinstance(d, dict) or not isinstance(d.get("items"), list):
            return "no items list"
        pag = d.get("pagination", {})
        if set(pag) != {"page", "perPage", "total", "totalPages"}:
            return f"pagination {pag}"
        if d["items"] and not {"id", "name", "sku", "regular_price"} <= set(d["items"][0]):
            return f"row keys {sorted(d['items'][0])}"
        return "" if pag["total"] > 0 else "empty"

    def paged_shape(r: Response) -> str:
        d = r.json()
        if not isinstance(d, dict) or not isinstance(d.get("items"), list):
            return "no items list"
        return "" if set(d.get("pagination", {})) == {"page", "perPage", "total", "totalPages"} else f"pagination {d.get('pagination')}"

    def selection_shape(r: Response) -> str:
        d = r.json()
        return "" if isinstance(d, dict) and d.get("count") == 12 and isinstance(d.get("token"), str) else f"{d}"

    def tree_shape(r: Response) -> str:
        d = r.json()
        items = d.get("items", d) if isinstance(d, dict) else d
        node = find_node(items, root)
        if node is None:
            return "probe root category missing from the tree"
        kids = sorted(c["id"] for c in node.get("children", []))
        want = sorted([p.seed["categories"]["shirts"], p.seed["categories"]["hats"]])
        return "" if kids == want else f"children {kids} != {want}"

    def export_shape(r: Response) -> str:
        ctype = r.headers.get("Content-Type", "")
        if "text/csv" not in ctype:
            return f"content-type {ctype}"
        rows = list(csv.reader(io.StringIO(r.body.decode("utf-8-sig"))))
        return "" if len(rows) == 13 else f"{len(rows)} lines (want header + 12)"

    def users_shape(r: Response) -> str:
        d = r.json()
        if not isinstance(d, dict) or not isinstance(d.get("items"), list):
            return "no items list"
        return "" if all(set(u) == {"id", "name"} for u in d["items"]) else f"entries {d['items'][:2]}"

    def csv_header(want: list[str]) -> Callable[[Response], str]:
        def shape(r: Response) -> str:
            ctype = r.headers.get("Content-Type", "")
            if "text/csv" not in ctype:
                return f"content-type {ctype}"
            rows = list(csv.reader(io.StringIO(r.body.decode("utf-8-sig"))))
            return "" if rows and rows[0] == want else f"header {rows[0] if rows else None} want {want}"
        return shape
    def item_shape(want_id: int) -> Callable[[Response], str]:
        def shape(r: Response) -> str:
            d = r.json()
            if not isinstance(d, dict) or set(d) != {"item"} or not isinstance(d["item"], dict):
                return f"{str(d)[:120]}"
            item = d["item"]
            return "" if item.get("id") == want_id and {"id", "parent_id", "sku", "name"} <= set(item) else f"item keys {sorted(item)[:8]}"
        return shape

    def terms_shape(r: Response) -> str:
        d = r.json()
        if not isinstance(d, list):
            return f"not a flat array: {type(d).__name__}"
        if not all(isinstance(t, dict) and set(t) == {"id", "name", "count"} for t in d):
            return f"entries {d[:2]}"
        names = [t["name"] for t in d]
        # The route sorts as PHP's strnatcasecmp: case-insensitive, digit runs as numbers.
        natural = lambda n: [(0, int(x), "") if x.isdigit() else (1, 0, x.casefold()) for x in re.split(r"(\d+)", n)]  # noqa: E731
        return "" if names == sorted(names, key=natural) else f"not by name: {names[:5]}"

    def parent_shape(r: Response) -> str:
        d = r.json()
        problem = paged_shape(r)
        if problem:
            return problem
        rows = d["items"]
        if not rows or d["pagination"]["total"] < 1:
            return "no variations"
        if not all(row.get("parent_id") == variable and isinstance(row.get("attributes"), list) for row in rows):
            return f"rows {[(row.get('id'), row.get('parent_id')) for row in rows][:5]}"
        return "" if all(set(row) <= {"id", "parent_id", "sku", "parent_name", "attribute_summary", "attributes"} for row in rows) else f"row keys {sorted(rows[0])}"

    def views_shape(r: Response) -> str:
        d = r.json()
        if not isinstance(d, dict) or not isinstance(d.get("items"), list) or d.get("max") != 50 or "default" not in d:
            return f"{d}"
        return ""

    cat = filter_params("category_ids", "isAny", [root])
    return [
        ("fields", "GET", "/fields", None, None, fields_shape),
        ("products", "GET", "/products", cat + [("per_page", "100")], None, list_shape),
        ("variations", "GET", f"/products/{variable}/variations", None, None, list_shape),
        ("selection", "POST", "/selection", None, {"category_ids": {"operator": "isAny", "value": [root]}}, selection_shape),
        ("categories", "GET", "/categories", None, None, tree_shape),
        ("export", "GET", "/export", cat + [("columns[]", c) for c in cols], None, export_shape),
        # T54's history and audit routes carry the same four legs.
        ("batches", "GET", "/batches", None, None, paged_shape),
        ("audit", "GET", "/audit", None, None, paged_shape),
        # Server follow-ups 2026-09-23: the batch owners and the history exports (T58).
        ("batches-users", "GET", "/batches/users", None, None, users_shape),
        ("export-audit", "GET", "/export", [("source", "audit"), ("columns[]", "applied_gmt"), ("columns[]", "name")], None, csv_header(["When (UTC)", "Product"])),
        ("export-batches", "GET", "/export", [("source", "batches"), ("columns[]", "batch_id"), ("columns[]", "user_name")], None, csv_header(["Change number", "Who"])),
        # T52's saved views: the current user's own list.
        ("views", "GET", "/views", None, None, views_shape),
        # Server wire-ins 2026-09-23: one row, the flat term lists and a parent-filtered list.
        ("product", "GET", f"/products/{simple}", [("fields[]", "sku"), ("fields[]", "name")], None, item_shape(simple)),
        ("terms-tags", "GET", "/terms/product_tag", None, None, terms_shape),
        ("terms-shipping-classes", "GET", "/terms/product_shipping_class", None, None, terms_shape),
        ("products-parent", "GET", "/products", [("mode", "variations"), ("parent", str(variable)), ("fields[]", "sku"), ("per_page", "100")], None, parent_shape),
    ]


def find_node(nodes: Any, term_id: int) -> dict | None:
    for node in nodes if isinstance(nodes, list) else []:
        if node.get("id") == term_id:
            return node
        found = find_node(node.get("children", []), term_id)
        if found:
            return found
    return None


def check_read_matrix(p: Probe) -> None:
    for name, method, route, params, body, shape in read_routes(p):
        base = f"read:{name}"
        r = p.http(None, method, route, params, body)
        p.row("read", f"{base}:anonymous", r.status == 401 and r.code() == "bulkcat_forbidden", r.brief())
        r = p.http("subscriber", method, route, params, body)
        p.row("read", f"{base}:subscriber", r.status == 403 and r.code() == "bulkcat_forbidden", r.brief())
        r = p.http("manager", method, route, params, body)
        problem = shape(r) if r.status == 200 else r.brief()
        p.row("read", f"{base}:manager", r.status == 200 and problem == "", problem or "200, documented shape")
        stale = p.http("manager", method, route, params, body, nonce="stale")
        bare = p.http("manager", method, route, params, body, nonce="none")
        p.row(
            "read",
            f"{base}:no-valid-nonce",
            stale.status == 403 and stale.code() == "rest_cookie_invalid_nonce" and bare.status == 401,
            f"stale {stale.brief()}; none {bare.brief()}",
        )


def all_ids(p: Probe, params: list[tuple[str, str]], per_page: int = 100) -> tuple[list[int], dict]:
    ids: list[int] = []
    page, pag = 1, {}
    while True:
        r = p.http("manager", "GET", "/products", params + [("per_page", str(per_page)), ("page", str(page))])
        d = r.json()
        if r.status != 200:
            raise AssertionError(r.brief())
        ids += [row["id"] for row in d["items"]]
        pag = d["pagination"]
        if page >= pag["totalPages"]:
            return ids, pag
        page += 1


def truth(p: Probe, args_php: str) -> list[int]:
    """Product ids from wc_get_products() with the given query args."""
    return sorted(p.wp_eval(f"bulkcat_out( wc_get_products( array_merge( array( 'limit' => -1, 'return' => 'ids', 'status' => array( 'publish', 'draft', 'pending', 'private' ) ), {args_php} ) ) );"))


def check_read_queries(p: Probe) -> None:
    root, shirts = p.seed["categories"]["root"], p.seed["categories"]["shirts"]
    cat = filter_params("category_ids", "isAny", [root])

    def category() -> None:
        ids, pag = all_ids(p, cat)
        want = truth(p, "array( 'category' => array( 'bulkcat-probe', 'bulkcat-probe-shirts', 'bulkcat-probe-hats' ) )")
        p.row("read", "read:products:filter-category", sorted(ids) == want and pag["total"] == len(want),
              f"{len(ids)} ids, total {pag['total']}, WooCommerce {len(want)}")

    def search() -> None:
        ids, _ = all_ids(p, cat + [("search", "Probe Simple 0")])
        want = sorted(p.simple(n)["id"] for n in range(1, 10))
        p.row("read", "read:products:search", sorted(ids) == want, f"{len(ids)} ids for 'Probe Simple 0' (want 9)")

    def price_range() -> None:
        ids, _ = all_ids(p, cat + filter_params("regular_price", "between", [12, 15]))
        want = sorted(p.simple(n)["id"] for n in range(2, 6))
        p.row("read", "read:products:filter-price", sorted(ids) == want, f"{ids} want {want}")

    def status() -> None:
        ids, _ = all_ids(p, cat + filter_params("status", "isAny", ["draft"]))
        p.row("read", "read:products:filter-status", ids == [p.seed["draft"]["id"]], f"{ids}")

    def sort() -> None:
        params = filter_params("category_ids", "isAny", [shirts]) + [("orderby", "regular_price"), ("order", "desc")]
        ids, _ = all_ids(p, params + filter_params("type", "isAny", ["simple"]))
        want = [p.simple(n)["id"] for n in (9, 7, 5, 3, 1)]
        p.row("read", "read:products:sort", ids == want, f"{ids} want {want}")

    def paging() -> None:
        ids, pag = all_ids(p, cat, per_page=4)
        whole, _ = all_ids(p, cat)
        ok = ids == whole and len(set(ids)) == len(ids) and pag["totalPages"] == 3 and pag["perPage"] == 4
        p.row("read", "read:products:paging", ok, f"3 pages of 4: {len(ids)} ids, {len(set(ids))} distinct, pagination {pag}")

    def variations() -> None:
        vid = p.seed["variable"]["id"]
        r = p.http("manager", "GET", f"/products/{vid}/variations", [("per_page", "100")])
        d = r.json()
        want = p.wp_eval(f"bulkcat_out( wc_get_product( {vid} )->get_children() );")
        got = [row["id"] for row in d["items"]]
        ok = sorted(got) == sorted(want) and d["pagination"]["total"] == len(want)
        ok = ok and all(row.get("parent_id") == vid and row.get("attribute_summary") for row in d["items"])
        p.row("read", "read:variations:children", ok, f"{got} vs get_children() {want}")
        miss = p.http("manager", "GET", f"/products/{p.simple(1)['id'] + 100000}/variations")
        p.row("read", "read:variations:not-found", miss.status == 404 and miss.code() == "bulkcat_not_found", miss.brief())

    def selection_ids() -> None:
        ids = [p.simple(1)["id"], p.simple(2)["id"], p.simple(1)["id"]]
        r = p.http("manager", "POST", "/selection", body={"ids": ids})
        d = r.json() or {}
        p.row("read", "read:selection:ids", r.status == 200 and d.get("count") == 2, r.brief() + f" count {d.get('count')}")

    def categories_counts() -> None:
        d = p.http("manager", "GET", "/categories").json()
        node = find_node(d.get("items", d) if isinstance(d, dict) else d, shirts)
        want = len(truth(p, "array( 'category' => array( 'bulkcat-probe-shirts' ) )"))
        count = {k: v for k, v in (node or {}).items() if "count" in k}
        p.row("read", "read:categories:counts", node is not None and want in count.values(), f"shirts {count}, WooCommerce {want}")

    def export_diff() -> None:
        cols = ["id", "sku", "name", "regular_price"]
        r = p.http("manager", "GET", "/export", cat + [("columns[]", c) for c in cols] + [("per_page", "4")])
        lines = list(csv.reader(io.StringIO(r.body.decode("utf-8-sig"))))
        rows = [[p_ for p_ in line] for line in lines[1:]]
        grid: list[list[str]] = []
        page = 1
        while True:
            d = p.http("manager", "GET", "/products", cat + [("per_page", "4"), ("page", str(page))]).json()
            grid += [[str(item["id"]), item["sku"], item["name"], item["regular_price"] or ""] for item in d["items"]]
            if page >= d["pagination"]["totalPages"]:
                break
            page += 1
        norm = lambda table: [[cell.strip() for cell in row] for row in table]  # noqa: E731
        p.row("read", "read:export:diff-products", r.status == 200 and page == 3 and norm(rows) == norm(grid),
              f"{len(rows)} CSV rows vs {len(grid)} grid rows over {page} pages; header {lines[0] if lines else None}")

    def hydration() -> None:
        found = p.wp_eval(HYDRATION_PHP % {"user": p.seed["users"]["manager"]["id"]})
        spread = {mode: sorted({v["queries"] for v in sizes.values()}) for mode, sizes in found["modes"].items()}
        rows = {mode: [v["rows"] for v in sizes.values()] for mode, sizes in found["modes"].items()}
        invalid = sum(v["invalid"] for sizes in found["modes"].values() for v in sizes.values())
        p.row("read", "read:products:fixed-query-count", all(len(q) == 1 for q in spread.values()),
              f"queries per page at 25/50/100 rows, cold object cache: {spread}; rows {rows}")
        p.row("read", "read:products:no-product-objects", found["product_reads"] == 0, f"woocommerce_product_read fired {found['product_reads']} times")
        p.row("read", "read:products:live-schema", invalid == 0, f"{invalid} rows invalid against the registered schema (rest_validate_value_from_schema)")

    def include_children() -> None:
        # T62: "this category only" (include_children false) against WordPress's own tax query without children.
        only = cat[:1] + [("category_ids[value][]", str(root)), ("category_ids[include_children]", "false")]
        ids, pag = all_ids(p, only)
        want = sorted(p.wp_eval(
            "bulkcat_out( get_posts( array( 'post_type' => 'product', 'post_status' => array( 'publish', 'draft', 'pending', 'private' ), "
            f"'fields' => 'ids', 'numberposts' => -1, 'tax_query' => array( array( 'taxonomy' => 'product_cat', 'terms' => array( {root} ), 'include_children' => false ) ) ) ) );"
        ))
        branch, _ = all_ids(p, cat + [("category_ids[include_children]", "true")])
        whole, _ = all_ids(p, cat)
        ok = sorted(ids) == want and pag["total"] == len(want) and 0 < len(want) < len(whole) and sorted(branch) == sorted(whole)
        p.row("read", "read:products:filter-category-only", ok,
              f"only {sorted(ids)} vs WordPress {want}; with children {len(branch)} = default {len(whole)}")
        bad = p.http("manager", "GET", "/products", cat + [("category_ids[include_children]", "maybe")])
        p.row("read", "read:products:filter-category-only-refused", bad.status == 400 and bad.code() == "rest_invalid_param", bad.brief())

    def variation_sku() -> None:
        vsku = p.seed["variable"]["variations"][0]["sku"] if isinstance(p.seed["variable"]["variations"][0], dict) else "bulkcat-probe-variable-small"
        mixed, _ = all_ids(p, [("mode", "products_and_variations"), ("search", vsku)])
        plain, _ = all_ids(p, [("search", vsku)])
        want = p.wp_eval(f"bulkcat_out( wc_get_product_id_by_sku( '{vsku}' ) );")
        p.row("read", "read:products:search-variation-sku", mixed == [want] and plain == [],
              f"{vsku}: products_and_variations {mixed}, products {plain}, WooCommerce {want}")

    def category_description() -> None:
        d = p.http("manager", "GET", "/categories").json()
        node = find_node(d.get("items", d) if isinstance(d, dict) else d, root) or {}
        want = p.wp_eval(f"bulkcat_out( get_term( {root}, 'product_cat' )->description );")
        p.row("read", "read:categories:description", node.get("description") == want, f"node {node.get('description')!r} vs term {want!r}")

    def registry_flags() -> None:
        fields = p.http("manager", "GET", "/fields").json()["fields"]
        default = [f["key"] for f in sorted((f for f in fields if f.get("defaultColumn")), key=lambda f: f["defaultOrder"])]
        hiding = {f["key"]: sorted(c["value"] for c in f.get("choices", []) if c.get("hidesProduct")) for f in fields}
        hiding = {k: v for k, v in hiding.items() if v}
        want_hiding = {"status": ["draft", "pending"], "catalog_visibility": ["hidden"], "visible_on_site": ["off"]}
        ok = default == ["sku", "category_ids", "regular_price", "stock_status", "status", "visible_on_site"] and hiding == want_hiding
        p.row("read", "read:fields:flags", ok, f"default columns {default}; hiding choices {hiding}")

    for name, fn in [("hydration", hydration), ("filter-category", category), ("search", search), ("filter-price", price_range),
                     ("filter-status", status), ("sort", sort), ("paging", paging), ("variations", variations),
                     ("selection-ids", selection_ids), ("categories-counts", categories_counts), ("export-diff", export_diff),
                     ("filter-category-only", include_children), ("search-variation-sku", variation_sku),
                     ("categories-description", category_description), ("fields-flags", registry_flags)]:
        p.guard("read", f"read:{name}", fn)


def check_saved_views(p: Probe) -> None:
    """T52, T53: a view saved, marked default and read back into the grid state;
    another user neither lists nor reaches it; the export downloads with the
    nonce as a _wpnonce query argument, as the Export control sends it."""
    name = "Probe saved view"
    view = {"type": "grid", "perPage": 50, "search": "probe", "fields": ["sku", "regular_price"],
            "filters": [{"field": "stock_status", "operator": "isAny", "value": ["instock"]}],
            "sort": {"field": "regular_price", "direction": "desc"}, "page": 4, "futureKey": {"kept": True}}
    category = {"id": p.seed["categories"]["root"], "includeChildren": True}
    state: dict = {}

    def create() -> None:
        for item in (p.http("manager", "GET", "/views").json() or {}).get("items", []):
            if item["name"] == name:
                p.http("manager", "DELETE", f"/views/{item['id']}")
        r = p.http("manager", "POST", "/views", body={"name": name, "view": view, "category": category})
        item = (r.json() or {}).get("item", {})
        state["id"] = item.get("id")
        p.row("read", "read:views:create", r.status == 201 and item.get("name") == name, r.brief())

    def default_and_reload() -> None:
        r = p.http("manager", "POST", f"/views/{state['id']}/default", body={"default": True})
        d = p.http("manager", "GET", "/views").json()
        item = next((i for i in d["items"] if i["id"] == state["id"]), {})
        want = {k: v for k, v in view.items() if k != "page"}
        p.row("read", "read:views:default-reload", r.status == 200 and d["default"] == state["id"] and item.get("isDefault") is True
              and item.get("view") == want and item.get("category") == category,
              f"default {d['default']}; view {item.get('view')}")

    def isolation() -> None:
        listed = p.http("admin", "GET", "/views").json()
        other = [i for i in listed.get("items", []) if i["id"] == state["id"]]
        patch = p.http("admin", "PATCH", f"/views/{state['id']}", body={"name": "taken"})
        gone = p.http("admin", "DELETE", f"/views/{state['id']}")
        p.row("read", "read:views:other-user", not other and patch.status == 404 and gone.status == 404
              and patch.code() == "bulkcat_view_not_found", f"listed {len(other)}; PATCH {patch.brief()}; DELETE {gone.brief()}")
        sub = p.http("subscriber", "POST", "/views", body={"name": "x", "view": view})
        p.row("read", "read:views:subscriber-write", sub.status == 403, sub.brief())

    def refusals() -> None:
        bad = p.http("manager", "POST", "/views", body={"name": name + " bad", "view": {"type": "kanban"}})
        dup = p.http("manager", "POST", "/views", body={"name": name.upper(), "view": view})
        p.row("read", "read:views:schema", bad.code() == "bulkcat_invalid_view" and dup.code() == "bulkcat_view_exists", f"{bad.brief()}; {dup.brief()}")

    def export_query_nonce() -> None:
        _, nonce = p.session("manager")
        params = filter_params("category_ids", "isAny", [p.seed["categories"]["root"]]) + [
            ("columns[]", "name"), ("columns[]", "sku"), ("_wpnonce", nonce)]
        r = p.http("manager", "GET", "/export", params, nonce="none")
        ctype = r.headers.get("Content-Type", "")
        rows = list(csv.reader(io.StringIO(r.body.decode("utf-8-sig")))) if "text/csv" in ctype else []
        p.row("read", "read:export:query-nonce", r.status == 200 and len(rows) == 13, f"{r.status} {ctype}; {len(rows)} lines")

    def cleanup() -> None:
        r = p.http("manager", "DELETE", f"/views/{state['id']}")
        p.row("read", "read:views:delete", r.status == 200 and all(i["id"] != state["id"] for i in r.json()["items"]), r.brief())

    for surface, fn in [("views:create", create), ("views:default-reload", default_and_reload), ("views:other-user", isolation),
                        ("views:schema", refusals), ("export:query-nonce", export_query_nonce), ("views:delete", cleanup)]:
        if surface != "views:create" and surface != "export:query-nonce" and not state.get("id"):
            p.row("read", f"read:{surface}", False, "no view from the create step")
            continue
        p.guard("read", f"read:{surface}", fn)


# ---------------------------------------------------------------------------
# write
# ---------------------------------------------------------------------------

ADJUST = [{"action": "adjust", "args": {"field": "regular_price", "direction": "increase", "by": "percent", "amount": "10"}}]


def check_write_engine(p: Probe) -> None:
    shirts = p.seed["categories"]["shirts"]
    targets = [p.simple(n) for n in (1, 3, 5, 7, 9)]
    ids = [t["id"] for t in targets]
    state: dict[str, Any] = {}

    def refusals() -> None:
        bodies = [("POST", "/preview", {"ids": ids, "operations": ADJUST}),
                  ("POST", "/batches", {"uuid": str(uuid.uuid4()), "ids": ids, "operations": ADJUST}),
                  ("PATCH", f"/products/{ids[0]}", {"regular_price": "1.00"})]
        bad = []
        for method, route, body in bodies:
            anon, sub = p.http(None, method, route, body=body), p.http("subscriber", method, route, body=body)
            if anon.status != 401 or sub.status != 403:
                bad.append(f"{route}: {anon.status}/{sub.status}")
        p.row("write", "write:refusals", not bad, "; ".join(bad) or "anonymous 401, subscriber 403 on preview, batches, PATCH")

    def preview() -> None:
        before = p.modified(ids)
        sel = p.http("manager", "POST", "/selection", body={"category_ids": {"operator": "isAny", "value": [shirts]}}).json()
        r = p.http("manager", "POST", "/preview", body={"token": sel["token"], "operations": ADJUST})
        d = r.json() or {}
        state.update(token=sel["token"], hash=d.get("hash"), count=sel["count"])
        totals = d.get("totals", {})
        untouched = p.modified(ids) == before and p.prices(ids) == {str(t["id"]): t["regular_price"] for t in targets}
        ok = r.status == 200 and totals.get("would_change") == 5 and re.fullmatch(r"[0-9a-f]{64}", d.get("hash") or "") and untouched
        p.row("write", "write:preview", bool(ok), f"{r.status} would_change {totals.get('would_change')} matched {totals.get('matched')}, nothing saved: {untouched}")

    def apply() -> None:
        body = {"uuid": str(uuid.uuid4()), "token": state["token"], "operations": ADJUST,
                "preview_hash": state["hash"], "preview_count": state["count"]}
        r = p.http("manager", "POST", "/batches", body=body)
        d = r.json() or {}
        again = p.http("manager", "POST", "/batches", body=body).json() or {}
        state["batch"] = d.get("batch_id")
        ok = r.status == 201 and d.get("status") == "queued" and again.get("replayed") is True and again.get("batch_id") == d.get("batch_id")
        p.row("write", "write:batch-create", ok, f"{r.brief()} {d}; replay {again}")

    def run() -> None:
        report = p.run_queue(state["batch"])
        # Six matched (five simple shirts and the variable parent, which has no
        # regular price of its own and is skipped with a reason): partial, by design.
        ok = report.get("status") == "partial" and report.get("changed_items") == 5 and report.get("failed_items") == 0 and report.get("pending_items") == 0
        p.row("write", "write:batch-run", ok, f"status {report.get('status')} total {report.get('total_items')} changed {report.get('changed_items')} failed {report.get('failed_items')} summary {[s.get('sentence') for s in report.get('summary') or []]}")
        rev = (report.get("reversibility") or {}).get("state")
        p.row("write", "write:batch-reversible", rev in ("reversible", "completed_with_errors"), f"reversibility {report.get('reversibility')}")

    def verify() -> None:
        want = {str(t["id"]): f"{round(float(t['regular_price']) * 1.1 + 1e-9, 2):.2f}" for t in targets}
        got = p.prices(ids)
        p.row("write", "write:batch-values", got == want, f"{got} want {want}")
        feed = p.http("manager", "GET", f"/batches/{state['batch']}/changes", [("per_page", "100")]).json() or {}
        applied = [c for c in feed.get("items", []) if c.get("result") == "applied"]
        p.row("write", "write:batch-changes-feed", len(applied) == 5 and all(c.get("field") == "regular_price" for c in applied),
              f"{len(applied)} applied rows of {feed.get('pagination')}")

    def undo() -> None:
        route = f"/batches/{state['batch']}/undo"
        pre = p.http("manager", "POST", route, body={"preview": True})
        d = pre.json() or {}
        p.row("write", "write:undo-preview", pre.status == 200 and d.get("preview") is True and re.fullmatch(r"[0-9a-f]{64}", d.get("plan_hash") or "") is not None,
              f"{pre.brief()} counts {d.get('counts')}")
        r = p.http("manager", "POST", route, body={"uuid": str(uuid.uuid4()), "plan_hash": d.get("plan_hash")})
        u = r.json() or {}
        p.row("write", "write:undo-apply", r.status == 201 and bool(u.get("batch_id")), f"{r.brief()} {u.get('batch_id')}")
        report = p.run_queue(u["batch_id"])
        original = p.http("manager", "GET", f"/batches/{state['batch']}").json() or {}
        p.row("write", "write:undo-run", report.get("status") == "complete" and original.get("status") == "undone",
              f"undo batch {report.get('status')}, original {original.get('status')}, undo {(report.get('undo') or {}).get('label')}")
        got = p.prices(ids)
        want = {str(t["id"]): t["regular_price"] for t in targets}
        p.row("write", "write:undo-values", got == want, f"{got} want {want}")

    def ids_need_preview() -> None:
        # Security review m6: ticked rows are held to their preview as a filter is.
        target = p.simple(2)
        ops = [{"action": "set_value", "args": {"field": "regular_price", "value": target["regular_price"]}}]
        before = p.modified([target["id"]])
        bare = p.http("manager", "POST", "/batches", body={"uuid": str(uuid.uuid4()), "ids": [target["id"]], "operations": ops})
        wrong = p.http("manager", "POST", "/batches", body={"uuid": str(uuid.uuid4()), "ids": [target["id"]], "operations": ops, "preview_hash": "0" * 64})
        untouched = p.modified([target["id"]]) == before
        ok = bare.status == 409 and bare.code() == "bulkcat_preview_stale" and wrong.status == 409 and wrong.code() == "bulkcat_preview_stale" and untouched
        p.row("write", "write:ids-need-preview", ok, f"no hash {bare.brief()}, wrong hash {wrong.brief()}, nothing written: {untouched}")

    def skip_already() -> None:
        target = p.simple(4)
        before = p.modified([target["id"]])
        ops = [{"action": "set_value", "args": {"field": "regular_price", "value": target["regular_price"]}}]
        pre = p.http("manager", "POST", "/preview", body={"ids": [target["id"]], "operations": ops}).json() or {}
        body = {"uuid": str(uuid.uuid4()), "ids": [target["id"]], "operations": ops, "preview_hash": pre.get("hash")}
        d = p.http("manager", "POST", "/batches", body=body).json() or {}
        report = p.run_queue(d.get("batch_id", 0))
        same = p.modified([target["id"]]) == before
        p.row("write", "write:skip-already", report.get("status") in ("complete", "partial") and report.get("changed_items") == 0 and same,
              f"status {report.get('status')} changed {report.get('changed_items')}, post_modified_gmt untouched: {same}")

    for name, fn in [("refusals", refusals), ("preview", preview), ("batch", apply), ("batch-run", run),
                     ("batch-values", verify), ("undo", undo), ("ids-need-preview", ids_need_preview), ("skip-already", skip_already)]:
        if name not in ("refusals", "preview") and not state.get("token"):
            p.row("write", f"write:{name}", False, "no selection token from the preview step")
            continue
        if name in ("batch-run", "batch-values", "undo") and not state.get("batch"):
            p.row("write", f"write:{name}", False, "no batch from the batch step")
            continue
        p.guard("write", f"write:{name}", fn)


def check_write_stale(p: Probe) -> None:
    hats, root = p.seed["categories"]["hats"], p.seed["categories"]["root"]
    draft = p.seed["draft"]["id"]
    ops = [{"action": "set_value", "args": {"field": "weight", "value": "1"}}]
    hat_filter = {"category_ids": {"operator": "isAny", "value": [hats]}}
    grid = filter_params("category_ids", "isAny", [hats])

    def run() -> None:
        sel = p.http("manager", "POST", "/selection", body=hat_filter).json()
        pre = p.http("manager", "POST", "/preview", body={"token": sel["token"], "operations": ops}).json()
        total_before = p.http("manager", "GET", "/products", grid).json()["pagination"]["total"]
        p.wp_eval(f"$p = wc_get_product( {draft} ); $p->set_category_ids( array( {hats} ) ); $p->save(); bulkcat_out( 1 );")
        try:
            total_after = p.http("manager", "GET", "/products", grid).json()["pagination"]["total"]
            p.row("write", "write:count-cache-renewed", total_after == total_before + 1, f"hats total {total_before} -> {total_after} after a product save")
            body = {"uuid": str(uuid.uuid4()), "token": sel["token"], "operations": ops, "preview_hash": pre["hash"], "preview_count": sel["count"]}
            first = p.http("manager", "POST", "/batches", body=body)
            p.row("write", "write:stale-selection-refused", first.status == 409 and first.code() == "bulkcat_selection_changed", first.brief())
            body["uuid"] = str(uuid.uuid4())
            body["confirm_changed"] = True
            second = p.http("manager", "POST", "/batches", body=body)
            d = second.json() or {}
            report = p.http("manager", "GET", f"/batches/{d.get('batch_id', 0)}").json() or {}
            p.row("write", "write:stale-selection-confirmed", second.status == 201 and report.get("previewed_count") == sel["count"] and report.get("total_items") == sel["count"] + 1,
                  f"{second.brief()} previewed {report.get('previewed_count')} total {report.get('total_items')}: {report.get('selection_difference')}")
            before = p.modified([t["id"] for t in p.seed["simple"]] + [draft])
            cancel = p.http("manager", "POST", f"/batches/{d.get('batch_id', 0)}/cancel")
            p.run_queue(d.get("batch_id", 0), rounds=2)
            final = p.http("manager", "GET", f"/batches/{d.get('batch_id', 0)}").json() or {}
            untouched = p.modified([t["id"] for t in p.seed["simple"]] + [draft]) == before
            p.row("write", "write:cancel-before-run", cancel.status == 200 and final.get("status") == "cancelled" and final.get("done_items") == 0 and untouched,
                  f"{cancel.brief()} status {final.get('status')} done {final.get('done_items')}, nothing written: {untouched}")
        finally:
            p.wp_eval(f"$p = wc_get_product( {draft} ); $p->set_category_ids( array( {root} ) ); $p->save(); bulkcat_out( 1 );")

    p.guard("write", "write:stale-selection", run)


def check_write_inline(p: Probe) -> None:
    target = p.simple(2)

    def patch() -> None:
        r = p.http("manager", "PATCH", f"/products/{target['id']}", body={"regular_price": "99.00"})
        d = r.json() or {}
        live = p.prices([target["id"]])[str(target["id"])]
        ok = r.status == 200 and d.get("status") == "complete" and (d.get("item") or {}).get("regular_price") == "99.00" and live == "99.00"
        p.row("write", "write:patch-product", ok, f"{r.brief()} status {d.get('status')} batch {d.get('batch_id')} live {live}")
        back = p.http("manager", "PATCH", f"/products/{target['id']}", body={"regular_price": target["regular_price"]})
        live = p.prices([target["id"]])[str(target["id"])]
        p.row("write", "write:patch-product-back", back.status == 200 and live == target["regular_price"], f"{back.brief()} live {live}")

    p.guard("write", "write:patch-product", patch)


def check_write_categories(p: Probe) -> None:
    root, shirts = p.seed["categories"]["root"], p.seed["categories"]["shirts"]
    name = f"Probe New {uuid.uuid4().hex[:8]}"

    def run() -> None:
        r = p.http("manager", "POST", "/categories", body={"name": name, "parent": root})
        d = r.json() or {}
        term_id = d.get("id") or (d.get("item") or {}).get("id") or (d.get("term") or {}).get("id")
        live = p.wp_eval(f"$t = get_term_by( 'name', '{name}', 'product_cat' ); bulkcat_out( $t ? array( $t->term_id, $t->parent ) : null );")
        tree = p.http("manager", "GET", "/categories").json()
        in_tree = live is not None and find_node(tree.get("items", tree) if isinstance(tree, dict) else tree, live[0]) is not None
        p.row("write", "write:category-create", r.status in (200, 201) and live is not None and live[1] == root and in_tree,
              f"{r.brief()} live {live} answer id {term_id}, in the cached tree at once: {in_tree}")
        if live is None:
            return
        term_id = live[0]
        r = p.http("manager", "PATCH", f"/categories/{term_id}", body={"name": name + " Edited", "description": "Edited by the probe"})
        got = p.wp_eval(f"$t = get_term( {term_id}, 'product_cat' ); bulkcat_out( array( $t->name, $t->description ) );")
        p.row("write", "write:category-edit", r.status == 200 and got == [name + " Edited", "Edited by the probe"], f"{r.brief()} live {got}")
        tree = p.http("manager", "GET", "/categories").json()
        node = find_node(tree.get("items", tree) if isinstance(tree, dict) else tree, term_id) or {}
        p.row("write", "write:category-description", node.get("description") == "Edited by the probe", f"tree node description {node.get('description')!r}")
        r = p.http("manager", "POST", f"/categories/{term_id}/move", body={"parent": shirts, "position": 0})
        parent = p.wp_eval(f"bulkcat_out( get_term( {term_id}, 'product_cat' )->parent );")
        p.row("write", "write:category-move", r.status == 200 and parent == shirts, f"{r.brief()} parent {parent} want {shirts}")
        sub = p.http("subscriber", "POST", "/categories", body={"name": name + " sub"})
        p.row("write", "write:category-refusal", sub.status == 403, sub.brief())
        p.wp("term", "delete", "product_cat", str(term_id), check=False)

    p.guard("write", "write:categories", run)


def check_write_history(p: Probe) -> None:
    """History rows and exports over the batches the write engine made (server follow-ups 2026-09-23, T58)."""
    manager_id = p.seed["users"]["manager"]["id"]

    def lines(r: Response) -> list[list[str]]:
        return list(csv.reader(io.StringIO(r.body.decode("utf-8-sig"))))

    def run() -> None:
        listing = p.http("manager", "GET", "/batches", [("per_page", "100")]).json() or {}
        items = listing.get("items", [])
        undone = next((b for b in items if b.get("status") == "undone"), None)
        names = p.wp_eval(f"bulkcat_out( get_userdata( {manager_id} )->display_name );")
        ok = bool(items) and all(isinstance(b.get("variations_included"), bool) and b.get("user_name") for b in items)
        ok = ok and all((b.get("undone_by") is None) == (b.get("status") != "undone") for b in items)
        by = (undone or {}).get("undone_by") or {}
        ok = ok and undone is not None and by.get("user_name") == names and by.get("batch_id", 0) > undone["batch_id"]
        p.row("write", "write:history-rows", ok, f"{len(items)} rows; undone #{(undone or {}).get('batch_id')} put back by {by}; manager is {names!r}")

        users = p.http("manager", "GET", "/batches/users").json() or {}
        p.row("write", "write:history-users", {"id": manager_id, "name": names} in users.get("items", []), f"{users.get('items')}")

        if undone is None:
            p.row("write", "write:export-changes", False, "no undone batch from the engine")
            return
        bid = undone["batch_id"]
        cols = ["object_id", "name", "field_label", "old_value", "new_value", "result"]
        r = p.http("manager", "GET", "/export", [("source", "changes"), ("batch", str(bid))] + [("columns[]", c) for c in cols])
        csv_rows = lines(r)
        feed = p.http("manager", "GET", f"/batches/{bid}/changes", [("per_page", "100")]).json() or {}
        want = [[str(c["object_id"]), c["name"], c["field_label"], str(c["old_value"]), str(c["new_value"])] for c in feed.get("items", [])]
        got = [row[:5] for row in csv_rows[1:]]
        ok = r.status == 200 and f"change-{bid}-" in r.headers.get("Content-Disposition", "") and got == want and len(want) == feed["pagination"]["total"] > 0
        ok = ok and all(row[5] in ("Applied", "Skipped", "Failed") for row in csv_rows[1:])
        p.row("write", "write:export-changes", ok, f"{r.brief()} {len(got)} CSV rows vs {len(want)} feed rows; header {csv_rows[0] if csv_rows else None}")

        product = feed["items"][0]["object_id"] if feed.get("items") else 0
        r = p.http("manager", "GET", "/export", [("source", "audit"), ("product", str(product)), ("columns[]", "batch_id"), ("columns[]", "object_id")])
        audit = p.http("manager", "GET", "/audit", [("product", str(product)), ("per_page", "100")]).json() or {}
        got = [[int(a), int(b)] for a, b in lines(r)[1:]]
        want = [[a["batch_id"], a["object_id"]] for a in audit.get("items", [])]
        p.row("write", "write:export-audit", r.status == 200 and got == want and len(want) == audit["pagination"]["total"] > 0,
              f"product {product}: {len(got)} CSV rows vs {len(want)} audit rows")

        r = p.http("manager", "GET", "/export", [("source", "batches"), ("status", "undone"), ("columns[]", "batch_id"), ("columns[]", "status"), ("columns[]", "undone_by")])
        rows = lines(r)[1:]
        total = (p.http("manager", "GET", "/batches", [("status", "undone")]).json() or {}).get("pagination", {}).get("total")
        ok = r.status == 200 and len(rows) == total and all(row[1] == "Undone" and row[2].startswith("#") for row in rows)
        p.row("write", "write:export-batches", ok, f"{len(rows)} undone rows vs {total}; first {rows[:1]}")

        refusals = [
            p.http(None, "GET", "/export", [("source", "changes"), ("batch", str(bid)), ("columns[]", "name")]).status,
            p.http("subscriber", "GET", "/export", [("source", "changes"), ("batch", str(bid)), ("columns[]", "name")]).status,
            p.http("manager", "GET", "/export", [("source", "changes"), ("columns[]", "name")]).status,
            p.http("manager", "GET", "/export", [("source", "changes"), ("batch", "999999"), ("columns[]", "name")]).status,
        ]
        p.row("write", "write:export-history-refusals", refusals == [401, 403, 400, 404], f"anonymous, subscriber, no batch, unknown batch: {refusals}")

    p.guard("write", "write:history", run)


# ---------------------------------------------------------------------------
# access
# ---------------------------------------------------------------------------

SET_ADMIN_ONLY = (
    "$s = get_option( 'bulkcat_settings', array() ); if ( ! is_array( $s ) ) { $s = array(); } "
    "$s['administrators_only'] = %s; update_option( 'bulkcat_settings', $s ); bulkcat_out( get_option( 'bulkcat_settings' )['administrators_only'] );"
)


def check_access(p: Probe) -> None:
    def run() -> None:
        p.wp_eval(SET_ADMIN_ONLY % "true")
        try:
            manager = p.http("manager", "GET", "/fields")
            admin = p.http("admin", "GET", "/fields")
            p.row("access", "access:administrators-only:manager", manager.status == 403 and manager.code() == "bulkcat_forbidden", manager.brief())
            p.row("access", "access:administrators-only:admin", admin.status == 200, admin.brief())
        finally:
            p.wp_eval(SET_ADMIN_ONLY % "false")
        back = p.http("manager", "GET", "/fields")
        p.row("access", "access:administrators-only:off-again", back.status == 200, back.brief())

    p.guard("access", "access:administrators-only", run)


def check_settings(p: Probe) -> None:
    """T73: GET /settings for anyone with the screen capability, PUT for manage_options
    only, and a refused value named by its setting with nothing saved."""

    def run() -> None:
        before = p.wp_eval("bulkcat_out( get_option( 'bulkcat_settings', null ) );")
        got = p.http("manager", "GET", "/settings")
        data = got.json() or {}
        keys = {row.get("key") for row in data.get("settings", [])}
        p.row(
            "access",
            "settings:get:manager",
            got.status == 200 and data.get("canEdit") is False and "variation_batch_size" in keys,
            f"{got.brief()} canEdit={data.get('canEdit')} settings={len(keys)}",
        )
        put = p.http("manager", "PUT", "/settings", body={"values": {"rows_per_page": 50}})
        p.row("access", "settings:put:manager", put.status == 403, put.brief())
        bad = p.http("admin", "PUT", "/settings", body={"values": {"variation_batch_size": 5, "rows_per_page": 50}})
        refusal = bad.json() or {}
        errors = (refusal.get("data") or {}).get("errors") or {}
        after = p.wp_eval("bulkcat_out( get_option( 'bulkcat_settings', null ) );")
        p.row(
            "access",
            "settings:put:admin-invalid",
            bad.status == 400
            and bad.code() == "bulkcat_invalid_setting"
            and list(errors) == ["variation_batch_size"]
            and "Variations per background batch" in refusal.get("message", "")
            and after == before,
            f"{bad.brief()} errors={list(errors)} saved-nothing={after == before}",
        )

    p.guard("access", "settings", run)


# ---------------------------------------------------------------------------
# lifecycle
# ---------------------------------------------------------------------------

HYDRATION_PHP = r"""
wp_set_current_user( %(user)d );
$get = function ( $params ) { $r = new WP_REST_Request( 'GET', '/bulkcat/v1/products' ); foreach ( $params as $k => $v ) { $r->set_param( $k, $v ); } return rest_do_request( $r ); };
$reads = 0;
add_action( 'woocommerce_product_read', function () use ( &$reads ) { $reads++; } );
$opts   = rest_get_server()->get_route_options( '/bulkcat/v1/products' );
$schema = call_user_func( $opts['schema'] );
global $wpdb;
$out = array();
foreach ( array( 'products', 'products_and_variations', 'variations' ) as $mode ) {
	$get( array( 'per_page' => 10, 'mode' => $mode ) ); // warms the shared count, which paging does not change
	foreach ( array( 25, 50, 100 ) as $n ) {
		wp_cache_flush();
		$get( array() ); // re-reads options and WooCommerce state outside the measured window
		wp_cache_flush();
		$before = $wpdb->num_queries;
		// Id order keeps every page's make-up alike (products and variations mixed), since the
		// variation lookups run only for a page that holds variation rows.
		$res    = $get( array( 'per_page' => $n, 'mode' => $mode, 'orderby' => array( 'id' ), 'order' => array( 'asc' ) ) );
		$errors = 0;
		foreach ( $res->get_data()['items'] as $item ) {
			$errors += is_wp_error( rest_validate_value_from_schema( $item, $schema, 'item' ) ) ? 1 : 0;
		}
		$out[ $mode ][ $n ] = array( 'queries' => $wpdb->num_queries - $before, 'rows' => count( $res->get_data()['items'] ), 'invalid' => $errors );
	}
}
bulkcat_out( array( 'modes' => $out, 'product_reads' => $reads ) );
"""

INDEXES_PHP = r"""
global $wpdb;
$out = array();
foreach ( \BulkCatalogueManager\Data\Registry::plugin()->tables() as $name => $body ) {
	$table = $wpdb->prefix . $name;
	preg_match_all( '/^\s*(PRIMARY KEY|UNIQUE KEY \w+|KEY \w+)\s*\(([^)]*)\)/m', $body, $m, PREG_SET_ORDER );
	$want = array();
	foreach ( $m as $k ) {
		$key = 'PRIMARY KEY' === $k[1] ? 'PRIMARY' : preg_replace( '/^(UNIQUE )?KEY /', '', $k[1] );
		$want[ $key ] = array_map( 'trim', explode( ',', $k[2] ) );
	}
	$have = array();
	$exists = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( $exists ) {
		foreach ( $wpdb->get_results( "SHOW INDEX FROM `$table`", ARRAY_A ) as $row ) {
			$have[ $row['Key_name'] ][ (int) $row['Seq_in_index'] - 1 ] = $row['Column_name'];
		}
	}
	ksort( $want );
	ksort( $have );
	$out[ $name ] = array( 'exists' => $exists, 'want' => $want, 'have' => array_map( 'array_values', $have ) );
}
bulkcat_out( $out );
"""

FOOTPRINT_PHP = r"""
global $wpdb;
$like = $wpdb->esc_like( $wpdb->prefix . 'bulkcat_' ) . '%';
$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
$rows = array();
foreach ( $tables as $t ) { $rows[ $t ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$t`" ); }
bulkcat_out( array(
	'tables'    => $rows,
	'options'   => $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'bulkcat\\_%' OR option_name LIKE '\\_transient\\_bulkcat\\_%' OR option_name LIKE '\\_transient\\_timeout\\_bulkcat\\_%' ORDER BY option_name" ),
	'usermeta'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key LIKE 'bulkcat\\_%'" ),
	'termmeta'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key LIKE 'bulkcat\\_%'" ),
	'actions'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions a JOIN {$wpdb->prefix}actionscheduler_groups g ON g.group_id = a.group_id WHERE g.slug = 'bulk-catalogue-manager-for-woocommerce' AND a.status = 'pending'" ),
	'version'   => get_option( 'bulkcat_db_version', null ),
) );
"""

COMPAT_PHP = r"""
$c = wc_get_container()->get( \Automattic\WooCommerce\Internal\Features\FeaturesController::class );
$file = 'bulk-catalogue-manager-for-woocommerce/bulk-catalogue-manager-for-woocommerce.php';
$o = array();
foreach ( array( 'custom_order_tables', 'cart_checkout_blocks' ) as $f ) {
	$l = $c->get_compatible_plugins_for_feature( $f, true );
	$o[ $f ] = array( 'compatible' => in_array( $file, $l['compatible'], true ), 'incompatible' => in_array( $file, $l['incompatible'], true ), 'uncertain' => in_array( $file, $l['uncertain'] ?? array(), true ) );
}
bulkcat_out( $o );
"""


def set_remove_data(p: Probe, on: bool) -> None:
    p.wp_eval(
        "$s = get_option( 'bulkcat_settings', array() ); if ( ! is_array( $s ) ) { $s = array(); } "
        f"$s['remove_data_on_delete'] = {'true' if on else 'false'}; update_option( 'bulkcat_settings', $s ); bulkcat_out( 1 );"
    )


def check_lifecycle(p: Probe) -> None:
    def tables() -> None:
        found = p.wp_eval(INDEXES_PHP)
        bad = [f"{n}: want {t['want']} have {t['have']}" for n, t in found.items() if not t["exists"] or t["want"] != t["have"]]
        p.row("lifecycle", "lifecycle:tables-and-indexes", len(found) == 3 and not bad, "; ".join(bad) or f"{sorted(found)} with every documented index")

    def compat() -> None:
        found = p.wp_eval(COMPAT_PHP)
        ok = all(v["compatible"] and not v["incompatible"] and not v["uncertain"] for v in found.values())
        p.row("lifecycle", "lifecycle:woocommerce-compatibility", ok, json.dumps(found))

    def bump() -> None:
        before = p.wp_eval(FOOTPRINT_PHP)
        p.wp("option", "update", "bulkcat_db_version", "5")
        p.http("admin", "GET", "/fields")  # a REST request does not run admin_init; the admin page does
        p._raw(p.session("admin")[0], "GET", "/wp-admin/")
        after = p.wp_eval(FOOTPRINT_PHP)
        ok = after["version"] == before["version"] and after["tables"] == before["tables"] and sum(before["tables"].values()) > 0
        p.row("lifecycle", "lifecycle:version-bump-keeps-rows", ok, f"version {before['version']} -> 5 -> {after['version']}; rows {before['tables']} -> {after['tables']}")

    def reactivate() -> None:
        before = p.wp_eval(FOOTPRINT_PHP)
        p.wp("plugin", "deactivate", PLUGIN)
        mid = p.wp_eval(FOOTPRINT_PHP)
        p.wp("plugin", "activate", PLUGIN)
        p.wp("plugin", "activate", PLUGIN, check=False)  # activating an active plugin is a no-op
        after = p.wp_eval(FOOTPRINT_PHP)
        ok = after == before and mid["actions"] == 0 and mid["tables"] == before["tables"] and before["actions"] == 1
        p.row("lifecycle", "lifecycle:deactivate-activate-idempotent", ok,
              f"prune actions {before['actions']} -> {mid['actions']} -> {after['actions']}; rows kept {after['tables'] == before['tables']}")

    def uninstall(on: bool) -> None:
        set_remove_data(p, on)
        before = p.wp_eval(FOOTPRINT_PHP)
        p.wp("plugin", "uninstall", PLUGIN, "--deactivate", "--skip-delete")
        after = p.wp_eval(FOOTPRINT_PHP)
        if on:
            ok = not after["tables"] and not after["options"] and after["usermeta"] == 0 and after["termmeta"] == 0 and after["actions"] == 0
            surface, detail = "lifecycle:uninstall-remove-data-on", f"left {after}"
        else:
            kept = {k: v for k, v in before.items() if k != "actions"} == {k: v for k, v in after.items() if k != "actions"}
            ok = kept and sum(before["tables"].values()) > 0 and after["actions"] == 0
            surface, detail = "lifecycle:uninstall-remove-data-off", f"rows {after['tables']}, {len(after['options'])} options kept, termmeta {after['termmeta']}"
        p.row("lifecycle", surface, ok, detail)
        p.wp("plugin", "activate", PLUGIN)
        again = p.wp_eval(FOOTPRINT_PHP)
        p.row("lifecycle", surface + ":reinstalled", len(again["tables"]) == 3 and again["version"] is not None and again["actions"] == 1,
              f"tables {sorted(again['tables'])} version {again['version']} prune actions {again['actions']}")

    for name, fn in [("tables-and-indexes", tables), ("woocommerce-compatibility", compat), ("version-bump", bump),
                     ("reactivate", reactivate), ("uninstall-off", lambda: uninstall(False)), ("uninstall-on", lambda: uninstall(True))]:
        p.guard("lifecycle", f"lifecycle:{name}", fn)
    p.guard("lifecycle", "lifecycle:remove-data-reset", lambda: set_remove_data(p, False))


# ---------------------------------------------------------------------------
# variations (T72)
# ---------------------------------------------------------------------------

SIXTY_SKU = "bulkcat-probe-sixty"
SIXTY = 60

# Upserts variable product bulkcat-probe-sixty with variations 1..60 (menu order
# 1..60) at 10.00 + (n - 1), every third out of stock, none managing stock,
# resynchronises it through WooCommerce, and echoes the parent and the
# variation ids in menu order with their prices and stock statuses.
SIXTY_SEED_PHP = r"""
$sku    = '%s';
$count  = %d;
$id     = wc_get_product_id_by_sku( $sku );
$parent = $id ? wc_get_product( $id ) : new WC_Product_Variable();
$options = array();
for ( $n = 1; $n <= $count; $n++ ) { $options[] = (string) $n; }
$attribute = new WC_Product_Attribute();
$attribute->set_name( 'Number' );
$attribute->set_options( $options );
$attribute->set_visible( true );
$attribute->set_variation( true );
$parent->set_name( 'Probe Sixty' );
$parent->set_sku( $sku );
$parent->set_status( 'publish' );
$parent->set_attributes( array( $attribute ) );
$parent_id = (int) $parent->save();
$out = array();
for ( $n = 1; $n <= $count; $n++ ) {
	$vsku = $sku . '-' . $n;
	$vid  = wc_get_product_id_by_sku( $vsku );
	$v    = $vid ? wc_get_product( $vid ) : new WC_Product_Variation();
	$v->set_parent_id( $parent_id );
	$v->set_sku( $vsku );
	$v->set_status( 'publish' );
	$v->set_menu_order( $n );
	$v->set_attributes( array( 'number' => (string) $n ) );
	$v->set_regular_price( sprintf( '%%d.00', 9 + $n ) );
	$v->set_sale_price( '' );
	$v->set_manage_stock( false );
	$v->set_stock_status( 0 === $n %% 3 ? 'outofstock' : 'instock' );
	$out[] = array( 'id' => (int) $v->save(), 'price' => $v->get_regular_price( 'edit' ), 'stock' => $v->get_stock_status( 'edit' ) );
}
WC_Product_Variable::sync( $parent_id );
wc_delete_product_transients( $parent_id );
bulkcat_out( array( 'parent' => $parent_id, 'variations' => $out ) );
"""

# The parent's lookup row and every variation's stored regular price.
SIXTY_STATE_PHP = r"""
global $wpdb;
$parent = %d;
$row    = $wpdb->get_row( $wpdb->prepare( 'SELECT min_price, max_price FROM %%i WHERE product_id = %%d', $wpdb->prefix . 'wc_product_meta_lookup', $parent ), ARRAY_A );
$prices = array();
foreach ( wc_get_product( $parent )->get_children() as $id ) { $prices[ $id ] = wc_get_product( $id )->get_regular_price( 'edit' ); }
bulkcat_out( array( 'min' => $row ? $row['min_price'] : null, 'max' => $row ? $row['max_price'] : null, 'prices' => $prices ) );
"""


def money(value: str | float | None) -> str:
    return "" if value is None or value == "" else f"{float(value):.2f}"


def check_variations(p: Probe) -> None:
    """T72: a variable product with 60 variations read past the 50-row grid cap,
    filtered Variations only to out of stock, previewed and changed with Also
    apply to variations on, its parent's lookup range resynchronised, and the
    change undone back to the snapshot; every variation route refuses an
    anonymous and a subscriber request."""
    state: dict[str, Any] = {}
    increase = [{"action": "adjust", "args": {"field": "regular_price", "direction": "increase", "by": "percent",
                                              "amount": "10", "apply_to_variations": True}}]

    def seed() -> None:
        made = p.wp_eval(SIXTY_SEED_PHP % (SIXTY_SKU, SIXTY))
        state.update(parent=made["parent"], variations=made["variations"])
        state["before"] = p.wp_eval(SIXTY_STATE_PHP % made["parent"])
        ok = len(made["variations"]) == SIXTY and money(state["before"]["min"]) == "10.00" and money(state["before"]["max"]) == f"{9 + SIXTY:.2f}"
        p.row("variations", "variations:seed", ok, f"parent {made['parent']}, {len(made['variations'])} variations, lookup {state['before']['min']}-{state['before']['max']}")

    def refusals() -> None:
        route = f"/products/{state['parent']}/variations"
        bad = []
        for method, path, body in (("GET", route, None),
                                   ("POST", "/preview", {"ids": [state["parent"]], "operations": increase}),
                                   ("POST", "/batches", {"uuid": str(uuid.uuid4()), "ids": [state["parent"]], "operations": increase})):
            anon, sub = p.http(None, method, path, body=body), p.http("subscriber", method, path, body=body)
            if anon.status != 401 or sub.status != 403:
                bad.append(f"{method} {path}: {anon.status}/{sub.status}")
        p.row("variations", "variations:refusals", not bad, "; ".join(bad) or "anonymous 401, subscriber 403 on the variations list, preview and batches")

    def past_cap() -> None:
        route = f"/products/{state['parent']}/variations"
        first = p.http("manager", "GET", route, [("per_page", "50"), ("page", "1")]).json() or {}
        second = p.http("manager", "GET", route, [("per_page", "50"), ("page", "2")]).json() or {}
        got = [row["id"] for row in first.get("items", []) + second.get("items", [])]
        want = [v["id"] for v in state["variations"]]
        pag = first.get("pagination", {})
        ok = got == want and pag.get("total") == SIXTY and pag.get("totalPages") == 2 and len(first.get("items", [])) == 50
        p.row("variations", "variations:past-grid-cap", ok, f"{len(first.get('items', []))} + {len(second.get('items', []))} rows, total {pag.get('total')}, in menu order: {got == want}")

    def out_of_stock() -> None:
        ids, pag = all_ids(p, [("mode", "variations")] + filter_params("stock_status", "isAny", ["outofstock"]))
        mine = {v["id"] for v in state["variations"]}
        got = sorted(i for i in ids if i in mine)
        want = sorted(v["id"] for v in state["variations"] if v["stock"] == "outofstock")
        truth_ids = p.wp_eval(
            "bulkcat_out( array_map( 'intval', get_posts( array( 'post_type' => 'product_variation', 'post_status' => array( 'publish', 'private' ), "
            "'posts_per_page' => -1, 'fields' => 'ids', 'meta_key' => '_stock_status', 'meta_value' => 'outofstock', 'orderby' => 'ID', 'order' => 'ASC' ) ) ) );"
        )
        ok = got == want and len(want) == SIXTY // 3 and sorted(ids) == sorted(truth_ids)
        p.row("variations", "variations:filter-out-of-stock", ok, f"{len(got)} of this product's (want {len(want)}), {pag.get('total')} in all vs {len(truth_ids)} by WooCommerce")

    def preview() -> None:
        r = p.http("manager", "POST", "/preview", body={"ids": [state["parent"]], "operations": increase, "sample_size": "all"})
        d = r.json() or {}
        state["hash"] = d.get("hash")
        totals = d.get("totals", {})
        by = totals.get("by_type", {})
        sample_ids = {row["object_id"] for row in d.get("sample", [])}
        untouched = p.wp_eval(SIXTY_STATE_PHP % state["parent"]) == state["before"]
        ok = (r.status == 200 and totals.get("matched") == SIXTY and (by.get("variation") or {}).get("would_change") == SIXTY
              and (by.get("product") or {}).get("matched") == 0 and d.get("summary") == "Regular price will change on 60 variations."
              and (d.get("selection") or {}).get("variations_added") == SIXTY and (d.get("selection") or {}).get("parents_left_out") == 1
              and sample_ids == {v["id"] for v in state["variations"]} and untouched)
        p.row("variations", "variations:preview", ok, f"{r.brief()} matched {totals.get('matched')} by_type {by} summary {d.get('summary')!r}, nothing saved: {untouched}")

    def apply() -> None:
        r = p.http("manager", "POST", "/batches", body={"uuid": str(uuid.uuid4()), "ids": [state["parent"]], "operations": increase, "preview_hash": state["hash"]})
        d = r.json() or {}
        state["batch"] = d.get("batch_id")
        p.row("variations", "variations:batch-create", r.status == 201 and d.get("total_items") == SIXTY, f"{r.brief()} total_items {d.get('total_items')}")
        report = p.run_queue(state["batch"])
        ok = report.get("status") == "complete" and report.get("changed_items") == SIXTY and report.get("failed_items") == 0
        p.row("variations", "variations:batch-run", ok, f"status {report.get('status')} changed {report.get('changed_items')} failed {report.get('failed_items')} chunk {report.get('chunk_size')}")

    def verify() -> None:
        after = p.wp_eval(SIXTY_STATE_PHP % state["parent"])
        want = {str(v["id"]): f"{round(float(v['price']) * 1.1 + 1e-9, 2):.2f}" for v in state["variations"]}
        got = {str(k): money(v) for k, v in after["prices"].items()}
        p.row("variations", "variations:batch-values", got == want, f"{sum(1 for k in want if got.get(k) == want[k])} of {SIXTY} variations at +10%")
        lo, hi = money(after["min"]), money(after["max"])
        want_lo, want_hi = min(want.values(), key=float), max(want.values(), key=float)
        p.row("variations", "variations:parent-lookup-resynced", (lo, hi) == (want_lo, want_hi), f"lookup {lo}-{hi}, want {want_lo}-{want_hi}")
        feed = p.http("manager", "GET", f"/batches/{state['batch']}/changes", [("per_page", "100")]).json() or {}
        items = feed.get("items", [])
        p.row("variations", "variations:journal", len(items) == SIXTY and all(c.get("object_type") == "variation" and c.get("result") == "applied" for c in items),
              f"{len(items)} journal rows, types {sorted({c.get('object_type') for c in items})}")

    def undo() -> None:
        route = f"/batches/{state['batch']}/undo"
        pre = p.http("manager", "POST", route, body={"preview": True}).json() or {}
        r = p.http("manager", "POST", route, body={"uuid": str(uuid.uuid4()), "plan_hash": pre.get("plan_hash")})
        u = r.json() or {}
        report = p.run_queue(u.get("batch_id", 0))
        original = p.http("manager", "GET", f"/batches/{state['batch']}").json() or {}
        p.row("variations", "variations:undo-run", r.status == 201 and u.get("total_items") == SIXTY and report.get("status") == "complete" and original.get("status") == "undone",
              f"{r.brief()} undo total {u.get('total_items')} {report.get('status')}, original {original.get('status')}")
        after = p.wp_eval(SIXTY_STATE_PHP % state["parent"])
        same = after == state["before"]
        p.row("variations", "variations:undo-snapshot", same, f"prices and lookup {after['min']}-{after['max']} back to the snapshot: {same}")

    steps = [("seed", seed), ("refusals", refusals), ("past-grid-cap", past_cap), ("filter-out-of-stock", out_of_stock),
             ("preview", preview), ("batch", apply), ("batch-values", verify), ("undo", undo)]
    for name, fn in steps:
        if name != "seed" and not state.get("parent"):
            p.row("variations", f"variations:{name}", False, "no seeded product")
            continue
        if name in ("batch-values", "undo") and not state.get("batch"):
            p.row("variations", f"variations:{name}", False, "no batch from the batch step")
            continue
        p.guard("variations", f"variations:{name}", fn)

# ---------------------------------------------------------------------------

CHAPTERS: dict[str, list[Callable[[Probe], None]]] = {
    "screen": [check_screen],
    "read": [check_read_matrix, check_read_queries, check_saved_views],
    "write": [check_write_engine, check_write_stale, check_write_inline, check_write_categories, check_write_history],
    "access": [check_access, check_settings],
    "variations": [check_variations],
    "lifecycle": [check_lifecycle],
}
NEEDS_WP = {"write", "access", "variations", "lifecycle"}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--base-url", required=True)
    parser.add_argument("--seed", default=os.path.join(os.path.dirname(os.path.abspath(__file__)), "seed.json"))
    parser.add_argument("--wp-cmd", default=os.environ.get("WP_CMD", "wp"))
    parser.add_argument("--no-wp", action="store_true", help="skip every surface that needs WP-CLI (documented SKIP)")
    parser.add_argument("--chapters", default=",".join(CHAPTERS))
    parser.add_argument("--verbose", action="store_true")
    args = parser.parse_args()

    p = Probe(args)
    chosen = [c.strip() for c in args.chapters.split(",") if c.strip()]
    unknown = [c for c in chosen if c not in CHAPTERS]
    if unknown:
        parser.error(f"unknown chapter(s): {', '.join(unknown)}")
    for chapter in chosen:
        if args.no_wp and chapter in NEEDS_WP:
            p.skip(chapter, f"{chapter}:*", "documented: --no-wp given, this chapter needs WP-CLI")
            continue
        for check in CHAPTERS[chapter]:
            if args.no_wp and check is check_read_queries:
                p.skip(chapter, "read:queries:*", "documented: --no-wp given, ground truth needs WP-CLI")
                continue
            try:
                check(p)
            except RuntimeError as error:
                p.row(chapter, f"{chapter}:{check.__name__}", False, str(error))

    width = max(len(r[1]) for r in p.rows) if p.rows else 10
    print(f"{'CHAPTER':<10} {'SURFACE':<{width}} RESULT  DETAIL")
    for chapter, surface, result, detail in p.rows:
        print(f"{chapter:<10} {surface:<{width}} {result:<7} {detail}")
    print()
    for chapter in chosen:
        mine = [r for r in p.rows if r[0] == chapter]
        counts = {k: sum(1 for r in mine if r[2] == k) for k in ("PASS", "FAIL", "SKIP")}
        print(f"{chapter}: {counts['PASS']} passed / {counts['FAIL']} failed / {counts['SKIP']} skipped")
    failed = sum(1 for r in p.rows if r[2] == "FAIL")
    print(f"total: {sum(1 for r in p.rows if r[2] == 'PASS')} passed / {failed} failed / {sum(1 for r in p.rows if r[2] == 'SKIP')} skipped")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
