# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

<!-- global-off-on (branch codex/global-off-on): ticket 04 entries -->
### Added: Status is the global Off/On switch

- Setting a product's Status to Draft switches it Off: it leaves the site for every buyer, and the status it had is saved on the product (`_bulkcat_availability_off`, one post meta value holding the product id, the status, when and the batch). Published switches it On and restores exactly that status: Private and Pending review come back as they were, a scheduled product is scheduled again, or published when its time has passed. Draft on a genuine draft saves nothing; Pending review and Private stay ordinary statuses (on a product that was Off they clear the saved status, and the preview says so). Variations are never switched on their own.
- The preview carries an `availability` block counting every outcome (switched Off, back On, restores to another status, publications of products never switched Off, conflicts first), and the bulk panel, the grid's Status cell and the detail panel confirm the same cases in the same words. Status is edited in the table, with the confirm in the cell.
- A product whose status was changed elsewhere while Off reads "Changed outside this screen"; Published leaves it alone and says why (`bulkcat_availability_conflict`), **Keep current status** (`keep_changed_status`) clears its saved status instead, and Draft saves its new status.
- The table shows an Off product as "Off (was Published)"; the Status filter offers **Off** and **Changed outside this screen**; product rows carry `off_state` when Status is read.
- Undo in History restores the status and the saved status together (an internal `restore_status` step no client can send), leaves a product alone when it was switched Off again since, and shows the saved status as "Published, saved <date>".
- Uninstall with **Remove all data** on deletes the saved statuses and leaves every product's status as it is (Off products stay drafts). The native Duplicate does not copy a saved status, and a saved status copied onto another product by any other means is ignored.
- The saved status is protected meta, which only hides it from the editor's Custom Fields box and core's REST: WooCommerce's REST API v3 returns it in `meta_data` and accepts it on write from anyone who may edit the product, and WooCommerce's CSV exporter includes it with custom meta. What protects it is the record itself: bound to its product's id with a fixed shape and one of four statuses, so any other value is ignored, and a well-formed one can only restore a status the person switching On could already set.

### Fixed

- A queued undo re-checks each product when it is written: a product switched Off again by a newer change, given another status outside this screen, or with its saved status removed after the undo was applied is left alone and reported as changed since (skipped, not failed), instead of being published by the undo. The undo's internal step now carries the status and saved status it was planned against.
- History names who undid a batch when the undo finished partial (an item left alone); it showed the batch as Undone with no name.
- Setting a never-Off scheduled product to Published reports that it stayed scheduled (already, not changed to Published) in the change's results; the history was already exact.
- The history now records the status WordPress actually stored after a status change: a Published on a product whose date is ahead is recorded as scheduled (`future`), as WordPress keeps it, instead of `publish`.
- An edit in the grid's cell or the detail panel writes only the product it names: its steps carry **Also apply to variations** off, so switching a variable product Off in place is no longer refused for its variations (before, a Status change on a variable product in the detail panel could be refused because the settings' default carried it down to the variations it never writes).

### Changed: listing controls and minimum WooCommerce

- The **Visible on site** switch is now **Shown in shop and search** (choices Shown, Hidden, Partly), and it is no longer a default column; its key `visible_on_site`, saved views, journal rows and CSV column id are unchanged. It changes listing only: a hidden product can still be opened by link and bought. The selection bar's quick actions read **Show in shop and search** and **Hide from shop and search**.
- CSV export: the one heading change is "Visible on site" → "Shown in shop and search". An export of the default view no longer contains that column at all, because it is not a default column any more; a view that shows it exports it under the new heading. The Status column's heading and values are unchanged (an Off product exports its post status, `draft`).
- A saved view filtering Status is Draft now lists genuine drafts only, not products switched Off; the Status filter also offers **Off** and **Changed outside this screen**.
- Requires WooCommerce 11.1 or later (was 10.0); on an older WooCommerce the plugin shows only a notice.
- A change queued before this update and run after it treats a `set_value` status step as the switch: a Draft now also saves the product's status (harmless: the draft is the same, and the saved status only lets Published restore it later).
- Going back to 1.0.0: let every change finish first (no batch queued, running or undoing), and switch Off products On, or note their saved statuses. 1.0.0 leaves Off products as drafts and ignores their saved status, refuses to undo their Off in History, and its Published publishes them whatever status they had.
<!-- /global-off-on -->

### Added

- **Export CSV** opens a dialog. **Selected**, chosen whenever anything is selected, exports exactly the selected products and variations, including rows the list is not showing, in ID order, and counts those no longer available; **All matching the current filters** exports every matching row, and reads **The whole catalogue (N products)** with a confirmation when nothing narrows it. The columns are the table's. **Include identifiers for re-import** (off each time) puts ID, SKU, Parent and Type first so the file can go back through **Products → Import** with *Update existing products*, and warns with a count about SKUs and names the importer would not keep as they are. The dialog shows the file's row count and name before the download. The readme's FAQ and the README's "Re-import the file" say how to re-import and what the importer does not read back.
- REST: `POST /bulkcat/v1/export` exports the catalogue as CSV from exactly one source: a selection's token (its members, in ascending id order, with any no longer available left out and counted), at most 1,000 product and variation ids, or the `GET /products` filter arguments; a filter with no search, filter or category needs `confirm_all`. `identifiers` puts `ID`, `SKU`, `Parent` and `Type` first, as WooCommerce's product importer maps them, marks columns that would not read back "(for reference)", and writes the first heading unquoted so the importer reads it. `summary` answers the row, left-out and guarded counts and the file name without exporting. Every refusal comes before any CSV byte; a token is taken only from the request body. File names are `catalogue-selected`, `-filtered` or `-all`, with `-with-ids`, and the site's date and time.
- Exports lift PHP's time limit before they stream, where the host allows (`wc_set_time_limit()`).
- An export that fails after it has started (catalogue or history) stops where it is and the error is written to the WooCommerce log, instead of the site's error page being added to the end of the file.

### Changed

- REST: `GET /bulkcat/v1/export` no longer exports the catalogue; use `POST /bulkcat/v1/export`. History exports (`source=changes|audit|batches`) are unchanged. A catalogue `GET` (what a page loaded before this update sends) is refused 400 `bulkcat_invalid_value` with a sentence asking for a reload.
- Selections are exact and kept on the server: ticked rows and **Select all matching** add to one selection that survives searches, filters, pages and a reload, until **Clear selection**, **Clear search**, unticking, or a change that finished in full. **Review selected products** lists and removes members, including ones no longer available.
- Preview covers exactly the selected products and variations; Apply and undo are durable requests with a server-issued grant, so a retry after a lost answer is answered with the change already made instead of making it twice. Chosen products that cannot be changed stay in the change and are recorded as not changed.
- Pages from 1.0 cannot apply or undo: their requests are refused with `bulkcat_legacy_request` and a request to reload.
- An Apply whose answer was lost is kept by the browser tab across a reload: the page asks what became of it with the same request, shows the change it had made, or sends the same request again for the same change, so a relative change can never be made twice.
- A change applied to the selection clears it once it finishes in full even when the page was reloaded or History was open meanwhile.
- The search chip's clear button says what it clears ("Clear search and selection (N)"), and its notice offers **Restore selection** while the selection is still kept.
- Previewing, applying and undoing a whole-catalogue change fit a normal web request's memory: a preview or Apply of 90,000 products and variations needed over 200 MB before, and an undo of 20,000 products about 100 MB (the scope and plan hashes are now worked out a part at a time, unchanged in value).
- A preview or change that fails on the server says so in plain words; the server's own error page is no longer shown as text.
- `GET /batches/{id}` no longer counts conflicts by default (`reversibility.conflicts` is `null`): counting builds the whole undo plan, and the screen polls this read until a change finishes. Pass `reversibility=count` to count them. The screen follows a change with bounded retries (after 10, 20, 40, 80 and 160 seconds, about five minutes in all; six failed reads) and then says it could not check, with **Check again**, which starts one follow however often it is pressed.
- Undo works at any size: the undo plan is walked a page of products at a time instead of held whole, so previewing, counting and confirming the undo of the largest change a selection can hold (100,000 products and variations) fit a 128 MB web request, and the queued undo runs in bounded memory. The screen offers undo for every change again; the interim size limit (and its "not yet supported from this screen" wording) is gone.
- A queued undo checks each product again just before writing it, for every field the change touched: a product changed after the undo was confirmed is left alone whole and reported as changed since, as a status changed since is, unless **Revert anyway** was chosen. A product whose status step is found changed since is left alone whole too: its other fields are not put back either. A retried chunk finishes what it had written instead of calling it changed.
- An undo that put nothing back (every product changed again before it reached them) no longer marks the change undone: the change keeps its status and can still be undone with **Revert anyway**, and History says nothing was put back.
- Every tab says a failed request in plain words: History, Categories, Settings, saved views and the CSV export now show the plugin's own sentence or their own, never a server's error page or message as text (its code and HTTP status stay behind **Show details**, in Settings too).
- Counts that can include variations say so: **Select all** names variations in the Variations mode and items where products and variations are listed together; **Apply** and its confirmation count products and variations apart ("Apply to 1,200 products and 50 variations"); the result of a change counts items unless only products changed, and History's rows count items.

- `bulkcat_batch_created` now fires after the first chunk is scheduled, at most once per accepted batch, and not on a retry after `BatchRecovery::finish()` throws (`src/Requests/RequestService.php`, the post-acceptance guard). 1.0 listeners that assumed one call per `POST /batches` must check the batch id.

### Fixed

- A long-running background process no longer grows in memory as it works through a change. WordPress counts every hook name it fires for as long as the process lives, and WooCommerce's product save fires hooks named after each product's cached transients (`wc_var_prices_{id}` and four more), so one `wp action-scheduler run` over 400 chunks kept about 34 MB. At the end of every chunk, whichever runner runs it (WP-CLI, WP-Cron, Action Scheduler's async request runner or the **Run** link on the Scheduled Actions screen), the plugin now forgets those per-product transient hook names that chunk added, and only those: every other hook name keeps its count, and no callback is touched.

## [1.0.0] - 2026-09-24

First release.

### Added

- **Products → Bulk Catalogue Manager**, one screen with Catalogue, History, Categories and Settings tabs, built on WordPress's own components and DataViews (`@wordpress/dataviews` 12.0.1, bundled for WordPress 7). On any other WordPress major the screen shows a notice instead of mounting.
- Catalogue: a server-paged table (25, 50 or 100 rows) and a grid of cards; search by name or SKU; filters for category (with or without sub-categories), tag, type, status, catalogue visibility, featured, stock status, managed stock, price and stock ranges, tax class, shipping class and date modified, shown as plain sentences; sorting, column choice and density; saved views per user, with a default; CSV export of the current view, streamed and guarded against spreadsheet formulas.
- Three modes: Products, Products and variations (a variable product opens to show its variations as rows of their own, 50 at a time with Show all) and Variations only.
- Inline editing of prices, stock, SKU, weight and dimensions and the Visible on site switch, and an Edit details panel for every editable field of one product, each saved as a recorded, undoable change.
- Bulk changes on selected products or every product matching the filters: set or clear a value; increase or decrease by an amount or percentage; round to decimals, a step or a .95/.99 ending; copy between regular and sale price with an optional discount; clear the sale price; find and replace in name or SKU; SKU prefix and suffix; add, remove or replace categories and tags. Up to 20 steps run in order as one change, with Also apply to variations and Skip rows where the value is already correct.
- A preview before every bulk change: a one-sentence summary, counts, sample rows with old and new values, warnings (sale above regular, price at or below zero, negative stock, duplicate SKU), a CSV download, and a second confirmation above a set number of rows or for any change that drafts, hides or empties a field. Apply refuses a preview that is out of date.
- Background processing in small groups through WooCommerce's built-in queue, resumable after a timeout, safe against double application, with live progress, cancel, a warning when the queue has stalled, and automatic halving of the group size when a run nears the time limit.
- History and an audit log: every change with who, when, what, how many and the outcome; each field's old and new value; filters; CSV export. Undo with its own preview that leaves products changed since alone unless Revert anyway is chosen; Redo; partial undo of a batch that finished with errors or was cancelled.
- History retention: 90 days by default, 7 to 730 days or indefinitely, pruned daily in bounded steps; pruned batches stay listed as no longer reversible.
- Category manager: a tree with counts; create, rename, edit slug and description, move and reorder by drag or through Move to…; dropping selected products on a category adds it as a previewed change. Category writes are recorded and undoable like product writes.
- Settings: who can use the screen, rows per page, the category panel, the confirmation threshold, where Also apply to variations starts for status, visibility, price and stock, background group sizes, history retention, recording unchanged rows, CSV separator and removing all data on deletion.
- Permissions: the screen needs `manage_woocommerce` (or `manage_options` when limited to administrators, filterable through `bulkcat_capability`); writes need `edit_products` and `edit_post` on every object; category writes need `manage_product_terms`; settings need `manage_options`. Background work re-checks every object against the user who started the batch.
- Data lifecycle: three tables created with `dbDelta()` under `bulkcat_db_version`; deactivation only unschedules the daily prune; deletion keeps everything unless Remove all data when the plugin is deleted is on, in which case every table, option, user meta, term meta, transient and scheduled action of the plugin is removed on every site of a network.
- Keyboard operation throughout, including the category tree and Move to…, with progress and results announced to screen readers; translation-ready strings with a `.pot` template, no external requests, and compatibility declarations for WooCommerce's order tables and Cart and Checkout blocks.
- Development: PHPUnit, a standalone test runner, PHPCS (WordPress Coding Standards), ESLint, Prettier and Jest, a seed and live probe harness for wp-env or a throwaway docker stack, continuous integration across PHP 8.1 to 8.4, WordPress 7.0 and 7.1 and WooCommerce 10 and 11, and a release checklist in `docs/RELEASE.md`.

[1.0.0]: https://github.com/wadejbeckett/bulk-catalogue-manager-for-woocommerce/releases/tag/v1.0.0
