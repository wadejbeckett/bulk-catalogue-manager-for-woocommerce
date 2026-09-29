# Global Off/On through Status (T86–T94)

This is the generic copy of the approved plan for T86–T94 (approved 28 September 2026, with its amendments (a)–(f) applied), kept with the code. Section numbers follow the approved plan; its site-acceptance section is kept with the site that runs it, not here. No merge, release or deployment is part of it.

**Goal:** make the grid's existing **Status** field the global Off/On switch. Draft switches a product Off, saving its prior status on the product. Published switches it On, restoring exactly what Off saved. Every buyer surface is blocked while the product is Off. Preview, journal, History and undo all describe and reverse the change exactly. The work runs on plain WordPress and WooCommerce 11.1 or later.

**Contract:** the owner's rulings of 28 September (1: existing orders are unaffected, so there is no order handling; 2: WooCommerce 11.1 is the minimum; 3: one control, Status is the Off/On switch, with no Available column or second field; 4: build order T84 → T85 → Off/On → CSV), the native proof of the mechanism (Draft plus a product-bound record blocks every buyer surface tested, for anonymous visitors and logged-in customers, on simple and variable products; its tests A–E are listed in §9 below), and SPEC §§4.1, 4.5, 5.2, 5.3, 5.5, 8, 9.1, 9.3, 9.4, 15 and 16, which change first (T86), before any code.

**Rule for the build:** start from the accepted T85 head (`fedea65`) on branch `codex/global-off-on`. Lines cited below are from `bc032ec` (T84 step 8); T85 moved some of them, so re-read each file before editing it.

## Baseline

What the code did at `bc032ec`, so each slice knows what it changes:

- **Status field.** `src/Fields/Registry.php:221-245`. Key `status`, `ENUM` over `post_status`, edit kinds `[SET]`, `CONDITIONAL` for variations (`variation_choices` publish and private), `default_order: 5`, `hiding_choices` draft and pending → `HIDES_OFF_SITE`. It has **no** `inline` flag, so the grid does not edit it in place. The bulk panel and the detail drawer (PATCH) edit it. Its choices are publish, draft, pending and private. A scheduled product reads `future`, which is not a choice.
- **Listing controls.** Catalogue visibility at `Registry.php:246-265` (four choices, parent only). Visible on site at `266-287` (the same storage, `ADAPTER_SWITCH`, `inline: true`, `default_order: 6`). The selection bar's **Show on site** and **Hide from site** (`assets/src/panels/catalogue/selection/quick-actions.js`, `defaults.js:137-141 QUICK_VISIBILITY`) set Visible on site.
- **Write path.** `Engine/Writer.php:230-240` computes each step, calls `Validator::check()` (which may return a second Result, as it does for the sale-price clear), applies index 0 through the operation and later Results through `apply_field()`, saves once, and journals one row per field (`Engine/Journal.php:259-286`). Replay after a killed run (`Writer.php:285-339`) resolves each journalled prop through `Fields\Registry::get()`. `Engine/Preview.php:329-340` runs the same compute/check/set on a clone. `Engine/InlineWrite.php` dry-runs through Preview and refuses 400 if any step would be skipped or would fail.
- **Undo.** `Undo/Planner.php:386-466` compares each applied field's current value with the journal's `new_value`. `operation()` (`477-514`) turns each field back into `set_value`, `clear` or a term action through the public `validate_list()`. A prop the registry does not know makes the item `CANNOT_RESTORE`.
- **Expansion.** `Engine/BatchFactory.php:739-787 expand()` appends every added variation after all selected objects, with one shared step list per added variation. `ExactScope` signs the expanded scope, so a changed expansion makes Apply stale.
- **Data registry.** `src/Data/Registry.php` has no post-meta kind. Its name rule `^bulkcat_[a-z0-9_]+$` (`140-142`) would refuse `_bulkcat_availability_off`. `Data/Cleanup.php:69-88` removes user and term meta only.
- **Requirements.** The plugin header, `readme.txt` and `README.md` say WooCommerce 10.0. `src/Plugin.php:30` sets `MIN_WC_VERSION = '10.0'` and `:173` holds the notice text. CI's wp-env matrix is `wc: [ '11.1.2', '10.9.4' ]` (`.github/workflows/ci.yml:74`). `.wp-env.json` already pins WooCommerce 11.1.0.
- **Proof fact.** The installed 1.0.0 Status field already writes and journals `post_status` (proof, batches 9 and 10).

## Contract summary

1. **One control.** Status is the only Off/On control (owner ruling 3). No Available column, no second field, no separate availability filter. Pending and Private stay ordinary statuses.
2. **Off = Status set to Draft on a product.** Save the prior status in the product-bound record, then set draft through WooCommerce CRUD. Change nothing else: allocations, categories, prices, listing settings, dates, slug and variations all survive.
3. **On = Status set to Published on an Off product.** Restore exactly what Off saved and clear the record. A product that was private before Off comes back private, and the preview says so. On is never a public grant.
4. **Conflicts, not overwrites.** A status changed outside the manager while Off is a conflict. Published leaves that product alone and offers one explicit resolution, "Keep its current status and clear the Off record". Draft again saves the current status and drafts.
5. **Variations.** Off/On is parent-only. On restores exactly what Off saved. Off never changes a variation's status, so On leaves variations alone, whatever **Also apply to variations** says.
6. **Same outcome by every path.** Inline, bulk and drawer produce identical records and outcomes. Everything is journalled, idempotent and exactly undoable. Undo takes the status it writes only from the journal, never from the record. It may still clear a record (journalled), and it reports "changed since" when the product's record is newer than the batch being undone (§3).
7. **Listing controls are separate.** "Visible on site" becomes the secondary listing control **Shown in shop and search**, no longer a default column. The four-choice Catalogue visibility stays in the drawer.
8. **Minimum WooCommerce 11.1** (owner ruling 2).
9. **No order handling** (owner ruling 1).
10. **Generic core only.** No group system or site-specific checks enter the core. Site systems are verified on each site, outside this repository.

### The six confirmed defaults

The maintainer confirmed these on 28 September, with the variation tweak folded into default 3, and restated them when approving the plan:

1. **A never-Off product set to Published is published**, and the preview lists it separately: "Not switched Off: this publishes it".
2. **Pending or Private on an Off product clears the record.**
3. **On restores only what Off saved**, and variation statuses only if Off saved them. Off saves none in this build (§1), so On leaves variations alone, whatever **Also apply to variations** says.
4. **Status is inline-editable** in the grid.
5. **A second Published after a restore to Private publishes.** The restore cleared the record, so this is an ordinary status change (probe `offon:d3-second-published`).
6. **One Status filter.** Off and Changed outside this screen are values of the Status filter, not a separate filter.

Also note: a never-Off product whose date is in the future **stays scheduled** when set to Published. This is WordPress's own rule in `wp_insert_post()` (§2). The preview says "Not switched Off: stays scheduled for <time>".

## Source map

| Boundary | Current source | Planned responsibility |
|---|---|---|
| Record | none | New `src/Availability/OffRecord.php`: value object, ID binding, canonical JSON, meta read/write (never saves) |
| Decision | none | New `src/Availability/StatusSwitch.php`: the ticket's Status table as one pure decision. New `Availability/Outcome.php`: outcome codes and labels. New `Availability/Stamp.php`: batch ID and clock for new records |
| Fields | `Fields/Field.php`, `Fields/Registry.php` | Status gains `inline` and filter-only choices. New `internal` flag and `ADAPTER_OFF_RECORD`. Internal field `availability_off`. Visible on site relabelled |
| Operations | `Operations/{Operation,BaseOperation,SetValue,Result,Registry}.php` | New `follow()` seam. SetValue's status path. `keep_changed_status` argument. `Result::note/context`. Internal actions. New `Operations/RestoreStatus.php` |
| Engine | `Engine/{Writer,Preview,BatchFactory,InlineWrite}.php` | Call `follow()`. Stamp records. The preview's `availability` block. On excluded from variation push-down |
| Undo/History | `Undo/Planner.php`, `Rest/BatchChangesController.php` | Status and record fold into one `restore_status` step. Record rows displayed readably |
| Grid | `Query/{Hydrator,Criteria,ProductQuery}.php`; `assets/src/panels/catalogue/{cells.js,edit/*}` | Row extra `off_state`. Status filter values `off` and `changed_outside`. Inline Status with an in-cell confirm. The drawer uses the same confirm |
| Bulk panel | `assets/src/panels/bulk/{confirm,preview,words}.js` | Off/On confirm reasons; availability block rendered, conflicts first |
| Data lifecycle | `Data/{Registry,Cleanup}.php`, `uninstall.php` | Post-meta kind; opt-in uninstall removes the record and leaves status alone |
| Requirements | plugin header, `Plugin.php`, `readme.txt`, `README.md`, `ci.yml`, `.wp-env.json`, SPEC §15/§16.6 | WooCommerce 11.1 |

## 1. Data: the Off record

**Key.** `_bulkcat_availability_off`, as fixed by the contract and the proof. The leading underscore makes it protected meta. It is hidden from the editor's Custom Fields box, left out of WooCommerce's CSV exporter meta columns, and not in REST.

**Value.** One canonical JSON string, keys always in this order, encoded with `JSON_UNESCAPED_SLASHES`:

```json
{"id":123,"status":"publish","at":"2026-09-28T14:03:11Z","batch":418}
```

- `id`: the product ID. It binds the record to its product (proof D8). A copied record (WooCommerce's Duplicate, an import, a meta copy) is "no record" for the product it sits on.
- `status`: the status before Off. One of `publish`, `future`, `pending`, `private`, which are the listable statuses other than draft (`ProductQuery::STATUSES`).
- `at`: UTC time Off was written, from `Stamp`'s injectable clock.
- `batch`: the batch that wrote it. Inline, drawer and bulk writes are all batches. An undo that restores a record writes it back verbatim, keeping the original batch.
- The value holds only integers and fixed-vocabulary strings, so `wp_unslash()` in `add_metadata()` cannot alter it.
- **Variation statuses are not stored.** Off never changes a variation's status: Draft is not a variation status, so Draft pushed down is skipped (SPEC §8). "What Off saved" for variations is therefore always nothing. If a later version lets Off change variations, it must add a `variations` key and restore it on On; this build writes none. A record with any key or value outside this shape is **unreadable**. It is treated as no record for the product (see §2), and a later Off overwrites it with a valid one.

**Reading.** `OffRecord::for_product( object $product ): ?self` returns a record only when the value parses to exactly these keys, `id === $product->get_id()` and `status` is one of the four. `OffRecord::raw( object $product ): string` is the canonical JSON of the product's own record, or `''`. It is the value the journal and undo compare. The SQL filter (§5) matches the prefix `{"id":<ID>,`. A hand-corrupted value with that prefix is the only case where the filter and PHP could disagree; this is accepted and documented.

**Duplicates.** Add the key through WooCommerce's `woocommerce_duplicate_product_exclude_meta` filter, so the native Duplicate no longer copies it. The ID binding stays the primary guard, because imports and other copies are not filtered.

**Registration and uninstall.**

- `Data\Registry` gains a `post_meta` kind (`POST_META = [ OffRecord::META_KEY ]`, accessor `post_meta()`). Its own name rule is `^_bulkcat_[a-z0-9_]+$`. The existing `bulkcat_` rule for other kinds is unchanged.
- `Cleanup::uninstall()` removes each key with `delete_metadata( 'post', 0, $key, '', true )`, only when *Remove all data when the plugin is deleted* is on, on every site through `on_every_site()`.
- Statuses are left alone. Off products stay drafts, which fails closed.
- Deactivation does nothing.
- The readme FAQ gains: "Set Off products back to Published before deleting the plugin with Remove all data on if you want their saved status restored."
- No table, no migration, and `bulkcat_db_version` unchanged.

**Internal journal field.**

- Add `availability_off` to `Fields\Registry` as an **internal** field: label "Status before Off", storage meta `_bulkcat_availability_off`, new adapter `ADAPTER_OFF_RECORD`.
  - `read()` gives `OffRecord::raw()`.
  - `write()` sets or deletes the meta through `update_meta_data()`/`delete_meta_data()` and never saves.
  - Validation: `''`, or a record whose `id` is the object's own.
  - `PARENT_ONLY`, with a variation note.
  - Edit kinds `[SET, CLEAR]`, so undo and replay can write it.
- `Registry::get()` resolves it, and a new `Registry::internal()` lists it. It is excluded from `all()`, `keys()`, `editable()`, `columns()`, `filterable()`, `sortable()` and `to_client()`. So it is never in `/fields`, the grid, the Hydrator, CSV export, route arguments, PATCH, the bulk panel or any public action's accepted fields.
- `Field`'s constructor refuses an internal field with a group, a default column place, filter operators or `inline`.
- Consequence: Writer replay, Journal readback, `Preview::set()`, `apply_field()` and the Planner all go through `read()`/`write()` as before, and nothing names the meta key except `OffRecord`.
- It **does** appear as a journal row in History and the audit log, labelled "Status before Off" (§3), because undo depends on it. This is not a second Off/On control.

## 2. Write path

**The seam.**

- Add `follow( object $subject, Result $checked, array $args ): list<Result>` to the `Operation` interface. `BaseOperation` returns `[]`.
- `Writer::write_one()` and `Preview::compute()` call it straight after `Validator::check()` and before applying anything, only when the checked Result is changed or already. A skipped or failed status step, including a permission refusal, never produces a record change.
- Its Results are applied like the Validator's extra Results: through `apply_field()` in the Writer and `set()` in Preview. They are journalled per field through the existing `before`-for-unnamed-field path (`Writer.php:235-238`).

**SetValue on `status` for a product** (not a variation) delegates to `StatusSwitch::decide( $subject, $target, $keep_changed_status )`, which reads `OffRecord::for_product()` once:

- `compute()` returns the decision's status Result.
- `follow()` returns its record Result, or nothing.
- The subject is unchanged between the two calls, so both see the same state.
- On a variation, the existing field rules stand: publish and private only; Draft pushed down is skipped and Draft chosen fails, both with the existing reason. The record is never touched.

**Decision table.** N means no own record (none, copied or unreadable). O means own record and status `draft` (Off). X means own record and any other status (changed outside while Off). R is the record; R′ is a new record stamped now with the current batch.

| Target | State | Status Result | Record Result | Outcome code |
|---|---|---|---|---|
| draft | N, status draft | already(draft) | none | `already_draft` |
| draft | N, status publish/future/pending/private (s) | changed(s → draft) | '' → R′{status: s} | `off` |
| draft | O | already(draft) | none | `already_off` |
| draft | X, status s | changed(s → draft) | R → R′{status: s} | `off_recapture` |
| publish | O, R.status publish | changed(draft → publish) | R → '' | `on` |
| publish | O, R.status private or pending | changed(draft → R.status) | R → '' | `on_restores` |
| publish | O, R.status future, date ≥ 60 s ahead | changed(draft → publish) | R → '' | `on_scheduled` |
| publish | O, R.status future, date passed | changed(draft → publish) | R → '' | `on_schedule_passed` |
| publish | X, keep off | skipped(s), code `bulkcat_availability_conflict` | none | `conflict` |
| publish | X, keep on | already(s) | R → '' | `conflict_kept` |
| publish | N, status publish | already(publish) | none | none |
| publish | N, any other status | changed(→ publish), as today | none | `publishes_not_off` |
| pending or private (t) | O or X | changed(→ t), or already(t) | R → '' | `record_dropped` |
| pending or private | N | as today | none | none |
| any | in the bin | T83 already records it as an unavailable member ("in the bin"), failed, never written | none | none |

**Scheduled products.** The manager never writes `future`. The Status field cannot, because `future` is not one of its choices. On for a product saved as scheduled writes `publish`. WordPress's own rule in `wp_insert_post()` then decides: a post saved as publish whose date is at least a minute ahead becomes `future`, and its publish event is re-created; a scheduled post whose date has passed is published. WooCommerce passes `post_date`/`post_date_gmt` from `date_created` on every status save, and the proof showed the dates unchanged through Off.

- **The Writer must re-read the status after save** (amendment (a), before T89 closes).
  - The problem: `Journal::row()` (`Journal.php:~267-268`) reads the saved value through `Field::read()`, which asks the in-memory object (`Field.php:~568-591`). WooCommerce's `update()` calls `wp_update_post()` and never reads `post_status` back into the object. It then calls `apply_changes()`, so the object keeps the status that was set. See `includes/data-stores/class-wc-product-data-store-cpt.php` ~364-365 and ~394-395 in 11.1.0, checked against a local 11.1.0 copy on 28 September.
  - The effect: a product WordPress keeps or makes scheduled is journalled as `publish` while the database says `future`, and undo later reports a false "changed since". This is a 1.0 bug too: Published on any scheduled product today journals `publish`.
  - The fix: after `save()` returns, when the saved changes included `status`, the Writer runs `clean_post_cache( $id )`, reads `get_post_field( 'post_status', $id )` and puts that value on the saved object (`set_status()`, then `apply_changes()`). Journal settlement, the `WriteOutcome` and the PATCH answer's row then all see what WordPress stored. `Writer::replay()` does the same after its own save.
- The D10 probe rows must prove the date rule, the journal recording `future`, and an exact undo before anything relies on them. Ruling 6 is accepted on that condition.
- Fallback, if it fails: give the status field a narrow restore path that may write `future` only when it is the record's saved status. Record the finding in the plan.
- The same WordPress rule means Published on a never-Off scheduled product whose date is ahead keeps it scheduled, as today. The preview says "Not switched Off: stays scheduled for <time>" rather than "this publishes it".

**Conflict resolution argument.**

- `set_value` gains `keep_changed_status` (boolean, default false), kept only when the field is `status`, as `clear_invalid_sale` is kept only for money.
- The bulk panel shows it on a Published step as "For products changed by someone else while Off: keep their current status and clear the Off record".
- `PATCH /products/{id}` accepts a top-level `keep_changed_status` only alongside `status`. `InlineWrite::step()` passes it into the status step. This backs the inline and drawer **Keep current status** button.

**Stamping.**

- `Writer::write()` runs `write_one()` inside `Stamp::within( $this->batch_id, $clock, … )`, so R′ carries the batch that writes it.
- Preview stamps batch 0 and "now". Its record Results are informational and hidden from the sample (§4).
- Replay writes the journalled JSON verbatim.

**Result.**

- `Operations\Result` gains `note` (a `[a-z_]{0,40}` code or `''`) and `context` (a small map of scalars: `saved`, `restores`, `scheduled_gmt`, `current`). Both are allowed on any state, and set through `with_note( string, array )`.
- `to_array()` adds both keys. `ResultTest` is updated.
- The Journal ignores them.

**InlineWrite.**

- A conflict is skipped, so the dry run refuses 400 with the conflict sentence and `data.field = status`.
- `already_off` and `already_draft` write nothing. The answer's `results` carry the note.
- An Off or On journals two rows under source `inline`, the same as bulk (E3).

**Outside writers.** The editor's Publish button, quick edit, WooCommerce's CSV importer, WP All Import or any sync writing `post_status` are detected (X), not prevented. The plugin adds **no** `save_post`/`transition_post_status` hook. The manager's own Status field is the control, not an outside writer.

## 3. Undo and history

**One internal step per object.**

- The Planner folds an object's `status` and `availability_off` journal fields into a single step: `{ action: 'restore_status', args: { field: 'status', value?: <journal old_value>, record?: <journal old_value> } }`. Each argument is present only when the batch applied that prop.
- Conflicts are computed as today, per prop: current `read()` against the journal's `new_value`. A record conflict lists as field "Status before Off".
- **Newer-record guard** (amendment (b), before T90 closes).
  - The rule: if the object carries its own record whose `batch` is later than the batch being undone, the item is conflicted with "changed since" (code `changed_since`). The conflict lists field "Status before Off", with the record as its current value. Batch IDs only grow, so "later" is a plain comparison. A record the undone batch wrote itself carries that batch's own ID, so it never trips the guard.
  - The case it stops: a 1.0 batch set a product from Published to Draft. The product was later made Private and switched Off, so its record saved Private. Undoing the 1.0 batch would otherwise pass the status check (draft equals draft), write publish, and drop the saved Private through the status-only rule below.
  - With **Revert anyway**, the item proceeds under the rules below.
- `restore_status` (new `Operations/RestoreStatus.php`) is **internal**:
  - `Operations\Registry` marks it so. `descriptors()`, `to_client()` and `actions_for()` leave it out.
  - `validate_list( $list, internal: false )` refuses it as `bulkcat_unknown_action` for every caller except the Planner, which passes `internal: true`.
  - `get()` still resolves it, so `Writer::resolve()` runs stored undo items.
  - A client's Apply intent (`BatchIntent::apply()`/`current_operations()`) therefore cannot use it. A test proves this.

**`restore_status` never consults the record for the status it writes.** The record is read only for the newer-record guard above and for the record handling below.

- **Status:** the target is the journal's `old_value`, written exactly through the status field. A target of `future` is written as `publish` with note `restores_scheduled`, and WordPress's date rule restores `future` or publishes. The status field is used on variations too, so every undo of a status change, 1.0 rows included, takes this one path.
- **Record, when the batch journalled it:** the journal's `old_value` is written verbatim (`''` deletes it). The JSON must parse and name this object, or the item is skipped with a reason. So undoing an Off gives back the old status and no record, and undoing an On gives back draft plus the original record (id, status, at and batch unchanged).
- **Record, when the batch journalled only `status`** (1.0 history rows, and ordinary post-04 status changes):
  - If the product now has its own record and the restored status is not `draft`, the record is cleared and journalled. The operator chose a status by undoing, as with Pending/Private in the table, and this keeps undo from leaving the product in the "changed outside" state.
  - If the restored status is `draft`, the record is left, and the product stays Off with its record.
  - Ruling 2, accepted together with the newer-record guard.
- **Redo** (undo of the undo) plans `restore_status` again from the undo batch's own rows.

**History display.** `BatchChangesController::change()` shows an `availability_off` row with its field label "Status before Off", and each value as the saved status and time ("Published, saved 28 Sep 2026 14:03") or "—", instead of the JSON. The journal keeps the JSON, and History CSV export uses the same display.

**1.0 compatibility.**

- 1.0 journal rows hold `status` only. Their undo takes the status-only path above, behind the newer-record guard. A real upgrade from the 1.0.0 zip proves this (T90), not only synthetic rows.
- A 1.0 undo batch queued before the upgrade holds `set_value status` items, which now run with switch semantics. An item that sets a product to Draft now writes a record, because it runs as an Off. This is harmless: the product is drafted exactly as before, and the record only lets a later Published restore the status it had.

## 4. Preview and confirmation

**Server.** `Preview::run()` appends `availability` after `operations`, keeping T83's key order. It is `null` when no step sets `status`, and otherwise:

```json
{
  "counts": { "off": 0, "off_recapture": 0, "already_off": 0, "already_draft": 0, "on": 0, "on_restores": 0,
              "on_scheduled": 0, "on_schedule_passed": 0, "publishes_not_off": 0, "conflict": 0,
              "conflict_kept": 0, "record_dropped": 0 },
  "restores": { "private": 0, "pending": 0 },
  "variations_kept": 0,
  "conflicts": [ { "object_id": 0, "name": "", "current": "publish", "saved": "publish" } ]
}
```

- Counts are scaled like every other total when `estimated`.
- `conflicts` holds up to the chosen sample size of conflicted products, in object order.
- Record Results are left out of `sample`, `rows` and the summary's field list. Their object still counts under `would_change`, so a `conflict_kept` product that only loses its record counts once.
- The summary names "Status" for such an object.
- Status sample rows carry `note` and `context`.

**Wording** (`assets/src/panels/bulk/words.js` and `edit/status.js`; the server's conflict reason is the same sentence):

| Code | Row | Count line |
|---|---|---|
| `off` | Switched Off (was Published) | "{n} will be switched Off. Each one's status is saved, so Published brings it back." |
| `off_recapture` | Changed by someone else while Off: switched Off again, saving Private | "{n} were changed by someone else while Off and will be switched Off again." |
| `already_off` | Already Off | "{n} are already Off." |
| `already_draft` | Already Draft: nothing to save | "{n} are already Draft. Nothing is saved for them." |
| `on` | Switched back On | "{n} will be switched back On." |
| `on_restores` | Was Private before Off: comes back Private | "{n} come back with the status they had before Off, not Published: {private} Private, {pending} Pending review." |
| `on_scheduled` | Was scheduled before Off: comes back scheduled for {time} | "{n} come back scheduled." |
| `on_schedule_passed` | Its scheduled time has passed: this publishes it now | "{n} were scheduled and their time has passed: this publishes them now." |
| `publishes_not_off` | Not switched Off: this publishes it (or "Not switched Off: stays scheduled for {time}") | "{n} were not switched Off: this publishes them." |
| `conflict` | Changed by someone else while Off: left alone | "{n} were changed by someone else while Off and will be left alone. Choose Keep current status to clear their Off record instead." |
| `conflict_kept` | Changed by someone else while Off: keeps Published, Off record cleared | same, "…keep their current status." |
| `record_dropped` | Its saved status (Published) will not be restored | "{n} are Off; their saved status will not be restored." |
| `variations_kept` | none | "{n} variations are left as they are: switching On restores only what switching Off saved." |

The panel renders the availability block above the ordinary sample in this order: conflicts first, then `on_restores`, `on_schedule_passed` and `publishes_not_off`, then the rest.

**Confirmation** (`bulk/confirm.js`, SPEC §4.5). Ruling 3, accepted: the bulk panel, the inline cell and the drawer confirm the **same cases**, from one list, `Outcome::CONFIRMS`: `off`, `off_recapture`, `on_restores`, `on_scheduled`, `on_schedule_passed`, `publishes_not_off`, `record_dropped` and `conflict`. The shared fixture (§5) exports this list, so Jest checks the client against it.

- **Bulk.** A Draft step still always confirms (anything that drafts, §4.5). Its sentence becomes "This switches {n} Off: they leave the site for every buyer, and each one's status is saved for switching On." Any Status step also always confirms when the preview counts a product under one of the listed codes, naming each count. `confirmPolicy()` takes these preview-derived reasons alongside the operation-derived ones.
- **Inline and drawer.** The same list decides (§5). The only difference is `conflict`: inline and drawer write nothing for it. They show the server's 400 sentence with **Keep current status**, and that button is the confirmation.
- `on`, and ordinary changes with no record, add no reason. The bulk row-count threshold applies as today.

**Variations: On is not pushed down.**

- `BatchFactory::expand()` reads, in one prepared query per page of parent IDs, the `_bulkcat_availability_off` values of the selected variable products, binding each to its ID in PHP.
- A `set_value status publish` step with `apply_to_variations` on is not carried to the variations of a parent that is Off (own record) at expansion. A variation left with no step is not added.
- The count goes to `selection.variations_kept`, surfaced as `availability.variations_kept`.
- `ExactScope` signs the per-variation step lists. If a parent's Off state changes between preview and Apply, the scope hash differs and Apply is 409 `bulkcat_preview_stale` ("Preview again"). This keeps the preview true and the batch order-independent, even though added variations run after all parents (`expand()` appends them).
- Draft pushed down is still skipped per variation with the existing reason, as the ticket says ("as today").
- Inline and drawer writes never expand, so all three paths leave variations alone on On (E3).

## 5. The grid: Status is the control

**Server.**

- The status field gains `inline: true`. SPEC §5.2's Status row becomes "set (inline)".
- `Field` gains `filter_choices` (value → label), appended to `dataviews().elements` and to `Criteria::FILTERS['status']`'s allowed values. It is **not** added to `choices()`, `writable_choices()` or the REST enum, so no one can write them. Status gets `off` → "Off" and `changed_outside` → "Changed outside this screen".
- `ProductQuery` (`470-471`) builds the status condition from these parts, using OR for `isAny` and `NOT( … )` for `isNone`:
  - real values other than draft: `p.post_status IN (…)`;
  - `draft` means a **genuine** draft: `p.post_status = 'draft' AND NOT <own>`;
  - `off`: `p.post_status = 'draft' AND <own>`;
  - `changed_outside`: `p.post_status <> 'draft' AND <own>`.
  - `<own>` is `EXISTS (SELECT 1 FROM {postmeta} m WHERE m.post_id = p.ID AND m.meta_key = %s AND m.meta_value LIKE CONCAT(%s, p.ID, %s))`, with arguments `'{"id":'` and `',%'`.
  - The two special values (`off`, `changed_outside`) match product rows only. A variation never carries a record.
  - Saved views filtering Status = Draft now exclude Off products, as intended. This goes in the CHANGELOG.
- `Hydrator` adds the row extra `off_state` on product rows whenever `status` is among the page's fields:
  - `null`, or `{ state: 'off'|'changed_outside', saved, saved_label, switched_off_gmt, batch_id }`;
  - `saved_label` is translated server-side, including "Scheduled" for `future`, which is not a Status choice;
  - it primes `update_meta_cache()` over the page's IDs when no other meta column did. That is at most one extra query, which must stay inside the §12.5 limit of 20 per page (T94 measures it).
- The count cache relies on WooCommerce's save cleaning the post cache (bumping `posts` last-changed). A probe row checks that the Off filter's count moves right after an inline Off.

**Client.**

- `cells.js`: the Status cell reads "Off (was Published)" for `off_state.state === 'off'`, "Changed outside this screen" for `changed_outside`, and the ordinary label otherwise. It is still a badge, and still the default column at `default_order` 5.
- **Confirm component.** New `edit/status.js` exports:
  - `statusOutcome( item, target )`, a pure mirror of `StatusSwitch` that returns the outcome code and its sentence;
  - `<StatusConfirm>`, an in-place confirm with the sentence plus **Switch Off**/**Switch On**/**Change status** and **Cancel**. Enter confirms; Escape returns to the select; announcements go through `announce()`.
- **Shared fixture.** `tests/fixtures/status-switch.json` lists every table row as {status, record, target, keep} → {status result, record result, code}, plus the `confirms` list (`Outcome::CONFIRMS`). PHPUnit (`StatusSwitchTest`) and Jest (`status-switch.test.js`) both run it, so the mirror cannot drift. The server stays the judge.
- `edit/cell.js`: for `status` on a product row, a commit whose `statusOutcome()` code is in `Outcome::CONFIRMS` (§4) shows `<StatusConfirm>` before the PATCH. These are the same cases the bulk panel confirms. `on`, and an ordinary change with no record, commit directly as other inline cells do. `conflict` writes nothing: the server's 400 sentence shows with a **Keep current status** button (PATCH with `keep_changed_status: true`), which is its confirmation. Variation rows keep publish/private with no confirm.
- `edit/drawer.js`: Save with a changed Status runs the same `statusOutcome()` and shows `<StatusConfirm>` above the Save button before writing. Same PATCH, same records.
- An Off product stays listed and editable in the manager (E6). No change is needed: `ProductQuery::STATUSES` lists drafts.

## 6. Listing controls

- Keep the field key `visible_on_site`, so saved views, journal props, CSV column IDs and 1.0 history all stay valid. Change only these:
  - label → **Shown in shop and search**;
  - choice labels → Shown / Hidden / Partly;
  - `default_order` 6 → 0, so it is not a default column;
  - `inline` stays, for owners who add the column.
- **Why keep it rather than drop it:** there is less churn for saved views, the cards slot and the selection bar. The contract allows either.
- The selection bar's quick actions relabel to **Show in shop and search** and **Hide from shop and search**. Behaviour is unchanged. Their old names claim global availability, which the proof shows they do not give. Ruling 5, accepted.
- `confirm.js`'s "hidden" sentence names the new label. The cards slot and `edit/switch.js` strings follow.
- SPEC §5.3 is retitled and gains: "Listing controls change listing only; a hidden product can still be opened by link and bought. Set Status to Draft to take a product off sale."
- Catalogue visibility (four choices) stays in the drawer and is unchanged.
- **CSV headings.**
  - Status keeps its heading and its four choices. Off/On adds no column, so it does not touch the CSV work's byte-identical rule.
  - The one heading change is "Visible on site" → "Shown in shop and search", because the header row is each column's registry label (`Export/CsvExport.php:105-110`).
  - The build order puts this before the CSV selection work (T95 onwards), so its baseline includes it. If that lands first after all, this slice updates its byte-identical test and records the heading change in the CHANGELOG.
  - The CSV **values** of the Status column do not change: an Off product still exports its `post_status`.
  - `default_order` 0 also takes the column out of **default-view exports**. The export writes the view's visible columns, so an export of the default view no longer contains this column at all, not just under a new heading. Views that already show the column export it under "Shown in shop and search". It was accepted as sequenced; it goes in the CHANGELOG with the heading change.

## 7. Minimum WooCommerce 11.1

Every place that names the minimum changes together:

- the plugin header `WC requires at least: 11.1`;
- `Plugin::MIN_WC_VERSION = '11.1'` and the notice text "…needs WooCommerce 11.1 or later…";
- `readme.txt`: the header, install step 1 ("Install and activate WooCommerce 11.1 or later") and the FAQ line;
- `README.md` line 7;
- SPEC §15 Headers;
- SPEC §16.6, which becomes "current WooCommerce and the minimum".

In CI, `.github/workflows/ci.yml:74` becomes `wc: [ '11.1.2', '11.1.0' ]`. `.wp-env.json` already pins 11.1.0: no change, but a test asserts it.

In tests:

- `DependencyTest`: 11.0 now gets the notice only; 11.1 and 11.1.2 boot. The header ↔ constant assertion stays.
- `PackagingTest` gains three assertions: readme.txt's `WC requires at least` equals `MIN_WC_VERSION`, `.wp-env.json`'s WooCommerce zip is at least that version, and the lowest CI `wc` leg is at least that version.

The readme Upgrade Notice and the CHANGELOG say sites below 11.1 will see only the notice after updating, with their data kept.

## 8. Tickets

Each slice is test-first. Write the listed tests, run them red against the previous slice's head, implement, then run them green. Finish each slice with `composer lint`, `composer test`, `npm run lint:js` and `npm test`. Run `npm run build` whenever `assets/src` changed. Run the probe chapters named against the throwaway docker stack of `tests/probe/README.md` (never a real shop), and `npm run e2e` where e2e specs are named. Record the numbers in `docs/TICKETS.md` and in the maintainer's evidence notes. No slice may leave the branch unable to build.

### T86 — SPEC and tickets first (docs only)

- [ ] SPEC: §4 Columns (the status badge carries Off/On; "Visible on site" is no longer a default column); §4.1 (Status filter values Off and Changed outside this screen); §4.5 (the Off/On confirm reasons); §5.2 (the Status row: set (inline), storage `post_status` plus `_bulkcat_availability_off`; Visible on site relabel); §5.3 retitled plus its sentence; new **§5.5 Status as global Off/On** (the proof's text, this plan's decision table, conflicts, display, outside writers); §8 (Off/On parent-only; On not pushed down; `variations_kept`); §9.1 (the `availability` block); §9.3 (the record journalled as `availability_off`); §9.4 (`restore_status`, the status-only rule, History display); §15 (WooCommerce 11.1; post meta in the data lifecycle; uninstall behaviour); §16 (buyer tests; CI current plus minimum).
- [ ] `docs/TICKETS.md`: rows T86–T94 with their acceptance.
- [ ] A **generic** copy of this plan in core `docs/plans/2026-MM-DD-global-off-on.md` (this file), with every client name, site and the site-acceptance section removed.
- [ ] Before committing, run the generic-name check (amendment (f)): a case-insensitive `grep -rinE` over `docs src tests assets` for the first client's name and the third-party systems named in its records (the maintainer holds the exact pattern). The check passes only if it reports nothing beyond the three lines already known at `bc032ec`: `docs/SCOPE-2026-09-21.md` lines 14, 64 and 65, which the TICKETS backlog's pre-public-release item removes. Run it again at the end of every later slice.
- **Acceptance:** the maintainer reviews the SPEC diff before T87 starts. No code changes.

### T87 — WooCommerce 11.1 minimum

- [ ] Red: `DependencyTest` expects the 11.1 notice text and the refusal of 11.0. New `PackagingTest` assertions (§7).
- [ ] Green: §7's file list.
- **Probe:** the CI wp-env legs 11.1.0 and 11.1.2 run the existing chapters green.
- **Acceptance:** every place naming the minimum says 11.1; the CI matrix floor is 11.1.0; nothing else changes.

### T88 — Record foundation (no visible behaviour)

- [ ] Red, `tests/Unit/Availability/OffRecordTest.php`:
  - canonical JSON and key order;
  - round trip;
  - a foreign `id` is none (D8);
  - malformed JSON, extra keys, a bad status and a `draft` status are each unreadable, so none;
  - `raw()` is `''` or the canonical JSON;
  - `write()` sets or deletes and never saves;
  - a slashing round trip through `update_meta_data`/`add_metadata`.
- [ ] Red, `Fields/RegistryTest`:
  - `availability_off` is resolved by `get()`, listed by `internal()`, and absent from `all()`/`keys()`/`editable()`/`columns()`/`filterable()`/`sortable()`/`to_client()`;
  - `fields.snapshot.json` is unchanged by this slice;
  - the constructor refuses an internal field with a group, a default place, filters or inline.
- [ ] Red, other suites:
  - `Data/RegistryTest`: the post-meta name rule;
  - `UninstallTest`: the key is removed only with Remove all data on, and `post_status` is untouched;
  - `ExportRouteTest`/`CsvExportTest`: CSV headings are unchanged;
  - the duplicate filter excludes the key.
- [ ] Green: `OffRecord`, the internal field and adapter, the `Data\Registry` post-meta kind, `Cleanup`, the duplicate filter. Test doubles (`tests/Support/fake-product.php` `FakeProduct`/`SavedProduct`, `Engine/StoredProduct.php`) gain `get_id`/`get_meta`/`update_meta_data`/`delete_meta_data` over a `meta` map.
- **Probe:** lifecycle chapter, row `lifecycle:uninstall-off-record` (record kept with Remove all data off, removed with it on, status still draft).
- **Acceptance:** every existing suite is green; nothing visible changes.

### T89 — The status switch (engine)

- [ ] Red, `tests/Unit/Availability/StatusSwitchTest.php`: every row of `tests/fixtures/status-switch.json`, which is every row of the §2 table, plus unreadable and foreign records as N, scheduled ahead and passed, and `keep_changed_status`.
- [ ] Red, operations:
  - `SetValueTest`: status on a product goes through the switch; on a variation it does not; `keep_changed_status` is kept only for status and refused on other fields;
  - `ValidatorTest`: a permission refusal produces no record Result;
  - `ResultTest`: `note`/`context`.
- [ ] Red, `WriterTest`:
  - Off writes two journal rows (`status`, `availability_off`), saves once and stamps the batch;
  - On restores and clears;
  - intent rows for both props, and replay after a kill between save and settle;
  - `skip_already` leaves already-Off unsaved and unjournalled;
  - a conflict is skipped and unsaved;
  - `conflict_kept` journals only the record;
  - **status re-read after save** (amendment (a)): the stub's stored `post_status` differs from the object's (`future` against `publish`), and the journal records the stored value; the same through `replay()`.
- [ ] Red, `InlineWriteTest`: a conflict gives 400 `bulkcat_availability_conflict` naming `status`; `keep_changed_status` on PATCH; inline and bulk records are identical (E3, server half).
- [ ] Green: `follow()`, SetValue, `StatusSwitch`, `Outcome`, `Stamp`, the Writer and Preview calls, the Writer's status re-read after save, the InlineWrite and `ProductWriteController` parameter.
- **Probe,** new chapter `offon` in `probe.py`, added to `CHAPTERS` and `NEEDS_WP`. Its fixtures are upserted through WP-CLI inside the chapter: the proof's state set (published; published hidden and search-only; draft; private; pending; scheduled; variable with one disabled variation; unrelated draft, private and scheduled products). Drive the manager's own REST routes: grant → `/selection` → `/preview` → `/batches` → `wp action-scheduler run` → readback with `wc_get_product()`.
  - `offon:d1-round-trip`: the mixed selection Draft then Published gives an identical snapshot, using the proof's `offon_snap()` field list. Unrelated products are untouched.
  - `offon:d2-already-draft`, `offon:d2-not-off-publishes`
  - `offon:d3-repeat-off`, `offon:d3-repeat-on`
  - `offon:d3-second-published` (confirmed default 5): after an On restores Private, Published again publishes as an ordinary change, and the preview says "Not switched Off: this publishes it".
  - `offon:d4-edits-survive`
  - `offon:d5-conflict`, `offon:d5-recapture`, `offon:d5-keep`
  - `offon:d6-slug-reserved`
  - `offon:d7-variation-choices`: publish/private only, Draft pushed down skipped, Draft chosen fails.
  - `offon:d8-duplicate`: `WC_Admin_Duplicate_Product` gives no copied meta, and a hand-copied foreign record restores nothing.
  - `offon:d9-bin`
  - `offon:d10-scheduled`:
    - the event is removed while Off;
    - Published before the time re-creates the event at the original time, which proves §2's `publish` → `future`;
    - the journal's `new_value` is `future` (amendment (a));
    - undo of that On is exact, with no "changed since" (E2 check);
    - after the time, Published publishes it, via `wp cron event run --due-now`.
  - `offon:scheduled-never-off`: Published on a never-Off scheduled product whose date is ahead leaves it `future` with its event intact. The journal records `future`, the preview said "Not switched Off: stays scheduled for <time>", and undo reports no false conflict. This pins the 1.0 bug fixed by amendment (a).
  - `offon:e10-single-control`: Private and Pending on an Off product clear the record.
- **Acceptance:** D1–D10 (D7's On push-down half follows in T91) and E10 pass through the manager. The unit table is complete. `offon:d10-scheduled` and `offon:scheduled-never-off` pass with the status re-read; if D10 fails, apply §2's fallback before T89 closes and record it here.

### T90 — Undo and History exactness

- [ ] Red, `Operations/RegistryTest`: `restore_status` is absent from `to_client()`, `descriptors()` and `actions_for()`; public `validate_list()` refuses it; internal `validate_list()` accepts it.
- [ ] Red, `Requests/BatchIntentTest`: an Apply intent carrying `restore_status` is refused.
- [ ] Red, `Undo/PlannerTest`:
  - undoing an Off gives back publish, private, pending or scheduled, and no record;
  - undoing an On gives back draft plus the byte-identical original record;
  - a record-only (`conflict_kept`) batch;
  - status and record conflicts, and Revert anyway;
  - **1.0 status-only rows never read the record for the status they write**; the record is cleared only when the restored status is not draft;
  - **the newer-record guard** (amendment (b)): a status-only batch older than the product's record is "changed since", and Revert anyway proceeds; a record written by the undone batch itself never trips it;
  - undo of an On for a scheduled product whose journal (after amendment (a)) holds `future` is revertible, not conflicted;
  - Redo.
- [ ] Red, other suites: `UndoRouteTest`; `HistoryAuditRouteTest` (the record row is labelled "Status before Off" with readable values; the audit `prop` enum is unchanged).
- [ ] Green: `RestoreStatus`, the internal-action registry, `Planner` folding, `BatchChangesController` display.
- **Probe:** `offon:e2-undo-off`, `offon:e2-undo-on`, `offon:e2-undo-conflict` (changed since → skipped; forced → exact), `offon:undo-status-only` (a batch with a status row only on a product that is now Off: forced undo writes the journalled status and never the saved one).
- **Upgrade probe** (amendment (b): real 1.0 rows, not only synthetic ones). New script `tests/probe/offon-upgrade.sh`, documented in `tests/probe/README.md`. It runs on its own throwaway stack whose plugin directory is a volume, not the read-only bind mount, so the plugin can be replaced.
  - Install the 1.0.0 zip (`dist/bulk-catalogue-manager-for-woocommerce-1.0.0.zip`, sha256 `3319e14f…e865f` as the proof recorded; check the full hash first). Seed.
  - Through 1.0.0's own `PATCH /products/{id}`, set a published product to Draft. This is the 1.0 batch.
  - Install the branch build from `bin/build-zip.sh` with `wp plugin install <zip> --force`. That is an upgrade, not an uninstall, and migration 6 → 7 runs.
  - Make the product Private and switch it Off through the manager.
  - Rows:
    - `upgrade:guard-changed-since`: the undo preview of the 1.0 batch lists the product as "changed since", with the saved Private intact;
    - `upgrade:guard-revert-anyway`: forced undo behaves as §3 says;
    - `upgrade:1.0-undo-plain`: a second 1.0 batch on a product never switched Off undoes exactly.
- **Acceptance:** E2 passes. An Off → On → undo → undo chain returns every snapshot field byte for byte. The `upgrade:*` rows pass on a real 1.0.0 → branch upgrade.

### T91 — Preview, confirmation and the bulk panel

- [ ] Red, `PreviewTest`: the `availability` block (every code, the split restores, scaling when estimated, conflicts sample, `null` without a status step); record rows absent from `sample`/`rows`; the summary for record-only objects; T83's key order kept.
- [ ] Red, `BatchFactoryTest`/`ExactScopeTest`: On not pushed to an Off parent's variations; `variations_kept`; Off pushed down still skipped; a parent's Off state changing between preview and Apply gives stale.
- [ ] Red, Jest `preview.test.js`, `confirm.test.js`, `bulk-panel.test.js`: wording, ordering (conflicts first), confirm reasons for exactly the fixture's `confirms` list, the `keep_changed_status` control shown only for a Published status step.
- [ ] Green: `Preview`, `expand()`, `bulk/{preview,confirm,words,operations}.js`.
- **Probe:** `offon:e1-preview` (every code counted over the state set; nothing saved: aggregate checksums of status and meta unchanged by the preview); `offon:d7-on-not-pushed` (Published with Also apply to variations on leaves the disabled variation disabled).
- **Acceptance:** E1 passes; D7 passes in full.

### T92 — The grid: Status is the control

- [ ] Red, server:
  - `HydratorTest`: `off_state` states and labels; one meta prime at most;
  - `CriteriaTest`/`ProductQueryTest`/`SqlEvaluatorTest`: `off`, `changed_outside` and genuine `draft`, each with `isAny`/`isNone`, a foreign record ignored, variations never matched;
  - `fields.snapshot.json`: status `inline: true` plus filter elements.
- [ ] Red, Jest:
  - `status-switch.test.js` runs the shared fixture;
  - `catalogue-inline.test.js`: the confirm appears for exactly the fixture's `confirms` codes, as in bulk, and not for `on` or ordinary changes; the Keep current status button on a 400 conflict; keyboard Enter/Escape;
  - `catalogue-detail.test.js`: the drawer confirm, same PATCH;
  - `catalogue-filters.test.js`: new filter values;
  - `catalogue-fields.test.js`/`catalogue.test.js`: the Status display.
- [ ] Green: §5.
- **e2e,** new `tests/e2e/status-off-on.spec.js`, keyboard only:
  - an inline Off shows the confirm; the cell reads "Off (was Published)";
  - the Off filter finds it and the Draft filter does not;
  - an inline On restores;
  - a product that was Private before Off comes back Private, and the confirm said so;
  - the drawer's Off/On gives the same journal rows as inline (read through `/batches/{id}/changes`);
  - undo from History;
  - axe-core clean on the confirm.
- **e2e,** existing specs: `a11y.spec.js` and `grid-states.spec.js` updated for the new default columns.
- **Probe:** `offon:e3-paths-identical` (inline PATCH, drawer PATCH and bulk batch on three twin products give identical `status`/`availability_off` rows apart from batch and time); `offon:e4-filter`; `offon:e6-listed-editable`; `offon:count-cache` (Off filter count moves right after an inline Off).
- **Acceptance:** E3, E4 (display and filter half) and E6 pass.

### T93 — Listing controls relabel

- [ ] Red: `Fields/RegistryTest` and snapshot (label, choice labels, `default_order` 0); `CsvExportTest`/`ExportRouteTest` (heading "Shown in shop and search"; Status heading unchanged); Jest `catalogue-switch.test.js`, `catalogue-selection.test.js`, `confirm.test.js`, `catalogue-views.test.js`; `SetValueTest`/`WriterTest` string fixtures that name the label.
- [ ] Green: §6; SPEC §5.3; readme; CHANGELOG (the heading change, and saved-view Draft now meaning a genuine draft).
- **e2e:** `persistent-selection.spec.js` and `a11y.spec.js` label updates.
- **Probe:** `offon:e5-independent` (changing Catalogue visibility, or Shown in shop and search, leaves Status and the record alone, and the reverse).
- **Acceptance:** E4 (no Available column, Visible on site not a default column) and E5 pass. The CSV work's baseline note is recorded in TICKETS and the CHANGELOG.

### T94 — Buyer enforcement, scale and records

- [ ] **Probe, `offon` buyer rows.** They use the manager's routes to switch Off, never `ref.php`, and run for anonymous and for a logged-in customer, on the simple product and on the variable product with its disabled variation. They port `probe_offon.py`'s surfaces:
  - A1–A12;
  - B1–B5: carts filled while On and checked after Off; a Store API checkout as the first request; a classic checkout with a nonce from while On; a persistent cart after session expiry; Order again;
  - C1–C2: the control orders while On; every A surface is back after On.
- [ ] **Chapter setup.** The chapter creates its customer users, Check payments, "shipping disabled" and a `[woocommerce_checkout]` page through WP-CLI. It records every WooCommerce option it changes and restores it at the end.
- [ ] **E7:** assert that the active plugins are exactly WooCommerce and this plugin.
- [ ] **e2e,** new `tests/e2e/buyer-off.spec.js` (B6): a fresh context without the admin storage state; Add to cart → checkout while On; Off through the manager's REST; reload; removal notices, no Place order, product page 404. A customer context repeats it.
- [ ] **E8:** the CI wp-env legs 11.1.0 and 11.1.2 run `offon`, and the e2e job runs on `.wp-env.json`'s 11.1.0.
- [ ] **Downgrade rows** (amendment (c)), in `tests/probe/offon-upgrade.sh` from T90. With the branch build, switch Off a product that was Private. Then:
  - `downgrade:no-work-queued`: no batch is queued, running or undoing, and no `bulkcat_run_chunk` action is pending. This is the step 0 gate.
  - `downgrade:off-survives`: install the 1.0.0 zip with `--force`. The product stays draft, and its record is intact and ignored.
  - `downgrade:history-refuses`: 1.0.0's History refuses the Off batch's items as `CANNOT_RESTORE`.
  - `downgrade:old-published-warning`: 1.0.0's Status Published publishes the product regardless of its saved Private. This demonstrates the warning in §12; run it on a copy product.
  - `downgrade:reinstall-restores`: reinstall the branch build, and On restores Private.
- [ ] **E9,** `perf.py` group `offon` on the scale stack:
  - Off, then On, of 5,000 products: at least 300 products a minute at the default chunk, chunk peak under 96 MB;
  - undo of the Off;
  - a grid page with the Status column and the Off filter within the §12.5 grid budget (p95 under 400 ms, at most 20 queries);
  - the Off filter's cold count under 500 ms.
  - Record the numbers in `PERF.md`.
- [ ] **Records:** `.pot` regenerated (`composer make-pot`), readme FAQ ("What does switching a product Off do?", outside writers, uninstall warning), CHANGELOG, TICKETS verification numbers, the maintainer's evidence note.
- **Acceptance:** A1–A12, B1–B6, C1–C2 and E7–E9 pass; every chapter green on both wp-env legs; Plugin Check clean apart from the documented warnings.

## 9. Test map

| Proof test | Where | Ticket |
|---|---|---|
| A1–A12 | probe `offon:a*` | T94 |
| B1–B5 | probe `offon:b*` | T94 |
| B6 | e2e `buyer-off.spec.js` | T94 |
| C1–C2 | probe `offon:c*` | T94 |
| D1, D2, D3, D4, D5, D6, D8, D9, D10 | `StatusSwitchTest` plus probe `offon:d*`, including `d3-second-published` and D10's journal `future` check | T89 |
| Status re-read after save (amendment (a)) | `WriterTest`, probe `offon:d10-scheduled`, `offon:scheduled-never-off` | T89 |
| Newer-record guard on a real 1.0 upgrade (amendment (b)) | `PlannerTest`, `tests/probe/offon-upgrade.sh` rows `upgrade:*` | T90 |
| Downgrade (amendment (c)) | `tests/probe/offon-upgrade.sh` rows `downgrade:*` | T94 |
| D7 | probe `offon:d7-variation-choices` (T89) and `offon:d7-on-not-pushed` (T91) | T89, T91 |
| E1 | `PreviewTest`, Jest `preview.test.js`, probe `offon:e1-preview` | T91 |
| E2 | `PlannerTest`, probe `offon:e2-*` | T90 |
| E3 | `InlineWriteTest`, probe `offon:e3-paths-identical`, e2e `status-off-on.spec.js` | T89, T92 |
| E4 | snapshot, Jest, e2e (T92); relabel (T93) | T92, T93 |
| E5 | probe `offon:e5-independent` | T93 |
| E6 | probe `offon:e6-listed-editable` | T92 |
| E7 | probe `offon` active-plugins row | T94 |
| E8 | CI legs 11.1.0/11.1.2 | T87, T94 |
| E9 | `perf.py --only offon` | T94 |
| E10 | `StatusSwitchTest`, probe `offon:e10-single-control` | T89 |

The earlier E4 and E10, which assumed an Available switch, are replaced by the Status versions above.

## 11. Risks

- **1.0 history with status changes.** Undo of an old status change must not take its status from the record. §3 routes every status undo through `restore_status`, whose status comes only from the journal. The newer-record guard stops a stale 1.0 undo from dropping a later saved status. `offon:undo-status-only`, `PlannerTest` and the real-upgrade `upgrade:*` rows pin it. The status-only record rule is ruling 2, accepted.
- **Journalled status can disagree with the database** (a 1.0 bug). WooCommerce does not read `post_status` back after save, so a product WordPress keeps scheduled is journalled as `publish`. Amendment (a)'s re-read fixes it for every status write, 1.0 paths included.
- **Imports or syncs writing `post_status` while Off.** The record stays. The product shows "Changed outside this screen", Published reports a conflict, Draft re-captures, and Keep current status resolves it. One consequence to know: if an import later sets the product back to draft, record plus draft reads as Off again, and On restores the status saved at Off. That is what the record says; the History and audit rows show the sequence. A site should configure any automatic import not to update status on existing products.
- **Scheduled products depend on WordPress's date rule** (§2). The D10 probe rows must prove it, with the journal recording `future`, before T89 closes; otherwise use the stated fallback.
- **Queued pre-upgrade undo jobs** run their `set_value status` items with switch semantics. One that drafts a product now also writes a record. That is harmless: the draft is the same, and the record only enables an exact On later. Stated in the CHANGELOG.
- **Downgrade with work in flight.** The previous version's `Writer::resolve()` throws on `restore_status` (`Writer.php:~393-397`), and would run a queued Off or On without the record. §12's step 0 gates on this.
- **Saved views** with Status = Draft stop showing Off products, and users whose saved views include Visible on site keep that column. Both are intended and go in the CHANGELOG.
- **WooCommerce 10.x/11.0 installs** see only the notice after updating. Data is kept. Stated in the readme Upgrade Notice.
- **Record integrity.** Only the manager writes the record, with integers and fixed strings. A hand-edited value is read as no record, and the SQL prefix filter could disagree with PHP only for a corrupted value with a valid prefix.
- **Inline confirm UX and accessibility.** It is a new in-cell interaction, covered by Jest keyboard cases and axe in `status-off-on.spec.js`.
- **Grid query budget.** At most one added meta prime per page. T94 measures it against §12.5.
- **Editors bypass Off** (proof Limitation 1). This is expected for staff. A site confirms that no buyer role holds product-edit capability.
- **Uninstall with Remove all data on** leaves Off products as drafts with no saved status. This fails closed; the readme warns.
- **Branch hygiene.** Build on its own branch in its own worktree, from the accepted T85 head.

## 12. Rollback

- **Per change:** undo in History restores status and record exactly (T90). The record lives on the product, so On works after history retention too.
- **Whole feature, before release:** drop the branch. Nothing is merged or deployed by this plan.
- **Whole feature, after a release** (a site's own change process applies; T94's `downgrade:*` rows prove these steps on a fixture):
  0. **No running or queued work.** History must show no batch queued, running or undoing, and Action Scheduler no pending `bulkcat_run_chunk`. The previous version's `Writer::resolve()` throws on a `restore_status` step it does not know (`Writer.php:~393-397`), and a queued Off or On would run without its record. Wait for the queue to empty, or cancel the work, before downgrading.
  1. Before downgrading, list Off products and their saved statuses: `wp post list --post_type=product --meta_key=_bulkcat_availability_off --fields=ID,post_status` plus `wp post meta get <ID> _bulkcat_availability_off` for each, kept in the site's private record.
  2. Deploy the previous package. It has no schema to reverse: there is no table or migration, and `bulkcat_db_version` is unchanged.
  3. Off products stay drafts. Buyers still cannot reach them, which fails closed. Their records are inert.
     **Warning:** in the previous version, Status Published publishes an Off product whatever its saved status. A product saved as Private or Pending review would become public. Tell operators not to use Published on Off products until the ticket-04 version is back; restore those products by hand from step 1's list.
  4. Either reinstall the ticket-04 version later, when On restores from the records as if nothing happened, or restore statuses by hand from the list in step 1 and delete the key with `delete_post_meta_by_key()`.
  5. The previous version's Planner does not know the `availability_off` prop, so it refuses those History items as `CANNOT_RESTORE` ("One of the values this batch changed can no longer be changed here"). That is a safe refusal, not damage.
  6. The previous package declares WooCommerce 10.0 again.
- **Labels only** (T93) are reverted with the code. No stored data depends on them.

## 13. Rulings (maintainer, 28 September)

1. **The six defaults:** replaced by the six the maintainer confirmed (contract summary).
2. **Status-only undo on a product with a record** clears the record when the restored status is not draft, and keeps it when it is draft (§3). **Accepted, with the newer-record guard** (amendment (b)).
3. **Confirmation. Accepted:** inline, drawer and bulk confirm the same cases, from `Outcome::CONFIRMS` (§4, §5).
4. **Inline and drawer Off/On confirm in place** (§5). **Accepted.**
5. **Keep and relabel Visible on site**, and relabel the Show on site / Hide from site quick actions to listing wording (§6). **Accepted.**
6. **The manager never writes `future`.** On and undo write `publish`, and WordPress's date rule schedules or publishes (§2). **Accepted on condition** that D10 passes and the status re-read (amendment (a)) is in place; otherwise use the fallback.
7. **The record carries no variation statuses** in this build (§1). **Accepted**; it is confirmed default 3.

**Amendments applied** (28 September):

- **(a)** The status re-read after save: §2, T89, test map, risks. It must be done before T89 closes.
- **(b)** The newer-record guard and the real 1.0.0-zip upgrade probe: §3, T90, test map, risks. They must be done before T90 closes.
- **(c)** Rollback step 0, the old-version Published warning, and the downgrade probe rows: §12, T94, test map, risks.
- **(d)** Wording: contract item 6, §3's 1.0 compatibility, and the queued pre-upgrade undo risk.
- **(e)** Default-view exports lose the column: §6.
- **(f)** T86's wider client-name check.
