# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.4.4] - 2026-10-08

- The bulk panel's Done step reads "Queued, starts within a minute" from the moment Apply is accepted, instead of "0 of N done" for the second before the first progress read answers.

## [1.4.3] - 2026-10-08

- While a change waits for the site's background tasks to pick it up, the bulk panel, the Products screen and History read "Queued, starts within a minute" instead of "0 of N done", which looked stuck. The count shows from the first item done; the five-minute "queue has not run" notice is unchanged.
- After saving a variation's price in the table, keyboard focus stays on the edited cell instead of jumping to the search box.

## [1.4.2] - 2026-10-06

- After Undo then Redo, History reads "Redone" with who put it back and who did it again, in the list, the change and the CSV.
- Undo puts prices back exactly as they were stored.
- The Categories tab lists, reads and edits every category even where another plugin hides categories from queries; edit rights are still checked.
- A SKU prefix or suffix is always added, also when the SKU already starts or ends with that text.
- The database update is recorded only once every column and index is in place.
- A second run of a change waits for a product still being written.
- If an add-on removes an action after Apply, the change stops with one reason.
- History says when progress or reasons cannot be read, with Try again; a category change that could not be recorded says so.
- The Select all offer always shows the count of the current search; an Apply left unconfirmed stays on screen after the panel closes.
- Category actions offer View in History; reordering announces the new position.
- Messages translate as whole sentences, numbers follow the admin language, and the table's own labels can be translated.
- New screenshots.

## [1.4.1] - 2026-10-05

- An add-on action chosen from a product's row menu opens Bulk change at that action's settings, with the first one ready to fill in; the full list of changes is folded behind "Choose a different change". The same applies to the selection bar's quick actions.
- In the Categories tab, an add-on's actions now sit in one menu per group beside Rename and Move, instead of one button each. The tree row menu and the product row menu group them the same way.

## [1.4.0] - 2026-10-05

- Add-ons can now offer searchable, paged choices (for example people or groups from a long list), checked again on the server at preview and Apply.
- Add-ons can act on product categories: one category or several, with Include subcategories, from the Categories tab, with per-category results in History and Undo.
- Add-on actions can be run on a single product from its row menu, without touching the current selection.
- History names the choices an add-on change used.
- Extension contract version 2 (docs/EXTENSIONS-v2.md); version 1 add-ons keep working unchanged.

## [1.3.4] - 2026-10-05

- A change whose only problems were rows skipped as the preview said (not applicable, already at the value) now reads "Done, some rows skipped" in History, the Done step, the progress banner and the history export, not "Done with errors".
- Export CSV opened straight after ticking rows waits for the selection to be saved, then chooses Selected from it, so it no longer opens one selection behind.
- Settings shown to someone who cannot change them read as each value in words (On, Off, the chosen option or the number) instead of greyed-out switches.
- Undo of a category change, and category delete and merge, now see every category even where another plugin hides some from term queries, so an undo run in the background puts every category back instead of skipping some as gone.
- The Categories tab orders categories with the same position by name exactly as WooCommerce and the shop do ("26WC" before "2RM"), not naturally.
- Saves made through WordPress's own category form, including All fields in the Categories tab, are recorded in History, with fields other plugins keep for a category shown as "Other fields". All fields warns before the first save of a category never saved through that form, whose other plugins' fields start empty or unticked.
- An undone add-on change no longer repeats its field ("Undone: Add customer groups."); Merge into… opens with nothing chosen and no error; saving a category's details shows a visible "Saved" message.

## [1.3.3] - 2026-10-05

- Categories tab uses the full width: the tree on the left with whole names, expand and collapse all, search that keeps the path, and a details pane on the right with the category's details and its products.
- All fields shows WordPress's own category form inside the tab, so fields other plugins add to categories appear and save there; Open in WooCommerce links to the native screen.
- Reordering categories sets WooCommerce's own category order, so the shop and WooCommerce's screen follow, and the other way round.
- Delete and Merge into… for one or several categories, with WooCommerce's own consequences and counts stated first; the default category can be chosen and is never deleted.
- Category renames, moves, reordering, descriptions, display type and image can be undone from History.
- History names categories by their path and describes category changes in words.
- Undo of a change that added and removed categories no longer reports false conflicts; the preview lists each product once.
- Catalog visibility and Shown in shop and search are default columns; only display changes you make are remembered.

## [1.3.2] - 2026-10-05

- Category editor: set a category's Display type (Default, Products, Subcategories or Both) and choose, replace or remove its image from the media library. Both are WooCommerce's own category settings, so the native category screen shows the same values.
- History names category changes in plain words (Name, Slug, Description, Parent category, Display type, Image, Position) instead of internal keys.
- The Select all button in an empty Bulk change wraps inside its box.

## [1.3.1] - 2026-10-05

Fixes from the 5 October standalone code review (context: defects in undo, filtering, export, recovery and the admin screen). Schema 9 (InnoDB for every plugin table; no column change).

- Undo of Manage stock now puts back the stock quantity, backorders, low-stock threshold and stock status WooCommerce changed on save; the preview warns that switching stock management off clears quantities.
- Undo puts a regular and sale price back as a valid pair, so an original sale price is restored.
- Stock status changes on products whose stock is managed are skipped with a plain reason instead of being reported as changed.
- The Regular price filter and sort use the regular price the table shows, also for products on sale.
- Date filters use the site's own day; drafts without a GMT date sort and filter by their site date.
- Filtered CSV exports walk products by ID, so no row is repeated or dropped, and run faster.
- A save that fails after WooCommerce stored the change is recorded as made (or uncertain), never as "not made", and is logged.
- Retrying an interrupted change checks again for edits made since, products moved to the Bin and the user's rights, for the plugin's own fields and for add-on fields.
- A product that stops its batch every time fails alone; products already changed stay counted, and products not reached say so.
- Batches no longer stay "Being undone", finish early on a failed count, or skip parents after one parent sync fails.
- Bulk change with nothing selected asks before selecting all matching products.
- A second Apply waits until the running change ends; the running change can be stopped only after a confirmation.
- Draft and Pending review always ask before Apply; settings text says "at or above".
- History, audit and change exports send their security token in the request body.
- Per-person display settings: rows per page and display options are remembered.
- Multisite: saved views are kept per site; history pruning is scheduled on every site.
- All plugin tables use InnoDB (schema 9); an add-on that fails while registering no longer affects other REST requests.

## [1.2.0] - 2026-10-04

Private installation candidate; target-host acceptance is pending.

### Fixed

- Ordinary product edits reconcile the current list after one Save. A failed list refresh can be retried without repeating the edit.
- Safe request identities and durable outcomes let a lost reply or reload recover an ordinary save result without automatically repeating the mutation. Uncertain interrupted saves remain held pending verified reconciliation; automatic recovery is not included.

### Changed

- New Status edits follow native publication rules: Draft does not remember a target; Published requests publication and can become Scheduled from its stored date. One ordinary Save is enough. Bulk changes use Preview → Apply, with count confirmation at the configured threshold.
- Accepted 1.1.0 batches, receipts, historical labels, explicit Undo and legacy saved filters retain their original meaning. Unaccepted old grants and explicit old Keep current status intent require a fresh review. Password effects are journalled and redacted; unsupported password-bearing variation transitions refuse before saving.
- Downgrading to 1.1.0 requires draining or explicitly handling queued `set_status` work; a file replacement alone is insufficient.

### Fixed

- Selection cleanup retries one supported database conflict after confirmed rollback; unknown errors and uncertain commits remain failures.
- Category creation now keeps a visible confirmation after the dialog closes, distinguishing successful list readback, a missing entry and a failed refresh. Refresh retries only read the list and never create another category. An incomplete save response asks the user to check the list without claiming success.
- Category saves keep native dialog focus restoration working while busy and refuse repeated form submission until the response. Notices preserve a focus target when the empty-state opener or refresh button disappears, without taking focus from a user who has moved elsewhere.
- The narrow category sheet remains open beneath its create dialog, keeping confirmation and retry controls available after saving. Category error details retain native disclosure behavior with readable contrast.

These changes report creation and readback outcomes; they do not repair category membership restrictions on an affected installation.

## [1.1.1] - 2026-10-01

### Fixed

- Category-save confirmation distinguishes missing entries and failed list refreshes, with read-only retry and improved dialog focus and narrow-layout feedback.

## [1.1.0] - 2026-09-30

<!-- external-action-contract (branch codex/external-action-contract): T104–T109 entries -->
### Added: actions from other plugins (external action contract, version 1)

- Another plugin can add bulk actions for values it keeps itself, through one action, `bulkcat_register_providers`, and the constant `BULKCAT_EXTENSION_CONTRACT` (1). `docs/EXTENDING.md` is the guide and `docs/EXTENSIONS.md` the contract. Its actions appear in the bulk panel after the built-in ones and use the same selection, preview, Apply, background queue, History, undo, audit log and CSV exports. With no such plugin installed nothing changes, and the fields list the screen loads is byte-identical.
- One change holds either built-in steps or one add-on's steps, never both.
- An add-on that is inactive or unavailable: its actions disappear, or are listed disabled with its reason and nothing else of its own. A change already running stops before its next part, says how many products were changed, and says whether the add-on is not active or is active but cannot run (then with the add-on's own reason). History stays readable, and the undo waits until the add-on can run. A product moved to the bin after Apply is never changed by an add-on's action, and History shows the values an add-on's action was given only to people allowed to use that action. An add-on that fails the core's checks shows one notice on the screen, and everything else works.
- Database: schema version 8 adds one nullable column, `extensions`, to the batches table (which add-on made a change, and its labels). Uninstall with **Remove all data** leaves an add-on's own data alone.

### Fixed

- The bulk panel's checkbox lists (categories, tags and an add-on's choices) leave at least 24 px between checkboxes (WCAG 2.2 target size).
<!-- /external-action-contract -->

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
- A product moved to the bin after a change or an undo was confirmed is left alone when its turn comes, reported as no longer available (skipped, not failed), as a product in the bin at preview is; it is never written.
- A scheduled product shows its status as **Scheduled** in the table, the detail panel and the Status filter, instead of a blank or an unknown value. Status can still only be set to Published, Draft, Pending review or Private; WordPress schedules a product by its date.
- Every tab says a failed request in plain words: History, Categories, Settings, saved views and the CSV export now show the plugin's own sentence or their own, never a server's error page or message as text (its code and HTTP status stay behind **Show details**, in Settings too).
- Counts that can include variations say so: **Select all** names variations in the Variations mode and items where products and variations are listed together; **Apply** and its confirmation count products and variations apart ("Apply to 1,200 products and 50 variations"); the result of a change counts items unless only products changed, and History's rows count items.

- `bulkcat_batch_created` now fires after the first chunk is scheduled, at most once per accepted batch, and not on a retry after `BatchRecovery::finish()` throws (`src/Requests/RequestService.php`, the post-acceptance guard). 1.0 listeners that assumed one call per `POST /batches` must check the batch id.

### Fixed

- A long-running background process no longer grows in memory as it works through a change. WordPress counts every hook name it fires for as long as the process lives, and WooCommerce's product save fires hooks named after each product's cached transients (`wc_var_prices_{id}` and four more), so one `wp action-scheduler run` over 400 chunks kept about 34 MB. At the end of every chunk, whichever runner runs it (WP-CLI, WP-Cron, Action Scheduler's async request runner or the **Run** link on the Scheduled Actions screen), the plugin now forgets those per-product transient hook names that chunk added, and only those: every other hook name keeps its count, and no callback is touched.
- **Review selected products** removes the product it lists: its pager named the next page at once while the list still showed the previous one, so **Remove** could take a product from the page before. The pager now names the page listed; while another page loads the rows stay but cannot be removed, and a late answer for a page no longer asked for is dropped.
- The bulk change slide-over no longer covers controls of the catalogue: while it is open the catalogue narrows into the room beside it (on a phone-width screen the slide-over covers the page). A control that takes the keyboard focus is scrolled clear of the sticky selection bar.
- Counts that can include variations say what they count in three more places: History's undo question ("This puts 100 products and 20 variations back to their old prices."), its changed-since lines and the finished notice ("items" when an undo held both), and the preview's "Stock would go below zero on 3 items".
- An estimated preview's parts add up to the totals they split (products and variations to the change count; restores to Private and Pending review to the restores counted; the Off/On outcomes and the rows to their totals); each part was rounded on its own before.
- A failed save in the table's cell, the detail panel or a switch says the plugin's own sentence, never another program's error page or message.
- Undo requests that plan a whole change (its preview, confirming it, and counting conflicts with `reversibility=count`) lift PHP's time limit where the host allows, as exports do: planning 100,000 objects takes about two minutes.

REST: the undo preview answers `by_type` (products and variations apart, per state), and an undo batch's `undo` summary carries `by_type` (`{ products, variations }`, `null` for an undo recorded before this).

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
