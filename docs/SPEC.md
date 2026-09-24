# Specification — Bulk Catalogue Manager for WooCommerce

Agreed with Wade Beckett in a scoping session on 21 September 2026. This is the shared understanding; change it here before changing code.

Sections are numbered so tickets can cite them, for example §4.2.

## 1. Purpose

A shop owner with thousands of products needs to change many at once. WooCommerce offers a twenty-row list and a bulk edit form covering a handful of fields, on the current page only, with no preview and no way back. This plugin adds one screen under **Products** where the whole catalogue can be filtered and edited in bulk, safely. Three promises shape every decision.

**1.1 Simple first.** The user is a shop owner, not a developer. Every control says what it does in ordinary words, and no raw database key is shown. Where simplicity and completeness conflict, simplicity wins and completeness goes to version 2.

**1.2 Native only.** The plugin edits native WooCommerce product data through WooCommerce's own CRUD classes, on a bare WordPress and WooCommerce install with nothing else present and no site-specific code.

**1.3 Reversible.** Products have no revisions in WooCommerce, so undo is ours to build. Nothing is written until a preview has shown what will change, every change is recorded with its previous value, and every batch can be reversed.

Free under GPL-3.0-or-later, built to WordPress.org directory standards from the first commit.

## 2. Non-goals

- **Per-customer or per-group product access.** Not a WooCommerce concept. Out of scope permanently, not deferred.
- **Creating or deleting products.** Version 1 edits products that already exist.
- **Deleting categories.** The native taxonomy screen keeps that job, because it explains what happens to the products.
- **Importing.** WooCommerce's native CSV importer stays the import path.
- **A spreadsheet.** No formula bar, no fill handle, no free-form cell paste.
- **Orders, customers and coupons**, **front-end output**, and **editing data belonging to other plugins.**
- **Paid tiers, upsells, telemetry or any external service.**

## 3. Definitions

- **View** — the current filters, search, sort, columns, layout and page size.
- **Selection** — an explicit list of identifiers, or *all rows matching the current view*, stored as the view's query rather than as a list.
- **Batch** — a selection plus an ordered list of operations, applied in the background and recorded as a unit. It is the unit of preview, progress, history and undo, and the interface calls it a **change**.
- **Chunk** — a slice of a batch processed by one scheduled action. Never appears in the interface.
- **Journal** — every touched field's before and after value, written inside the same code path that saves the product. Undo reads it. Never appears in the interface.
- **Audit log** — the readable view over the journal: who, when, which product, which field, from what, to what.

## 4. Screens

One admin page, **Products → Bulk Catalogue Manager**, registered with `add_submenu_page()` and rendered as a single React application from `@wordpress/components` and `@wordpress/dataviews`, bundled through `wp-scripts`, with client-side routing between the panels below and a Settings tab holding §11. No WooCommerce admin JavaScript framework is loaded; the page inherits the admin's own look and colour schemes.

### 4.1 Catalogue

**Layout.** A resizable, collapsible category panel on the left, its width and state remembered per user; the DataViews table fills the rest; a sticky selection bar appears when anything is selected. Below 960 pixels the panel becomes a button opening it as a sheet.

**Controls.** Search on name and SKU. Filters for category, tag, type, status, catalogue visibility, featured, stock status, whether stock is managed, price and stock ranges, tax class, shipping class and last modified, shown as chips reading as sentences such as "Stock is 0 or less". A category filter includes every category below the chosen ones, as choosing a branch in the category panel does. Multi-column sort, column chooser, density, export. Page size 25, 50 or 100, default 25. A table or grid layout, table being the default and the layout the design targets. A mode switch between **Products**, **Products and variations** and **Variations only**.

**Columns.** By default thumbnail, name linking to the classic product editor, SKU, status badge, the **Visible on site** switch, regular and sale price, stock status with quantity, and categories; which columns appear is the owner's choice. Each row also carries a checkbox and a menu with *Edit details*, *Open in product editor*, *View on site* and *History for this product*. An inline cell opens on click or Enter, commits on Enter or blur, reverts on Escape.

**Detail panel.** A `DataForm` drawer holding every version 1 field for one product, including the four-way catalogue visibility field and the full status field, so the row stays simple. Saving uses the same engine as a bulk action, journalled and undoable, as a batch of one.

**Selection.** A header checkbox selects the page, then one line appears above the table: "All 25 on this page are selected. Select all 1,284 products matching these filters."

**States.**

- *Loading* — skeleton rows at real row height, so the page does not jump.
- *Empty, no products* — "There are no products yet." with a link to Products → Add New, and nothing else on screen.
- *Empty, filtered* — "No products match these filters." with **Clear filters** and a list of the active filters, so the owner can see what excluded everything.
- *Error* — a notice saying what failed with **Try again**; the previous rows stay visible behind it rather than being cleared.
- *Change in progress* — a banner with the operation, a progress bar, a live count, cancel and a link to History; affected rows are pending and read-only.

### 4.2 Variations in the grid

In **Products and variations** mode a variable row carries a disclosure control and a count. Expanding fetches its variations in one request and inserts them as indented child rows sharing the parent's columns, each independently editable and selectable, with the attribute summary such as "Blue / Large" in the name column. Expansion is announced to assistive technology and is not persisted.

In **Variations only** mode the grid lists variation rows, each showing its parent name. Filters that suit a variation apply directly; category, tag, featured and visibility filter by the parent. This is the mode for "every variation that is out of stock". Expanding a product with no variations shows "This product has no variations yet."

### 4.3 Bulk action panel

A slide-over on the right, never a modal that hides the rows it is about to change, in four steps on one scrollable form.

1. **Scope.** "24 selected products", or "All 4,312 products matching the current filter" with the active filters listed.
2. **Action.** Grouped as Pricing, Stock, Organisation, Shipping and tax, and Publishing. Choosing one reveals only its controls plus a plain description such as "Takes the regular price of each product and reduces it by the percentage you give." **Also apply to variations** appears where the field supports it — a field a variation has a value of its own for (§5.2 last column: per variation, or conditional such as status and stock quantity) — starting where the shop's setting for the field's group puts it (§11), with **Skip rows where the value is already correct**, on by default. The panel sends it as the step's `apply_to_variations` argument; left out, the server takes the same default; on for a parent-only or derived field, the step is refused with a plain sentence.
3. **Preview.** **Preview changes** produces §4.4. Nothing is written yet.
4. **Apply.** Enabled only after a preview that is still current.

**States.** Idle, configuring, previewing, preview ready, preview empty which disables Apply, blocked by validation with the reason in place, stale where the selection or settings changed so Apply becomes **Preview again**, applying, done. A disabled action carries a one-line reason.

**After apply.** The panel closes and progress moves into the selection bar: "Updating 1,284 products — 312 done." The owner may leave the screen; work continues on the server. On completion: "1,284 products updated. **Undo this change** · **View in History**".

### 4.4 Preview

In this order.

1. A plain summary: "This will change the regular price on 1,284 products and 3,915 variations."
2. Counts: will change, already at the target value, cannot change with the reason for each.
3. A sample table — product, field, current value, new value — old struck through, new in bold, with a note on rows that will be skipped.
4. A warning block: a sale price above its regular price, a price falling to zero or below, stock going negative, a SKU duplicating another product's.

**Controls.** Sample size 25, 100, or every row up to 1,000. Filter to changed rows only. Download the preview as CSV before applying.

**States.** Computing; ready; nothing to do; partially computable, listing the products that could not be read and excluding them.

### 4.5 Confirmation policy

Apply is a single click under 200 rows. Above that threshold, which is a setting, Apply restates the count and needs one more click. Anything that drafts, hides or empties a field always confirms, whatever the count. Nothing requires typing a word to confirm; that is friction without safety, because every change is reversible.

### 4.6 History and audit log

Batches newest first: when, who, what, how many rows, outcome, and whether it is still reversible, filtered by date range, user, action type and status. Expanding one shows its changes — object, field, old value, new value, result — paginated and searchable; there is no second tab for that detail. **Undo this change** runs through the preview screen first. A batch's changes export as CSV. A separate **Audit log** tab lists field changes across all batches, filtered by product, field, user and date range.

**States.** Reversible; partially reversible, with the conflict count; in progress, showing a progress row instead of Undo; completed with errors, expanding to the failing rows and reasons; not reversible, with the reason — outside the retention window, already undone, or pruned. An undo batch is listed as "Undo of #418" and can itself be undone, offered as **Redo**.

### 4.7 Category manager

The tree at full width, with counts and a details pane. Create, rename, edit slug and description, change parent, reorder within a parent. Drag to re-parent and reorder, with an equivalent **Move to…** dialog on every node's menu for keyboard and screen reader users. Dropping a product selection onto a node adds that category to every selected product as an ordinary previewed batch.

**States.** Empty — "You have no product categories yet." with **Create your first category**. Dragging — a drop indicator and a preview of the new path. Blocked — "A category cannot be moved inside itself." Read-only without `manage_product_terms`.

### 4.8 Error wording

Errors name the thing, say what happened and say what to do, in one or two short sentences, with no codes in the main text; a technical line sits behind a **Show details** toggle. Examples:

- "We could not save 3 products because another user was editing them. Try again in a moment."
- "Stock quantity has to be a whole number. Enter a number like 12."
- "A product with the SKU SHOE-42 already exists, so this SKU was not applied to 2 products."

## 5. Fields

### 5.1 The field registry

Version 1 edits single-value native fields. Each is declared once in a PHP registry supplying its type, storage, sanitiser, validator, REST schema fragment and DataViews definition. Route `args`, with their `validate_callback` and `sanitize_callback`, are generated from it, so grid, REST, preview and writer cannot disagree. The client reads it through `GET /fields` and hard-codes no field list.

### 5.2 Field table

| Field | Type | Storage | Edit kinds | Variations |
| --- | --- | --- | --- | --- |
| Name | text | `post_title` | set, find and replace | Read-only; derived |
| Status | choice | `post_status` | set | Publish and private only |
| Catalogue visibility | four choices | `product_visibility` terms `exclude-from-catalog`, `exclude-from-search` | set | Parent only |
| Visible on site | switch (§5.3) | the same terms | set (inline) | Parent only |
| Featured | boolean | `product_visibility` term `featured` | set (inline) | Parent only |
| Categories, tags | terms | `product_cat`, `product_tag` | set, add, remove | Parent only |
| Regular price | decimal | `_regular_price`, lookup `min_price`/`max_price` | set (inline), adjust, round, copy from sale | Per variation; a variable parent's price is derived and read-only |
| Sale price | decimal | `_sale_price` | set (inline), clear, adjust, round, copy from regular | Per variation |
| Stock status | choice | `_stock_status`, lookup | set (inline) | Per variation |
| Manage stock | boolean | `_manage_stock` | set | Per variation |
| Stock quantity | integer or empty | `_stock`, lookup | set (inline), adjust | Only where that variation manages stock |
| SKU | text | `_sku`, lookup | set (inline), find and replace, prefix, suffix | Own value; uniqueness via `wc_product_has_unique_sku()` across products and variations together |
| Weight | decimal | `_weight` | set (inline), adjust, clear | Per variation |
| Length, width, height | decimal | `_length`, `_width`, `_height` | set (inline), clear | Per variation |
| Shipping class | term | `product_shipping_class` | set | Per variation, including "same as parent" |
| Tax status, tax class | choice | `_tax_status`, `_tax_class` | set | Per variation |
| Sold individually | boolean | `_sold_individually` | set | Parent only |
| Reviews | boolean | `comment_status` | set | Parent only |

Read-only columns: ID, type, thumbnail, rendered price, dates created and modified, total sales, variation count.

### 5.3 The "Visible on site" switch

Catalogue visibility has four values, three more than most owners want. One switch: on means shop and search, off means hidden from both. A product in one of the two middle states renders in a third, neutral position labelled **Partly**, with a tooltip naming the exact state, and clicking it offers the two real choices. Nothing flattens a middle state into a full state without the owner choosing it.

Visibility and `post_status` are separate fields, so a grey **Draft** or **Pending** badge sits beside the switch where the status would keep the product off the site anyway. The interface never tells an owner a draft is visible.

### 5.4 Value rules

Prices are stored through `wc_format_decimal()` at the shop's precision. Empty is a real value distinct from zero: clearing a sale price writes an empty string, as WooCommerce expects. A sale price not below its regular price fails validation and is reported in the preview; the row is skipped rather than cleared, and the form offers "clear the sale price where it is not below the regular price" as an explicit choice. A result below zero is refused and the row reported as skipped, never clamped. A name changed by find and replace does not change the slug.

## 6. Bulk actions

Each action is a class implementing one interface: describe itself, validate its inputs, compute a result for one object without writing, apply that result. Preview and writer call the same computation, so what is previewed is what is written.

- **Set value** — any field in §5.2, or clear it where nullable.
- **Increase or decrease** — price, stock quantity, weight or dimensions, by amount or percentage. Objects with no current value are skipped and counted.
- **Round** — to decimals, to the nearest step, or to a charm ending such as .95 or .99, up, down or to nearest. Rounding may run in the same action as an increase or decrease, because that is the common pricing job.
- **Copy regular price to sale price**, or back, optionally with a percentage discount in the same step, and **clear sale price**.
- **Find and replace** in name or SKU: plain text, optionally whole word, optionally case sensitive. No regular expressions in version 1, because a preview cannot make a bad expression safe for a non-technical owner. **Add prefix or suffix** to SKU.
- **Add or remove categories** and **tags**, leaving other terms alone. **Replace** sets the full list and says so in bold in the preview.

A batch holds an ordered list of at most twenty operations applied to each object in turn, so "increase regular by 12 per cent, then round to .95" is one batch, one preview and one undo.

Every action on a field a variation has its own value for carries **Also apply to variations** (the step argument `apply_to_variations`), starting from the shop's setting for the field's panel group — on for Publishing (status), off for Pricing and Stock, off for any other group (catalogue visibility belongs to the product, so it never reaches a variation) — and every action carries **Skip rows where the value is already correct**, on by default, so an unchanged product is neither re-saved nor logged.

## 7. Category tree

`get_terms()` on `product_cat` with `hide_empty => false` and `update_term_meta_cache => true`, built into a nested array in PHP and cached in a transient keyed by the taxonomy's last-changed value, invalidated on `created_term`, `edited_term` and `delete_term`. Each node shows its own count and its count including descendants, from one grouped query rather than one per node, and counts respect the other active filters, so the tree doubles as a summary of the current result.

Selecting a node filters the grid; a toggle chooses *this category only* or *this category and its children*, defaulting to including children; Ctrl or Cmd click filters to several at once.

Re-parenting uses `wp_update_term()`, and a move that would make a term its own ancestor is rejected on the server as well as in the browser. Order is a `bulkcat_`-prefixed term meta value affecting this tree only, not imposed on the storefront. Every term write is journalled and undoable like a product write. Only single-parent hierarchy is supported, because that is what WordPress taxonomies are.

## 8. Variations

Four rules govern variations everywhere.

1. **A parent never silently speaks for its variations.** Where variations disagree the parent cell shows a range or "Mixed" and is not editable in place.
2. **Selecting a parent does not select its variations.** **Also apply to variations** is what reaches them, and the preview states how many it adds.
3. **A field that does not exist on a variation is never offered on a variation row.** It is greyed with the reason, "Categories belong to the product, not to each variation", never silently hidden.
4. **Expansion is capped at 50 variations** with **Show all**.

A variable parent has no price of its own to set. Stock quantity applies only where that variation manages stock; a row that does not is reported as skipped with its reason rather than ignored. SKU uniqueness is checked across products and variations together, and a clash fails that row without failing the batch. After any variation write the parent's price range and stock summary are resynchronised through WooCommerce's own data store, never by writing parent meta directly.

A parent carries its variation count from a grouped count query, so variation rows are fetched only on expansion. **Also apply to variations** expands the selection when it is materialised, resolving each selected variable product to its published and private variations (menu order, then id), so the recorded batch contains every object it touched and the undo is exact. A variation the selection already holds is not added twice and keeps every step. An added variation carries only the steps whose switch is on, as its own item operation list. The parent keeps its row for the steps it takes, and is left out when it takes none — every step has the switch on and changes a price, which a variable product takes from its variations — so its row cannot only report a skip. A step with the switch on that meets a variable product without a value of its own is skipped, not failed, and a choice a variation cannot take (a status other than published or private) pushed down by the switch is skipped with its reason; chosen on the variation itself it fails. In **Variations only** mode nothing is expanded. The variations added count toward the 100,000-object limit. Parent-only fields are never pushed down, and variation-only fields never written to a parent. Attribute editing is version 2.

## 9. Preview, journal, history and undo

### 9.1 Preview

`POST /bulkcat/v1/preview` returns the computed change for the sample plus totals: matched, would change, already at target, would fail validation. With **Also apply to variations** on, the variations it adds are computed and sampled too, `totals.by_type` splits matched and would change into products and variations, and `summary` says it in one sentence ("Regular price will change on 1,284 products and 3,915 variations."); the selection's `current_count` stays the selection's own objects, before expansion, and `variations_added` and `parents_left_out` say what the switch did. It uses the same lean query and operation classes as the writer and writes nothing. A result is bound to a hash of the selection and settings; changing either invalidates it. For a filter selection it also returns the filter's current count, and if that has moved by the time Apply is pressed the dialog says so and asks again. Every bulk apply sends the preview's hash — for ticked rows (an `ids` list or a token made from one) as much as for a filter, so no bulk write reaches the queue unpreviewed — and a filter apply also the count it showed; the server refuses 409 `bulkcat_preview_stale` when the hash is missing or no longer what a preview gives now, and 409 `bulkcat_selection_changed`, naming the previewed and the current count, when the filter now matches a different number of products, unless the request carries `confirm_changed: true`. The previewed count is stored on the batch.

### 9.2 Schema

Applying creates a batch row and materialises the selection into `bulkcat_batch_items` before any work starts, so a filter whose membership shifts mid-run cannot change what the batch does, and so undo is exact.

```
{prefix}bulkcat_batches
  batch_id      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
  uuid          CHAR(36)            -- client request id, for idempotent submission
  user_id       BIGINT UNSIGNED
  created_gmt   DATETIME
  finished_gmt  DATETIME NULL
  status        VARCHAR(20)         -- queued|running|complete|partial|failed|cancelled|undoing|undone
  source        VARCHAR(20)         -- bulk|inline|tree|undo
  undo_of       BIGINT UNSIGNED NULL
  operations    LONGTEXT            -- JSON
  selection     LONGTEXT            -- JSON snapshot of the filter or id list
  total_items   INT UNSIGNED
  previewed_items INT UNSIGNED NULL -- the count the preview of a filter selection showed; NULL for an id selection
  done_items    INT UNSIGNED
  changed_items INT UNSIGNED
  failed_items  INT UNSIGNED
  chunk_size    SMALLINT UNSIGNED   -- NOT NULL DEFAULT 0; size the runner settled on, 0 until the first chunk
  note          VARCHAR(255)        -- NOT NULL DEFAULT ''; plain reason the chunk size changed
  actions       VARCHAR(255)        -- NOT NULL DEFAULT ''; action ids the operations use, comma-delimited with a comma at each end (",adjust,round,"), for the history's action-type filter
  pruned_gmt    DATETIME NULL       -- when retention pruning removed the batch's journal; the batch stays listed, not reversible
  KEY created_gmt (created_gmt), KEY status (status), KEY user_id (user_id)

{prefix}bulkcat_batch_items
  item_id     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
  batch_id    BIGINT UNSIGNED
  object_id   BIGINT UNSIGNED
  object_type VARCHAR(16)           -- product|variation|term
  state       VARCHAR(12)           -- pending|done|skipped|failed
  attempts    TINYINT UNSIGNED
  claim_token CHAR(32) NULL         -- claim lease held by the chunk working the item
  claimed_gmt DATETIME NULL         -- when that claim was taken
  message     VARCHAR(255)          -- NOT NULL DEFAULT ''; plain reason the item failed or was skipped
  operations  LONGTEXT NULL         -- JSON; the item's own operation list (an undo batch, or a variation Also apply to variations added whose other steps do not reach it), NULL to take the batch's
  KEY batch_state (batch_id, state), KEY object (object_id)

{prefix}bulkcat_changes
  change_id    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
  batch_id     BIGINT UNSIGNED
  object_id    BIGINT UNSIGNED
  object_type  VARCHAR(16)
  prop         VARCHAR(64)          -- field key from the registry
  old_value    LONGTEXT             -- JSON encoded, preserving type and null
  new_value    LONGTEXT
  modified_gmt DATETIME             -- the object's post_modified_gmt at write time
  applied_gmt  DATETIME
  result       VARCHAR(12)          -- applied|skipped|failed
  message      VARCHAR(255)
  KEY batch_id (batch_id), KEY object_prop (object_id, prop), KEY applied_gmt (applied_gmt)
```

Created with `dbDelta()` on activation and on a version bump held in `bulkcat_db_version`.

### 9.3 Journal and audit log

The journal is written inside the same code path that saves the product, recording each touched field's before and after value with the object's `post_modified_gmt` at that moment. Untouched fields are not recorded, so the log stays proportionate to the change. Inline edits and detail-panel saves are one-item batches. Changes made outside the plugin are not recorded in version 1, and the screen says so.

### 9.4 Undo

Undo creates a batch with `source = undo` and `undo_of` set, whose operations are the recorded changes in reverse order, written back through the same CRUD path, queue and journal, with its own preview. Before reverting, the current value is compared with `new_value`; if they differ the item is skipped and reported as "changed since this batch ran". The preview lists conflicts first, and **Revert anyway** is off by default. The result reports plainly: "12 of 1,284 were changed again after this batch and were left alone." A partial, failed or cancelled batch can be undone for the part that was applied.

`POST /batches/{id}/undo` with `preview: true` returns the plan's counts and a page of its objects, conflicts first, with a `plan_hash`; applying sends the client `uuid`, that hash (409 `bulkcat_preview_stale` when the plan has moved on since) and `force` for **Revert anyway**. The undo batch's items each carry their own operations (`bulkcat_batch_items.operations`), materialised before any work; the batch undone is `undoing` while it runs, `undone` once it completes or finishes partial, and back to its old status if the undo fails or is cancelled. The history list shows reversibility from the cheap signals only (`conflicts: null`, no product read); the single batch read counts conflicts.

### 9.5 Retention

`bulkcat_history_retention_days` defaults to 90, adjustable between 7 and 730, or kept indefinitely. A daily action, `bulkcat_prune_history`, removes batches past the limit in bounded chunks. A pruned batch stays in the list, marked no longer reversible. Running batches are never pruned.

## 10. Saved views and export

**Saved views.** The DataViews view object plus the category selection is saved by name in the `bulkcat_views` user meta as JSON. Views can be duplicated, renamed and reordered, and one marked as the default the screen opens with. They are per user, not shared in version 1.

**Export.** A CSV of the current view: the visible columns in order, for every row matching the current filter, not only the current page. It is a read, generated on request, streamed in pages so memory stays flat, and stored nowhere. UTF-8 with a byte-order mark, every field quoted, and a field beginning `=`, `+`, `-` or `@` prefixed with an apostrophe so a spreadsheet does not treat exported data as a formula. History and audit rows export the same way.

## 11. Settings

One option, `bulkcat_settings`, an array sanitised on save against an explicit schema.

| Setting | Default | Effect |
| --- | --- | --- |
| Who can use this screen | Shop managers and administrators | The alternative is administrators only |
| Rows per page | 25 | 25, 50 or 100 |
| Products per background batch | 50 | 10 to 100; lowered if a chunk nears the time limit |
| Variations per background batch | 100 | 10 to 100; lowered with the product size |
| Keep history for | 90 days | 7 to 730, or indefinitely |
| Confirm above this many rows | 200 | The second-click threshold |
| Apply to variations by default | Status and visibility on, price and stock off | Starting position of the switch, per field group: Publishing reads status, Pricing price, Stock stock, anything else starts off; catalogue visibility belongs to the product, so its switch reaches no variation today |
| Record unchanged rows in the audit log | Off | On makes the log complete but much larger |
| Show the category panel by default | On | |
| CSV separator | Comma | Comma or semicolon |
| Remove all data when the plugin is deleted | Off | Off keeps history and undo records on reinstall |

## 12. Engine

### 12.1 REST

Routes are registered under `bulkcat/v1` with `register_rest_route()`. Every route declares an explicit `permission_callback`; none returns `true`. Authentication is the logged-in cookie plus the `wp_rest` nonce through `apiFetch`'s nonce middleware. There is no REST API key or application password path, and no request outside the site. The plugin does not extend the WooCommerce REST namespace.

The routes are `/fields`; `GET /products` returning `{ items, pagination: { page, perPage, total, totalPages } }`; `PATCH /products/{id}`, recorded as a one-item batch and answering `{ item, batch_id, status, results }` — the updated row in the `/products` item shape, the batch's id, its final status (complete, partial or failed) and one result per step; `GET /products/{id}/variations`, returning `{ items, pagination }` in WooCommerce's variation order with each variation's parent name and attribute summary, and 404 for an id that is not a product; `POST /selection`, resolving a filter to a count and a token (the selection itself is kept on the server in a `bulkcat_selection_` transient; the token is its random id and a hex HMAC of it, bound to the user and an expiry); `POST /preview`; `/batches` to queue and to list, `/batches/{id}` for live progress, `/batches/{id}/changes`, and `/batches/{id}/cancel` and `/undo`, the latter accepting `force`; `/audit`; `/categories`, `/categories/{id}` and `/categories/{id}/move`; `/views` and `/views/{id}`; `/export`; and `/settings`.

`/products` accepts paging with `per_page` capped at 100, `search`, `orderby`, `order`, `mode`, `fields[]` and one argument per filter in §4.1. Every argument is generated from the registry with its own type, sanitise and validate callbacks, so a malformed or unknown parameter is rejected before any handler runs. The batch body carries a client-supplied `uuid`, the preview's `preview_hash` (required for every selection), and with a filter selection token the preview's `preview_count` and, after the owner has seen a changed count, `confirm_changed` (§9.1). Other limits: 20 operations per batch, 100,000 objects per selection (the variations **Also apply to variations** adds included), 100 terms per term operation.

### 12.2 Reads

Reads never instantiate `WC_Product`; building 100 product objects per page costs hundreds of queries. A query builder produces one prepared `$wpdb` statement against `wp_posts` joined to `wc_product_meta_lookup`, adding `wp_term_relationships` joins only when a taxonomy filter is present, and returning identifiers and sort keys only. The lookup table carries SKU, minimum and maximum price, stock status, stock quantity, rating and total sales, covering most sorts and filters without touching `wp_postmeta`.

Those identifiers are hydrated in a fixed number of queries regardless of page size: `_prime_post_caches()`, `update_meta_cache()` for the visible columns' meta keys, one `wp_get_object_terms()` across `product_cat`, `product_tag`, `product_visibility` and `product_shipping_class`, and one grouped variation-count query.

Counts are a separate `COUNT(*)` on the same conditions, cached 60 seconds in a transient keyed by a hash of the filter, because `SQL_CALC_FOUND_ROWS` is slow and deprecated in MySQL 8. No query is issued without a `LIMIT`; column and table names come from an allow-list; nothing from a request is interpolated into SQL; no index is added to a core table. The direct database access here is deliberate and annotated, with results cached in an object-cache group invalidated by the products' last-changed value, and the tree by the taxonomy's.

### 12.3 Writes

Writes go through WooCommerce CRUD — `wc_get_product()`, typed setters, `save()` — never direct SQL and never `update_post_meta` for a product field, which keeps the lookup table, term relationships, transients and the `woocommerce_update_product` hook correct for every other plugin on the site. Term changes use `wp_set_object_terms()` and `wp_update_term()`.

Each chunk runs inside one Action Scheduler action in the `bulk-catalogue-manager-for-woocommerce` group (the full slug, so no other plugin can share it), wrapped in `wp_defer_term_counting( true )` and flushed at its end. Each object is loaded, each operation applied in order, before and after journalled, and the object saved only if something changed. A failure marks that item failed and does not stop the chunk. Chunks are idempotent. Just before `save()` the writer records an intent row per changed field (result `pending`, holding the computed new value) and settles it to `applied` or `failed` once `save()` returns or throws. A retry that finds settled rows for an object skips it; one that finds intent rows — the run died after WooCommerce saved but before the journal caught up — computes nothing again, but writes each recorded absolute value, or leaves it where the object already holds it, and settles the rows. So a retry after a timeout cannot double-apply a percentage increase, even when the timeout struck inside a slow save hook. An intent row left unsettled (the batch was cancelled before a retry) is planned by undo like an applied one.

### 12.4 Scheduler

Action Scheduler ships inside WooCommerce and is used through its public `as_*` functions only. The plugin does not touch WooCommerce's internal batch-processing classes, which carry no backwards-compatibility guarantee.

The model is a chain, not a fan-out, because Action Scheduler's default concurrency is one. Creating a batch enqueues one `bulkcat_run_chunk` action through `as_enqueue_async_action()`; each run claims the next chunk of pending items with a locking update, processes them, updates the counters and enqueues its successor if items remain. A claim is released after a timeout and retried up to three times. Each claim schedules its own retry six minutes ahead (the five-minute lease plus one minute), unscheduled when the chunk ends normally, so a chunk killed mid-way is picked up without anyone watching; a claim older than five minutes is stale and the next run takes its items over. So that a slow run whose lease went stale never works the same items as the run that took them over, each item is claimed again on its own just before it is written (`state` from `pending` to `working`, only while the chunk's `claim_token` is still the run's); an item the run no longer holds is skipped, neither written nor marked, and a `working` item whose claim has gone stale is put back to `pending` for the next run. Past 80 per cent of the lease a run claims no further item and releases the rest of its chunk to its successor. This survives a crash and gives accurate progress without scheduling hundreds of actions up front.

Default chunk size is 50 products or 100 variations (the settings *Products per background batch* and *Variations per background batch*), floor 10, ceiling 100, each filterable through `bulkcat_batch_size`, which receives the size, the batch id and the kind, `product` or `variation`. A chunk holds one kind: products (and category items) while any is left to claim, up to the product size, then variations, up to the variation size, so a mixed batch counts its variations against the variation size. A chunk using more than 70 per cent of the PHP time limit halves the size for the rest of the batch and records why; the batch records the product size and the variation size follows it in proportion, so both halve and grow back together. With no PHP time limit (WP-CLI, some cron runners) the lease stands in for it. Background work checks every object, each variation and its parent too, against the batch's recorded user before writing it. Cancelling sets the status and the next run exits without claiming; applied chunks stay applied and the batch remains undoable. The queue runner is not reconfigured on the owner's behalf — `action_scheduler_queue_runner_batch_size` and `_concurrent_batches` are left alone — and chunk size is the only knob.

### 12.5 Performance targets

On 20,000 seeded products and 70,000 variations, PHP 8.3, MySQL 8, no persistent object cache. Asserted in the performance suite, not left as intentions.

- Grid page of 25 rows with three filters: 95th-percentile server time under 400 ms, no more than 20 queries, constant with respect to page size.
- Grid page of 25 rows with three filters in products-and-variations mode: 95th-percentile server time under 750 ms, filtered count under 1 second cold, the same query limit. Measured 514 ms p95 and 669 ms cold on a loaded host (T76, `tests/probe/PERF.md`). The mode lists products and their variations together, so three non-selective filters match most of 90,000 rows, and sorting them by name has no index on `post_title` to use (§12.2 adds no index to a core table); the work grows with the matches, not the page.
- Filtered count under 500 ms cold, category tree under 300 ms cold, whole-catalogue selection resolved under 5 seconds.
- At least 300 products a minute written at the default chunk size, peak memory under 96 MB per chunk.
- CSV export of 20,000 rows: first byte under 2 seconds, complete under 60 seconds, memory flat. Built JavaScript under 400 KB compressed, loaded only on this screen.

### 12.6 Failure modes

- **Partial failure.** The batch finishes as *completed with errors*, each failure listed with its reason. The successful part is journalled and undoable on its own.
- **Timeouts.** A chunk killed mid-way leaves completed objects journalled and its chunk row incomplete; the retry continues with the remainder. Chunk size is bounded to fit a conservative execution limit.
- **Concurrent edits.** Batches touching the same product are serialised by the queue's single concurrency. A product edited in the native editor is not locked; the journal records the value immediately before the plugin's write, which is what undo needs.
- **Undo conflicts.** Reported, not overwritten, unless **Revert anyway** is chosen.
- **Stale selection.** A view-based selection resolved at batch start may include products created after the preview; the difference from the previewed count is stated on the result.
- **Abandoned inline edit.** An inline batch is written in the request that opened it; if that request dies, the batch stays running with no runner behind it. The next read of it after ten minutes without activity marks it failed, its unwritten item failed with a plain reason, and says why in its note.
- **Scheduler stalled.** If the queue has not run for five minutes while a batch is pending, the screen says so and points at the scheduled actions tool rather than showing false progress; the batch applies nothing.
- **Duplicate submission.** Batch creation is idempotent over the `uuid` for sixty seconds, so a double-click creates one batch.

## 13. Security

- Page and read routes require `manage_woocommerce`, filterable through `bulkcat_capability`, or `manage_options` under the administrators-only setting; category writes `manage_product_terms`, plus the per-term capabilities core checks — `edit_term` on the category edited or moved, the taxonomy's `edit_terms` to create one; settings writes `manage_options`.
- Write routes also require `edit_products`, and every object is checked with `current_user_can( 'edit_post', $id )` before it is written, honouring `edit_others_products`; a variation also needs `edit_post` on its parent, whose price range and stock its save resynchronises, and a change to categories, tags or shipping class needs the taxonomy's `assign_terms` (`assign_product_terms`). A product the user may not edit is skipped and reported, never silently dropped. An undo plan checks the person undoing, not the batch's original author.
- Background work runs with no current user, so each chunk re-checks every object against the batch's recorded `user_id` with `user_can()`. A batch cannot give its initiator more rights than they had at queue time, and an item whose permission was revoked is skipped and recorded.
- `wp_create_nonce( 'wp_rest' )` is localised at page load and refreshed through the Heartbeat API; a `rest_cookie_invalid_nonce` response triggers one automatic refresh and retry before the application reports an error.
- Input passes the registry's sanitiser and validator. All SQL uses `$wpdb->prepare()`, with interpolated identifiers cast to integers. PHP escapes at output, the JavaScript renders text as text, and `dangerouslySetInnerHTML` is never used. A name written without `unfiltered_html` passes through `wp_filter_post_kses`; in background work that is the batch's recorded user's `unfiltered_html` (`user_can()`), as in their own edit, and a category name or description follows the user its batch is recorded for. Unexpected errors are written to the WooCommerce log and never returned to the client.
- Export applies the formula-injection guard in §10. No external HTTP request, no remote asset or font, no public route, no data collected.

## 14. Accessibility

The target is WCAG 2.2 AA, verified rather than asserted.

- A real table with a caption, sort state in `aria-sort`, and the product name cell as each row's header. Selection checkboxes carry the product name in their accessible name, not "select row".
- Everything is operable by keyboard alone, including the whole path from filter to preview to apply to undo. Arrows move between cells, Enter begins an inline edit, Escape cancels, Tab leaves. The slide-over and dialogs trap focus, close on Escape and return focus to whatever opened them.
- The category panel is an ARIA tree with roving tab index, arrow keys, Home and End, and type-ahead; every drag has the **Move to…** dialog as its keyboard equivalent.
- Progress is announced politely through `wp.a11y.speak` at milestones rather than every tick, the result once, errors assertively. Switches carry the product in their accessible name, so a screen reader says "Visible on site, Blue Widget".
- Colour is never the only signal; every status is a word as well as a badge. Text meets 4.5:1 and components 3:1 in both admin colour schemes, targets are at least 24 by 24 pixels, focus appearance meets the 2.2 criterion, and animation respects `prefers-reduced-motion`.
- Usable at 320 pixels wide and at 400 per cent zoom, the panel collapsing to a control, and every control has a visible label.

## 15. Packaging and WordPress.org compliance

- **Identity.** Slug and text domain `bulk-catalogue-manager-for-woocommerce`, namespace `BulkCatalogueManager`, prefix `bulkcat_` for options, hooks, tables, term meta and transients, `BULKCAT_` for constants, `bulkcat-` for CSS, script handles and element ids, REST namespace `bulkcat/v1`, browser global `bulkcatSettings`. The directory (and Plugin Check) requires a prefix of four or more characters; `bcm` was renamed to `bulkcat` before the first release (2026-09-24), with no migration because nothing was installed. British spelling throughout. The name does not begin with "WooCommerce", which the directory rejects, and claims no affiliation.
- **Headers.** `Requires at least: 7.0`, `Requires PHP: 8.1`, `Requires Plugins: woocommerce`, `WC requires at least: 10.0`, `WC tested up to`, `Text Domain`, and `License: GPL-3.0-or-later` with `License URI: https://www.gnu.org/licenses/gpl-3.0.html`. HPOS and Cart and Checkout Blocks declared through `FeaturesUtil::declare_compatibility()`.
- **Layout.** Composer PSR-4 autoload with a plain fallback autoloader, `src/`, `assets/src` built by `wp-scripts` into `assets/build`, `tests/`, `docs/`, `readme.txt`, `CHANGELOG.md`, `uninstall.php`, phpcs configuration, `.distignore` and `bin/build-zip.sh`.
- **DataViews.** Bundled through `wp-scripts` from the tag matching the supported WordPress major, because it is not a registered core script handle and unlocks private `@wordpress/components` APIs that must match the host. One tag is pinned per major, and a runtime mismatch shows an admin notice rather than a broken screen.
- **Standards.** WordPress Coding Standards under PHPCS, `@wordpress/eslint-plugin` and Prettier, `declare( strict_types = 1 )` throughout, no `eval` and no debug output. Every string wrapped with a literal text domain, translator comments on every placeholder, `_n()` for plurals, escaping at output, JavaScript strings through `@wordpress/i18n` with `wp_set_script_translations()`, and a generated `.pot`.
- **readme.txt.** Correct stable tag and tested-up-to, a short description under 150 characters, screenshots, changelog, and an FAQ covering what the plugin writes and how to reverse it. No marketing claims and no comparison with any other plugin.
- **No external calls.** No CDN, remote font, update check or analytics. Dependencies are bundled and GPL-compatible, and the built JavaScript ships beside its readable source and build configuration so a reviewer can reproduce it. Plugin Check runs on every push and must be clean; any unavoidable notice, such as the direct database access on the read path, carries an inline justification and appears in the release checklist rather than being hidden.
- **Data lifecycle.** Tables created with `dbDelta()` under a versioned schema option; scheduled actions registered on activation; deactivation only unschedules the prune. `uninstall.php` removes the three tables, every `bulkcat_` option, user meta, transient and term meta, and all scheduled actions in the plugin's `bulk-catalogue-manager-for-woocommerce` group, on every site of a network, unless *Remove all data when the plugin is deleted* is off, which is the default.
- **Behaviour when inactive.** If WooCommerce is absent or below the minimum, the plugin registers nothing but one admin notice. A fatal error is not possible.

## 16. Testing

**16.1 PHPUnit** for the registry, every operation's computation including rounding, percentage and negative edges, sanitisers and validators, the query builder's SQL, the selection materialiser and the undo conflict rules.

**16.2 Integration** in `@wordpress/env`: each route's authentication, capability and response shape; a batch's lifecycle including chunk chaining, resumption after a killed chunk, retry idempotency and cancellation; an undo round trip asserting the catalogue is identical afterwards; retention pruning; uninstall leaving nothing.

**16.3 End-to-end probe** at `tests/probe/probe.py`, the house pattern: seeds a catalogue through WP-CLI, drives filter, preview, apply, verify, undo, verify, and asserts that unauthenticated and under-privileged requests are refused on every route. It is the definition of done for each ticket and each release.

**16.4 JavaScript** with the WordPress Jest preset: view serialisation, filter-to-query mapping, selection state including select-all-matching, preview rendering, inline edit validation. **Browser** with Playwright: keyboard-only bulk edit and undo, expanding a variable product, a category move through the dialog, and axe-core on each screen with zero serious or critical violations.

**16.5 Performance** seeding 20,000 products and 70,000 variations and asserting §12.5 including query counts, failing the build on regression.

**16.6 Continuous integration** across PHP 8.1 to 8.4, current and previous WordPress, current and previous WooCommerce, running PHPCS, PHPUnit, Jest, the probe and Plugin Check.

**16.7 Manual release checklist**: fresh install, upgrade from the previous tag, deactivate and reactivate, uninstall with and without history retained, and a run against a catalogue with no products.

A ticket is done when its tests are green, the static checks pass and Plugin Check is clean.

## 17. Version 2

- **More fields**: images and gallery, descriptions, attributes, up-sells and cross-sells, purchase note, menu order, and the sale price schedule.
- **Custom fields**: any meta registered with `register_post_meta( 'product', … )` with `show_in_rest` and a scalar type becomes a typed column, labelled from its description or through the `bulkcat_custom_fields` filter. Alongside it, a bridge detecting ACF's public API — the same API Secure Custom Fields exposes — reading field groups with `acf_get_field_groups()` and `acf_get_fields()` and writing with `update_field()`. Text, textarea, number, true or false, select, radio, date picker, URL and email map to columns; other types appear read-only; the bridge degrades to nothing when the API is absent.
- **Shared saved views** and per-role defaults, term meta columns, scheduled batches and exports, regular expressions in find and replace, product duplication, and recording changes made outside the plugin into the same audit log.

## 18. Open questions

1. Should inline edits be journalled individually, as specified, or coalesced per editing session?
2. May any user with the capability undo another user's batch, or only their own?
3. Should pruning hard-delete history, or write it to a CSV first?
4. Are both the table and grid layouts worth shipping, or is the table alone enough to learn?
5. Should term writes and product writes share one batch type, as specified, or have separate undo paths?

### 18.1 Working defaults

Each answer below is a **working assumption until the owner answers**. It records what version 1.0.0 does, so the code, the docs and this specification agree; an answer from the owner that differs replaces it here first and then in the code.

1. **Inline edits are journalled individually.** Each inline edit and each detail-panel save is its own one-item batch, written in the request through `Engine\InlineWrite`, listed in History and undone on its own (§9.3). Nothing is coalesced per editing session. *Working assumption until the owner answers.*
2. **Any user with the capability may undo any batch.** Undo needs the write capabilities of §13 (`Access::can_write`), and the plan checks the person undoing against every object, not the batch's original author; there is no own-batches-only rule. *Working assumption until the owner answers.*
3. **Pruning hard-deletes.** Past the retention limit the daily prune deletes a batch's `bulkcat_changes` and `bulkcat_batch_items` rows; the batch row stays listed as no longer reversible (§9.5). No CSV is written first: an owner who wants a copy exports it beforehand through History's CSV export (a batch's changes, the audit log or the batch list). The History panel states the retention period and the Settings tab where it changes. *Working assumption until the owner answers.*
4. **Both the table and grid layouts ship.** Table is the default and the layout the design targets; the Table | Grid switch offers the grid as a second layout of the same view (§4.1). *Working assumption until the owner answers.*
5. **Term writes and product writes share one batch type.** A category create, rename or move is a batch like a product change (source `tree`, items of `object_type` `term`), journalled in `bulkcat_changes` and undone through the same undo path and History (§7, §9.2). *Working assumption until the owner answers.*
