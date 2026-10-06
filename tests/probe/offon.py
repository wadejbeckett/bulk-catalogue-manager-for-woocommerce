"""The retained ``offon`` regression chapter, updated for native Status14.
Historical row IDs stay stable; ordinary new requests now use native semantics.
Genuine released receipts/recovery are qualified by native-status-upgrade.sh.
These current requests are proved through the manager routes on WordPress and
WooCommerce, never a real shop.

Its fixtures are upserted through WP-CLI inside the chapter (SKUs
``bulkcat-offon-*``, category "Bulkcat Offon"), each row resetting the
products it uses first. A switch is always the manager's own: an exact
preview of the ids (``POST /preview``), a durable Apply under a
``/requests`` grant (``POST /batches``), Action Scheduler run through
``wp action-scheduler run``, and the result read back with WooCommerce's
own ``wc_get_product()`` and the journal table. probe.py imports this
module and lists its checks as the ``offon`` chapter.

Standard library only, like probe.py.
"""

from __future__ import annotations

import base64
import http.cookiejar
import json
import re
import time
import urllib.parse
from typing import Any

SKU = "bulkcat-offon-"
META = "_bulkcat_availability_off"
CHAPTER = "offon"

# ---------------------------------------------------------------------------
# fixtures
# ---------------------------------------------------------------------------

# One product per key. type simple or variable (Small and Medium enabled,
# Large disabled); status; visibility; date as seconds from now (a scheduled
# product needs it ahead); cat for a category of its own.
UPSERT_PHP = r"""
$specs = json_decode( base64_decode( '%s' ), true );
$term = function ( $tax, $slug, $name ) {
	$t = get_term_by( 'slug', $slug, $tax );
	if ( $t ) { return (int) $t->term_id; }
	$made = wp_insert_term( $name, $tax, array( 'slug' => $slug ) );
	return (int) $made['term_id'];
};
$shared = $term( 'product_cat', 'bulkcat-offon', 'Bulkcat Offon' );
$tag    = $term( 'product_tag', 'bulkcat-offon-allocation', 'Bulkcat Offon allocation' );
$out    = array();
foreach ( $specs as $spec ) {
	$sku   = 'bulkcat-offon-' . $spec['key'];
	$class = 'variable' === $spec['type'] ? 'WC_Product_Variable' : 'WC_Product_Simple';
	$id    = wc_get_product_id_by_sku( $sku );
	if ( $id && 'trash' === get_post_status( $id ) ) { wp_untrash_post( $id ); }
	$p = $id ? wc_get_product( $id ) : null;
	if ( ! $p || ! is_a( $p, $class ) ) { $p = new $class(); $p->set_sku( $sku ); }
	$cat = empty( $spec['cat'] ) ? $shared : $term( 'product_cat', 'bulkcat-offon-' . $spec['cat'], 'Bulkcat Offon ' . $spec['cat'] );
	$p->set_name( 'Bulkcat Offon ' . $spec['key'] );
	$p->set_slug( 'bulkcat-offon-' . $spec['key'] );
	$p->set_catalog_visibility( $spec['visibility'] ?? 'visible' );
	$p->set_featured( false );
	$p->set_category_ids( array( $cat ) );
	$p->set_tag_ids( array( $tag ) );
	$p->set_post_password( '' );
	$p->set_menu_order( 0 );
	$p->set_reviews_allowed( true );
	$p->set_date_created( time() + (int) ( $spec['date'] ?? -2592000 ) );
	$p->update_meta_data( '_probe_allocation', 'group-alpha' );
	$p->delete_meta_data( '_bulkcat_availability_off' );
	if ( 'variable' === $spec['type'] ) {
		$a = new WC_Product_Attribute();
		$a->set_name( 'Size' );
		$a->set_options( array( 'Small', 'Medium', 'Large' ) );
		$a->set_visible( true );
		$a->set_variation( true );
		$p->set_attributes( array( $a ) );
	} else {
		$p->set_regular_price( '10.00' );
		$p->set_sale_price( '' );
		$p->set_manage_stock( false );
		$p->set_stock_status( 'instock' );
	}
	$p->set_status( $spec['status'] );
	$id  = $p->save();
	$row = array( 'id' => $id, 'key' => $spec['key'] );
	if ( 'variable' === $spec['type'] ) {
		foreach ( array( 'Small' => array( '20.00', 'publish' ), 'Medium' => array( '21.00', 'publish' ), 'Large' => array( '22.00', 'private' ) ) as $opt => $v ) {
			$vsku = $sku . '-' . strtolower( $opt );
			$vid  = wc_get_product_id_by_sku( $vsku );
			$c    = $vid ? wc_get_product( $vid ) : null;
			if ( ! $c || ! is_a( $c, 'WC_Product_Variation' ) ) { $c = new WC_Product_Variation(); $c->set_sku( $vsku ); }
			$c->set_parent_id( $id );
			$c->set_status( $v[1] );
			$c->set_attributes( array( 'size' => $opt ) );
			$c->set_regular_price( $v[0] );
			$c->set_manage_stock( false );
			$c->set_stock_status( 'instock' );
			$row[ strtolower( $opt ) ] = (int) $c->save();
		}
		WC_Product_Variable::sync( $id );
	}
	clean_post_cache( $id );
	$row['status'] = get_post_status( $id );
	$row['slug']   = get_post_field( 'post_name', $id );
	$row['cat']    = $cat;
	$out[ $spec['key'] ] = $row;
}
bulkcat_out( $out );
"""

# The proof's snapshot (ref.php offon_snap()): every field Off and On must leave alone.
SNAP_PHP = r"""
$out = array();
foreach ( %s as $id ) {
	clean_post_cache( $id );
	$p = wc_get_product( $id );
	$post = get_post( $id );
	if ( ! $p || ! $post ) { $out[ $id ] = array( 'missing' => true ); continue; }
	$row = array(
		'status' => $post->post_status, 'slug' => $post->post_name, 'date' => $post->post_date, 'date_gmt' => $post->post_date_gmt,
		'menu_order' => (int) $post->menu_order, 'password' => $post->post_password, 'comment_status' => $post->comment_status,
		'catalog_visibility' => $p->get_catalog_visibility(), 'featured' => $p->get_featured(), 'stock_status' => $p->get_stock_status(),
		'price' => $p->get_price(), 'regular_price' => $p->get_regular_price(), 'cats' => $p->get_category_ids(), 'tags' => $p->get_tag_ids(),
		'allocation' => get_post_meta( $id, '_probe_allocation', true ), 'record' => get_post_meta( $id, '_bulkcat_availability_off', true ),
	);
	if ( $p->is_type( 'variable' ) ) {
		$row['variations'] = array();
		foreach ( get_children( array( 'post_parent' => $id, 'post_type' => 'product_variation', 'post_status' => 'any', 'orderby' => 'ID', 'order' => 'ASC' ) ) as $c ) {
			$v = wc_get_product( $c->ID );
			$row['variations'][ $c->ID ] = array( 'status' => $c->post_status, 'price' => $v ? $v->get_price() : null, 'stock_status' => $v ? $v->get_stock_status() : null, 'slug' => $c->post_name );
		}
		ksort( $row['variations'] );
	}
	$out[ $id ] = $row;
}
bulkcat_out( $out );
"""

JOURNAL_PHP = r"""
global $wpdb;
$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT object_id, prop, old_value, new_value, result FROM %%i WHERE batch_id = %%d ORDER BY change_id ASC', $wpdb->prefix . 'bulkcat_changes', %d ), ARRAY_A );
$out = array();
foreach ( $rows as $r ) {
	$out[] = array( (int) $r['object_id'], $r['prop'], \BulkCatalogueManager\Data\ValueCodec::decode( $r['old_value'] ), \BulkCatalogueManager\Data\ValueCodec::decode( $r['new_value'] ), $r['result'] );
}
bulkcat_out( $out );
"""


def php_ids(ids: list[int]) -> str:
    return "array( " + ", ".join(str(int(i)) for i in ids) + " )"


def upsert(p: Any, specs: list[dict]) -> dict[str, dict]:
    """Create or reset fixture products (no Off record) and answer their ids by key."""
    blob = base64.b64encode(json.dumps(specs).encode()).decode()
    # Pin a valid native slug even for Pending/Draft fixtures. A first Publish
    # legitimately generates a missing slug; that is not a Status/Undo defect.
    actor = int(p.seed['users']['admin']['id'])
    return p.wp_eval(f"wp_set_current_user( {actor} );" + UPSERT_PHP % blob)


def simple(key: str, status: str = "publish", **extra: Any) -> dict:
    return {"key": key, "type": "simple", "status": status, **extra}


def snap(p: Any, ids: list[int]) -> dict[str, dict]:
    return p.wp_eval(SNAP_PHP % php_ids(ids))


def journal(p: Any, batch_id: int) -> list[list]:
    return p.wp_eval(JOURNAL_PHP % int(batch_id))


def record(p: Any, pid: int) -> str:
    return str(p.wp_eval(f"clean_post_cache( {pid} ); bulkcat_out( get_post_meta( {pid}, '{META}', true ) );"))


def statuses(p: Any, ids: list[int]) -> dict[str, str]:
    return p.wp_eval("$o = array(); foreach ( " + php_ids(ids) + " as $id ) { clean_post_cache( $id ); $o[ $id ] = get_post_status( $id ); } bulkcat_out( $o );")


def set_status_outside(p: Any, pid: int, status: str) -> None:
    """An outside writer (the editor, an import): WooCommerce CRUD, not the manager."""
    p.wp_eval(f"$x = wc_get_product( {pid} ); $x->set_status( '{status}' ); $x->save(); bulkcat_out( 1 );")


def status_step(value: str, keep: bool = False, variations: bool = False) -> list[dict]:
    args: dict[str, Any] = {"field": "status", "value": value, "apply_to_variations": variations}
    if keep:
        args["keep_changed_status"] = True
    return [{"action": "set_value", "args": args}]


def preview(p: Any, ids: list[int], operations: list[dict]) -> dict:
    reply = p.http("manager", "POST", "/preview", body={"ids": ids, "operations": operations, "sample_size": "all"})
    if reply.status != 200:
        raise AssertionError(f"preview {reply.brief()}")
    return reply.json() or {}


def apply(p: Any, ids: list[int], operations: list[dict], pre: dict | None = None) -> dict:
    """Preview (unless given), Apply under a grant, run the queue: {preview, batch, report}."""
    pre = pre or preview(p, ids, operations)
    reply = p.http("manager", "POST", "/batches", body=p.apply_body("manager", pre, {"ids": ids}))
    made = reply.json() or {}
    if reply.status != 201 or not made.get("batch_id"):
        raise AssertionError(f"batch {reply.brief()} {made}")
    report = p.run_queue(made["batch_id"])
    return {"preview": pre, "batch": made["batch_id"], "report": report}


def switch(p: Any, ids: list[int], value: str, keep: bool = False, variations: bool = False) -> dict:
    return apply(p, ids, status_step(value, keep, variations))


def notes(pre: dict) -> dict[int, str]:
    """Each object's Status outcome code, from the preview's sample."""
    return {row["object_id"]: row.get("note", "") for row in pre.get("sample", []) if row.get("field") == "status"}


def own_record(raw: str, pid: int) -> dict | None:
    try:
        data = json.loads(raw) if raw else None
    except ValueError:
        return None
    return data if isinstance(data, dict) and data.get("id") == pid else None


def event(p: Any, pid: int) -> int:
    """When the post's publish_future_post event runs, or 0 when it has none."""
    return int(p.wp_eval(f"bulkcat_out( (int) wp_next_scheduled( 'publish_future_post', array( {pid} ) ) );"))


def row(p: Any, name: str, ok: bool, detail: str) -> None:
    p.row(CHAPTER, f"{CHAPTER}:{name}", bool(ok), detail)


def guard(p: Any, name: str, fn) -> None:
    p.guard(CHAPTER, f"{CHAPTER}:{name}", fn)


# ---------------------------------------------------------------------------
# T89: the switch through the engine (D1-D10, E10, the status read-back)
# ---------------------------------------------------------------------------

STATE = [
    simple("publish"),
    simple("hidden", visibility="hidden"),
    simple("search", visibility="search"),
    simple("draft", "draft"),
    simple("private", "private"),
    simple("pending", "pending"),
    simple("future", "future", date=7 * 86400),
    {"key": "variable", "type": "variable", "status": "publish"},
    simple("unrelated-draft", "draft"),
    simple("unrelated-private", "private"),
    simple("unrelated-future", "future", date=7 * 86400),
]


def check_engine(p: Any) -> None:
    def round_trip() -> None:
        fx = upsert(p, STATE)
        chosen = [fx[k]["id"] for k in ("publish", "hidden", "search", "draft", "private", "pending", "future", "variable")]
        unrelated = [fx[k]["id"] for k in ("unrelated-draft", "unrelated-private", "unrelated-future")]
        before = snap(p, chosen + unrelated)
        off = switch(p, chosen, "draft")
        mid = snap(p, chosen)
        saved = {pid: (own_record(mid[str(pid)]["record"], pid) or {}).get("status") for pid in chosen}
        want_saved = {pid: None for pid in chosen}
        all_draft = all(mid[str(pid)]["status"] == "draft" for pid in chosen)
        # A genuine Draft no-op is left out; a new Publish is native for every selected state.
        switched = [pid for pid in chosen if pid != fx["draft"]["id"]]
        on = switch(p, switched, "publish")
        after = snap(p, chosen + unrelated)
        for pid in switched:
            before[str(pid)]["status"] = "future" if pid == fx["future"]["id"] else "publish"
        diffs = [f"{pid}.{k}: {before[str(pid)].get(k)!r} -> {after[str(pid)].get(k)!r}" for pid in chosen + unrelated
                 for k in set(before[str(pid)]) | set(after[str(pid)]) if before[str(pid)].get(k) != after[str(pid)].get(k)]
        ok = all_draft and saved == want_saved and not diffs and off["report"].get("status") == "complete" and on["report"].get("status") == "complete"
        row(p, "d1-round-trip", ok, f"off {off['report'].get('status')} saved {saved}; on {on['report'].get('status')}; diffs {diffs[:6]}")

    def already_draft() -> None:
        fx = upsert(p, [simple("genuine-draft", "draft")])
        pid = fx["genuine-draft"]["id"]
        before = snap(p, [pid])
        done = switch(p, [pid], "draft")
        after = snap(p, [pid])
        ok = (notes(done["preview"]).get(pid) == "" and done["report"].get("changed_items") == 0
              and before == after and after[str(pid)]["record"] == "" and journal(p, done["batch"]) == [])
        row(p, "d2-already-draft", ok, f"note {notes(done['preview']).get(pid)} changed {done['report'].get('changed_items')} record {after[str(pid)]['record']!r}")

    def not_off_publishes() -> None:
        fx = upsert(p, [simple("never-draft", "draft"), simple("never-private", "private"), simple("never-future", "future", date=7 * 86400)])
        ids = [fx[k]["id"] for k in ("never-draft", "never-private", "never-future")]
        done = switch(p, ids, "publish")
        after = snap(p, ids)
        got = {pid: after[str(pid)]["status"] for pid in ids}
        want = {ids[0]: "publish", ids[1]: "publish", ids[2]: "future"}
        codes = notes(done["preview"])
        ok = got == want and all(codes.get(pid) == "" for pid in ids) and all(after[str(pid)]["record"] == "" for pid in ids)
        row(p, "d2-not-off-publishes", ok, f"statuses {got} (the scheduled one stays scheduled) notes {codes} no record written")

    def repeat_off_on() -> None:
        fx = upsert(p, [simple("repeat")])
        pid = fx["repeat"]["id"]
        switch(p, [pid], "draft")
        first = record(p, pid)
        again = switch(p, [pid], "draft")
        ok = notes(again["preview"]).get(pid) == "" and again["report"].get("changed_items") == 0 and record(p, pid) == first
        row(p, "d3-repeat-off", ok, f"note {notes(again['preview']).get(pid)} changed {again['report'].get('changed_items')} record kept {record(p, pid) == first}")
        switch(p, [pid], "publish")
        on_again = switch(p, [pid], "publish")
        ok = on_again["report"].get("changed_items") == 0 and statuses(p, [pid])[str(pid)] == "publish" and notes(on_again["preview"]).get(pid) == ""
        row(p, "d3-repeat-on", ok, f"a second Published changed {on_again['report'].get('changed_items')}, status {statuses(p, [pid])[str(pid)]}")

    def second_published() -> None:
        fx = upsert(p, [simple("second", "private")])
        pid = fx["second"]["id"]
        switch(p, [pid], "draft")
        on = switch(p, [pid], "publish")
        restored = statuses(p, [pid])[str(pid)]
        again = switch(p, [pid], "publish")
        final = statuses(p, [pid])[str(pid)]
        ok = (notes(on["preview"]).get(pid) == "" and restored == "publish" and record(p, pid) == ""
              and notes(again["preview"]).get(pid) == "" and final == "publish")
        row(p, "d3-second-published", ok, f"New Publish stored {restored} ({notes(on['preview']).get(pid)}); Published again: {notes(again['preview']).get(pid)} -> {final}")

    def edits_survive() -> None:
        fx = upsert(p, [simple("edits")])
        pid = fx["edits"]["id"]
        switch(p, [pid], "draft")
        other = fx["edits"]["cat"]
        extra = int(p.wp_eval("$t = get_term_by( 'slug', 'bulkcat-offon-extra', 'product_cat' ); bulkcat_out( $t ? $t->term_id : wp_insert_term( 'Bulkcat Offon extra', 'product_cat', array( 'slug' => 'bulkcat-offon-extra' ) )['term_id'] );"))
        edit = [{"action": "set_value", "args": {"field": "regular_price", "value": "12.34"}},
                {"action": "replace_terms", "args": {"field": "category_ids", "terms": [other, extra]}},
                {"action": "set_value", "args": {"field": "catalog_visibility", "value": "search"}}]
        apply(p, [pid], edit)
        p.wp_eval(f"update_post_meta( {pid}, '_probe_allocation', 'group-beta' ); bulkcat_out( 1 );")
        switch(p, [pid], "publish")
        s = snap(p, [pid])[str(pid)]
        ok = (s["status"] == "publish" and s["regular_price"] == "12.34" and sorted(s["cats"]) == sorted([other, extra])
              and s["catalog_visibility"] == "search" and s["allocation"] == "group-beta" and s["record"] == "")
        row(p, "d4-edits-survive", ok, f"status {s['status']} price {s['regular_price']} cats {s['cats']} listing {s['catalog_visibility']} allocation {s['allocation']}")

    def conflicts() -> None:
        fx = upsert(p, [simple("conflict")])
        pid = fx["conflict"]["id"]
        put_record(p, pid, "publish")
        set_status_outside(p, pid, "private")
        published = switch(p, [pid], "publish")
        row(p, "d5-conflict", statuses(p, [pid])[str(pid)] == "publish" and record(p, pid) == "",
            "New Publish ignores the remembered target, journals its cleanup and publishes natively.")
        again = switch(p, [pid], "draft")
        row(p, "d5-recapture", statuses(p, [pid])[str(pid)] == "draft" and record(p, pid) == "",
            "New Draft does not recapture a status.")
        refused = p.http("manager", "POST", "/preview", body={"ids":[pid], "operations":status_step("publish", keep=True)})
        row(p, "d5-keep", refused.status == 400 and (refused.json() or {}).get("code") == "bulkcat_invalid_operation" and statuses(p,[pid])[str(pid)] == "draft",
            f"Explicit obsolete keep intent refuses with refresh guidance: {refused.brief()}")

    def slug_reserved() -> None:
        fx = upsert(p, [simple("slug")])
        pid, slug = fx["slug"]["id"], fx["slug"]["slug"]
        switch(p, [pid], "draft")
        twin = int(p.wp_eval("$t = new WC_Product_Simple(); $t->set_name( 'Bulkcat Offon slug' ); $t->set_status( 'publish' ); $t->set_regular_price( '1' ); bulkcat_out( $t->save() );"))
        twin_slug = p.wp_eval(f"bulkcat_out( get_post_field( 'post_name', {twin} ) );")
        switch(p, [pid], "publish")
        after = snap(p, [pid])[str(pid)]["slug"]
        p.wp("post", "delete", str(twin), "--force", check=False)
        ok = twin_slug == f"{slug}-2" and after == slug
        row(p, "d6-slug-reserved", ok, f"a new product while Off got {twin_slug}; after On {after} (was {slug})")

    def variation_choices() -> None:
        fx = upsert(p, [{"key": "var-choices", "type": "variable", "status": "publish"}])
        v = fx["var-choices"]
        fields = p.http("manager", "GET", "/fields").json() or {}
        status = next((f for f in fields.get("fields", []) if f.get("key") == "status"), {})
        pushed = switch(p, [v["id"]], "draft", variations=True)
        items = p.http("manager", "GET", f"/batches/{pushed['batch']}").json() or {}
        var_rows = [r for r in pushed["preview"].get("sample", []) if r.get("object_type") == "variation"]
        chosen = p.patch_product("manager", v["small"], {"status": "draft"})
        after = snap(p, [v["id"]])[str(v["id"])]
        var_status = {int(k): s["status"] for k, s in after["variations"].items()}
        # The parent is Off; its variations were never written, and none carries a record.
        records = [record(p, vid) for vid in (v["small"], v["medium"], v["large"])]
        ok = (status.get("variationChoices") == ["publish", "private"] and len(var_rows) == 3 and all(r.get("state") == "skipped" for r in var_rows)
              and chosen.status == 400 and var_status == {v["small"]: "publish", v["medium"]: "publish", v["large"]: "private"}
              and records == ["", "", ""] and after["status"] == "draft" and after["record"] == "")
        row(p, "d7-variation-choices", ok, f"variation choices {status.get('variationChoices')}; Draft pushed down: {[r.get('state') for r in var_rows]} ({items.get('status')}); Draft chosen on a variation: {chosen.brief()}; variations {var_status}")

    def duplicate() -> None:
        fx = upsert(p, [simple("original"), simple("copy-target", "draft")])
        pid = fx["original"]["id"]
        put_record(p, pid, "publish")
        copy = int(p.wp_eval(f"$d = new WC_Admin_Duplicate_Product(); $c = $d->product_duplicate( wc_get_product( {pid} ) ); bulkcat_out( $c->get_id() );"))
        copied = record(p, copy)
        # A record copied by hand (an import, a meta copy) onto another draft names the original.
        target = fx["copy-target"]["id"]
        p.wp_eval(f"update_post_meta( {target}, '{META}', get_post_meta( {pid}, '{META}', true ) ); bulkcat_out( 1 );")
        on = switch(p, [target], "publish")
        final = statuses(p, [target])[str(target)]
        p.wp("post", "delete", str(copy), "--force", check=False)
        ok = copied == "" and notes(on["preview"]).get(target) == "" and final == "publish" and own_record(record(p, pid), pid) is not None
        row(p, "d8-duplicate", ok, f"Duplicate copied record {copied!r}; a hand-copied foreign record: {notes(on['preview']).get(target)} -> {final}")

    def bin_restore() -> None:
        fx = upsert(p, [simple("bin", "pending")])
        pid = fx["bin"]["id"]
        switch(p, [pid], "draft")
        p.wp_eval(f"wp_trash_post( {pid} ); wp_untrash_post( {pid} ); bulkcat_out( 1 );")
        back = statuses(p, [pid])[str(pid)]
        on = switch(p, [pid], "publish")
        final = statuses(p, [pid])[str(pid)]
        ok = back == "draft" and notes(on["preview"]).get(pid) == "" and final == "publish" and record(p, pid) == ""
        row(p, "d9-bin", ok, f"restored from the bin as {back}; Published: {notes(on['preview']).get(pid)} -> {final}")

    def scheduled() -> None:
        fx = upsert(p, [simple("sched", "future", date=7 * 86400)])
        pid = fx["sched"]["id"]
        when = event(p, pid)
        off = switch(p, [pid], "draft")
        gone = event(p, pid)
        on = switch(p, [pid], "publish")
        back = event(p, pid)
        rows = journal(p, on["batch"])
        status_row = next((r for r in rows if r[1] == "status"), [])
        undo = p.http("manager", "POST", f"/batches/{on['batch']}/undo", body={"preview": True}).json() or {}
        conflicted = (undo.get("counts") or {}).get("conflicted")
        ok = (when > 0 and gone == 0 and back == when and statuses(p, [pid])[str(pid)] == "future" and notes(on["preview"]).get(pid) == ""
              and status_row[2:5] == ["draft", "future", "applied"] and conflicted == 0 and off["report"].get("status") == "complete")
        row(p, "d10-scheduled", ok, f"event {when} -> Off {gone} -> On {back}; status {statuses(p, [pid])[str(pid)]}; journal {status_row}; undo preview conflicted {conflicted}")
        state["d10"] = {"id": pid, "on": on["batch"], "when": when}

    def schedule_passed() -> None:
        # Scheduled 70 seconds ahead, switched Off, and switched On once its time has passed: WordPress publishes it.
        fx = upsert(p, [simple("sched-passed", "future", date=70)])
        pid = fx["sched-passed"]["id"]
        switch(p, [pid], "draft")
        time.sleep(75)
        p.wp("cron", "event", "run", "--due-now", check=False)
        still = statuses(p, [pid])[str(pid)]
        on = switch(p, [pid], "publish")
        final = statuses(p, [pid])[str(pid)]
        ok = still == "draft" and notes(on["preview"]).get(pid) == "" and final == "publish" and record(p, pid) == ""
        row(p, "d10-schedule-passed", ok, f"while Off after its time: {still}; Published: {notes(on['preview']).get(pid)} -> {final}")

    def never_off_scheduled() -> None:
        fx = upsert(p, [simple("sched-never", "future", date=7 * 86400)])
        pid = fx["sched-never"]["id"]
        when = event(p, pid)
        done = switch(p, [pid], "publish")
        sample = next((r for r in done["preview"].get("sample", []) if r.get("object_id") == pid and r.get("field") == "status"), {})
        rows = journal(p, done["batch"])
        undo = p.http("manager", "POST", f"/batches/{done['batch']}/undo", body={"preview": True}).json() or {}
        ok = (statuses(p, [pid])[str(pid)] == "future" and event(p, pid) == when and when > 0 and sample.get("note") == ""
              and bool((sample.get("context") or {}).get("scheduled_gmt")) and rows == [] and done["report"].get("changed_items") == 0
              and undo.get("code") == "bulkcat_not_reversible" and (undo.get("data") or {}).get("reason") == "pruned")
        row(p, "scheduled-never-off", ok, f"status {statuses(p, [pid])[str(pid)]} event kept {event(p, pid) == when}; preview {sample.get('note')} {sample.get('context')}; journal {rows}; empty-journal Undo response {undo}")

    def scheduled_reads() -> None:
        # T114: a scheduled product (post status future) reads, validates and filters as Scheduled,
        # and Status is never set to it.
        fx = upsert(p, [simple("sched-read", "future", date=7 * 86400)])
        pid = fx["sched-read"]["id"]
        fields = p.http("manager", "GET", "/fields").json() or {}
        status = next((f for f in fields.get("fields", []) if f.get("key") == "status"), {})
        labels = {c.get("value"): c.get("label") for c in status.get("choices", [])}
        schema_enum = (status.get("schema") or {}).get("enum") or []
        listed = p.http("manager", "GET", "/products", params=[("search", SKU + "sched-read"), ("fields[]", "status")]).json() or {}
        found = next((i for i in listed.get("items", []) if i.get("id") == pid), {})
        valid = p.wp_eval(
            "$f = \\BulkCatalogueManager\\Fields\\Registry::instance()->get( 'status' ); "
            "bulkcat_out( true === rest_validate_value_from_schema( 'future', $f->rest_schema(), 'status' ) );"
        )
        filtered = p.http("manager", "GET", "/products", params=[("status[operator]", "isAny"), ("status[value][]", "future"), ("search", SKU + "sched-read")]).json() or {}
        refused = p.patch_product("manager", pid, {"status": "future"})
        ok = (labels.get("future") == "Scheduled" and "future" not in (status.get("writableChoices") or []) and "future" in schema_enum
              and found.get("status") == "future" and valid is True and [i.get("id") for i in filtered.get("items", [])] == [pid]
              and refused.status == 400 and statuses(p, [pid])[str(pid)] == "future")
        row(p, "scheduled-reads-scheduled", ok, f"label {labels.get('future')}, writable {status.get('writableChoices')}; row {found.get('status')} valid {valid}; filter {[i.get('id') for i in filtered.get('items', [])]}; PATCH future {refused.brief()}")

    def single_control() -> None:
        fx = upsert(p, [simple("to-private"), simple("to-pending")])
        a, b = fx["to-private"]["id"], fx["to-pending"]["id"]
        switch(p, [a, b], "draft")
        to_private = switch(p, [a], "private")
        to_pending = switch(p, [b], "pending")
        got = statuses(p, [a, b])
        ok = (got == {str(a): "private", str(b): "pending"} and record(p, a) == "" and record(p, b) == ""
              and notes(to_private["preview"]).get(a) == "" and notes(to_pending["preview"]).get(b) == "")
        row(p, "e10-single-control", ok, f"statuses {got}; records cleared; notes {notes(to_private['preview']).get(a)}, {notes(to_pending['preview']).get(b)}")

    state: dict[str, Any] = {}
    for name, fn in [("d1-round-trip", round_trip), ("d2-already-draft", already_draft), ("d2-not-off-publishes", not_off_publishes),
                     ("d3-repeat", repeat_off_on), ("d3-second-published", second_published), ("d4-edits-survive", edits_survive),
                     ("d5-conflict", conflicts), ("d6-slug-reserved", slug_reserved), ("d7-variation-choices", variation_choices),
                     ("d8-duplicate", duplicate), ("d9-bin", bin_restore), ("d10-scheduled", scheduled), ("d10-schedule-passed", schedule_passed),
                     ("scheduled-never-off", never_off_scheduled), ("scheduled-reads-scheduled", scheduled_reads), ("e10-single-control", single_control)]:
        guard(p, name, fn)
    p.offon_state = state


# ---------------------------------------------------------------------------
# T90: undo and History exactness (E2, the status-only rule, the guard)
# ---------------------------------------------------------------------------


def undo(p: Any, batch_id: int, force: bool = False) -> dict:
    """Preview the undo of a batch, apply it under a grant and run it: {preview, status, batch, report}."""
    route = f"/batches/{batch_id}/undo"
    pre = p.http("manager", "POST", route, body={"preview": True}).json() or {}
    reply = p.http("manager", "POST", route, body=p.undo_body("manager", batch_id, pre.get("plan_hash"), force))
    made = reply.json() or {}
    report = p.run_queue(made["batch_id"]) if reply.status == 201 and made.get("batch_id") else {}
    return {"preview": pre, "status": reply.status, "code": reply.code(), "batch": made.get("batch_id"), "report": report}


def check_undo(p: Any) -> None:
    def undo_off() -> None:
        fx = upsert(p, [simple("undo-off", "private")])
        pid = fx["undo-off"]["id"]
        before = snap(p, [pid])
        off = switch(p, [pid], "draft")
        rows = journal(p, off["batch"])
        back = undo(p, off["batch"])
        after = snap(p, [pid])
        feed = p.http("manager", "GET", f"/batches/{off['batch']}/changes", [("per_page", "100")]).json() or {}
        labels = [(r.get("field_label"), r.get("old_value")) for r in feed.get("items", [])]
        ok = ([r[1] for r in rows] == ["status"] and (back["preview"].get("counts") or {}).get("revertible") == 1
              and back["report"].get("status") == "complete" and after == before and ("Status", "private") in labels)
        row(p, "e2-undo-off", ok, f"journal {[r[1] for r in rows]}; undo {back['report'].get('status')}; snapshot identical {after == before}; History {labels}")

    def undo_on() -> None:
        fx = upsert(p, [simple("undo-on", "pending")])
        pid = fx["undo-on"]["id"]
        switch(p, [pid], "draft")
        saved = snap(p, [pid])
        on = switch(p, [pid], "publish")
        back = undo(p, on["batch"])
        after = snap(p, [pid])
        ok = back["report"].get("status") == "complete" and after == saved and after[str(pid)]["record"] == ""
        row(p, "e2-undo-on", ok, f"undo {back['report'].get('status')}; original Draft snapshot with no new record: {after == saved} ({after[str(pid)]['record']})")

    def undo_conflict() -> None:
        fx = upsert(p, [simple("undo-conflict")])
        pid = fx["undo-conflict"]["id"]
        before = snap(p, [pid])
        off = switch(p, [pid], "draft")
        set_status_outside(p, pid, "private")
        plain = undo(p, off["batch"])
        left = snap(p, [pid])[str(pid)]
        forced = undo(p, off["batch"], force=True)
        after = snap(p, [pid])
        ok = ((plain["preview"].get("counts") or {}).get("conflicted") == 1 and plain["status"] == 409 and left["status"] == "private"
              and forced["report"].get("status") == "complete" and after == before)
        row(p, "e2-undo-conflict", ok, f"changed since: {(plain['preview'].get('counts') or {})} -> {plain['status']} {plain['code']}, left {left['status']}; forced {forced['report'].get('status')}, exact {after == before}")

    def status_only() -> None:
        # An ordinary status change (a status-only journal row), then the product switched Off from its new status.
        fx = upsert(p, [simple("status-only")])
        pid = fx["status-only"]["id"]
        first = switch(p, [pid], "pending")
        rows = journal(p, first["batch"])
        switch(p, [pid], "draft")
        saved = own_record(record(p, pid), pid) or {}
        guarded = p.http("manager", "POST", f"/batches/{first['batch']}/undo", body={"preview": True}).json() or {}
        item = (guarded.get("items") or [{}])[0]
        forced = undo(p, first["batch"], force=True)
        final = statuses(p, [pid])[str(pid)]
        ok = (rows == [[pid, "status", "publish", "pending", "applied"]] and saved == {} and item.get("state") == "conflicted"
              and [c.get("field") for c in item.get("conflicts", [])] == ["status"]
              and final == "publish" and record(p, pid) == "")
        row(p, "undo-status-only", ok, f"journal {rows}; Off saved {saved.get('status')}; undo preview {item.get('state')} {[(c.get('field'), c.get('current')) for c in item.get('conflicts', [])]}; forced -> {final} (never the saved pending), record {record(p, pid)!r}")

    def scheduled_exact() -> None:
        d10 = getattr(p, "offon_state", {}).get("d10")
        if not d10:
            row(p, "d10-undo-exact", False, "no scheduled On from offon:d10-scheduled")
            return
        pid = d10["id"]
        before = record(p, pid)
        back = undo(p, d10["on"])
        rec = own_record(record(p, pid), pid)
        ok = ((back["preview"].get("counts") or {}).get("conflicted") == 0 and back["report"].get("status") == "complete"
              and statuses(p, [pid])[str(pid)] == "draft" and rec is None and event(p, pid) == 0 and before == "")
        row(p, "d10-undo-exact", ok, f"undo of the scheduled On: conflicted {(back['preview'].get('counts') or {}).get('conflicted')}, {back['report'].get('status')}; now {statuses(p, [pid])[str(pid)]} with its record {rec}; event {event(p, pid)}")

    def chain() -> None:
        # Native Draft/Publish differs from restoration; two explicit Undos restore both snapshots.
        fx = upsert(p, [simple("chain", "private"), {"key": "chain-variable", "type": "variable", "status": "publish"}])
        ids = [fx["chain"]["id"], fx["chain-variable"]["id"]]
        s0 = snap(p, ids)
        off = switch(p, ids, "draft")
        s1 = snap(p, ids)
        on = switch(p, ids, "publish")
        s2 = snap(p, ids)
        u1 = undo(p, on["batch"])
        s3 = snap(p, ids)
        u2 = undo(p, off["batch"])
        s4 = snap(p, ids)
        ok = all(v["status"] == "publish" for v in s2.values()) and s3 == s1 and s4 == s0 and u1["report"].get("status") == "complete" and u2["report"].get("status") == "complete"
        row(p, "e2-chain", ok, f"after On == before {s2 == s0}; undo On == after Off {s3 == s1}; undo Off == before {s4 == s0}")

    def changed_after_apply() -> None:
        # The planner's changed-since guard holds when the undo is applied; these changes land after
        # that, before the queue runs its chunk, and the item is left alone when it is written.
        fx = upsert(p, [simple("late-again"), simple("late-outside"), simple("late-cleared"), simple("late-control")])
        again, outside, cleared, control = (fx[k]["id"] for k in ("late-again", "late-outside", "late-cleared", "late-control"))
        off = switch(p, [again, outside, cleared, control], "draft")
        route = f"/batches/{off['batch']}/undo"
        pre = p.http("manager", "POST", route, body={"preview": True}).json() or {}
        reply = p.http("manager", "POST", route, body=p.undo_body("manager", off["batch"], pre.get("plan_hash")))
        made = reply.json() or {}
        if reply.status != 201 or not made.get("batch_id"):
            raise AssertionError(f"undo {reply.brief()} {made}")
        on = patch(p, again, {"status": "publish"}, ["status"])
        re_off = patch(p, again, {"status": "draft"}, ["status"])
        newer = record(p, again)
        set_status_outside(p, outside, "private")
        p.wp_eval(f"$x = wc_get_product( {cleared} ); $x->delete_meta_data( '{META}' ); $x->save(); bulkcat_out( 1 );")
        report = p.run_queue(made["batch_id"])
        end = statuses(p, [again, outside, cleared, control])
        items = p.wp_eval(
            "global $wpdb; bulkcat_out( $wpdb->get_results( $wpdb->prepare( 'SELECT object_id, state, message FROM %i WHERE batch_id = %d ORDER BY object_id', "
            f"$wpdb->prefix . 'bulkcat_batch_items', {int(made['batch_id'])} ), ARRAY_A ) );"
        )
        states = {int(i["object_id"]): i["state"] for i in items}
        sentence = "This product was changed since this batch ran, so it is left alone unless you choose Revert anyway."
        ok = (pre.get("counts", {}).get("revertible") == 4 and on["status"] == 200 and re_off["status"] == 200
              and states == {again: "done", outside: "skipped", cleared: "done", control: "done"}
              and all(i["message"] == sentence for i in items if int(i["object_id"]) == outside)
              and end == {str(again): "publish", str(outside): "private", str(cleared): "publish", str(control): "publish"}
              and record(p, again) == newer and newer == "" and record(p, cleared) == "" and not report.get("failed_items"))
        row(p, "e2-undo-changed-after-apply", ok, f"undo applied with 4 revertible, then: switched On and Off again (newer record), changed outside, record removed; queue run {report.get('status')}, items {states}, statuses {end}; newer record kept {record(p, again) == newer}")

    for name, fn in [("e2-undo-off", undo_off), ("e2-undo-on", undo_on), ("e2-undo-conflict", undo_conflict),
                     ("undo-status-only", status_only), ("d10-undo-exact", scheduled_exact), ("e2-chain", chain),
                     ("e2-undo-changed-after-apply", changed_after_apply)]:
        guard(p, name, fn)


# ---------------------------------------------------------------------------
# T91: the preview's availability block, and On not pushed down (E1, D7)
# ---------------------------------------------------------------------------

CHECKSUM_PHP = r"""
global $wpdb;
$ids = %s;
$in  = implode( ',', array_map( 'intval', $ids ) );
$posts = $wpdb->get_results( "SELECT ID, post_status, post_modified_gmt FROM {$wpdb->posts} WHERE ID IN ($in) OR post_parent IN ($in) ORDER BY ID", ARRAY_A );
$meta  = $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($in) ORDER BY meta_id", ARRAY_A );
bulkcat_out( md5( wp_json_encode( array( $posts, $meta ) ) ) );
"""


def put_record(p: Any, pid: int, status: str, date: int | None = None) -> None:
    """Labelled synthetic legacy metadata; never evidence of release-created work."""
    extra = f"$x->set_date_created( time() + ({int(date)}) );" if date is not None else ""
    p.wp_eval(
        f"$x = wc_get_product( {pid} ); {extra} $x->set_status( 'draft' ); "
        f"$x->update_meta_data( '{META}', \\BulkCatalogueManager\\Availability\\OffRecord::create( {pid}, '{status}', gmdate( 'Y-m-d\\TH:i:s\\Z' ), 1 )->to_json() ); $x->save(); bulkcat_out( 1 );"
    )


def check_preview(p: Any) -> None:
    def e1() -> None:
        fx = upsert(p, [simple("e1-private", "private"), simple("e1-sched", "draft", date=7*86400), simple("e1-legacy", "draft")])
        ids = [r["id"] for r in fx.values()]
        put_record(p, ids[2], "pending")
        before = p.wp_eval(CHECKSUM_PHP % php_ids(ids))
        pre = preview(p, ids, status_step("publish"))
        after = p.wp_eval(CHECKSUM_PHP % php_ids(ids))
        samples = {r["object_id"]:r for r in pre.get("sample",[]) if r.get("field")=="status"}
        ok = (before == after and pre["operations"][0]["action"] == "set_status" and not any((pre.get("availability") or {}).get("counts", {}).values())
              and samples[ids[0]]["new"] == "publish" and samples[ids[1]]["new"] == "future" and samples[ids[2]]["new"] == "publish"
              and all(r["context"]["requested_status"] == "publish" for r in samples.values()))
        row(p, "e1-preview", ok, f"Native requested/effective preview, no writes, legacy target ignored: {samples}")

    def on_not_pushed() -> None:
        fx = upsert(p, [{"key": "d7-push", "type": "variable", "status": "publish"}])
        v = fx["d7-push"]
        variations = [v["small"], v["medium"], v["large"]]
        before = snap(p, [v["id"]])[str(v["id"])]["variations"]
        switch(p, [v["id"]], "draft", variations=True)
        on = switch(p, [v["id"]], "publish", variations=True)
        after = snap(p, [v["id"]])[str(v["id"])]
        report = p.http("manager", "GET", f"/batches/{on['batch']}").json() or {}
        kept = (on["preview"].get("availability") or {}).get("variations_kept")
        ok = (after["status"] == "publish" and all(row["status"] == "publish" for row in after["variations"].values())
              and kept == 0 and report.get("total_items") == 4 and all(record(p, x) == "" for x in variations))
        row(p, "d7-on-not-pushed", ok, f"Published with Also apply to variations: variations_kept {kept}, batch items {report.get('total_items')}; the explicitly included variation becomes {after['variations'][str(v['large'])]['status']}; variations unchanged {after['variations'] == before}")

    for name, fn in [("e1-preview", e1), ("d7-on-not-pushed", on_not_pushed)]:
        guard(p, name, fn)


# ---------------------------------------------------------------------------
# T92: the grid - Status is the control (E3, E4 display and filter, E6)
# ---------------------------------------------------------------------------

def patch(p: Any, pid: int, body: dict, fields: list[str] | None = None) -> dict:
    """PATCH /products/{id} as the grid's cell or drawer sends it, with its product.inline receipt: {status, json}."""
    params = [("fields[]", f) for f in (fields or [])]
    reply = p.patch_product("manager", pid, body, params=params or None)
    return {"status": reply.status, "json": reply.json() or {}}


def listed(p: Any, value: list[str], operator: str = "isAny", extra: dict | None = None) -> dict:
    """GET /products filtered on Status: {ids, total, items}."""
    params: list[tuple[str, str]] = [("search", SKU), ("per_page", "100"), ("status[operator]", operator), ("fields[]", "name"), ("fields[]", "status")]
    params += [("status[value][]", v) for v in value]
    params += list((extra or {}).items())
    reply = p.http("manager", "GET", "/products", params=params)
    data = reply.json() or {}
    items = data.get("items") or []
    return {"ids": [i.get("id") for i in items], "total": (data.get("pagination") or {}).get("total"), "items": items, "status": reply.status}


def generic(rows: list[list], pid: int) -> list[list]:
    """Journal rows with the product id, and the record's time and batch, made generic."""
    def value(v: Any) -> Any:
        rec = own_record(v, pid) if isinstance(v, str) and v.startswith("{") else None
        return {**rec, "id": "ID", "at": "AT", "batch": "B"} if rec else v
    return [[r[1], value(r[2]), value(r[3]), r[4]] for r in rows if r[0] == pid]


# The fields the drawer asks the answer to carry: every field of the row.
DRAWER_FIELDS = ["name", "sku", "status", "catalog_visibility", "regular_price", "sale_price", "stock_status", "category_ids"]


def check_grid(p: Any) -> None:
    def paths() -> None:
        fx = upsert(p, [simple("e3-inline"), simple("e3-drawer"), simple("e3-bulk")])
        a, b, c = fx["e3-inline"]["id"], fx["e3-drawer"]["id"], fx["e3-bulk"]["id"]
        out: dict[str, list] = {}
        for step in ("draft", "publish"):
            inline = patch(p, a, {"status": step}, ["name", "status"])
            drawer = patch(p, b, {"status": step}, DRAWER_FIELDS)
            bulk = switch(p, [c], step)
            if inline["status"] != 200 or drawer["status"] != 200:
                raise AssertionError(f"PATCH {inline['status']}/{drawer['status']}: {inline['json']} {drawer['json']}")
            out[step] = [generic(journal(p, inline["json"]["batch_id"]), a), generic(journal(p, drawer["json"]["batch_id"]), b), generic(journal(p, bulk["batch"]), c)]
        same = all(rows[0] == rows[1] == rows[2] and len(rows[0]) == 1 for rows in out.values())
        fields = [r[0] for r in out["draft"][0]]
        end = statuses(p, [a, b, c])
        ok = same and fields == ["status"] and set(end.values()) == {"publish"} and all(record(p, x) == "" for x in (a, b, c))
        row(p, "e3-paths-identical", ok, f"inline PATCH, drawer PATCH and bulk batch: Off rows identical {out['draft'][0] == out['draft'][1] == out['draft'][2]}, On rows identical {out['publish'][0] == out['publish'][1] == out['publish'][2]} (fields {fields}); all back to {sorted(set(end.values()))} with no record")

    def filter_and_display() -> None:
        fx = upsert(p, [simple("e4-off"), simple("e4-outside"), simple("e4-draft", "draft"), simple("e4-plain")])
        off, outside, draft, plain = (fx[k]["id"] for k in ("e4-off", "e4-outside", "e4-draft", "e4-plain"))
        put_record(p, off, "publish")
        put_record(p, outside, "publish")
        set_status_outside(p, outside, "private")
        found = {v: set(listed(p, [v])["ids"]) for v in ("off", "changed_outside", "draft", "publish", "private")}
        none_off = set(listed(p, ["off"], "isNone")["ids"])
        mine = {off, outside, draft, plain}
        rows = {i["id"]: i for i in listed(p, ["off", "changed_outside", "draft", "publish"])["items"]}
        state = {k: (rows.get(v, {}).get("off_state") or {}) for k, v in (("off", off), ("outside", outside))}
        ok = (found["off"] & mine == {off} and found["changed_outside"] & mine == {outside} and found["draft"] & mine == {draft}
              and found["private"] & mine == {outside} and found["publish"] & mine == {plain} and off not in none_off and {outside, draft, plain} <= none_off
              and state["off"].get("state") == "off" and state["off"].get("saved_label") == "Published"
              and state["outside"].get("state") == "changed_outside" and rows.get(plain, {}).get("off_state") is None)
        row(p, "e4-filter", ok, f"Off finds {sorted(found['off'] & mine) == [off]}, Changed outside finds {sorted(found['changed_outside'] & mine) == [outside]}, Draft finds only the genuine draft {sorted(found['draft'] & mine) == [draft]}, none of Off excludes it {off not in none_off}; off_state {state['off'].get('state')}/{state['off'].get('saved_label')} and {state['outside'].get('state')}")

    def listed_editable() -> None:
        fx = upsert(p, [simple("e6-off")])
        pid = fx["e6-off"]["id"]
        switch(p, [pid], "draft")
        raw = record(p, pid)
        default = p.http("manager", "GET", "/products", params=[("search", SKU + "e6-off"), ("fields[]", "status")]).json() or {}
        shown = [i for i in (default.get("items") or []) if i.get("id") == pid]
        edit = patch(p, pid, {"regular_price": "12"}, ["regular_price", "status"])
        after = snap(p, [pid])[str(pid)]
        ok = (len(shown) == 1 and shown[0].get("status") == "draft" and edit["status"] == 200 and edit["json"].get("status") == "complete"
              and float(after["regular_price"]) == 12.0 and after["status"] == "draft" and record(p, pid) == raw)
        row(p, "e6-listed-editable", ok, f"an Off product is listed ({len(shown)} row, status {shown[0].get('status') if shown else None}) and editable: price PATCH {edit['status']} {edit['json'].get('status')}, price {after['regular_price']}, still {after['status']}, record kept {record(p, pid) == raw}")

    def count_cache() -> None:
        fx = upsert(p, [simple("cc-one"), simple("cc-two")])
        one, two = fx["cc-one"]["id"], fx["cc-two"]["id"]
        before = listed(p, ["draft"])["total"]
        again = listed(p, ["draft"])["total"]
        made = patch(p, one, {"status": "draft"}, ["status"])
        after = listed(p, ["draft"])["total"]
        back = patch(p, one, {"status": "publish"}, ["status"])
        final = listed(p, ["draft"])["total"]
        ok = (made["status"] == 200 and back["status"] == 200 and isinstance(before, int) and before == again and after == before + 1 and final == before and two)
        row(p, "count-cache", ok, f"Draft filter count {before} (cached read {again}), right after an inline Off {after}, after On {final}")

    def inline_variable() -> None:
        # The grid's cell and drawer write the one product: an inline Off of a variable product is
        # not refused for its variations (Also apply to variations defaults on for Status) and leaves them alone.
        fx = upsert(p, [{"key": "e3-variable", "type": "variable", "status": "publish"}])
        v = fx["e3-variable"]
        before = snap(p, [v["id"]])[str(v["id"])]
        off = patch(p, v["id"], {"status": "draft"}, ["status"])
        mid = snap(p, [v["id"]])[str(v["id"])]
        on = patch(p, v["id"], {"status": "publish"}, ["status"])
        after = snap(p, [v["id"]])[str(v["id"])]
        rows = journal(p, off["json"].get("batch_id", 0)) if off["status"] == 200 else []
        ok = (off["status"] == 200 and off["json"].get("status") == "complete" and mid["status"] == "draft" and mid["variations"] == before["variations"]
              and on["status"] == 200 and after["status"] == "publish" and after["variations"] == before["variations"] and after["record"] == ""
              and sorted({r[0] for r in rows}) == [v["id"]])
        row(p, "e3-inline-variable", ok, f"inline Off {off['status']} {off['json'].get('status') or off['json'].get('code')}, On {on['status']} {on['json'].get('status') or on['json'].get('code')}; variations unchanged {mid['variations'] == before['variations'] and after['variations'] == before['variations']}; journal objects {sorted({r[0] for r in rows})}")

    for name, fn in [("e3-paths-identical", paths), ("e3-inline-variable", inline_variable), ("e4-filter", filter_and_display), ("e6-listed-editable", listed_editable), ("count-cache", count_cache)]:
        guard(p, name, fn)


# ---------------------------------------------------------------------------
# T93: listing controls stay listing controls (E5)
# ---------------------------------------------------------------------------

def check_listing(p: Any) -> None:
    def independent() -> None:
        fx = upsert(p, [simple("e5-listing"), simple("e5-status")])
        a, b = fx["e5-listing"]["id"], fx["e5-status"]["id"]
        switch(p, [a], "draft")
        raw = record(p, a)
        seen = []
        # Listing controls on an Off product: the cell's switch, the drawer's catalogue visibility, a bulk step.
        for body in ({"visible_on_site": "off"}, {"catalog_visibility": "search"}, {"visible_on_site": "on"}):
            edit = patch(p, a, body, ["status", "visible_on_site", "catalog_visibility"])
            s = snap(p, [a])[str(a)]
            seen.append((edit["status"], s["status"], s["catalog_visibility"], record(p, a) == raw))
        bulk = apply(p, [a], [{"action": "set_value", "args": {"field": "visible_on_site", "value": "off"}}])
        s = snap(p, [a])[str(a)]
        seen.append((bulk["report"].get("status"), s["status"], s["catalog_visibility"], record(p, a) == raw))
        listing_ok = [x[0] for x in seen] == [200, 200, 200, "complete"] and all(x[1] == "draft" and x[3] for x in seen) and [x[2] for x in seen] == ["hidden", "search", "visible", "hidden"]
        # And the reverse: Off and On leave the listing controls alone.
        patch(p, b, {"catalog_visibility": "catalog"}, ["catalog_visibility"])
        off = switch(p, [b], "draft")
        mid = snap(p, [b])[str(b)]
        on = switch(p, [b], "publish")
        end = snap(p, [b])[str(b)]
        reverse_ok = (off["report"].get("status") == "complete" and on["report"].get("status") == "complete" and mid["catalog_visibility"] == "catalog"
                      and end["catalog_visibility"] == "catalog" and end["status"] == "publish" and end["record"] == ""
                      and all(r[1] in ("status", "availability_off") for r in journal(p, off["batch"]) + journal(p, on["batch"])))
        row(p, "e5-independent", listing_ok and reverse_ok, f"on an Off product, Shown in shop and search and Catalogue visibility (cell, drawer, bulk) {[x[2] for x in seen]} left Status {sorted({x[1] for x in seen})} and the record {all(x[3] for x in seen)}; Off and On kept the listing {mid['catalog_visibility']}/{end['catalog_visibility']} and journalled only status and the record")

    guard(p, "e5-independent", independent)


# ---------------------------------------------------------------------------
# T94: what a buyer sees (A1-A12, B1-B5, C1-C2) and the site's plugins (E7)
# ---------------------------------------------------------------------------

BILLING = {
    "first_name": "Probe", "last_name": "Buyer", "company": "", "address_1": "1 Test Street", "address_2": "",
    "city": "Los Angeles", "state": "CA", "postcode": "90210", "country": "US",
    "email": "buyer@example.test", "phone": "5555555555",
}
CLASSIC = "bulkcat-offon-checkout"  # the chapter's [woocommerce_checkout] page
# WooCommerce options the chapter sets for checkout, restored at its end.
SHOP_OPTIONS = {
    "woocommerce_ship_to_countries": "disabled",
    "woocommerce_cheque_settings": {"enabled": "yes", "title": "Check payments", "description": "Pay by cheque.", "instructions": ""},
    "woocommerce_enable_guest_checkout": "yes",
}

SHOP_SETUP_PHP = r"""
$set  = json_decode( base64_decode( '%s' ), true );
$was  = array();
foreach ( $set as $name => $value ) {
	$old          = get_option( $name, null );
	$was[ $name ] = array( 'exists' => null !== $old, 'value' => $old );
	update_option( $name, $value );
}
$page    = get_page_by_path( '%s' );
$created = 0;
if ( ! $page ) {
	$created = (int) wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Bulkcat Offon Checkout', 'post_name' => '%s', 'post_content' => '[woocommerce_checkout]' ) );
}
$users = array();
foreach ( %s as $login ) {
	$id = username_exists( $login );
	if ( ! $id ) {
		$id = wp_insert_user( array( 'user_login' => $login, 'user_email' => $login . '@example.test', 'user_pass' => '%s', 'role' => 'customer' ) );
	}
	delete_user_meta( (int) $id, '_woocommerce_persistent_cart_' . get_current_blog_id() );
	$users[ $login ] = (int) $id;
}
bulkcat_out( array( 'was' => $was, 'page' => $created, 'users' => $users ) );
"""

SHOP_RESTORE_PHP = r"""
$was  = json_decode( base64_decode( '%s' ), true );
foreach ( $was as $name => $row ) {
	if ( $row['exists'] ) { update_option( $name, $row['value'] ); } else { delete_option( $name ); }
}
if ( %d > 0 ) { wp_delete_post( %d, true ); }
bulkcat_out( 1 );
"""


def header(reply: Any, name: str) -> str:
    return next((v for k, v in reply.headers.items() if k.lower() == name.lower()), "")


def strip(html: str) -> str:
    return re.sub(r"\s+", " ", re.sub(r"<[^>]+>", " ", html)).strip()


class Buyer:
    """A storefront visitor with its own cookie jar: anonymous, or a customer once logged in."""

    def __init__(self, p: Any) -> None:
        self.p = p
        self.jar = http.cookiejar.CookieJar()
        self.opener = p._opener(self.jar)
        self.nonce = ""

    def path(self, url: str) -> str:
        return url[len(self.p.base):] if url.startswith(self.p.base) else url

    def request(self, method: str, url: str, data: bytes | None = None, headers: dict | None = None, follow: bool = False) -> Any:
        path = self.path(url)
        for _ in range(6):
            reply = self.p._raw(self.opener, method, path, data, headers)
            where = header(reply, "Location")
            if not (follow and 300 <= reply.status < 400 and where):
                return reply
            path, method, data = self.path(urllib.parse.urljoin(self.p.base + path, where)), "GET", None
        return reply

    def get(self, url: str, follow: bool = False) -> Any:
        return self.request("GET", url, follow=follow)

    def text(self, reply: Any) -> str:
        return reply.body.decode("utf-8", "replace")

    def form(self, url: str, fields: dict) -> Any:
        return self.request("POST", url, urllib.parse.urlencode(fields).encode(), {"Content-Type": "application/x-www-form-urlencoded"})

    def login(self, login: str, password: str) -> "Buyer":
        self.get("/wp-login.php")
        self.form("/wp-login.php", {"log": login, "pwd": password, "wp-submit": "Log In", "testcookie": "1"})
        if not any(c.name.startswith("wordpress_logged_in_") for c in self.jar):
            raise AssertionError(f"could not log in as {login}")
        return self

    def store(self, method: str, path: str, body: Any = None) -> tuple[int, Any]:
        """A Store API call carrying the cart's nonce, as the blocks send it."""
        if not self.nonce and method != "GET":
            self.store("GET", "/cart")
        headers = {"Accept": "application/json"}
        data = None
        if self.nonce:
            headers["Nonce"] = self.nonce
        if body is not None:
            data = json.dumps(body).encode()
            headers["Content-Type"] = "application/json"
        reply = self.request(method, "/wp-json/wc/store/v1" + path, data, headers)
        self.nonce = header(reply, "Nonce") or self.nonce
        return reply.status, reply.json()

    def cart(self) -> tuple[list[int], list[str]]:
        _, data = self.store("GET", "/cart")
        data = data if isinstance(data, dict) else {}
        return sorted(i["id"] for i in data.get("items", [])), [strip(str(e.get("message", ""))) for e in data.get("errors", [])]

    def empty(self) -> None:
        self.store("DELETE", "/cart/items")


def add_path(product: dict) -> str:
    if product["kind"] == "simple":
        return f"/?add-to-cart={product['id']}&quantity=1"
    return f"/?add-to-cart={product['id']}&variation_id={product['small']}&attribute_size=Small&quantity=1"


def target(product: dict) -> int:
    return product["id"] if product["kind"] == "simple" else product["small"]


# A number, its row name and the surfaces it covers.
A_ROWS = [
    ("a1", "permalink-404", ["permalink"]),
    ("a2", "id-link-404", ["id link"]),
    ("a3", "category-omits", ["category archive"]),
    ("a4", "search-omits", ["product search"]),
    ("a5", "store-api-list-omits", ["Store API listing"]),
    ("a6", "store-api-product-404", ["Store API product", "Store API variation"]),
    ("a7", "wp-rest-401", ["WP REST product"]),
    ("a8", "sitemap-omits", ["sitemap"]),
    ("a9", "feed-omits", ["category feed"]),
    ("a10", "oembed-404", ["oEmbed"]),
    ("a11", "add-to-cart-refused", ["add to cart (form handler)"]),
    ("a12", "store-api-add-refused", ["add to cart (Store API)"]),
]


def surfaces(c: Buyer, product: dict) -> dict[str, tuple[str, bool]]:
    """Every A surface for one product through one visitor: surface -> (what it answered, exposed)."""
    out: dict[str, tuple[str, bool]] = {}
    link = product["permalink"]
    r = c.get(link)
    out["permalink"] = (str(r.status), r.status == 200)
    r = c.get(f"/?post_type=product&p={product['id']}")
    where = header(r, "Location")
    out["id link"] = (f"{r.status}{' -> ' + where if where else ''}", r.status == 200 or (300 <= r.status < 400 and "/product/" in where))
    r = c.get(f"/product-category/{product['cat_slug']}/")
    out["category archive"] = (f"{r.status} {'listed' if link in c.text(r) else 'absent'}", link in c.text(r))
    r = c.get(f"/?s={urllib.parse.quote_plus(product['name'])}&post_type=product", follow=True)
    hit = link in c.text(r)
    out["product search"] = ("listed" if hit else "absent", hit)
    code, data = c.store("GET", f"/products?category={product['cat']}&per_page=100")
    ids = [d["id"] for d in data] if isinstance(data, list) else []
    out["Store API listing"] = (f"{code} {'listed' if product['id'] in ids else 'absent'}", product["id"] in ids)
    code, _ = c.store("GET", f"/products/{product['id']}")
    out["Store API product"] = (str(code), code == 200)
    if product["kind"] == "variable":
        code, _ = c.store("GET", f"/products/{product['small']}")
        out["Store API variation"] = (str(code), code == 200)
    r = c.get(f"/wp-json/wp/v2/product/{product['id']}")
    out["WP REST product"] = (str(r.status), r.status == 200)
    r = c.get(f"/wp-sitemap-posts-product-{product.get('sitemap_page', 1)}.xml")
    out["sitemap"] = ("listed" if link in c.text(r) else "absent", link in c.text(r))
    r = c.get(f"/product-category/{product['cat_slug']}/feed/")
    out["category feed"] = ("listed" if link in c.text(r) else "absent", link in c.text(r))
    r = c.get("/wp-json/oembed/1.0/embed?url=" + urllib.parse.quote(link, safe=""))
    out["oEmbed"] = (str(r.status), r.status == 200)
    c.empty()
    c.get(add_path(product))
    ids, _ = c.cart()
    out["add to cart (form handler)"] = ("added" if target(product) in ids else "refused", target(product) in ids)
    c.empty()
    code, data = c.store("POST", "/cart/add-item", {"id": target(product), "quantity": 1})
    added = code in (200, 201)
    out["add to cart (Store API)"] = (f"{code} {'' if added else (data or {}).get('code', '')}".strip(), added)
    c.empty()
    if product["kind"] == "variable":
        code, data = c.store("POST", "/cart/add-item", {"id": product["large"], "quantity": 1})
        out["disabled variation (Store API)"] = (f"{code} {'' if code in (200, 201) else (data or {}).get('code', '')}".strip(), code in (200, 201))
        c.empty()
    return out


def classic_nonce(c: Buyer) -> str:
    m = re.search(r'name="woocommerce-process-checkout-nonce" value="([^"]+)"', c.text(c.get(f"/{CLASSIC}/")))
    return m.group(1) if m else ""


def classic_checkout(c: Buyer, nonce: str) -> tuple[str, bool]:
    fields = {f"billing_{k}": v for k, v in BILLING.items()}
    fields.update({"payment_method": "cheque", "woocommerce-process-checkout-nonce": nonce, "_wp_http_referer": f"/{CLASSIC}/"})
    reply = c.form("/?wc-ajax=checkout", fields)
    data = reply.json() or {}
    if data.get("result") == "success":
        m = re.search(r"order-received/(\d+)", data.get("redirect", ""))
        return f"order {m.group(1) if m else '?'} created", True
    return "refused: " + strip(str(data.get("messages", "")))[:150], False


def store_checkout(c: Buyer) -> tuple[str, bool]:
    code, data = c.store("POST", "/checkout", {"billing_address": BILLING, "shipping_address": {k: v for k, v in BILLING.items() if k != "email"}, "payment_method": "cheque"})
    data = data if isinstance(data, dict) else {}
    if code == 200 and data.get("order_id"):
        return f"order {data['order_id']} created ({data.get('status')})", True
    return f"{code} refused: {data.get('code', '')}", False


def orders(p: Any) -> int:
    return int(p.wp_eval("bulkcat_out( count( wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'status' => array_keys( wc_get_order_statuses() ) ) ) ) );"))


def carts(p: Any, products: list[dict], logins: dict[str, str], password: str) -> dict[tuple[str, str], tuple[Buyer, dict]]:
    """Carts filled while On: anonymous and a customer for each path (a persistent cart only for the customer)."""
    out = {}
    for audience in ("anonymous", "customer"):
        for path in ("store_checkout", "classic_checkout", "cart_view", "persistent_cart"):
            if audience == "anonymous" and path == "persistent_cart":
                continue
            c = Buyer(p)
            if audience == "customer":
                c.login(logins[path], password)
                c.empty()
            for product in products:
                c.get(add_path(product))
            ids, _ = c.cart()
            state: dict[str, Any] = {"before": ids}
            if path == "classic_checkout":
                state["nonce"] = classic_nonce(c)
            out[(audience, path)] = (c, state)
    return out


def settle(p: Any, products: list[dict], held: dict, logins: dict[str, str], users: dict[str, int], password: str) -> dict[str, dict]:
    """What each cart does now: checkout, a cart read, a persistent cart after its session expired."""
    targets = [target(x) for x in products]
    out = {}
    for (audience, path), (c, state) in held.items():
        row: dict[str, Any] = {"before": state["before"]}
        count = orders(p)
        if path == "store_checkout":
            row["said"], row["placed"] = store_checkout(c)
        elif path == "classic_checkout":
            row["said"], row["placed"] = classic_checkout(c, state.get("nonce", "")) if state.get("nonce") else ("no checkout form while On", None)
        elif path == "cart_view":
            ids, notices = c.cart()
            row["after"], row["kept"], row["notices"] = ids, [i for i in ids if i in targets], notices
        else:
            uid = users[logins[path]]
            p.wp_eval(f"global $wpdb; $wpdb->delete( $wpdb->prefix . 'woocommerce_sessions', array( 'session_key' => '{uid}' ) ); bulkcat_out( 1 );")
            ids, notices = Buyer(p).login(logins[path], password).cart()
            row["after"], row["kept"], row["notices"] = ids, [i for i in ids if i in targets], notices
        row["orders_made"] = orders(p) - count
        out[f"{audience}/{path}"] = row
    return out


def active_plugins(p: Any) -> dict:
    return p.wp_eval("bulkcat_out( array( 'active' => array_values( (array) get_option( 'active_plugins', array() ) ), 'network' => array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ), 'mu' => array_keys( get_mu_plugins() ), 'dropins' => array_keys( get_dropins() ) ) );")


def check_buyer(p: Any) -> None:
    state: dict[str, Any] = {}

    def e7() -> None:
        found = active_plugins(p)
        active = sorted(x.split("/")[0] for x in found["active"])
        ok = active == ["bulk-catalogue-manager-for-woocommerce", "woocommerce"] and not found["network"] and not found["mu"]
        row(p, "e7-only-woocommerce-and-this-plugin", ok, f"active {active}; network {found['network']}; must-use {found['mu']}; drop-ins {found['dropins']}")

    def buyers() -> None:
        password = p.seed["password"]
        paths = ("store_checkout", "classic_checkout", "cart_view", "persistent_cart")
        logins = {phase: {path: f"bulkcat_offon_{phase}_{n}" for n, path in enumerate(paths, 1)} for phase in ("buy", "control")}
        every = [*logins["buy"].values(), *logins["control"].values(), "bulkcat_offon_browser", "bulkcat_offon_again"]
        setup = p.wp_eval(SHOP_SETUP_PHP % (base64.b64encode(json.dumps(SHOP_OPTIONS).encode()).decode(), CLASSIC, CLASSIC, "array( " + ", ".join(f"'{x}'" for x in every) + " )", password))
        state["setup"] = setup
        try:
            buyer_rows(p, setup, logins, password)
        finally:
            p.wp_eval(SHOP_RESTORE_PHP % (base64.b64encode(json.dumps(setup["was"]).encode()).decode(), int(setup["page"]), int(setup["page"])))
            after = p.wp_eval("bulkcat_out( array( get_option( 'woocommerce_ship_to_countries', null ), get_option( 'woocommerce_cheque_settings', null ) ) );")
            before = [setup["was"]["woocommerce_ship_to_countries"]["value"], setup["was"]["woocommerce_cheque_settings"]["value"]]
            row(p, "shop-options-restored", after == before, f"the chapter's WooCommerce options are back as they were: {after == before}; its checkout page {'removed' if setup['page'] else 'was already there'}")

    for name, fn in [("e7-only-woocommerce-and-this-plugin", e7), ("buyer", buyers)]:
        guard(p, name, fn)


def product_rows(p: Any, fx: dict, prefix: str) -> list[dict]:
    simple_, variable = fx[f"{prefix}-simple"], fx[f"{prefix}-variable"]
    cat_slug = "bulkcat-offon-" + prefix
    base = p.base
    return [
        {"kind": "simple", "id": simple_["id"], "name": f"Bulkcat Offon {prefix}-simple", "permalink": f"{base}/product/{simple_['slug']}/", "cat": simple_["cat"], "cat_slug": cat_slug},
        {"kind": "variable", "id": variable["id"], "name": f"Bulkcat Offon {prefix}-variable", "permalink": f"{base}/product/{variable['slug']}/", "cat": variable["cat"], "cat_slug": cat_slug,
         "small": variable["small"], "large": variable["large"]},
    ]


def buyer_rows(p: Any, setup: dict, logins: dict, password: str) -> None:
    users = setup["users"]
    fx = upsert(p, [simple("buy-simple", cat="buy"), {"key": "buy-variable", "type": "variable", "status": "publish", "cat": "buy"},
                    simple("control-simple", cat="control"), {"key": "control-variable", "type": "variable", "status": "publish", "cat": "control"}])
    products = product_rows(p, fx, "buy")
    control = product_rows(p, fx, "control")
    for x in products:
        # The sitemap page that lists it while On: core pages published products by ID, a fixed number a page.
        x["sitemap_page"] = int(p.wp_eval(
            "global $wpdb; $rank = (int) $wpdb->get_var( $wpdb->prepare( \"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' AND post_password = '' AND ID <= %d\", "
            f"{x['id']} ) ); bulkcat_out( max( 1, (int) ceil( $rank / wp_sitemaps_get_max_urls( 'post' ) ) ) );"
        ))
    ids = [x["id"] for x in products]
    visitors = {"anonymous": Buyer(p), "customer": Buyer(p).login("bulkcat_offon_browser", password)}

    # C1: the same cart paths place orders while nothing is Off.
    placed = settle(p, control, carts(p, control, logins["control"], password), logins["control"], users, password)
    c1 = all((r.get("placed") and r["orders_made"] == 1) if "placed" in r else (r["kept"] == sorted(target(x) for x in control) and r["orders_made"] == 0) for r in placed.values())
    row(p, "c1-control-orders-while-on", c1, "; ".join(f"{k}: {v.get('said') or ('kept ' + str(v.get('kept')))}" for k, v in placed.items()))

    on_before = {aud: {x["kind"]: surfaces(c, x) for x in products} for aud, c in visitors.items()}
    held = carts(p, products, logins["buy"], password)
    again = p.wp_eval(
        f"$o = wc_create_order( array( 'customer_id' => {int(users['bulkcat_offon_again'])} ) ); $o->add_product( wc_get_product( {products[0]['id']} ), 1 ); "
        f"$o->add_product( wc_get_product( {products[1]['small']} ), 1 ); $o->set_billing_email( 'buyer@example.test' ); $o->set_payment_method( 'cheque' ); "
        "$o->calculate_totals(); $o->set_status( 'completed' ); $o->save(); bulkcat_out( $o->get_id() );"
    )

    off = switch(p, ids, "draft")
    visitors["anonymous"] = Buyer(p)
    off_now = {aud: {x["kind"]: surfaces(c, x) for x in products} for aud, c in visitors.items()}
    for num, name, keys in A_ROWS:
        seen = [(aud, kind, key, off_now[aud][kind][key], on_before[aud][kind][key]) for aud in off_now for kind in off_now[aud] for key in keys if key in off_now[aud][kind]]
        ok = bool(seen) and all(not now[1] and was[1] for *_, now, was in seen)
        if num == "a12":
            ok = ok and all("woocommerce_rest_product_not_purchasable" in now[0] for *_, now, _ in seen)
        if num == "a7":
            ok = ok and all(now[0] == "401" for *_, now, _ in seen)
        row(p, f"{num}-{name}", ok, f"Off: {sorted({f'{a}/{k}: {n[0]}' for a, k, _, n, _ in seen})}; while On: {sorted({w[0] for *_, w in seen})}")
    disabled = [off_now[aud]["variable"]["disabled variation (Store API)"] for aud in off_now]
    row(p, "a12-disabled-variation-refused", all(not d[1] for d in disabled), f"the disabled variation, Off: {[d[0] for d in disabled]}; while On: {[on_before[a]['variable']['disabled variation (Store API)'][0] for a in on_before]}")

    held_now = settle(p, products, held, logins["buy"], users, password)
    targets = sorted(target(x) for x in products)
    views = {k: v for k, v in held_now.items() if k.endswith("cart_view")}
    row(p, "b1-cart-drops-off-items", all(v["before"] == targets and v["kept"] == [] and any("removed from your cart" in n for n in v["notices"]) for v in views.values()),
        "; ".join(f"{k}: before {v['before']} after {v['after']} notices {len(v['notices'])}" for k, v in views.items()))
    stores = {k: v for k, v in held_now.items() if k.endswith("store_checkout")}
    row(p, "b2-store-checkout-refused", all(not v["placed"] and "woocommerce_rest_cart_empty" in v["said"] and v["orders_made"] == 0 for v in stores.values()),
        "; ".join(f"{k}: {v['said']} (orders made {v['orders_made']})" for k, v in stores.items()))
    classics = {k: v for k, v in held_now.items() if k.endswith("classic_checkout")}
    row(p, "b3-classic-checkout-refused", all(v["placed"] is False and v["orders_made"] == 0 for v in classics.values()),
        "; ".join(f"{k}: {v['said'][:110]} (orders made {v['orders_made']})" for k, v in classics.items()))
    kept = held_now["customer/persistent_cart"]
    row(p, "b4-persistent-cart-drops", kept["before"] == targets and kept["kept"] == [], f"before {kept['before']}; after the session expired and a fresh login {kept['after']}")

    buyer = Buyer(p).login("bulkcat_offon_again", password)
    buyer.empty()
    page = buyer.text(buyer.get(f"/my-account/view-order/{int(again)}/", follow=True))
    link = re.search(r'href="([^"]*order_again=[^"]+)"', page)
    if link:
        buyer.get(link.group(1).replace("&amp;", "&"), follow=True)
    cart_after, _ = buyer.cart()
    row(p, "b5-order-again-adds-nothing", [i for i in cart_after if i in targets] == [], f"Order again link shown {bool(link)}; cart after following it {cart_after}")

    on = switch(p, ids, "publish")
    visitors["anonymous"] = Buyer(p)
    on_after = {aud: {x["kind"]: surfaces(c, x) for x in products} for aud, c in visitors.items()}
    same = {f"{aud}/{kind}/{key}": (on_before[aud][kind][key][1], on_after[aud][kind][key][1]) for aud in on_after for kind in on_after[aud] for key in on_after[aud][kind]}
    after_cart = Buyer(p)
    for x in products:
        after_cart.get(add_path(x))
    said, placed_now = store_checkout(after_cart)
    statuses_now = statuses(p, ids)
    row(p, "c2-everything-back-after-on", all(a == b for a, b in same.values()) and placed_now and set(statuses_now.values()) == {"publish"} and all(record(p, i) == "" for i in ids),
        f"Off {off['report'].get('status')}, On {on['report'].get('status')}; {sum(a != b for a, b in same.values())} of {len(same)} surfaces differ from before Off; a new cart checks out: {said}")


# ---------------------------------------------------------------------------
# Should-fix 2: a record written from outside the manager (WooCommerce REST v3 meta_data)
# ---------------------------------------------------------------------------

def wc_rest(p: Any, method: str, route: str, body: Any = None) -> Any:
    """WooCommerce's own REST v3 as the probe's shop manager (cookie and nonce stay in memory)."""
    opener, nonce = p.session("manager")
    headers = {"Accept": "application/json", "X-WP-Nonce": nonce}
    data = None
    if body is not None:
        data = json.dumps(body).encode()
        headers["Content-Type"] = "application/json"
    return p._raw(opener, method, "/wp-json/wc/v3" + route, data, headers)


def check_outside(p: Any) -> None:
    def written_from_outside() -> None:
        fx = upsert(p, [simple("rest-foreign", "draft"), simple("rest-tampered", "draft"), simple("rest-own", "draft")])
        foreign, tampered, own = (fx[k]["id"] for k in ("rest-foreign", "rest-tampered", "rest-own"))
        values = {
            foreign: OFF_JSON % (foreign + 1, "private"),
            tampered: OFF_JSON % (tampered, "trash"),
            own: OFF_JSON % (own, "private"),
        }
        wrote = {pid: wc_rest(p, "PUT", f"/products/{pid}", {"meta_data": [{"key": META, "value": value}]}).status for pid, value in values.items()}
        stored = {pid: record(p, pid) == value for pid, value in values.items()}
        shown = wc_rest(p, "GET", f"/products/{own}").json() or {}
        exposed = META in [m.get("key") for m in shown.get("meta_data", [])]
        narrow = {"search": SKU + "rest-"}
        rows = {i["id"]: i.get("off_state") for i in listed(p, ["off", "changed_outside", "draft"], extra=narrow)["items"]}
        found_off = set(listed(p, ["off"], extra=narrow)["ids"])
        pre = preview(p, [foreign, tampered, own], status_step("publish"))
        codes = notes(pre)
        on = switch(p, [foreign, tampered, own], "publish")
        end = statuses(p, [foreign, tampered, own])
        ok = (set(wrote.values()) == {200} and all(stored.values()) and exposed
              and rows.get(foreign) is None and rows.get(tampered) is None and (rows.get(own) or {}).get("state") == "off"
              and foreign not in found_off and own in found_off
              and codes == {foreign: "", tampered: "", own: ""}
              and on["report"].get("status") == "complete" and end == {str(foreign): "publish", str(tampered): "publish", str(own): "publish"})
        row(p, "record-written-from-outside", ok,
            f"WooCommerce REST v3 accepted meta_data writes {sorted(set(wrote.values()))} and returns the key in meta_data: {exposed}; "
            f"another product's id and a status outside the four read as no record (off_state {rows.get(foreign)}, {rows.get(tampered)}; Published {codes.get(foreign)}, {codes.get(tampered)}); "
            f"a well-formed owned legacy record reads as Off; new Publish ignores its remembered target ({codes.get(own)} -> {end.get(str(own))}); "
            f"the Off filter's SQL prefix match lists the tampered value with its own id: {tampered in found_off} (the documented, accepted case; the row, the switch and undo read it as no record)")

    guard(p, "record-written-from-outside", written_from_outside)


OFF_JSON = '{"id":%d,"status":"%s","at":"2026-09-01T08:00:00Z","batch":1}'


CHECKS = [check_engine, check_undo, check_preview, check_grid, check_listing, check_outside, check_buyer]
