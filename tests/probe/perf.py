#!/usr/bin/env python3
"""Performance benchmark of Bulk Catalogue Manager at 20,000 products (T76, SPEC §12.5).

Measures a seeded scale site over HTTP and WP-CLI, prints one row per metric
with its budget and PASS/FAIL/INFO, optionally writes the numbers as JSON, and
exits 1 when any budget is missed. INFO rows have no budget in §12.5 and are
recorded for comparison only.

Needs, on a throwaway site (never a live one: it writes 5,000 prices and puts
them back, inserts 200 history rows and 50,000 journal rows and prunes them):

* the scale catalogue: ``seed.sh 20000`` (20,000 products, 70,000 variations);
* no persistent object cache, ``DISABLE_WP_CRON`` true (so only this script
  drives Action Scheduler);
* the measurement mu-plugin ``bulkcat-perf-probe.php`` described in
  tests/probe/README.md (Performance). It is not part of the plugin: it adds
  an ``X-BULKCAT-Perf`` header to REST responses (queries and time inside the
  dispatch, the request's time so far, peak memory) and logs requests that
  carry ``X-BULKCAT-Perf-Log`` and every ``bulkcat_run_chunk`` / ``bulkcat_prune_history``
  action to ``wp-content/bulkcat-perf.log``. Query counts are ``$wpdb->num_queries``,
  which counts exactly what SAVEQUERIES would list, without its overhead.

Metrics (``--only`` takes these group names, comma separated, default all):

  grid       GET /products at per_page 25, 50 and 100, with no filter and with
             three (category, stock status, price range), in products,
             products-and-variations and variations-only modes: one cold
             request (the count transients cleared) then --runs warm ones.
             Budget: p95 server time < 400 ms and <= 20 queries in the
             dispatch for 25 rows with three filters, the query count equal at
             25, 50 and 100 rows, the cold filtered request (page + COUNT)
             < 500 ms; in products-and-variations mode p95 < 750 ms and cold
             < 1 s (SPEC §12.5); a 50-variation expansion
             (GET /products/{id}/variations) is one request inside the
             products grid's budget.
  categories GET /categories cold (tree transient cleared) < 300 ms, and warm.
  selection  POST /selection over the whole catalogue (products; products and
             variations) < 5 s.
  preview    POST /preview of a 10 % increase over 10,000 simple products (INFO).
  write      A 10 % regular price increase over 5,000 simple products, queued
             through POST /batches and run by ``wp action-scheduler run``:
             >= 300 products a minute at the default chunk size, every chunk's
             peak memory < 96 MB; then its undo, held to the same budget.
  history    GET /batches with 200 batches in the history, 25 and 100 a page
             (INFO; the query count must not grow with the page size).
  export     GET /export of 20,000 rows: first byte < 2 s, complete < 60 s,
             memory flat (peak at 20,000 rows within 8 MB of the peak at 2,000).
  export_post  POST /export as the export dialog posts it, identifier switch
             on (ticket 10): 20,000 rows by filter and 20,000 selected
             members by token, each with first byte < 2 s (token resolution
             included), complete < 60 s, every row, and memory flat (peak
             within 8 MB of the same source's 2,000-row export).
  export_scale A 100,000-member selection exported by token with the switch
             on: completes under the web request's 128 MB memory limit
             (first byte and total time recorded). Needs the scale category
             at 100,000 products and variations: seed.sh 22223.
  prune      The history prune over 50,000 journal rows in batches past the
             retention limit, run to the end through Action Scheduler (INFO).
  undo_scale T111 (ticket 11 item 1), opt-in, hours long: the undo of a
             100,000-object change, streamed. The change (Weight set to
             0.777 on the scale category's 100,000 products and variations,
             seed.sh 22223) is made through the durable routes and run with
             ``wp action-scheduler run``, or an existing finished change is
             named with --undo-batch. Then, each a web request at the site's
             memory limit: the undo preview (first and last page), the
             single batch read counting conflicts, the undo accepted through a
             /requests grant, and the undo batch run to its end through Action
             Scheduler's runner inside web requests (the measurement
             mu-plugin's runner; the stack sets WP_MAX_MEMORY_LIMIT to 128M so
             the runner's raise stays at 128 MB). Budget: every request's
             and every chunk's peak < 96 MB under a 128M limit; the undo
             complete, the change undone, no object left at the new weight.
  offon      Ticket 04 (SPEC §5.5), through the durable routes (a captured
             selection, an exact preview, a /requests grant): Status set to
             Draft (Off) on 5,000 simple products, the undo of that Off, Off
             again, then Published (On): each >= 300 products a minute at the
             default chunk, every chunk's peak < 96 MB; while 5,000 are Off,
             the grid's default columns (Status among them) with the Off
             filter, 25 rows: p95 < 400 ms and <= 20 queries, and its cold
             count < 500 ms.

Usage (after the stack, seed and mu-plugin of tests/probe/README.md):
    WP_CMD="..." python3 tests/probe/perf.py --base-url http://localhost:8896 \
        [--seed tests/probe/seed.json] [--runs 20] [--only grid,export] \
        [--json performance.json] [--verbose]

Standard library only. Slow (about 17 minutes, most of it the write group), so
it is not in CI; run it by hand before a release and record the numbers in
tests/probe/PERF.md.
"""

from __future__ import annotations

import argparse
import json
import math
import os
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from typing import Any

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from probe import NS, SIXTY, SIXTY_SEED_PHP, Probe, export_fixture, filter_params  # noqa: E402

GROUPS = ["grid", "categories", "selection", "preview", "write", "history", "export", "export_post", "export_scale", "prune", "offon", "undo_scale"]
# undo_scale runs a 100,000-object change and its undo (hours): only when named.
DEFAULT_GROUPS = [group for group in GROUPS if group != "undo_scale"]
SCALE_WEIGHT = "0.777"
ADJUST = [{"action": "adjust", "args": {"field": "regular_price", "direction": "increase", "by": "percent", "amount": "10"}}]
PERF_SIXTY_SKU = "bulkcat-perf-sixty"
LIST_BATCHES = 200
PRUNE_ROWS = 50000
EXPORT_FLAT_MB = 8.0

BUDGET = {
    "grid_p95_ms": 400.0,
    "grid_queries": 20,
    "count_cold_ms": 500.0,
    # Products-and-variations mode with three filters (SPEC §12.5): sorting
    # tens of thousands of matching rows by title has no core index to use.
    "mixed_grid_p95_ms": 750.0,
    "mixed_count_cold_ms": 1000.0,
    "tree_cold_ms": 300.0,
    "selection_ms": 5000.0,
    "write_per_minute": 300.0,
    "chunk_peak_mb": 96.0,
    "export_ttfb_ms": 2000.0,
    "export_total_ms": 60000.0,
}


def p95(values: list[float]) -> float:
    ordered = sorted(values)
    return ordered[max(0, math.ceil(0.95 * len(ordered)) - 1)]


def median(values: list[float]) -> float:
    ordered = sorted(values)
    mid = len(ordered) // 2
    return ordered[mid] if len(ordered) % 2 else (ordered[mid - 1] + ordered[mid]) / 2


class Perf:
    def __init__(self, args: argparse.Namespace) -> None:
        self.p = Probe(args)
        self.runs = args.runs
        self.verbose = args.verbose
        self.rows: list[dict[str, Any]] = []
        self.scale_cat = 0
        self.undo_batch = getattr(args, "undo_batch", 0)

    # ---- reporting -----------------------------------------------------------

    def metric(self, group: str, name: str, value: float | str, unit: str, budget: str = "", ok: bool | None = None, detail: str = "") -> None:
        result = "INFO" if ok is None else ("PASS" if ok else "FAIL")
        self.rows.append({"group": group, "metric": name, "value": value, "unit": unit, "budget": budget, "result": result, "detail": detail})
        shown = f"{value:.1f}" if isinstance(value, float) else str(value)
        print(f"{result:<5} {group}:{name:<44} {shown:>10} {unit:<8} {budget:<14} {detail}", flush=True)

    # ---- HTTP ----------------------------------------------------------------

    def call(self, method: str, route: str, params: list | None = None, body: Any = None, log: str = "") -> dict[str, Any]:
        """A REST call as the shop manager, timed at first byte and in full."""
        opener, nonce = self.p.session("manager")
        path = NS + route + ("?" + urllib.parse.urlencode(params, doseq=True) if params else "")
        headers = {"Accept": "application/json", "X-WP-Nonce": nonce}
        data = None
        if body is not None:
            data = json.dumps(body).encode()
            headers["Content-Type"] = "application/json"
        if log:
            headers["X-BULKCAT-Perf-Log"] = log
        request = urllib.request.Request(self.p.base + path, data=data, method=method, headers=headers)
        start = time.perf_counter()
        try:
            reply = opener.open(request, timeout=300)
        except urllib.error.HTTPError as error:
            reply = error
        first = time.perf_counter()
        payload = reply.read()
        end = time.perf_counter()
        status = reply.status if hasattr(reply, "status") else reply.code
        perf = json.loads(reply.headers.get("X-BULKCAT-Perf") or "{}")
        if not perf and "text/csv" not in (reply.headers.get("Content-Type") or ""):
            raise RuntimeError(f"{method} {route}: no X-BULKCAT-Perf header (status {status}); is the mu-plugin installed?")
        out = {"status": status, "ttfb_ms": (first - start) * 1000, "total_ms": (end - start) * 1000, "body": payload, "perf": perf}
        if self.verbose:
            print(f"  {method} {route} {status} {out['total_ms']:.0f} ms {perf}", file=sys.stderr)
        return out

    def json_of(self, reply: dict[str, Any]) -> Any:
        try:
            return json.loads(reply["body"].decode("utf-8"))
        except ValueError:
            return None

    # ---- WP-CLI helpers --------------------------------------------------------

    def clear_transients(self, prefix: str) -> None:
        self.p.wp_eval(
            "global $wpdb; $like = $wpdb->esc_like( '_transient_" + prefix + "' ) . '%'; $t = $wpdb->esc_like( '_transient_timeout_" + prefix + "' ) . '%';"
            " $wpdb->query( $wpdb->prepare( \"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s\", $like, $t ) ); bulkcat_out( true );"
        )

    def perf_log(self) -> list[dict[str, Any]]:
        lines = self.p.wp_eval("$f = WP_CONTENT_DIR . '/bulkcat-perf.log'; bulkcat_out( file_exists( $f ) ? file( $f, FILE_IGNORE_NEW_LINES ) : array() );")
        return [json.loads(line) for line in lines if line.strip()]

    def reset_log(self) -> None:
        self.p.wp_eval("$f = WP_CONTENT_DIR . '/bulkcat-perf.log'; if ( file_exists( $f ) ) { unlink( $f ); } bulkcat_out( true );")

    def run_actions(self, hook: str) -> float:
        """Runs every due action of one hook in one WP-CLI process; returns wall seconds."""
        start = time.perf_counter()
        self.p.wp("action-scheduler", "run", f"--hooks={hook}", "--batch-size=10", check=False)
        return time.perf_counter() - start

    def run_batch(self, batch_id: int) -> tuple[float, dict]:
        wall = 0.0
        report: dict = {}
        for _ in range(50):
            wall += self.run_actions("bulkcat_run_chunk")
            report = self.json_of(self.call("GET", f"/batches/{batch_id}")) or {}
            if report.get("status") not in ("queued", "running", "undoing"):
                break
        return wall, report

    # ---- fixtures ----------------------------------------------------------------

    def preflight(self) -> None:
        state = self.p.wp_eval(
            "global $wpdb; $cat = get_term_by( 'slug', 'bulkcat-scale', 'product_cat' );"
            " bulkcat_out( array( 'cat' => $cat ? (int) $cat->term_id : 0,"
            " 'products' => (int) $wpdb->get_var( \"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product'\" ),"
            " 'variations' => (int) $wpdb->get_var( \"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product_variation'\" ),"
            " 'ext_cache' => wp_using_ext_object_cache(), 'cron_off' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,"
            " 'mu' => file_exists( WPMU_PLUGIN_DIR . '/bulkcat-perf-probe.php' ),"
            " 'php' => PHP_VERSION, 'db' => $wpdb->db_server_info(), 'wp' => get_bloginfo( 'version' ), 'wc' => WC_VERSION ) );"
        )
        problems = []
        if state["products"] < 20000 or state["variations"] < 70000 or not state["cat"]:
            problems.append(f"catalogue is {state['products']} products / {state['variations']} variations; run seed.sh 20000")
        if state["ext_cache"]:
            problems.append("a persistent object cache is active; §12.5 is measured without one")
        if not state["cron_off"]:
            problems.append("DISABLE_WP_CRON is not true; WP-Cron would run batches behind the measurement")
        if not state["mu"]:
            problems.append("the measurement mu-plugin bulkcat-perf-probe.php is missing")
        if problems:
            raise SystemExit("perf.py: " + "; ".join(problems))
        self.scale_cat = state["cat"]
        self.stack = state
        print(f"stack: PHP {state['php']}, MySQL {state['db']}, WordPress {state['wp']}, WooCommerce {state['wc']}; "
              f"{state['products']} products, {state['variations']} variations; no persistent object cache", flush=True)

    def three_filters(self) -> list[tuple[str, str]]:
        return (filter_params("category_ids", "isAny", [self.scale_cat])
                + filter_params("stock_status", "isAny", ["instock"])
                + filter_params("regular_price", "between", ["10", "40"]))

    # ---- groups ------------------------------------------------------------------

    def grid(self) -> None:
        queries: dict[tuple[str, bool], dict[int, int]] = {}
        for mode in ("products", "products_and_variations", "variations"):
            for filtered in (False, True):
                for per_page in (25, 50, 100):
                    params = [("mode", mode), ("per_page", str(per_page)), ("orderby", "name"), ("order", "asc")]
                    if filtered:
                        params += self.three_filters()
                    self.clear_transients("bulkcat_count_")
                    cold = self.call("GET", "/products", params)
                    assert cold["status"] == 200, f"GET /products {cold['status']} {cold['body'][:200]!r}"
                    total = (self.json_of(cold) or {}).get("pagination", {}).get("total")
                    warm = [self.call("GET", "/products", params) for _ in range(self.runs)]
                    server = [w["perf"]["server_ms"] for w in warm]
                    counts = sorted({w["perf"]["dispatch_queries"] for w in warm})
                    queries.setdefault((mode, filtered), {})[per_page] = max(counts)
                    label = f"{mode}/{'3 filters' if filtered else 'no filter'}/{per_page}"
                    budgeted = filtered and per_page == 25
                    mixed = mode == "products_and_variations"
                    cold_budget = BUDGET["mixed_count_cold_ms"] if mixed else BUDGET["count_cold_ms"]
                    p95_budget = BUDGET["mixed_grid_p95_ms"] if mixed else BUDGET["grid_p95_ms"]
                    self.metric("grid", f"{label} cold", cold["perf"]["server_ms"], "ms",
                                f"< {cold_budget:.0f} ms" if filtered else "", (cold["perf"]["server_ms"] < cold_budget) if filtered else None,
                                f"{cold['perf']['dispatch_queries']} queries, total {total}")
                    self.metric("grid", f"{label} warm p95", p95(server), "ms",
                                f"< {p95_budget:.0f} ms" if budgeted else "", (p95(server) < p95_budget) if budgeted else None,
                                f"median {median(server):.0f} ms, dispatch {median([w['perf']['dispatch_ms'] for w in warm]):.0f} ms, client {median([w['total_ms'] for w in warm]):.0f} ms")
                    self.metric("grid", f"{label} queries", max(counts), "queries",
                                "<= 20" if budgeted else "", (max(counts) <= BUDGET["grid_queries"]) if budgeted else None,
                                f"request total {warm[-1]['perf']['queries']}")
        for (mode, filtered), by_size in queries.items():
            same = len(set(by_size.values())) == 1
            self.metric("grid", f"{mode}/{'3 filters' if filtered else 'no filter'} query count constant", "yes" if same else "no", "",
                        "25 = 50 = 100", same, str(by_size))

        parent = self.p.wp_eval(SIXTY_SEED_PHP % (PERF_SIXTY_SKU, SIXTY))["parent"]
        route = f"/products/{parent}/variations"
        warm = [self.call("GET", route, [("per_page", "50")]) for _ in range(self.runs)]
        items = len((self.json_of(warm[-1]) or {}).get("items", []))
        server = [w["perf"]["server_ms"] for w in warm]
        self.metric("grid", "50-variation expansion p95", p95(server), "ms", "< 400 ms, 1 req",
                    p95(server) < BUDGET["grid_p95_ms"] and items == 50, f"{items} rows in one request, median {median(server):.0f} ms")
        q = max(w["perf"]["dispatch_queries"] for w in warm)
        self.metric("grid", "50-variation expansion queries", q, "queries", "<= 20", q <= BUDGET["grid_queries"])

    def categories(self) -> None:
        cold = []
        for _ in range(3):
            self.clear_transients("bulkcat_category_tree_")
            cold.append(self.call("GET", "/categories"))
        warm = [self.call("GET", "/categories") for _ in range(self.runs)]
        worst = max(c["perf"]["server_ms"] for c in cold)
        self.metric("categories", "tree cold (worst of 3)", worst, "ms", "< 300 ms", worst < BUDGET["tree_cold_ms"],
                    f"{cold[0]['perf']['dispatch_queries']} queries")
        self.metric("categories", "tree warm p95", p95([w["perf"]["server_ms"] for w in warm]), "ms", "", None,
                    f"{warm[-1]['perf']['dispatch_queries']} queries")

    def selection(self) -> None:
        for mode in ("products", "products_and_variations"):
            self.clear_transients("bulkcat_count_")
            reply = self.call("POST", "/selection", body={"mode": mode})
            data = self.json_of(reply) or {}
            ms = reply["perf"]["server_ms"]
            self.metric("selection", f"whole catalogue ({mode})", ms, "ms", "< 5 s", reply["status"] == 200 and ms < BUDGET["selection_ms"],
                        f"{data.get('count')} objects, {reply['perf']['dispatch_queries']} queries, peak {reply['perf']['peak_mb']} MB")

    def simple_filter(self, extra: dict | None = None) -> dict:
        body = {"category_ids": {"operator": "isAny", "value": [self.scale_cat]}, "type": {"operator": "isAny", "value": ["simple"]}}
        body.update(extra or {})
        return body

    def preview(self) -> None:
        sel = self.json_of(self.call("POST", "/selection", body=self.simple_filter())) or {}
        reply = self.call("POST", "/preview", body={"token": sel.get("token"), "operations": ADJUST})
        data = self.json_of(reply) or {}
        self.metric("preview", f"preview over {sel.get('count')} objects", reply["perf"]["server_ms"], "ms", "", None,
                    f"status {reply['status']}, would change {(data.get('totals') or {}).get('would_change')}, "
                    f"{reply['perf']['dispatch_queries']} queries, peak {reply['perf']['peak_mb']} MB")

    def write(self) -> None:
        # Through the durable routes (T83): a captured selection, an exact preview, a /requests grant.
        body = self.simple_filter({"stock_quantity": {"operator": "between", "value": [1, 19]}})
        sel = self.p.capture("manager", {"query": body})
        assert sel.get("token"), f"capture {sel}"
        pre = self.json_of(self.call("POST", "/preview", body={"token": sel["token"], "operations": ADJUST})) or {}
        reply = self.call("POST", "/batches", body=self.p.apply_body("manager", pre, {"token": sel["token"]}))
        created = self.json_of(reply) or {}
        assert reply["status"] == 201, f"POST /batches {reply['status']} {created}"
        self.metric("write", f"queue a {sel['count']}-product batch", reply["perf"]["server_ms"], "ms", "", None,
                    f"{reply['perf']['dispatch_queries']} queries, peak {reply['perf']['peak_mb']} MB")
        self.batch_budget("apply", created["batch_id"])

        undo_route = f"/batches/{created['batch_id']}/undo"
        plan = self.json_of(self.call("POST", undo_route, body={"preview": True})) or {}
        reply = self.call("POST", undo_route, body=self.p.undo_body("manager", created["batch_id"], plan.get("plan_hash")))
        undo = self.json_of(reply) or {}
        assert reply["status"] == 201, f"undo {reply['status']} {undo}"
        self.batch_budget("undo", undo["batch_id"])

    def batch_budget(self, label: str, batch_id: int, group: str = "write") -> None:
        # A web runner starts each request from WordPress's own footprint; under WP-CLI
        # WooCommerce's commands alone hold about 110 MB, so a chunk's peak is read as
        # what the chunk adds to the memory it started with, on top of a light REST
        # request's peak (GET /fields: WordPress, WooCommerce and the plugin loaded).
        base = self.call("GET", "/fields")["perf"]["peak_mb"]
        self.reset_log()
        wall, report = self.run_batch(batch_id)
        chunks = [row for row in self.perf_log() if row["kind"] == "bulkcat_run_chunk"]
        done = int(report.get("done_items") or 0)
        rate = done / (wall / 60) if wall else 0.0
        in_chunks = sum(c["ms"] for c in chunks) / 1000
        ok_status = report.get("status") in ("complete", "partial") and not report.get("failed_items")
        self.metric(group, f"{label}: products a minute", rate, "/min", ">= 300", ok_status and rate >= BUDGET["write_per_minute"],
                    f"{done} items ({report.get('changed_items')} changed, {report.get('failed_items')} failed) in {wall:.0f} s wall, "
                    f"{in_chunks:.0f} s in {len(chunks)} chunks, status {report.get('status')}")
        own = max((c["peak_mb"] - c["start_mb"] for c in chunks), default=0.0)
        peak = base + own
        self.metric(group, f"{label}: peak memory per chunk", peak, "MB", "< 96 MB", bool(chunks) and peak < BUDGET["chunk_peak_mb"],
                    f"a request's {base} MB + at most {own:.2f} MB a chunk adds; queries a chunk median {median([c['queries'] for c in chunks]) if chunks else 0}")
        drift = (chunks[-1]["end_mb"] - chunks[0]["start_mb"]) if chunks else 0.0
        self.metric(group, f"{label}: memory kept across chunks", drift, "MB", "flat (+<8 MB)", drift < EXPORT_FLAT_MB,
                    f"one WP-CLI process: {chunks[0]['start_mb'] if chunks else 0} MB before the first chunk, {chunks[-1]['end_mb'] if chunks else 0} MB after the last")

    # ---- ticket 04: Status as the global Off/On switch (branch codex/global-off-on) ----

    OFFON_FIELDS = ["name", "status", "category_ids", "regular_price", "sale_price", "stock_status", "manage_stock",
                    "stock_quantity", "sku", "id", "type", "thumbnail", "price_html", "variation_count"]

    def offon_switch(self, label: str, token: str, value: str) -> int:
        """Preview and Apply Status set to a value over the captured selection, through a grant; run and measure it."""
        # Long queue runs can outlive the authorization lease. Renew the same
        # retained membership before each fresh preview, never recapture a query.
        renewal = self.call("POST", "/selection/renew", body={"token": token})
        renewed = self.json_of(renewal) or {}
        assert renewal["status"] == 200 and renewed.get("token"), f"selection renewal {renewal['status']}"
        token = renewed["token"]
        operations = [{"action": "set_value", "args": {"field": "status", "value": value, "apply_to_variations": False}}]
        pre = self.json_of(self.call("POST", "/preview", body={"token": token, "operations": operations})) or {}
        reply = self.call("POST", "/batches", body=self.p.apply_body("manager", pre, {"token": token}))
        created = self.json_of(reply) or {}
        assert reply["status"] == 201, f"POST /batches {reply['status']} {created}"
        counts = (pre.get("availability") or {}).get("counts")
        self.metric("offon", f"{label}: queue", reply["perf"]["server_ms"], "ms", "", None, f"preview counts {counts}; {reply['perf']['dispatch_queries']} queries")
        self.batch_budget(label, created["batch_id"], "offon")
        return int(created["batch_id"])

    def offon_undo(self, label: str, batch_id: int) -> None:
        route = f"/batches/{batch_id}/undo"
        plan = self.json_of(self.call("POST", route, body={"preview": True})) or {}
        reply = self.call("POST", route, body=self.p.undo_body("manager", batch_id, plan.get("plan_hash")))
        undo = self.json_of(reply) or {}
        assert reply["status"] == 201, f"undo {reply['status']} {undo}"
        self.batch_budget(label, undo["batch_id"], "offon")

    def offon_grid(self) -> None:
        params = [("per_page", "25"), ("status[operator]", "isAny"), ("status[value][]", "off")] + [("fields[]", f) for f in self.OFFON_FIELDS]
        self.clear_transients("bulkcat_count_")
        cold = self.call("GET", "/products", params)
        assert cold["status"] == 200, f"GET /products {cold['status']} {cold['body'][:200]!r}"
        data = self.json_of(cold) or {}
        total = (data.get("pagination") or {}).get("total")
        shown = sum(1 for item in data.get("items", []) if (item.get("off_state") or {}).get("state") == "off")
        self.metric("offon", "Off filter cold (page + count)", cold["perf"]["server_ms"], "ms", "< 500 ms", cold["perf"]["server_ms"] < BUDGET["count_cold_ms"],
                    f"{total} Off products, {cold['perf']['dispatch_queries']} queries")
        warm = [self.call("GET", "/products", params) for _ in range(self.runs)]
        server = [w["perf"]["server_ms"] for w in warm]
        self.metric("offon", "grid with Status and the Off filter, 25 rows, warm p95", p95(server), "ms", "< 400 ms", p95(server) < BUDGET["grid_p95_ms"],
                    f"median {median(server):.0f} ms; {shown} of 25 rows read Off")
        q = max(w["perf"]["dispatch_queries"] for w in warm)
        self.metric("offon", "grid with Status and the Off filter, queries", q, "queries", "<= 20", q <= BUDGET["grid_queries"], f"request total {warm[-1]['perf']['queries']}")

    def offon(self) -> None:
        body = self.simple_filter({"stock_quantity": {"operator": "between", "value": [1, 19]}})
        sel = self.p.capture("manager", {"query": body})
        assert sel.get("token"), f"capture {sel}"
        self.metric("offon", "selection", sel.get("count", 0), "products", "", None, "simple products in the scale category with stock 1 to 19")
        off = self.offon_switch("Off", sel["token"], "draft")
        self.offon_grid()
        self.offon_undo("undo of the Off", off)
        self.offon_switch("Off again", sel["token"], "draft")
        self.offon_switch("On", sel["token"], "publish")
        left = self.p.wp_eval(
            "global $wpdb; bulkcat_out( (int) $wpdb->get_var( $wpdb->prepare( \"SELECT COUNT(*) FROM {$wpdb->postmeta} AS m"
            " INNER JOIN {$wpdb->term_relationships} AS tr ON tr.object_id = m.post_id INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id"
            " WHERE m.meta_key = %%s AND tt.term_id = %%d\", '_bulkcat_availability_off', %d ) ) );" % self.scale_cat
        )
        self.metric("offon", "Off records left after On", left, "records", "0", left == 0, "in the scale category")

    def history(self) -> None:
        have = self.p.wp_eval(
            "global $wpdb; bulkcat_out( (int) $wpdb->get_var( \"SELECT COUNT(*) FROM {$wpdb->prefix}bulkcat_batches\" ) );"
        )
        if have < LIST_BATCHES:
            # Copies of the newest real batch, spread over the past 60 days: the list reads them as it reads any batch.
            self.p.wp_eval(
                "global $wpdb; $t = $wpdb->prefix . 'bulkcat_batches'; $src = (int) $wpdb->get_var( \"SELECT MAX(batch_id) FROM $t WHERE source = 'bulk'\" );"
                " if ( ! $src ) { throw new RuntimeException( 'no bulk batch to copy; run the write group first' ); }"
                " for ( $i = 0; $i < %d; $i++ ) { $wpdb->query( $wpdb->prepare( \"INSERT INTO $t (uuid, user_id, created_gmt, finished_gmt, status, source, undo_of, operations, selection, total_items, previewed_items, done_items, changed_items, failed_items, chunk_size, note, actions) SELECT %%s, user_id, DATE_SUB(UTC_TIMESTAMP(), INTERVAL %%d HOUR), DATE_SUB(UTC_TIMESTAMP(), INTERVAL %%d HOUR), 'complete', source, NULL, operations, selection, total_items, previewed_items, done_items, changed_items, failed_items, chunk_size, '', actions FROM $t WHERE batch_id = %%d\", wp_generate_uuid4(), $i * 7 + 1, $i * 7 + 1, $src ) ); }"
                " bulkcat_out( true );" % (LIST_BATCHES - have)
            )
        counts = {}
        for per_page in (25, 100):
            warm = [self.call("GET", "/batches", [("per_page", str(per_page))]) for _ in range(self.runs)]
            total = (self.json_of(warm[-1]) or {}).get("pagination", {}).get("total")
            counts[per_page] = max(w["perf"]["dispatch_queries"] for w in warm)
            self.metric("history", f"GET /batches per_page {per_page} p95", p95([w["perf"]["server_ms"] for w in warm]), "ms", "", None,
                        f"{counts[per_page]} queries, {total} batches")
        flat = counts[25] == counts[100]
        self.metric("history", "GET /batches query count constant", "yes" if flat else "no", "", "25 = 100", flat, str(counts))

    def export(self) -> None:
        # T103: the catalogue is exported by POST, as the export dialog's form posts it, switch off (the file the
        # retired catalogue GET wrote, byte for byte: T96); the same rows, columns and budgets as before.
        peaks = {}
        for rows in (2000, 20000):
            fields = filter_params("category_ids", "isAny", [self.scale_cat]) + [("columns", "name,sku,regular_price,stock_quantity,stock_status")]
            if rows == 2000:
                fields += filter_params("type", "isAny", ["simple"]) + filter_params("stock_quantity", "between", ["1", "8"])
            reply = self.post_export(fields, f"export-{rows}-{uuid.uuid4().hex[:8]}")
            lines = reply["lines"]
            peaks[rows] = reply["peak_mb"]
            if rows == 20000:
                self.metric("export", "20,000 rows: first byte", reply["ttfb_ms"], "ms", "< 2 s", reply["status"] == 200 and reply["ttfb_ms"] < BUDGET["export_ttfb_ms"],
                            f"{len(reply['body']) / 1048576:.1f} MB")
                self.metric("export", "20,000 rows: complete", reply["total_ms"], "ms", "< 60 s, every row",
                            lines == rows and reply["total_ms"] < BUDGET["export_total_ms"],
                            f"{lines} data rows; server {reply['server_ms']} ms, {reply['queries']} queries")
            else:
                self.metric("export", f"{rows} rows: peak memory", peaks[rows], "MB", "", None, f"{lines} data rows in {reply['total_ms']:.0f} ms")
        grew = peaks[20000] - peaks[2000]
        self.metric("export", "20,000 rows: peak memory", peaks[20000], "MB", "flat (+<8 MB)", grew < EXPORT_FLAT_MB,
                    f"{grew:+.2f} MB over the 2,000-row export")

    # ---- ticket 10: POST /export ------------------------------------------------------

    def post_export(self, fields: list[tuple[str, str]], log: str) -> dict[str, Any]:
        """POST /export as the dialog's form posts it (the nonce in the body), timed at first byte and in full."""
        opener, nonce = self.p.session("manager")
        data = urllib.parse.urlencode(list(fields) + [("_wpnonce", nonce)]).encode()
        headers = {"Content-Type": "application/x-www-form-urlencoded", "X-BULKCAT-Perf-Log": log}
        request = urllib.request.Request(self.p.base + NS + "/export", data=data, method="POST", headers=headers)
        start = time.perf_counter()
        try:
            reply = opener.open(request, timeout=600)
        except urllib.error.HTTPError as error:
            reply = error
        first = time.perf_counter()
        payload = reply.read()
        end = time.perf_counter()
        status = reply.status if hasattr(reply, "status") else reply.code
        logged = next((r for r in self.perf_log() if r.get("id") == log), {})
        return {"status": status, "ttfb_ms": (first - start) * 1000, "total_ms": (end - start) * 1000, "body": payload,
                "lines": payload.count(b"\n") - 1, "peak_mb": logged.get("peak_mb", 0.0), "server_ms": logged.get("total_ms"), "queries": logged.get("queries")}

    def capture_token(self, query: dict) -> tuple[str, int, float]:
        """A v2 selection of a query, captured under a grant: its token, its count and the capture's seconds."""
        start = time.perf_counter()
        sel = self.p.capture("manager", {"query": query})
        return sel.get("token", ""), int(sel.get("count") or 0), time.perf_counter() - start

    def export_post(self) -> None:
        export_fixture(self.p)  # refuses unless WP_CMD reaches the declared local fixture (BULKCAT_FIXTURE_URL)
        columns = [("columns", "name,sku,regular_price,stock_quantity,stock_status"), ("identifiers", "1")]
        scale = {"category_ids": {"operator": "isAny", "value": [self.scale_cat]}}
        small = {**scale, "type": {"operator": "isAny", "value": ["simple"]}, "stock_quantity": {"operator": "between", "value": [1, 8]}}
        as_form = lambda query: [(f"{k}[{sub}]" + ("[]" if isinstance(v, list) else ""), str(item))  # noqa: E731
                                 for k, spec in query.items() for sub, v in spec.items() for item in (v if isinstance(v, list) else [v])]
        for source in ("filter", "selection"):
            peaks = {}
            for rows, query in ((2000, small), (20000, scale)):
                if source == "filter":
                    fields, want, capture = columns + as_form(query), None, 0.0
                else:
                    token, want, capture = self.capture_token(query)
                    fields = columns + [("selection", token)]
                reply = self.post_export(fields, f"export-post-{source}-{rows}-{uuid.uuid4().hex[:8]}")
                peaks[rows] = reply["peak_mb"]
                name = f"export-{source}-20000-identifiers"
                if rows == 20000:
                    every = reply["lines"] == (want if want is not None else 20000)
                    self.metric("export_post", f"{name}: first byte", reply["ttfb_ms"], "ms", "< 2 s", reply["status"] == 200 and reply["ttfb_ms"] < BUDGET["export_ttfb_ms"],
                                f"{len(reply['body']) / 1048576:.1f} MB" + (f"; the capture before it took {capture:.1f} s" if source == "selection" else ""))
                    self.metric("export_post", f"{name}: complete", reply["total_ms"], "ms", "< 60 s, every row", every and reply["total_ms"] < BUDGET["export_total_ms"],
                                f"{reply['lines']} data rows; server {reply['server_ms']} ms, {reply['queries']} queries")
                else:
                    self.metric("export_post", f"{name}: 2,000-row peak memory", peaks[rows], "MB", "", None, f"{reply['lines']} data rows in {reply['total_ms']:.0f} ms")
            grew = peaks[20000] - peaks[2000]
            self.metric("export_post", f"export-{source}-20000-identifiers: peak memory", peaks[20000], "MB", "flat (+<8 MB)", grew < EXPORT_FLAT_MB,
                        f"{grew:+.2f} MB over the 2,000-row export")

    def export_scale(self) -> None:
        export_fixture(self.p)
        token, count, capture = self.capture_token({"category_ids": {"operator": "isAny", "value": [self.scale_cat]}, "mode": "products_and_variations"})
        if count != 100000:
            raise RuntimeError(f"the scale category holds {count} products and variations (want 100,000: seed.sh 22223)")
        reply = self.post_export([("columns", "name,sku,regular_price,stock_status"), ("identifiers", "1"), ("selection", token)],
                                 f"export-scale-100000-{uuid.uuid4().hex[:8]}")
        self.metric("export_scale", "export-selection-100000: complete under 128 MB", reply["peak_mb"], "MB", "< 128 MB",
                    reply["status"] == 200 and reply["lines"] == 100000 and 0 < reply["peak_mb"] < 128,
                    f"{reply['lines']} data rows; first byte {reply['ttfb_ms']:.0f} ms, total {reply['total_ms'] / 1000:.1f} s, server {reply['server_ms']} ms, "
                    f"{reply['queries']} queries; capture {capture:.1f} s; the web request's memory_limit is 128M (read:export:time-limit)")
        # The dialog's summary with the switch on: presence and the guarded counts, measured rather than assumed.
        scale = {"category_ids": {"operator": "isAny", "value": [self.scale_cat]}}
        for label, body in (("100,000 selected members", {"selection": token}), ("the scale category by filter", {**scale})):
            answer = self.call("POST", "/export", body={"columns": ["name", "sku"], "identifiers": True, "summary": True, **body})
            data = self.json_of(answer) or {}
            self.metric("export_scale", f"summary with identifiers: {label}", answer["perf"].get("server_ms", 0.0), "ms", "", None,
                        f"rows {data.get('rows')}, left out {data.get('left_out')}, guarded {data.get('guarded_identifiers')}/{data.get('guarded_names')}; "
                        f"{answer['perf'].get('dispatch_queries')} queries in the dispatch, peak {answer['perf'].get('peak_mb')} MB")

    def run_batch_web(self, batch_id: int) -> tuple[float, dict, list[dict[str, Any]]]:
        """Runs Action Scheduler's queue in web requests (the measurement mu-plugin's runner) until the batch finishes."""
        key = self.p.wp_eval("$k = wp_generate_password( 32, false, false ); update_option( 'bulkcat_perf_queue_key', $k, false ); bulkcat_out( $k );")
        runs: list[dict[str, Any]] = []
        report: dict = {}
        start = time.perf_counter()
        try:
            for _ in range(5000):
                request = urllib.request.Request(self.p.base + "/", data=b"", method="POST", headers={"X-BULKCAT-Perf-Queue": key})
                try:
                    reply = urllib.request.urlopen(request, timeout=600)
                    answer = json.loads(reply.read().decode("utf-8") or "{}")
                except (urllib.error.HTTPError, ValueError) as error:
                    runs.append({"error": str(error)[:200]})
                    break
                runs.append(answer)
                report = self.json_of(self.call("GET", f"/batches/{batch_id}")) or {}
                if report.get("status") not in ("queued", "running"):
                    break
        finally:
            self.p.wp_eval("delete_option( 'bulkcat_perf_queue_key' ); bulkcat_out( true );")
        return time.perf_counter() - start, report, runs

    def undo_scale(self) -> None:
        batch_id = self.undo_batch
        if not batch_id:
            token, count, capture = self.capture_token({"category_ids": {"operator": "isAny", "value": [self.scale_cat]}, "mode": "products_and_variations"})
            if count != 100000:
                raise RuntimeError(f"the scale category holds {count} products and variations (want 100,000: seed.sh 22223)")
            operations = [{"action": "set_value", "args": {"field": "weight", "value": SCALE_WEIGHT, "apply_to_variations": False}}]
            pre = self.json_of(self.call("POST", "/preview", body={"token": token, "operations": operations})) or {}
            reply = self.call("POST", "/batches", body=self.p.apply_body("manager", pre, {"token": token}))
            created = self.json_of(reply) or {}
            assert reply["status"] == 201, f"POST /batches {reply['status']} {created}"
            batch_id = int(created["batch_id"])
            self.metric("undo_scale", "the change: 100,000 accepted", reply["perf"]["peak_mb"], "MB", "", None,
                        f"batch {batch_id}; capture {capture:.1f} s; {reply['perf']['server_ms']} ms")
            wall, report = self.run_batch(batch_id)
            self.metric("undo_scale", "the change: run with wp action-scheduler run", wall, "s", "", None,
                        f"status {report.get('status')}, {report.get('changed_items')} changed")
        facts = self.p.wp_eval(
            "global $wpdb; $b = %d; bulkcat_out( array("
            " 'status' => $wpdb->get_var( $wpdb->prepare( \"SELECT status FROM {$wpdb->prefix}bulkcat_batches WHERE batch_id = %%d\", $b ) ),"
            " 'objects' => (int) $wpdb->get_var( $wpdb->prepare( \"SELECT COUNT(DISTINCT object_id) FROM {$wpdb->prefix}bulkcat_changes WHERE batch_id = %%d\", $b ) ) ) );" % batch_id
        )
        if facts["objects"] < 100000:
            raise RuntimeError(f"batch {batch_id} journalled {facts['objects']} objects (want 100,000)")
        route = f"/batches/{batch_id}/undo"

        def budget(name: str, reply: dict[str, Any], detail: str) -> None:
            # The limit the web request itself ran under, from the mu-plugin's header.
            peak = float(reply["perf"].get("peak_mb", 0.0))
            limit = reply["perf"].get("memory_limit")
            ok = reply["status"] in (200, 201) and 0 < peak < BUDGET["chunk_peak_mb"] and limit == "128M"
            self.metric("undo_scale", name, peak, "MB", "< 96 MB", ok,
                        f"under the request's {limit} limit; HTTP {reply['status']}, {reply['perf'].get('server_ms')} ms server, {reply['perf'].get('dispatch_queries')} queries; {detail}")

        first = self.call("POST", route, body={"preview": True, "per_page": 25})
        plan = self.json_of(first) or {}
        budget("undo preview, page 1 (100,000 objects)", first, f"counts {plan.get('counts')}, {len(plan.get('items') or [])} items")
        pages = int((plan.get("pagination") or {}).get("totalPages") or 1)
        last = self.call("POST", route, body={"preview": True, "per_page": 25, "page": pages})
        budget(f"undo preview, last page ({pages})", last, f"{len((self.json_of(last) or {}).get('items') or [])} items")
        count = self.call("GET", f"/batches/{batch_id}", [("reversibility", "count")])
        budget("single batch read counting conflicts", count, f"reversibility {(self.json_of(count) or {}).get('reversibility')}")
        accepted = self.call("POST", route, body=self.p.undo_body("manager", batch_id, plan.get("plan_hash")))
        undo = self.json_of(accepted) or {}
        budget("undo accepted (every item recorded)", accepted, f"{undo.get('total_items')} items, status {undo.get('status')}")
        assert accepted["status"] == 201, f"undo {accepted['status']} {undo}"

        self.reset_log()
        wall, report, runs = self.run_batch_web(int(undo["batch_id"]))
        chunks = [row for row in self.perf_log() if row["kind"] == "bulkcat_run_chunk"]
        peak = max((c["peak_mb"] for c in chunks), default=0.0)
        limits = sorted({r.get("memory_limit") for r in runs if "memory_limit" in r})
        failed = [r for r in runs if "error" in r]
        done = int(report.get("done_items") or 0)
        self.metric("undo_scale", "undo run in web requests: peak per chunk", peak, "MB", "< 96 MB",
                    bool(chunks) and peak < BUDGET["chunk_peak_mb"] and limits == ["128M"] and not failed,
                    f"{len(chunks)} chunks in {len(runs)} web requests (limit {limits}); a chunk starts at up to {max((c['start_mb'] for c in chunks), default=0.0)} MB; "
                    f"requests' own peak up to {max((r.get('peak_mb', 0.0) for r in runs), default=0.0)} MB; {len(failed)} failed requests")
        self.metric("undo_scale", "undo run: objects a minute", done / (wall / 60) if wall else 0.0, "/min", ">= 300",
                    report.get("status") == "complete" and done / (wall / 60) >= BUDGET["write_per_minute"] if wall else False,
                    f"{done} items, {report.get('changed_items')} changed, {report.get('failed_items')} failed, status {report.get('status')}, {wall / 60:.1f} min wall")
        after = self.p.wp_eval(
            "global $wpdb; $b = %d; bulkcat_out( array("
            " 'undone' => $wpdb->get_var( $wpdb->prepare( \"SELECT status FROM {$wpdb->prefix}bulkcat_batches WHERE batch_id = %%d\", $b ) ),"
            " 'left' => (int) $wpdb->get_var( $wpdb->prepare( \"SELECT COUNT(*) FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->prefix}bulkcat_batch_items i ON i.object_id = m.post_id AND i.batch_id = %%d WHERE m.meta_key = '_weight' AND m.meta_value = %%s\", $b, '%s' ) ) ) );"
            % (batch_id, SCALE_WEIGHT)
        )
        self.metric("undo_scale", "the change undone", after["left"], "objects", "0 left", after["undone"] == "undone" and after["left"] == 0,
                    f"batch {batch_id} reads {after['undone']}; objects still at weight {SCALE_WEIGHT}: {after['left']}")

    def prune(self) -> None:
        made = self.p.wp_eval(
            "global $wpdb; $b = $wpdb->prefix . 'bulkcat_batches'; $c = $wpdb->prefix . 'bulkcat_changes'; $i = $wpdb->prefix . 'bulkcat_batch_items';"
            " $src = (int) $wpdb->get_var( \"SELECT batch_id FROM $c GROUP BY batch_id ORDER BY COUNT(*) DESC LIMIT 1\" );"
            " if ( 5000 > (int) $wpdb->get_var( $wpdb->prepare( \"SELECT COUNT(*) FROM $c WHERE batch_id = %d\", $src ) ) ) { throw new RuntimeException( 'no 5,000-row journal to copy; run the write group first' ); }"
            " $old = gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ); $rows = 0; $ids = array();"
            " for ( $n = 0; $n < 10; $n++ ) {"
            "  $wpdb->query( $wpdb->prepare( \"INSERT INTO $b (uuid, user_id, created_gmt, finished_gmt, status, source, operations, selection, total_items, done_items, changed_items, chunk_size) SELECT %s, user_id, %s, %s, 'complete', 'bulk', operations, selection, 5000, 5000, 5000, 50 FROM $b WHERE batch_id = %d\", wp_generate_uuid4(), $old, $old, $src ) );"
            "  $id = (int) $wpdb->insert_id; $ids[] = $id;"
            "  $wpdb->query( $wpdb->prepare( \"INSERT INTO $c (batch_id, object_id, object_type, prop, old_value, new_value, modified_gmt, applied_gmt, result, message) SELECT %d, object_id, object_type, prop, old_value, new_value, %s, %s, result, message FROM $c WHERE batch_id = %d ORDER BY change_id LIMIT 5000\", $id, $old, $old, $src ) );"
            "  $rows += (int) $wpdb->rows_affected;"
            "  $wpdb->query( $wpdb->prepare( \"INSERT INTO $i (batch_id, object_id, object_type, state, attempts) SELECT %d, object_id, object_type, 'done', 1 FROM $i WHERE batch_id = %d ORDER BY item_id LIMIT 5000\", $id, $src ) );"
            " }"
            " bulkcat_out( array( 'rows' => $rows, 'ids' => $ids ) );"
        )
        ids = ",".join(str(int(i)) for i in made["ids"])
        self.reset_log()
        self.p.wp_eval("as_enqueue_async_action( 'bulkcat_prune_history', array(), 'bulk-catalogue-manager-for-woocommerce' ); bulkcat_out( true );")
        wall = 0.0
        for _ in range(20):
            wall += self.run_actions("bulkcat_prune_history")
            left = self.p.wp_eval(
                "global $wpdb; bulkcat_out( (int) $wpdb->get_var( \"SELECT COUNT(*) FROM {$wpdb->prefix}bulkcat_changes WHERE batch_id IN (" + ids + ")\" ) );"
            )
            if left == 0:
                break
        runs = [r for r in self.perf_log() if r["kind"] == "bulkcat_prune_history"]
        self.metric("prune", f"prune {made['rows']} journal rows", wall, "s", "", None if left == 0 else False,
                    f"{len(runs)} runs, longest {max((r['ms'] for r in runs), default=0):.0f} ms, peak {max((r['peak_mb'] for r in runs), default=0)} MB, {left} rows left")

    # ---- main ----------------------------------------------------------------------

    def run(self, only: list[str]) -> int:
        self.preflight()
        for group in GROUPS:
            if group not in only:
                continue
            try:
                getattr(self, group)()
            except (AssertionError, KeyError, TypeError, ValueError, RuntimeError) as error:
                self.metric(group, "error", type(error).__name__, "", "", False, str(error)[:300])
        failed = [r for r in self.rows if r["result"] == "FAIL"]
        print(f"\n{len(self.rows)} metrics: {sum(r['result'] == 'PASS' for r in self.rows)} passed, {len(failed)} failed, "
              f"{sum(r['result'] == 'INFO' for r in self.rows)} recorded", flush=True)
        return 1 if failed else 0


def main() -> int:
    here = os.path.dirname(os.path.abspath(__file__))
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--base-url", required=True)
    parser.add_argument("--seed", default=os.path.join(here, "seed.json"))
    parser.add_argument("--wp-cmd", default=os.environ.get("WP_CMD", "wp"))
    parser.add_argument("--runs", type=int, default=20, help="warm requests per read metric (default 20)")
    parser.add_argument("--only", default=",".join(DEFAULT_GROUPS), help="comma-separated groups: " + ",".join(GROUPS) + " (undo_scale only when named)")
    parser.add_argument("--undo-batch", type=int, default=0, help="undo_scale: undo this finished 100,000-object batch instead of making one")
    parser.add_argument("--json", default="", help="also write the metrics to this JSON file")
    parser.add_argument("--verbose", action="store_true")
    args = parser.parse_args()
    args.no_wp = False
    only = [g.strip() for g in args.only.split(",") if g.strip()]
    unknown = [g for g in only if g not in GROUPS]
    if unknown:
        parser.error(f"unknown group(s): {', '.join(unknown)}")
    perf = Perf(args)
    code = perf.run(only)
    if args.json:
        with open(args.json, "w", encoding="utf-8") as handle:
            json.dump({"date": time.strftime("%Y-%m-%d"), "stack": perf.stack, "budgets": BUDGET, "metrics": perf.rows}, handle, indent=1)
            handle.write("\n")
    return code


if __name__ == "__main__":
    sys.exit(main())
