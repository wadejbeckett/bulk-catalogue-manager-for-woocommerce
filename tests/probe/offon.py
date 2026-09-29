"""The probe's ``offon`` chapter (T89-T94): Status as the global Off/On switch
(SPEC §5.5), proved through the manager's own routes on plain WordPress and
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
import json
import time
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
    return p.wp_eval(UPSERT_PHP % blob)


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
        want_saved = {fx[k]["id"]: s for k, s in (("publish", "publish"), ("hidden", "publish"), ("search", "publish"), ("draft", None),
                                                   ("private", "private"), ("pending", "pending"), ("future", "future"), ("variable", "publish"))}
        all_draft = all(mid[str(pid)]["status"] == "draft" for pid in chosen)
        # On for what Off switched: the genuine draft saved nothing, and Published on it would publish it (D2).
        switched = [pid for pid in chosen if pid != fx["draft"]["id"]]
        on = switch(p, switched, "publish")
        after = snap(p, chosen + unrelated)
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
        ok = (notes(done["preview"]).get(pid) == "already_draft" and done["report"].get("changed_items") == 0
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
        ok = got == want and all(codes.get(pid) == "publishes_not_off" for pid in ids) and all(after[str(pid)]["record"] == "" for pid in ids)
        row(p, "d2-not-off-publishes", ok, f"statuses {got} (the scheduled one stays scheduled) notes {codes} no record written")

    def repeat_off_on() -> None:
        fx = upsert(p, [simple("repeat")])
        pid = fx["repeat"]["id"]
        switch(p, [pid], "draft")
        first = record(p, pid)
        again = switch(p, [pid], "draft")
        ok = notes(again["preview"]).get(pid) == "already_off" and again["report"].get("changed_items") == 0 and record(p, pid) == first
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
        ok = (notes(on["preview"]).get(pid) == "on_restores" and restored == "private" and record(p, pid) == ""
              and notes(again["preview"]).get(pid) == "publishes_not_off" and final == "publish")
        row(p, "d3-second-published", ok, f"On restored {restored} ({notes(on['preview']).get(pid)}); Published again: {notes(again['preview']).get(pid)} -> {final}")

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
        switch(p, [pid], "draft")
        saved = record(p, pid)
        set_status_outside(p, pid, "private")
        refused = switch(p, [pid], "publish")
        ok = (notes(refused["preview"]).get(pid) == "conflict" and refused["report"].get("changed_items") == 0
              and statuses(p, [pid])[str(pid)] == "private" and record(p, pid) == saved)
        row(p, "d5-conflict", ok, f"note {notes(refused['preview']).get(pid)} status {statuses(p, [pid])[str(pid)]} record kept {record(p, pid) == saved}")
        again = switch(p, [pid], "draft")
        rec = own_record(record(p, pid), pid) or {}
        ok = notes(again["preview"]).get(pid) == "off_recapture" and statuses(p, [pid])[str(pid)] == "draft" and rec.get("status") == "private"
        row(p, "d5-recapture", ok, f"note {notes(again['preview']).get(pid)} status {statuses(p, [pid])[str(pid)]} saved {rec.get('status')}")
        set_status_outside(p, pid, "pending")
        kept = switch(p, [pid], "publish", keep=True)
        ok = notes(kept["preview"]).get(pid) == "conflict_kept" and statuses(p, [pid])[str(pid)] == "pending" and record(p, pid) == ""
        row(p, "d5-keep", ok, f"note {notes(kept['preview']).get(pid)} status {statuses(p, [pid])[str(pid)]} record {record(p, pid)!r}")

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
        chosen = p.http("manager", "PATCH", f"/products/{v['small']}", body={"status": "draft"})
        after = snap(p, [v["id"]])[str(v["id"])]
        var_status = {int(k): s["status"] for k, s in after["variations"].items()}
        # The parent is Off; its variations were never written, and none carries a record.
        records = [record(p, vid) for vid in (v["small"], v["medium"], v["large"])]
        ok = (status.get("variationChoices") == ["publish", "private"] and len(var_rows) == 3 and all(r.get("state") == "skipped" for r in var_rows)
              and chosen.status == 400 and var_status == {v["small"]: "publish", v["medium"]: "publish", v["large"]: "private"}
              and records == ["", "", ""] and after["status"] == "draft" and own_record(after["record"], v["id"]) is not None)
        row(p, "d7-variation-choices", ok, f"variation choices {status.get('variationChoices')}; Draft pushed down: {[r.get('state') for r in var_rows]} ({items.get('status')}); Draft chosen on a variation: {chosen.brief()}; variations {var_status}")

    def duplicate() -> None:
        fx = upsert(p, [simple("original"), simple("copy-target", "draft")])
        pid = fx["original"]["id"]
        switch(p, [pid], "draft")
        copy = int(p.wp_eval(f"$d = new WC_Admin_Duplicate_Product(); $c = $d->product_duplicate( wc_get_product( {pid} ) ); bulkcat_out( $c->get_id() );"))
        copied = record(p, copy)
        # A record copied by hand (an import, a meta copy) onto another draft names the original.
        target = fx["copy-target"]["id"]
        p.wp_eval(f"update_post_meta( {target}, '{META}', get_post_meta( {pid}, '{META}', true ) ); bulkcat_out( 1 );")
        on = switch(p, [target], "publish")
        final = statuses(p, [target])[str(target)]
        p.wp("post", "delete", str(copy), "--force", check=False)
        ok = copied == "" and notes(on["preview"]).get(target) == "publishes_not_off" and final == "publish" and own_record(record(p, pid), pid) is not None
        row(p, "d8-duplicate", ok, f"Duplicate copied record {copied!r}; a hand-copied foreign record: {notes(on['preview']).get(target)} -> {final}")

    def bin_restore() -> None:
        fx = upsert(p, [simple("bin", "pending")])
        pid = fx["bin"]["id"]
        switch(p, [pid], "draft")
        p.wp_eval(f"wp_trash_post( {pid} ); wp_untrash_post( {pid} ); bulkcat_out( 1 );")
        back = statuses(p, [pid])[str(pid)]
        on = switch(p, [pid], "publish")
        final = statuses(p, [pid])[str(pid)]
        ok = back == "draft" and notes(on["preview"]).get(pid) == "on_restores" and final == "pending" and record(p, pid) == ""
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
        ok = (when > 0 and gone == 0 and back == when and statuses(p, [pid])[str(pid)] == "future" and notes(on["preview"]).get(pid) == "on_scheduled"
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
        ok = still == "draft" and notes(on["preview"]).get(pid) == "on_schedule_passed" and final == "publish" and record(p, pid) == ""
        row(p, "d10-schedule-passed", ok, f"while Off after its time: {still}; Published: {notes(on['preview']).get(pid)} -> {final}")

    def never_off_scheduled() -> None:
        fx = upsert(p, [simple("sched-never", "future", date=7 * 86400)])
        pid = fx["sched-never"]["id"]
        when = event(p, pid)
        done = switch(p, [pid], "publish")
        sample = next((r for r in done["preview"].get("sample", []) if r.get("object_id") == pid and r.get("field") == "status"), {})
        rows = journal(p, done["batch"])
        undo = p.http("manager", "POST", f"/batches/{done['batch']}/undo", body={"preview": True}).json() or {}
        ok = (statuses(p, [pid])[str(pid)] == "future" and event(p, pid) == when and when > 0 and sample.get("note") == "publishes_not_off"
              and bool((sample.get("context") or {}).get("scheduled_gmt")) and rows == [[pid, "status", "future", "future", "applied"]]
              and (undo.get("counts") or {}).get("conflicted") == 0)
        row(p, "scheduled-never-off", ok, f"status {statuses(p, [pid])[str(pid)]} event kept {event(p, pid) == when}; preview {sample.get('note')} {sample.get('context')}; journal {rows}; undo conflicted {(undo.get('counts') or {}).get('conflicted')}")

    def single_control() -> None:
        fx = upsert(p, [simple("to-private"), simple("to-pending")])
        a, b = fx["to-private"]["id"], fx["to-pending"]["id"]
        switch(p, [a, b], "draft")
        to_private = switch(p, [a], "private")
        to_pending = switch(p, [b], "pending")
        got = statuses(p, [a, b])
        ok = (got == {str(a): "private", str(b): "pending"} and record(p, a) == "" and record(p, b) == ""
              and notes(to_private["preview"]).get(a) == "record_dropped" and notes(to_pending["preview"]).get(b) == "record_dropped")
        row(p, "e10-single-control", ok, f"statuses {got}; records cleared; notes {notes(to_private['preview']).get(a)}, {notes(to_pending['preview']).get(b)}")

    state: dict[str, Any] = {}
    for name, fn in [("d1-round-trip", round_trip), ("d2-already-draft", already_draft), ("d2-not-off-publishes", not_off_publishes),
                     ("d3-repeat", repeat_off_on), ("d3-second-published", second_published), ("d4-edits-survive", edits_survive),
                     ("d5-conflict", conflicts), ("d6-slug-reserved", slug_reserved), ("d7-variation-choices", variation_choices),
                     ("d8-duplicate", duplicate), ("d9-bin", bin_restore), ("d10-scheduled", scheduled), ("d10-schedule-passed", schedule_passed),
                     ("scheduled-never-off", never_off_scheduled), ("e10-single-control", single_control)]:
        guard(p, name, fn)
    p.offon_state = state


CHECKS = [check_engine]
