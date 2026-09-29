# CSV export of the selection and the chosen columns (delivery ticket 10, Parts A and B together)

> **Core copy.** This is the repository's copy of the CSV export plan (tickets T95–T103), made on 29 September 2026 from the delivery plan with every client path and client name removed; the rulings (R1–R13, B1–B23) and Main Build's amendments and review are kept. The delivery tracker's ticket 10 and ticket 04 files are referred to by name, not linked. Where the two copies differ, the delivery copy is the record of what was decided and this one follows it.

**Stage:** approved by Main Build 28 Sept 19:05, amendments applied. Main Build accepted R1–R13 and asked for twelve amendments, which are applied in place and listed in [Main Build's amendments](#main-builds-amendments-28-september-1905). The plan was written on 28 September 2026. This is a documentation change only: no code, test, build or worktree change is part of it, and nothing here was run.

It is **one delivery slice**: ticket 10's Part A and Part B, built together by one owner (the slice's owner) and shipped in one release. Wade's rule B asks for "the ticked exact selection by default", and Part A alone cannot meet it. The coordinator restructured the work on 28 September on that basis.

It is built after T85 acceptance and ticket 04, following the accepted order T84 → T85 → ticket 04 → CSV (Wade, 28 September, Off/On ruling 4).

**Ticket numbers:**
- **T86–T94 belong to global Off/On** (ticket 04's plan, `2026-09-28-global-off-on.md` in the delivery tracker).
- This plan uses **T95–T103**.
- The external action contract (ticket 05) now uses **T104–T109**.

If Wade chooses to build this in parallel with ticket 04 rather than after it, see [Parallel start](#parallel-start).

**Goal:** the catalogue's **Export CSV** opens a small dialog.
- **Rows.**
  - **Selected** is the default whenever anything is selected. It exports the exact selection: every selected product and variation once, in ascending ID order, including items outside the current results, and nothing else.
  - **All matching the current filters** is the alternative. With no search, filter or category active, it is **The whole catalogue**, which needs an explicit confirmation that the server enforces too.
- **Columns** are the ones the column chooser shows.
- **Identifiers.** An optional switch puts `ID`, `SKU`, `Parent` and `Type` first, so the unedited file can go back through WooCommerce's importer (**Update existing products**) and change nothing: no values, types, flags or parents, and no new products, placeholders or terms.

**Contract:**
- Ticket 10 (`10-csv-selection-and-fields.md` in the delivery tracker): its scope, acceptance criteria, importer table, byte-identical heading rule, relabel sequencing note and proposed SPEC §10 amendment. This plan delivers both parts, so the whole of its **Tests** section applies.
- The owner's decision log for 28 September 2026: "Core triage accepted", rule B; "Global Off/On rulings" 3 and 4.
- Core SPEC §§3, 4.1, 10, 12.1, 12.5, 13 and 16.3.
- `docs/EXACT-SELECTION.md`: tokens are opaque and never placed in URLs; whole ID sets never go to the browser; explicit ID lists are capped at 1,000; members are in stable ascending order.
- Ticket 04's plan §6: the listing-control relabel and CSV headings.

Main Build accepted the rulings below at 19:05 on 28 September, with the amendments applied in place. The core repository's copy of this plan (`docs/plans/2026-09-28-csv-selection-export.md`) removes every client path and name.

## Main Build's amendments (28 September, 19:05)

Each amendment is applied where it belongs; this list says where.

| # | Amendment | Applied in |
|---|---|---|
| 1 | Parent also falls back to `id:N` when the parent's SKU is `0` or looks like `id:<digits>`. `guarded_identifiers` also counts a row's own SKU that `wc_clean()` would change | R2; Writer; Dialog warnings; T98 |
| 2 | "Empty filter" is decided on the parsed `Criteria`, so empty-valued arguments cannot bypass the confirmation | R6; T99 |
| 3 | The catalogue GET's retirement is recorded in the CHANGELOG as a REST change | R9; T103 |
| 4 | The token is read from the body or JSON parameters only, never `get_param()` | R11; T100 |
| 5 | `renewAll()` after a lapsed lease rotates the epoch and invalidates any open bulk preview | R13; Risks |
| 6 | `CsvWriter::guard()`'s apostrophe stays, stricter than WooCommerce's `escape_data()`. A counted warning is enough; a tick is optional | R3; Writer |
| 7 | At 100,000 members, an integer-to-integer map for `{ id, parent_id }`, and the `Selection` object dropped once its IDs are read. The 60-second budget is longer than a typical 30-second `max_execution_time`: call `wc_set_time_limit()` in `serve()` and record the host's limits | Rows; T100; T101; T102 |
| 8 | The round-trip probe filters `auto_map_columns()` through `get_mapping_options()`, as the mapping screen does | T102 |
| 9 | WooCommerce always regenerates variation titles. A fixture variation with a custom title records the outcome | T102; Risks |
| 10 | Parent follows WooCommerce's exporter rule for any item with a post parent, including a simple product | R2; Writer; T98 |
| 11 | The Off fixture is a variable product; fixtures include names with `&` and quotes; the import runs as the manager (`--user`) | T102 |
| 12 | The ticket 05 numbering note is updated (T104–T109), and a parallel-start section is added | Header; [Parallel start](#parallel-start) |

## Rulings (accepted by Main Build, 28 September, 19:05)

These replace the rulings in the earlier Part A–only draft (renamed to this plan in the delivery tracker):
- the draft's R1, "Part A has no Selected choice", is dropped, because the slice now delivers the selection;
- its R2–R11 become R1–R10 here, with R7 and R10 reworded;
- R11–R13 are new, for the selection token.

Each ruling takes the conservative option.

- **R1. The summary gains two fields.** It answers `{ rows, left_out, guarded_identifiers, guarded_names, whole_catalogue, filename }`. `whole_catalogue` lets the server decide when the confirmation applies, rather than the screen. `guarded_names` implements R3.
- **R2. Parent follows WooCommerce's rule, and falls back to `id:<parent ID>` whenever the parent's SKU would not read back.**
  - **When Parent is filled** (amendment 10): whenever the item has a post parent, whatever its type, as WooCommerce's exporter does (`export/class-wc-product-csv-exporter.php:489–499`). That covers a variation and a simple product with a post parent alike. The value is the parent's SKU, or `id:<parent ID>` when the parent has none. It is empty when there is no post parent, or when the parent post is gone or is not a product or variation (where WooCommerce's `wc_get_product()` gives nothing). An empty Parent would make the importer set the post parent to 0, which is why the rule is copied exactly.
  - **When the SKU is replaced by `id:<parent ID>`** (amendment 1): whenever the parent's SKU would not read back unchanged. That is when any of these holds:
    - the formula guard would prefix it;
    - `wc_clean()` would alter it;
    - it is `0`, which the importer's `empty()` check drops (`import/class-wc-product-csv-importer.php:206`);
    - it matches `id:<digits>`, which the importer reads as an ID (`:211`).

    One predicate, `ReimportIdentifiers::reads_back()`, decides this.
  - **Why:** the importer's `parse_relative_field()` (11.1.0, `:244–262`) creates an "Import placeholder" product for any SKU it cannot find, in update mode too. That would break "no placeholder is created" and move the item to the placeholder.
  - **Effects:**
    - WooCommerce's own exporter writes the SKU in the replaced case, so the Parent comparison with the exporter leaves that case out and gives it a probe row of its own.
    - `guarded_identifiers` counts rows whose **own** SKU would not read back, meaning the formula guard or `wc_clean()` would change it (amendment 1). A row's own SKU of `0` or `id:5` reads back unchanged as a SKU, so it is not counted.
- **R3. Guarded names are counted and warned about too.** The importer reads `name` through `parse_skip_field()` (`:799`, `:678`), which never removes the formula guard, so a name beginning `=`, `+`, `-` or `@` comes back with the apostrophe. WooCommerce's own export has the same limit. With the switch on, the dialog counts guarded names and shows a second sentence.
  - **The guard itself stays as it is** (amendment 6). `CsvWriter::guard()` also guards after leading spaces and guards numeric-looking text, which is stricter than WooCommerce's `escape_data()` (`export/abstract-wc-csv-exporter.php:373–388`). It is not weakened to make re-import easier: CSV injection protection comes first.
  - A counted warning is enough. Download does not need a tick. An optional "I'll correct these before re-importing" tick when a count is above 0 is left to the owner's review at T103; the default is the warning alone.
- **R4. How "existing tests pass unchanged" is read.** `tests/Unit/Export/*` and `tests/Unit/Rest/HistoryExportTest.php` stay unchanged. The ticket also retires the catalogue GET, so `ExportRouteTest`'s catalogue cases move from GET to POST at T103 with unchanged assertions.
- **R5. "(for reference)" is an allow-list, not a deny-list.** With the switch on, a column gets the suffix unless its key is in `ColumnPlan::ROUND_TRIP`: the keys proven by the round trip, plus the keys the importer does not map. For the version 1 registry, the result is exactly the ticket's four: `category_ids`, `tag_ids`, `shipping_class_id` and `price_html`. A column added later, such as an external field from ticket 05, is "for reference" until the round trip proves it, so it is never mapped silently. A unit test pins this.
- **R6. The server defines "whole catalogue" on the parsed `Criteria`, never on the raw arguments** (amendment 2). An export is whole-catalogue when the filter choice parses to a `Criteria` with:
  - no filter (`[] === $criteria->filters`; the category panel is the `category_ids` filter);
  - a blank search after trimming (`'' === trim( $criteria->search )`; `Criteria` trims already, at `:187`);
  - no parent (`0 === $criteria->parent_id`);

  in any mode.

  An argument that is present but empty cannot bypass the confirmation: `search=%20`, `category_ids[value]` with no values, or a filter with an empty list. `Criteria` already refuses empty term and choice lists (`:327`, `:343`), and whatever it lets through is judged by what it parsed to. The row noun follows the mode. A selection is never a whole-catalogue export, however large it is.
- **R7. Lists travel as one field.** The download form sends the selection token and each list (`columns`, `orderby`, `order`) as single fields. The server reads `ids`, `columns`, `orderby` and `order` either as a list or as one comma-separated field, through `RouteSchema::as_list()`. An API caller posting explicit IDs should send JSON or one field: PHP's `max_input_vars` (1,000 by default) silently cuts repeated `ids[]` form inputs.
- **R8. Headings stay in the site language, as they are today.** The download form posts without `_locale=user`, as the current hidden-frame GET does. `apiFetch` adds `_locale=user` to JSON calls, but the summary returns no headings.
- **R9. The catalogue GET is refused once the dialog ships.** A `GET /export` without a history `source` gets 400 `bulkcat_invalid_value`: "The catalogue is now exported from the Export dialog. Reload the page and export again." This is what a stale screen sees. History exports stay on GET. The CHANGELOG records the retirement as a REST change (amendment 3): "REST: `GET /bulkcat/v1/export` no longer exports the catalogue; use `POST /bulkcat/v1/export`. History exports (`source=changes|audit|batches`) are unchanged."
- **R10. A POST export takes only the catalogue, and rows come from exactly one source.** A `source` other than `products` is refused as unknown. When a `selection` token or `ids` are sent, the filter, search, mode, parent and sort arguments are ignored. A selection is never narrowed or widened by a filter, and its rows are always in ascending ID order.
- **R11. The token is resolved exactly as preview resolves it.**
  - **Where it is read** (amendment 4): only from `$request->get_body_params()` (the form) or `$request->get_json_params()` (the summary). It is never read through `get_param()` or `get_params()`, which merge the query string. `get_query_params()` is read only to refuse a token found there.
  - Resolution: `FoundationStorage::atomic( fn() => ( new SnapshotService() )->selection( $token, get_current_user_id() ) )` (as `Requests\RequestService::preview()` at `bc032ec:135`), then `Selection::of_members()->ids`, which are already in ascending order.
  - A 1.0 token, or anything not beginning `v2.`, gets `SelectionToken::refusal()`: 403 `bulkcat_invalid_selection`, "choose the products again", the same as `PreviewController:190–192`.
  - Other refusals are passed through unchanged: another user's or a tampered token gets the uniform 403 `bulkcat_invalid_selection`; a lapsed lease gets 409 `bulkcat_selection_renew_required`; retention past its limit gets 410 `bulkcat_selection_expired`.
  - Export never renews, extends or releases a selection. The atomic read holds the snapshot row only for its own statement, as preview's does.
- **R12. What "left out" means for a selection or ID list.** An item is left out and counted when it:
  - no longer exists;
  - is in the bin or in a status the catalogue does not list;
  - is not a product or variation;
  - is a variation whose product is gone or no longer listed. This matches Review selected products' "No longer available".

  Per-object read permission follows `GET /products`, meaning the capability behind `Access::can_read`, not inspection's per-object `read_post` check. The same user can export those rows by filter, so a stricter rule would protect nothing and would need every member's post primed. Where per-object capabilities differ, `left_out` can therefore differ from Review's count. If Main Build prefers inspection's rule, the extra cost is one chunked prime per 1,000 members, measured in T102.
- **R13. How the dialog chooses its rows.** Selected is chosen when the dialog opens whenever the selection count is above 0, and the dialog never changes the operator's choice after that.
  - If the selection changes while the dialog is open, the summary is asked again for the new token.
  - While the selection is saving or uncertain (`waitingReason()`), Selected stays chosen and Download waits, showing the reason, as the bulk panel's Check the change does.
  - When a summary answers 409 renew-required, the dialog calls the session's `renewAll()`, the same call as the bulk panel's **Restore the selection** (`index.js:622` at `bc032ec`), and asks again with the renewed token.
    - That renewal rotates the authorization epoch (amendment 5), so any open bulk-panel preview of the same selection becomes stale and must be checked again. This is the same effect as the bulk panel's own Restore.
    - A lease that has lapsed has already made such a preview unusable for Apply, so the renewal takes nothing away that still worked.
    - The dialog renews only on a 409. It never renews ahead of time, so it does not cut short a preview that is still live.
  - A 410 or 403 answer shows the server's sentence. The dialog does not switch to the filter choice automatically.

### Builder rulings during the parallel start (29 September)

Taken by the builder of T95 and T97–T102 on `codex/csv-selection-export` (Wade's parallel-start ruling, 28 September 20:45), each the conservative option where the plan left a choice open. B1 corrects the plan's evidence and changes the switch-on file's bytes, so it is for Main Build's review; the rest settle details. The pre-ruling plan is kept in the delivery tracker.

- **B1. The switch-on file's first heading is written unquoted (a T102 finding; for Main Build's review).** The plan's evidence said the file "reads cleanly" with a byte-order mark and every field quoted. It does not: WooCommerce's importer reads the first heading with PHP's `fgetcsv`, which treats a field as quoted only when a quote is its first byte, and the byte-order mark comes first; WooCommerce strips the mark afterwards, so `"ID"` arrives with its quotes, the mapping screen shows **Do not import** for it, and every row is matched by SKU alone. On the fixture 3 of 16 rows (the ones without a SKU) were skipped. With the switch on, the first heading, always the literal `ID`, now follows the byte-order mark unquoted (`CsvWriter::row_bare_first()`, an opt-in on `CsvExport`; RFC 4180 allows it, it cannot start a formula, and WooCommerce's own exporter starts its files that way). Switch-off bytes, `CsvExport`'s default and the existing export tests are unchanged; SPEC §10 Format says so. A switch-off file keeps the old quoting, so its first heading is read with quotes too; the T103 FAQ should say to re-import only switch-on files, which it already does for other reasons.
- **B2. `reads_back( $sku, $as_parent )` is the one predicate** (R2, amendment 1): the guard or `wc_clean()` changing the SKU fails it for both uses; as a Parent, `''`, `0` and `id:<digits>` fail it too.
- **B3. The 1,000-id limit counts the ids as sent**, duplicates included; duplicates collapse after the check.
- **B4. `selection` and `source` are registered POST arguments** (shape only: the token a string of at most 120 characters, `source` a string with no enum), so a history source gets `RequestsController::unknown()` (R10) rather than a schema refusal, and the token is still read only from the form or JSON body (amendment 4).
- **B5. "(for reference)" falls back to English** unless the translated format keeps exactly one `%s` and some marker text beside it.
- **B6. Refusal codes for failures before streaming:** token resolution with storage not ready or failing answers 503 `bulkcat_request_storage` (logged), as preview's guard does; any other database error before the first byte answers 503 `bulkcat_export_unavailable` (new, listed in `RegistryTest::NOT_STORAGE`).
- **B7. The guarded counts are 0 with the switch off** (not computed), as the summary shape has no null.
- **B8. Presence (R12) for a product does not depend on its post parent**; only a variation needs a listed product as its parent.
- **B9. A filter export's read arguments are an allow-list** (search, orderby, order, mode, parent and the filterable fields), so no unregistered parameter reaches the `/products` reader; a switch-off POST streams the GET's bytes for the same view (unit test).
- **B10. `read:export:switch-off-identical` stays a documented SKIP until T96**, as the parallel-start section says; meanwhile `ExportPostRouteTest::test_a_filter_post_with_the_switch_off_streams_what_the_get_streams` compares POST and GET bytes, and the golden-file test waits with T96.
- **B11. Locales without downloads.** No language pack could be installed on the fixture without a download this run was not authorised to make: `identifiers-header` under de_DE is a documented SKIP (ColumnPlanTest covers translated headings), and `importer-mapping` runs its second leg as a de_DE importing user without packs, which exercises WooCommerce's English-fallback path (it keys on `get_user_locale()`).
- **B12. WooCommerce 11.1.0 only.** 11.1.2 is not available locally without a download; the 11.1.2 leg comes with ticket 04's CI legs (T87).
- **B13. The fixture runs MySQL 8.4** (the `mysql:8` image), as the T101 gate names MySQL 8, rather than the probe README's MariaDB.
- **B14. The edited round trip allows WooCommerce's derived prices:** the rendered price of the edited items, of the variable product whose variation was edited, and of a grouped product holding the edited simple product change with the four edited values, which must reach exactly the values typed. The edited file is saved as a spreadsheet saves CSV UTF-8 (byte-order mark, minimal quoting).
- **B15. Variation titles:** WooCommerce's variation data store regenerates a variation's title on any CRUD read or save, not only on import. Recorded: stored custom, exported custom, after the import the generated title. The fixture sets the custom title just before the export, without a CRUD read.
- **B16. The host's limits, recorded through a throwaway must-use plugin** the probe installs and removes on the fixture: mod_php, `max_execution_time` 30 before streaming and 0 after `wc_set_time_limit( 0 )`, `memory_limit` 128M, Apache `Timeout` 300 s, no PHP-FPM or proxy.
- **B17. Probe safety (the coordinator's rule after the T85 fix):** every export helper that runs PHP or SQL through `WP_CMD` first requires `BULKCAT_FIXTURE_URL` to equal `--base-url`, to name a local development host and to be the site URL `WP_CMD` reaches; `export-probe.php` checks the site URL again. This matches `tests/e2e/lifecycle/guard.js` in substance, with the opt-in naming the URL.
- **B18. The query-count probe warms with the largest page** (WooCommerce's `wc_term_counts` transient, cleared by product saves, otherwise adds one term-meta read per new term to the first page showing it) and renews the count cache per mode.
- **B19. Plugin Check** (2.1.0) was copied read-only from the T85 fixture's container into the slice's stack rather than downloaded; nothing on that stack changed.
- **B20. The probe's lifecycle chapter fails 4 rows at the base too** (`2324d0a`): it still expects schema 6's three tables and one prune action. Outside this slice; reported as a follow-up.
- **B21. The core copy of this plan** (`docs/plans/2026-09-28-csv-selection-export.md`) was not in the repository when T95 started; the TICKETS section links that path, and the generic copy stays the coordinator's, as the Stopping point says.

### Main Build's review of the server work (29 September)

Main Build accepted the server work at `d72bed9` with no blocker: B1 as built; B2–B5, B7–B10 and B13–B19; B6 with L2 below; B12 with the merge gated on ticket 04's WooCommerce 11.1.2 CI leg; B11 for now, with a follow-up for a language-pack CI leg (TICKETS backlog); B20; B21 provided this plan is added to the core repository, which it now is (`docs/plans/2026-09-28-csv-selection-export.md`, client paths and names removed). It asked for these, each landed test-first as its own commit on `codex/csv-selection-export`:

- **L1.** `ExportController::serve()` streamed without catching errors, so a failure mid-stream let WordPress append its critical-error HTML to a cut-off CSV. The stream is wrapped in `try`/`catch ( \Throwable )`: writing stops, and the error goes to the WooCommerce log (source the plugin slug).
- **L2.** `ExportRows::token()`'s `try` also covered presence (`IdRows::resolve()`), so a presence failure answered 503 `bulkcat_request_storage` and a `TypeError` became that 503. Only the selection's own read stays inside it; a presence failure reaches the controller as 503 `bulkcat_export_unavailable`.
- **L3.** A binned parent's SKU was written as Parent; WooCommerce's SKU lookup skips binned products (`get_product_id_by_sku()`), so re-import would create an "Import placeholder". A parent in the bin is written as `id:<parent ID>`. The round trip's recorded set gains the case (a simple product whose grouped parent is binned for that export and import only): Parent `id:N`, the post parent kept, nothing created. WooCommerce's own exporter writes the SKU here, a deviation of R2's kind.
- **Minors.** Guarded names are counted only when the Name column is exported. `ids` carries `maxItems` 1,000 in the route schema. The probe's import helper refuses a mapping the import screen does not offer (row `read:export:importer-mapping-guard`), where it had only checked the column count.
- **B22 (from the `maxItems` minor).** With the cap in the schema, core refuses more than 1,000 ids before the handler: the top-level code is `rest_invalid_param`, and the plan's refusal (`bulkcat_invalid_value`, the plain sentence, `{ param: ids, limit: 1000 }`) is carried in its `details.ids`, as the filter arguments' refusals already are. Ids sent together with a token are still refused by the handler as `bulkcat_invalid_value`. Contract step 5 reads accordingly.
- **B23.** The review asked for a full stop in SPEC §10's Rows paragraph; every sentence and paragraph end in §10 already has one, so the SPEC was left unchanged pending the exact place.
- **Note for T103 (not started).** Until T103 retires it, `GET /export` still exports the whole catalogue without a confirmation, so the server-side whole-catalogue confirmation can be bypassed through GET. T103's retirement (R9) closes it, with the CHANGELOG line recording the REST change (amendment 3).

## Baseline and reproduction

The core code was read at `bc032ec` ("T84 step 8: records"). Since then, another agent has committed `00c0abe` ("T85 WIP: recovered work after the workstation reset") on the same branch, and it touches `catalogue/index.js`, `selection/session.js`, `requests.js` and `tab-store.js`. The slice branches from the commit that carries accepted T85 and ticket 04, and re-reads every file it cites before editing.

The latest recorded verification is from the T84 records; it was not rerun here because the worktree is in use:

| Check | Result |
|---|---|
| `composer test` | 789 tests, 18,644 assertions |
| `npm test` | 32 suites, 388 tests |
| `npm run build` | 307,930 bytes compressed (gate: under 400 KB) |
| Plugin Check | the 11 documented warnings |

T96 records the real baseline at the start commit.

**Importer evidence**, read on 28 September from WooCommerce 11.1.0 (a local copy of the release). Unless a line says otherwise, paths are under `includes/`.

- **How a heading is mapped** (`admin/importers/class-wc-product-csv-importer-controller.php:607–722`):
  - the heading is lowercased and matched against WooCommerce's translated default headings;
  - then against the generic aliases (`mappings/generic.php:23–24`: `Price` → regular price, `Parent SKU` → parent);
  - then against the English fallbacks (`mappings/default.php:36–93`), which are added whenever the importing user's locale is not `en_US`;
  - otherwise the heading stays as its own lowercased text. The mapping screen preselects it only if that text is an option key such as `weight`, `featured` or `length`; anything else shows **Do not import** (`views/html-csv-import-mapping.php:36–50`).
- **Saved mappings.** With **Use previous column mapping preferences** ticked, the last saved mapping replaces all of this (`:731–739`). The FAQ tells the operator to leave it unticked.
- **Reading the file.** The escape character is disabled (`import/class-wc-product-csv-importer.php:69`), so the file is read as RFC 4180. The byte-order mark is stripped (`:122`).
- **Parent.** `parse_relative_field()` (`:203–262`) returns nothing for an empty value, which includes `0` (`:206`). It reads anything matching `id:<digits>` as an ID (`:211`), otherwise looks the value up as a SKU, and creates a placeholder for a SKU it cannot find (R2).
- **Unmapped headings.** `auto_map_columns()` returns an unrecognised heading as its own lowercase text (`:707`). Only the mapping screen turns that into **Do not import**, so any automated import must filter it the same way (amendment 8).
- **The formula guard.** `unescape_data()` (`import/abstract-wc-product-importer.php:866–875`) removes an apostrophe only when it is immediately followed by `=`, `+`, `-` or `@`. The type, float, stock, tax-status and tag parsers use it. ID, SKU (`wc_clean`), name (`parse_skip_field`) and parent do not.
- **Type.** Type resets the virtual and downloadable flags (`:921–932`).
- **Update mode.** Rows are matched by ID, then by SKU (`:1311–1370`).
- **WooCommerce's own exporter** (`export/class-wc-product-csv-exporter.php`, and `export/abstract-wc-csv-exporter.php` for the helpers):
  - Parent: `:489–499`. A variation whose parent is gone gets `''`.
  - Type: `:629–642`, written as type, then `downloadable`, then `virtual`, joined by `, ` (the abstract's `:478–487`).
  - Guard: the abstract's `:373–388` checks the first character only and leaves numbers alone.
- **What the version 1 headings map to** in `en_US` with the switch on:
  - mapped: ID, SKU, Parent, Type, Name, Featured, Regular price, Sale price, Tax status, Tax class, Weight, Length, Width and Height;
  - would also map, hence the suffix: Categories, Tags, Shipping class and Price;
  - not mapped: Status, Catalogue visibility, Shown in shop and search, Stock status, Manage stock, Stock quantity, Sold individually, Reviews, Date created, Last modified, Total sales and Variations.
- **Recheck** at the minimum and the current supported WooCommerce. Ticket 04 raises the minimum to 11.1 and sets the CI legs to 11.1.0 and 11.1.2. The T102 mapping probe repeats this check on both legs.

## Source map

| Boundary | Current source (`bc032ec`) | This slice |
|---|---|---|
| Route | `src/Rest/ExportController.php`: GET only, for the catalogue and history (`:154–168`); `serve()` streams through `rest_pre_serve_request` | Adds a POST catalogue handler. GET keeps history exports and refuses the catalogue at T103 |
| Arguments | `RouteSchema::query_args()`, `fields_arg()`, `as_list()`, `flag()` | New `RouteSchema::export_args()` |
| Request rules | Inline in `get_items()` | New `src/Export/ExportRequest.php`: row source (selection token, IDs or filter), limits, guard, refusals |
| Token resolution | `SnapshotService::selection()`, as preview uses it (`RequestService.php:135`); `SelectionToken::refusal()` | New `src/Export/ExportRows.php`: `token()` and `ids()`, both feeding `IdRows` |
| Presence of members | `ProductQuery::snapshot_members()` (ID and type only) | New `ProductQuery::present_members()`: `{ id, parent_id }`, listed statuses, and a variation's product present too (R12) |
| Explicit rows | None | New `src/Export/IdRows.php` over `present_members()` and `Query\Hydrator` |
| Columns and headings | `CsvExport::header()` uses registry labels, or `$labels` when given (`:109–111`) | New `src/Export/ColumnPlan.php` feeds the existing `$labels` path. `CsvExport` and `CsvWriter` are unchanged |
| Identifier values | `Query\Snapshot::get_type()` (a bare type), `get_sku()` | New `src/Export/ReimportIdentifiers.php`, a `RowSource` decorator |
| Guard counts | `CsvWriter::guard()` | New `src/Export/GuardedValues.php` |
| File name | `CsvExport::filename()` gives `catalogue-export-Y-m-d.csv` | New `src/Export/ExportName.php` |
| Screen | `assets/src/panels/catalogue/views/export.js` (a hidden-frame GET); mounted in `index.js:478–484`; `panelSelection()` in `selection/model.js:134–144` | `export.js` gains `postDownload()` and `formFields()`. New `export-dialog.js`, `export-model.js` and `use-export-summary.js`. The mount passes `selection` and `onRenewSelection` |
| History | `assets/src/panels/history/export.js` uses `currentNonce`, `download` and `exportUrl` | Unchanged |
| Tests | `tests/Unit/Export/{CsvExportTest,CsvWriterTest}.php`, `Rest/ExportRouteTest.php`, `Rest/HistoryExportTest.php`, `tests/js/catalogue-export.test.js`; `probe.py` rows `export`, `read:export:diff-products` and `read:export:query-nonce`; the `perf.py` export group | Changed per ticket below |

## Contract

### Route: `POST /bulkcat/v1/export`

The route is a read. It is registered next to the GET, behind `Access::can_read`, with cookie and nonce authentication. The screen posts a form into the hidden frame with `_wpnonce` and the token in the body (core's `rest_cookie_check_errors()` reads `$_REQUEST['_wpnonce']`). The summary is a JSON `apiFetch` call with the nonce header.

Body:

| Field | Meaning |
|---|---|
| `columns` | Required; today's rules: registry keys, distinct, at least one |
| `identifiers` | A boolean; off by default |
| `selection` | A `v2.` snapshot token, from the body only (R11) |
| `ids` | At most 1,000 positive integers; duplicates collapse. For the API and the probe; the screen always sends the token |
| Filter arguments | Every `GET /products` argument except paging and `fields`, used only when there is no `selection` and no `ids` (R10) |
| `confirm_all` | A boolean. Needed to stream an empty filter |
| `summary` | A boolean. When true, the route answers JSON and streams nothing |

The server checks a request in this order. Every refusal is JSON, sent before any CSV byte, and carries a plain §4.8 sentence.

1. Core validates the arguments. A malformed one gets 400 `rest_invalid_param`, and then comes the permission check: anonymous 401, under-privileged 403.
2. `selection` in the query string gets 400 `bulkcat_invalid_value`: "The selected products must not be sent in the page address. Reload the catalogue and export again."
3. A `source` other than `products` gets `RequestsController::unknown()` (R10).
4. The columns are checked as today.
5. The row source:
   - `selection` together with `ids`, or more than 1,000 `ids`, get 400 `bulkcat_invalid_value`, carrying `{ param, limit: 1000 }` where the limit applies: "More than 1,000 products, or two kinds of rows, were sent to export. Choose Selected or All matching the current filters instead." (Since B22, core's `maxItems` refuses more than 1,000 ids first, as `rest_invalid_param` carrying this refusal in `details.ids`.)
   - `selection` → `ExportRows::token()` (R11), which may refuse with 403, 409 or 410.
   - `ids` → `ExportRows::ids()`.
   - Otherwise the filter is used. A filter `Criteria` refuses as it does today.
6. An empty filter (R6) without `confirm_all`, when not a summary, gets 400 `bulkcat_export_confirm_all`: "This would export the whole catalogue. Tick "Yes, export the whole catalogue", or add a search or filter, and try again."
7. A summary request gets `200 { rows, left_out, guarded_identifiers, guarded_names, whole_catalogue, filename }`:
   - `rows` is `CountCache::total()` for a filter (the grid's own count) or the number of present members;
   - `left_out` is the number of distinct IDs or members minus the present ones, and 0 for a filter;
   - the guarded counts are computed only with `identifiers` on.
8. Anything else gets a `CsvExport` response, which `serve()` streams as today with the new file name.
   - Just before streaming, `serve()` calls WooCommerce's `wc_set_time_limit( 0 )` (`wc-core-functions.php:1715`). §12.5 allows 60 seconds for 20,000 rows and a 100,000-member selection can take longer, while many hosts stop PHP at 30 seconds (amendment 7).
   - `wc_set_time_limit()` does nothing where a host disables `set_time_limit`, and it cannot lift a web server or PHP-FPM timeout. T102 therefore records the fixture's `max_execution_time`, whether the raise took effect, and any proxy or FPM limit. The readme states the limit that remains.
   - History exports go through the same `serve()` and gain the same raise, which is harmless.

**Writes.** The export writes nothing:
- no grant, receipt, lease, retention or release;
- no selection, product or term;
- no option, apart from the `bulkcat_count_` count-cache transient. `GET /products` already writes that transient for the same filter, and it is registered in `Data\Registry`.

### Rows

- **Selection rows.** The token resolves to the snapshot's members in ascending ID order (R11), and those go to `IdRows`. A product's variations are exported only when they are members themselves. A product that started matching after capture is not a member, so it is never exported.
- **Explicit rows.** `ids` are validated, have duplicates removed, are sorted and go to the same `IdRows`.
- **`IdRows`** works in these steps:
  1. Resolve which members are present through `ProductQuery::present_members()`, in statements of at most 1,000 IDs (R12). Nothing is read of an absent member beyond the fact that the presence query does not return it.
  2. Page over the present members, never the raw ones. `CsvExport` stops at the first empty page (`:140–142`), so paging over raw IDs would drop every row after a page whose members were all left out.
  3. Hydrate each page with the same `Hydrator` from `{ id, parent_id }`.
  - Resolution happens in the controller, before the response, so refusals and `left_out` come before the first byte.
  - **Scale** (amendment 7):
    - `IdRows` keeps the present members as one integer-to-integer array, `id => parent_id`, not a list of `{ id, parent_id }` arrays. A 100,000-member selection is then about 100 presence statements and one packed map of a few megabytes, not tens of megabytes of small arrays.
    - `ExportRows::token()` takes `Selection::of_members()->ids`, then drops the `Selection` object and the decoded members before presence is resolved, so no second copy stays in memory while the file streams.
    - Pages are sliced from the map by key, 100 at a time.
- **Filter rows** use the existing `products_reader()`: the `GET /products` handler, in the view's sort order, 100 a page. This path does not change, so with the switch off a filter export produces today's bytes.
- **Whole catalogue:** an empty filter (R6) with `confirm_all` streams every row and names the file `all`.

### Writer: columns, headings and values

- **Switch off.** The columns are exactly as sent, and `CsvExport` is built with `labels = null`, so the header is each column's registry label. For the same rows, the body is byte-for-byte today's.
- **Switch on.** `ColumnPlan` orders the columns as `id`, `sku`, `parent_sku`, `type`, then the sent columns in the order sent, minus `id`, `sku` and `type`. The screen already sends the title field first (`exportColumns()`).
  - It passes a labels map through `CsvExport`'s existing `$labels` path.
  - The four identifier headings are literal English, `ID`, `SKU`, `Parent` and `Type`, not gettext strings. A code comment cites the importer's English fallback mapping as the reason.
  - Other columns keep their registry labels.
  - A column outside `ROUND_TRIP` (R5) gets `sprintf( __( '%s (for reference)', … ), $label )`. If a translation drops the marker, the English suffix is used instead.
  - The rows are read with `fields = columns ∪ { id, sku, type }`.
- **Identifier values** come from `ReimportIdentifiers`, which wraps the row source.
  - **Type** replaces `type` with `[ type, 'downloadable'?, 'virtual'? ]`, so `CsvWriter::text()` writes WooCommerce's native `simple, downloadable, virtual`. The flags come from `_downloadable` and `_virtual` equal to `yes`, read from the page's meta cache, which `Hydrator` primes because `sku` is a meta column.
  - **Parent** is added as `parent_sku`, following WooCommerce's exporter rule (R2, amendment 10):
    - for any row whose `parent_id` is above 0, the parent's SKU. A row's `parent_id` is its `post_parent` for every row (`ProductQuery.php:228`), so a simple product with a post parent is covered as well as a variation;
    - `id:<parent ID>` when the parent has no SKU, or when its SKU would not read back (`reads_back()`: the guard, `wc_clean()`, `0`, `id:<digits>`);
    - empty when `parent_id` is 0, or when the parent post is gone or is not a product or variation. Filter rows can reach the "gone" case; selection rows cannot for variations (R12).
  - **Parent SKUs** cost one added read per page whose rows have a parent: `update_meta_cache( 'post', $parent_ids )`. `Hydrator::variations()` already primed the parents' posts, since it treats any row with a `parent_id` the same way.
- **Format** is unchanged: `CsvWriter` writes the BOM, quotes every field, ends lines with CRLF and takes the separator from Settings; an empty value is not 0. The guard applies to identifier cells too.
- **File name** comes from `ExportName`, in site time (`current_datetime()`):
  - `selected` for a token or `ids`;
  - `filtered` for a filter with conditions;
  - `all` for a confirmed whole catalogue;
  - `-with-ids` when the switch is on.
  - For example `catalogue-selected-with-ids-2026-10-05-1432.csv`. No request value enters the name. History file names are unchanged.
- **Guarded counts** come from `GuardedValues`. For the same row set (the filter's conditions or the present members), it reads `ID`, `post_title` and `_sku` in keyset pages of 5,000. It never hydrates. `_sku` comes from a correlated scalar sub-query, so `Tests\Support\SqlEvaluator` can run the statement.
  - A SKU counts when `ReimportIdentifiers::reads_back()` fails for it: the guard or `wc_clean()` would change it (amendment 1).
  - A name counts when `CsvWriter::guard()` would change it.

  The count uses the writer's and the importer's own functions, so it cannot disagree with the file.

### The export dialog on the current screen

- **Opening.** **Export CSV** opens a `Modal` titled "Export CSV". It traps focus, closes on Escape and returns focus to Export; Jest asserts all three.
- **Rows** are a group of two radio buttons, built from `rowChoices( selection, view )`:
  - **Selected: 25 products and 3 variations.** The count and types come from the envelope's `count` and `by_type`, worded by `countPhrase()`. It is chosen when the dialog opens whenever the count is above 0 (R13). With nothing selected, it is disabled and reads "Nothing selected", and the filter choice is used.
  - **All matching the current filters.** When the summary says `whole_catalogue`, it reads **The whole catalogue (N products)**, with the mode's noun, and shows a **Yes, export the whole catalogue** checkbox. The checkbox is unticked at every opening; with this choice, **Download** stays disabled until it is ticked. The confirmation never applies to Selected.
- **Columns** are a read-only line: "Columns: Name, SKU, Categories, … as the table shows them. Change them with the column chooser." The list comes from `exportColumns()`. There is no second field picker.
- **Identifiers** is a `ToggleControl`, **Include identifiers for re-import**, off at every opening, with help text: "Puts ID, SKU, Parent and Type first, so the file can go back through Products → Import to update the same products."
- **Summary:**
  - It is asked whenever the row choice, the token or the switch changes, and a late answer is ignored (a generation counter).
  - For Selected: "The file will have N rows. M selected items will be left out." For the filter choice: "The file will have N rows." The file name is shown with both.
  - A 409 renew-required answer triggers `onRenewSelection()` and a second request (R13). Any other refusal shows `ErrorNotice` with **Try again**.
  - **Download** is disabled until a summary for the current choice has answered.
- **Warnings** appear only with the switch on:
  - `_n()`: "N rows have a SKU that WooCommerce's importer would not keep as it is. It starts with -, +, = or @, or has line breaks, repeated or edge spaces, tags or % codes that the importer removes. Correct these SKUs, or remove the leading apostrophe, before re-importing." This widens the ticket's sentence to match amendment 1's count;
  - R3's matching sentence for names.
- **Download**
  - It closes the dialog and focus returns to Export. The button reads "Preparing CSV…" until the frame reports back or `SETTLE_MS` passes, as today. A JSON refusal in the frame shows today's notice with its plain sentence.
  - `postDownload()` adds a hidden iframe with a unique name, and a `<form method="post" target="…">` whose `action` is `routeUrl( 'export' )` with no arguments beyond the REST root's own. It then submits the form and removes both on cleanup.
  - `formFields()` writes the body: `_wpnonce` (`currentNonce()`); `selection` for Selected, or the filter arguments for the other choice; `columns` as one field; the booleans as `1`/`0`. The token never goes into `action`, the page history or a log.
- **Mount.** `index.js` passes `selection={ selection.panel }` (`panelSelection()`: `kind`, `token`, `key`, `count`, `byType`, `waiting`) and `onRenewSelection={ () => session.renewAll() }` to `ExportControl`. Rebase this onto T85's head.
- **Width.** The dialog works at 320 pixels (§14).

## Heading-rule check

- **Status keeps its heading.** Ticket 04's Off/On is the Status field itself (ruling 3), so it adds no column and the heading stays `Status`. The Status cells do not change either: ticket 04's plan §6 says an Off product still exports its `post_status`. Its `off_state` row extra and its internal `availability_off` field are not columns: `Registry::internal()` is excluded from `all()` and `keys()`.
- **Only one heading changes.** Ticket 04's plan (§6, T93) keeps the key `visible_on_site` and changes its label from "Visible on site" to **Shown in shop and search**. It also drops it from the default columns (`default_order` 0), so a default-view export no longer contains it. Its CSV cells stay `on`, `off` and `partly`, because `ADAPTER_SWITCH` writes keys, not choice labels. Because ticket 04 lands first, the baseline includes all of this.
- **The other 28 headings are 1.0.0's** (`tests/Unit/Fields/fields.snapshot.json`, pinned since `73af0c3`).
- **The check.** T96's `test_only_the_listing_control_heading_differs_from_1_0_0` compares every registry label with the 1.0.0 map.
  - It expects `visible_on_site` to read "Shown in shop and search" and `status` to read `Status`.
  - It fails, naming ticket 04's owner, if any other label differs or a key is added.
  - If the order is ever reversed, ticket 04 updates this test and the T96 golden files and records the heading change in the CHANGELOG, as its plan already says.
- **With the switch on,** only the identifier block and the "for reference" headings change. The T102 mapping probe proves that "Shown in shop and search" and `Status` map to nothing, so neither needs the suffix.

## Parallel start

The accepted order builds this slice after ticket 04. If Wade chooses instead to run it in parallel with ticket 04 (amendment 12), only the **server work, T95–T102**, starts early, under these conditions:

- **T95 (docs).** It may be written at any time, but lands after ticket 04's T86 SPEC change, or is rebased onto it. Both edit SPEC §12.5, and ticket 04 also edits §5.3.
- **T96 (goldens and the heading check) waits until ticket 04's T93** (the listing-control relabel) is merged. The byte-identical baseline and the heading check must include "Shown in shop and search" and ticket 04's `default_order` change. A golden taken earlier would be wrong the moment T93 lands.
  - Anything that compares against the goldens waits with T96: T101's `test_a_filter_post_with_the_switch_off_streams_the_recorded_bytes` and the `read:export:switch-off-identical` probe row.
- **T97–T100** have no dependency on ticket 04 and can start. They add new files, plus one new method on `ProductQuery`.
- **T101** can start, apart from the golden-based checks above.
- **T102's Off fixture** needs ticket 04's record and status switch (its T88–T89), and its CI legs need the 11.1 minimum (its T87). Until those land, T102 runs without the Off product, and it runs again in full afterwards.
- **T103 does not start until ticket 04 is accepted.** It changes the catalogue screen, which ticket 04's T92 and T93 also change: `index.js`, the Status cell and the relabelled control.
- **Branches.** The server work goes on its own branch and worktree, cut from the accepted T85 head, never on canonical `main` underneath preserved work. It is rebased onto ticket 04 before T96. The owner is still one person, the slice's owner, so the two tracks do not edit the same files at the same time.

## 1. Documentation before code (T95)

- [ ] Replace the SPEC §10 **Export.** paragraph with ticket 10's amendment, including Selected. Add R1–R13 where they apply, and make the consequential edits:
  - §4.1: "export (§10)";
  - §12.1: `POST /export` with the token, IDs or filter arguments, `columns[]`, `identifiers`, `confirm_all` and `summary`, and the nonce and token in the body; `GET /export` for history;
  - §12.5: 20,000 rows by filter or by selection, with or without identifiers;
  - §13: the guard, and "a selection export reads only products and variations, and only from the requester's own selection";
  - §16.3: the probe's export rows and the importer round trip.
- [ ] In `docs/TICKETS.md`, add a "CSV export" section with rows T95–T103, linking the core copy of this plan. It follows ticket 04's rows T86–T94 and precedes the external action contract's T104–T109.
- [ ] No runtime change.

**Gate:** the SPEC and TICKETS changes carry R1–R13 as accepted by Main Build (28 September, 19:05) with its amendments; the coordinator runs a focused check of the SPEC diff.

## 2. Characterise today's export (T96)

These tests pass when they arrive: they are the safety net, not red tests.

- [ ] `tests/Unit/Export/CatalogueBaselineTest.php` dispatches today's `GET /export` over a fixed fake `RowSource`. The rows include:
  - a formula start after leading spaces, and a tab and a carriage return;
  - quotes, both separators and a CRLF inside a value;
  - a list, true and false, `0` against `''` against `null`, and a float;
  - UTF-8 accents and an invalid byte;
  - a variation row.

  Columns: every non-image key in registry order, and separately the default view's columns. Tests:
  - `test_the_catalogue_export_matches_the_recorded_bytes_with_commas`
  - `test_the_catalogue_export_matches_the_recorded_bytes_with_semicolons`
  - `test_only_the_listing_control_heading_differs_from_1_0_0` ([heading-rule check](#heading-rule-check))
- [ ] Golden files `tests/Unit/Export/golden/catalogue-{all,default}-{comma,semicolon}.csv` are written once by the unchanged code (`BULKCAT_WRITE_GOLDEN=1`) and committed before any production change.
- [ ] Evidence: record the GET bytes and their sha256 for three live views on the fixture: the default view; every column in products-and-variations mode; a filtered export with semicolons.
- [ ] Record the start commit's full baseline counts.

**Gate:** green; goldens committed first.

## 3. Column plan (T97)

- [ ] Add `src/Export/ColumnPlan.php` with the `IDENTIFIERS` and `ROUND_TRIP` constants and `labels()`, `columns()` and `fields()`.
- [ ] Tests in `tests/Unit/Export/ColumnPlanTest.php`, written red first:
  - `test_switch_off_keeps_the_sent_columns_and_gives_no_labels`
  - `test_switch_on_starts_with_id_sku_parent_type_then_the_sent_columns_in_order`
  - `test_a_sent_id_sku_or_type_column_appears_once_in_the_identifier_block`
  - `test_identifier_headings_stay_english_under_a_translation`: with `$GLOBALS['bulkcat_test']['translate']` mapping ID, SKU, Type, Parent and Name, the identifier headings stay English and Name is translated
  - `test_the_v1_registry_puts_for_reference_on_categories_tags_shipping_class_and_price_only`
  - `test_a_column_outside_the_round_trip_list_is_for_reference` (R5, through a registry double)
  - `test_a_translation_that_drops_the_marker_falls_back_to_english`
  - `test_the_rows_read_carry_id_sku_and_type_only_when_the_switch_is_on`
  - `test_switch_on_leaves_every_other_cell_as_the_switch_off_file_has_it`

**Gate:** `CsvExportTest` and `CsvWriterTest` untouched and green; `composer lint`.

## 4. Identifier values and guard counts (T98)

- [ ] Add `src/Export/ReimportIdentifiers.php` (the `RowSource` decorator) and `src/Export/GuardedValues.php`.
- [ ] Tests in `tests/Unit/Export/ReimportIdentifiersTest.php`:
  - `test_type_is_the_native_form_for_every_type_and_flag`: simple, grouped, external, variable and variation, each with virtual and downloadable off or on
  - `test_parent_is_the_parent_sku_for_any_item_with_a_post_parent_and_empty_without_one` (a variation, a simple product with a post parent, and a product without one; amendment 10)
  - `test_parent_is_id_and_the_parent_id_when_the_parent_has_no_sku`
  - `test_parent_is_empty_for_an_item_whose_parent_is_gone_or_not_a_product`
  - `test_parent_is_id_and_the_parent_id_when_the_parent_sku_would_not_read_back` (R2: `-P`, ` P`, `P` followed by a line break, `A  B`, `<b>P</b>`, `P%20`, `0`, `id:12`)
  - `test_reads_back_matches_the_importer_for_own_and_parent_skus`: `0` and `id:5` read back as an item's own SKU but not as a Parent
  - `test_the_formula_guard_applies_to_identifier_cells`
  - `test_one_added_read_per_page_for_parent_skus_at_25_50_and_100_rows`
  - `test_a_page_without_parents_adds_no_read`
  - The unit harness has `sanitize_text_field()` (`tests/Support/wp-stubs.php:395`) but no `wc_clean()`. Add a `wc_clean()` stub copied from WooCommerce's definition, so the tests run the same cleaning the importer does.
- [ ] Tests in `tests/Unit/Export/GuardedValuesTest.php`, run through `SqlEvaluator`:
  - `test_counts_rows_whose_sku_would_not_read_back` (the guard, and what `wc_clean()` would change; an own SKU of `0` or `id:5` is not counted; amendment 1)
  - `test_counts_rows_whose_name_the_writer_would_guard` (R3)
  - `test_counts_only_the_rows_the_export_writes` (a filter; present members; left-out members are not counted)
  - `test_reads_in_keyset_pages_of_5000_and_never_hydrates`

**Gate:** green; the probe row `read:products:fixed-query-count` is unchanged.

## 5. Row sources, explicit IDs and the request (T99)

- [ ] Add `ProductQuery::present_members()`, `src/Export/IdRows.php`, `src/Export/ExportRows.php` (`ids()` for now), `src/Export/ExportRequest.php` and `src/Export/ExportName.php`.
- [ ] Tests in `tests/Unit/Query/PresentMembersTest.php`, run through `SqlEvaluator`:
  - `test_returns_listed_products_and_variations_with_their_parent`
  - `test_leaves_out_missing_binned_unlisted_and_non_product_ids`
  - `test_leaves_out_a_variation_whose_product_is_gone_or_unlisted` (R12)
  - `test_reads_at_most_1000_ids_per_statement`
- [ ] Tests in `tests/Unit/Export/IdRowsTest.php`:
  - `test_members_are_written_once_each_in_ascending_order`
  - `test_left_out_members_are_counted_and_never_read`
  - `test_a_variable_product_brings_no_variations`
  - `test_pages_follow_the_present_members_so_a_page_of_absent_ones_never_ends_the_file`
  - `test_filter_arguments_never_narrow_or_widen_the_rows`
- [ ] Tests in `tests/Unit/Export/ExportRequestTest.php`:
  - `test_ids_and_a_selection_together_are_refused`
  - `test_more_than_1000_ids_are_refused_and_1000_are_accepted`
  - `test_ids_accept_a_list_or_one_comma_separated_field`
  - `test_a_selection_in_the_query_string_is_refused`
  - `test_an_empty_filter_needs_confirm_all_in_every_mode`
  - `test_empty_valued_arguments_are_refused_or_still_need_confirm_all` (amendment 2): `search=%20`, `search=` with tabs, `category_ids[value]` with no values, `stock_status` with an empty list, `parent=0`. Each is either refused by `Criteria`, or parses to no condition and still needs the confirmation
  - `test_a_search_filter_category_or_parent_is_not_the_whole_catalogue`
  - `test_a_selection_or_ids_never_need_confirm_all`
  - `test_a_summary_does_not_need_confirm_all`
  - `test_a_history_source_is_refused_on_post`
- [ ] Tests in `tests/Unit/Export/ExportNameTest.php`:
  - `test_the_file_name_names_the_rows_and_the_switch_in_site_time`
  - `test_no_request_value_enters_the_file_name`

**Gate:** green; no route is registered yet.

## 6. Selection token rows (T100)

- [ ] Add `ExportRows::token()`, following R11. It adds no new selection storage or read path.
- [ ] Tests in `tests/Unit/Export/ExportRowsTokenTest.php`, using the T81/T82 unit stores:
  - `test_a_token_exports_exactly_its_members_in_ascending_order_including_off_screen_ones`
  - `test_a_product_that_matched_after_capture_is_not_exported`
  - `test_a_v1_token_gets_the_reselect_refusal`
  - `test_another_users_or_a_tampered_token_gets_the_uniform_invalid_selection_refusal`
  - `test_a_lapsed_lease_is_409_renew_required_and_nothing_is_renewed`
  - `test_retention_past_its_limit_is_410`
  - `test_resolution_writes_nothing_to_the_selection_row` (epoch, expiry, retention and release are unchanged)
  - `test_left_out_counts_unavailable_members_as_review_does` (R12)
  - `test_the_token_is_read_from_the_body_or_json_only` (amendment 4): a request whose query string and body both carry a token is refused, and one whose only token is in the query string is refused, never resolved; `get_param()` is not used for it (a test double for the request records the calls)
  - `test_the_selection_object_is_released_once_its_ids_are_read` (amendment 7: after resolution, a weak reference to the `Selection` is gone)
  - `test_presence_is_kept_as_an_integer_map` (amendment 7: `id => parent_id`, ascending)

**Gate:** green; the T81–T83 selection suites are unchanged and green.

## 7. `POST /export`, summary and refusals (T101)

- [ ] `ExportController` registers POST next to GET, using `RouteSchema::export_args()`. `serve()` does not change. GET keeps working until T103.
- [ ] Tests in `tests/Unit/Rest/ExportPostRouteTest.php`:
  - `test_post_registers_behind_the_read_permission`
  - `test_anonymous_and_under_privileged_posts_are_refused_before_any_row_is_read`
  - `test_a_filter_post_with_the_switch_off_streams_the_recorded_bytes` (T96's goldens; only the file name differs)
  - `test_an_empty_filter_without_confirm_all_is_refused_and_no_csv_byte_is_written`
  - `test_a_confirmed_whole_catalogue_streams_every_row_named_all`
  - `test_a_selection_streams_its_members_named_selected`
  - `test_the_switch_on_streams_the_identifier_block_and_for_reference_headings`
  - `test_token_refusals_arrive_as_json_before_any_csv_byte` (403, 409 and 410)
  - `test_more_than_1000_ids_or_ids_with_a_selection_are_refused`
  - `test_a_selection_in_the_query_string_is_refused`
  - `test_summary_answers_rows_left_out_guarded_counts_whole_catalogue_and_filename_without_streaming`
  - `test_the_semicolon_setting_applies_to_post_exports`
  - `test_serve_raises_the_time_limit_before_streaming` (a `wc_set_time_limit()` stub records the call before the first byte; amendment 7)
  - `test_an_export_writes_no_grant_receipt_selection_option_product_or_term`
  - `HistoryExportTest` and the GET catalogue cases in `ExportRouteTest` stay unchanged and green.
- [ ] Probe rows in the `read` chapter. Add a `p.form()` helper that posts `application/x-www-form-urlencoded` with `_wpnonce` in the body, and a helper that captures a selection through the durable routes the `write` chapter already uses.
  - `read:export:post-auth`: anonymous 401, subscriber 403, manager 200, for a filter, IDs and a token; a stale body nonce 403; the nonce only in the body 200.
  - `read:export:ids-exact`: the IDs of a variation, a product, a deleted post and a page give exactly the product and the variation, in ascending order, with `left_out` 2.
  - `read:export:token-exact`: capture A and B, compose C in, remove B, then make D start matching the capture's filter. The export holds exactly A and C in ascending order. A binned member is counted in `left_out`, and D is absent.
  - `read:export:token-not-in-url`: a token in the query string is refused 400. The screen form's `action` is checked in T103's e2e test.
  - `read:export:token-refusals`: another user's token 403; a 1.0 token 403 with the reselect sentence; a lapsed lease 409, then 200 after `/selection/renew`; an expired one 410.
  - `read:export:whole-catalogue-refused` and `read:export:whole-catalogue-confirmed` (the row count equals WooCommerce's own under `wp eval`).
  - `read:export:identifiers-header`: exactly `ID`, `SKU`, `Parent`, `Type`, then Name and the visible columns, with nothing repeated. Run under `en_US`, and under `de_DE` when its packs can be installed; otherwise SKIP with the reason, since the unit test covers translation.
  - `read:export:type-flags` and `read:export:parent-id-fallback`: compared with WooCommerce's exporter `get_column_value_type()` and `get_column_value_parent_id()` (by reflection under `wp eval`). R2's case is T102's.
  - `read:export:switch-off-identical`: the POST bytes equal the GET bytes for T96's three views.
  - `read:export:writes-nothing`: across a dozen exports and summaries by filter, IDs and token, the `bulkcat_requests` rows, the `bulkcat_selections` rows including each row's lease and retention columns, the highest post ID, a `post_modified` checksum, term counts and non-transient options are all unchanged.
  - `read:export:fixed-query-count-identifiers`: with the switch on and a cold cache, queries per page at 25, 50 and 100 rows give one value per mode and per row source.

**Gate:** PHP green, `composer lint`; the probe's `read` chapter is green on the fixture (WordPress 7.1, WooCommerce 11.1.x, PHP 8.3, MySQL 8).

## 8. Native importer round trip and scale (T102)

- [ ] **Fixtures,** upserted through CRUD by the probe:
  - category `bulkcat-probe-roundtrip`:
    - simple products in all four virtual/downloadable combinations;
    - a variable product with a SKU and three variations, one virtual and one downloadable and virtual;
    - a variable product without a SKU, with two variations;
    - a grouped product and an external product;
    - between them: sale prices, dimensions and weight, a tax class including a variation's "Same as product", a shipping class, tags and Featured;
    - names containing `&`, straight and curly quotes, and an apostrophe, such as `Tom & Jerry's "Classic" Mug` (amendment 11);
    - a simple product with a post parent, whose Parent must round-trip (amendment 10);
    - a **variable** product switched Off, with ticket 04's `_bulkcat_availability_off` record and its variations' saved state (amendment 11).
  - category `bulkcat-probe-recorded`, kept out of the strict round trip on purpose, because these rows record WooCommerce's behaviour rather than assert "no change":
    - SKU `-P1`;
    - a variable product with SKU `=V1` and a variation;
    - a variable product whose SKU is `0`, and one whose SKU is `id:999999`, each with a variation (R2);
    - a product named `@home`;
    - a variation whose stored title has been set to something other than the generated "Product – Attribute" title (amendment 9).
- [ ] **Probe rows:**
  - **How the probe maps headings** (amendment 8). The mapping is built the way the mapping screen builds it:
    1. Call the controller's `auto_map_columns()` by reflection, after loading `wp-admin/includes/class-wp-importer.php`.
    2. Keep each result only if it is a key in the controller's `get_mapping_options()`, flattened with its option groups (the screen's `selected()`, `views/html-csv-import-mapping.php:36–50`).
    3. Map everything else to `''` (**Do not import**).

    `auto_map_columns()` alone returns an unrecognised heading as its own lowercase text (`class-wc-product-csv-importer-controller.php:707`). That text would reach `set_props()` (`abstract-wc-product-importer.php:289`), so a heading such as `Status` would call `set_status()`. Every import in this ticket, in the probe and in any helper, uses the filtered mapping. A unit-level check in the probe helper refuses to run with an unfiltered one.
  - `read:export:importer-mapping` asks this screen-equivalent mapping about the switch-on header row, for an `en_US` importing user and a `de_DE` one. The mapping must be exactly:
    - ID, SKU, Parent, Type, Name and Featured → their fields;
    - Regular price, Sale price, Tax status, Tax class, Weight, Length, Width and Height → the same fields;
    - everything else, including the "(for reference)" headings, Status and Shown in shop and search, → nothing.
  - `read:export:native-import-roundtrip` compares a snapshot before and after an import, for each separator setting and for both row sources: the round-trip category exported by filter, and the same products exported as a captured selection.
    - The snapshot holds: every registry field; `_virtual`, `_downloadable`, the type, `post_parent`, the status and ticket 04's record; the highest post ID; the number of `importing` posts; each taxonomy's term count and highest term ID.
    - The import: the unchanged file goes through `WC_Product_CSV_Importer` with `update_existing` on, the delimiter from Settings and the filtered mapping. It runs as the shop manager, through `wp eval --user=<manager>` (amendment 11). Without a user, the kses filtering applied to names and descriptions differs from what a manager's import applies, so `&` and quotes could change for reasons that have nothing to do with the file.
    - Expected: every row updated, none failed or skipped. The snapshot is equal apart from `date_modified`. No post, placeholder or term is created.
  - `read:export:native-import-edited`: edit Regular price and Sale price on one simple row and one variation row; only those four values change.
  - `read:export:guarded-sku`: `guarded_identifiers` equals the number of SKU cells beginning with an apostrophe. The row also records what WooCommerce actually does when it imports `'-P1`, and the FAQ is written from that record.
  - `read:export:guarded-parent`: the `=V1` variation's Parent is `id:<parent ID>`, and importing it creates no placeholder and keeps the parent (R2).
  - `read:export:guarded-name`: `guarded_names` equals the file's count, and the row records the importer's behaviour (R3).
  - `read:export:parent-zero-and-id-sku`: the variations of the `0` and `id:999999` products carry `id:<parent ID>`, and importing them keeps their parents with no placeholder (R2, amendment 1).
  - `read:export:product-post-parent`: the simple product with a post parent exports its parent's SKU or `id:N`, and the round trip keeps its `post_parent` (amendment 10).
  - `read:export:variation-title` (amendment 9) records what happens to the custom-titled variation, expected to be WooCommerce regenerating the generated title on save. The FAQ says so if confirmed. The strict round trip's normal variations have generated titles, so a regeneration there changes nothing.
  - `read:export:time-limit` records the fixture's `max_execution_time`, whether `wc_set_time_limit( 0 )` took effect during an export, and the PHP-FPM and proxy timeouts (amendment 7).
- [ ] These run on both CI legs from ticket 04, WooCommerce 11.1.0 and 11.1.2.
- [ ] `perf.py` rows (POST, switch on):
  - `perf:export-filter-20000-identifiers` and `perf:export-selection-20000-identifiers`: first byte in under 2 seconds, including token resolution; complete in under 60 seconds; peak memory flat against the 2,000-row run.
  - `perf:export-selection-100000`: completes under a 128 MB memory limit, with the first byte and total time recorded.

**Gate:** the round trip is green on both WooCommerce versions, both separators and both row sources. If a `ROUND_TRIP` column fails, it leaves the list in this ticket with a test (R5).

## 9. The dialog, the GET retirement and the docs (T103)

- [ ] **JavaScript:**
  - `views/export-model.js`: `rowChoices`, `rowsBody`, `summarySentence`, `guardSentences`;
  - `views/export-dialog.js`;
  - `views/use-export-summary.js`;
  - `postDownload()` and `formFields()` in `views/export.js`;
  - the `index.js` mount (rebased onto T85);
  - styles in `views/style.scss`.
- [ ] **Jest.** Keep the column and argument tests in `tests/js/catalogue-export.test.js`, and the nonce and frame tests that history uses. Add `tests/js/catalogue-export-dialog.test.js`:
  - `chooses Selected when anything is selected, reading the envelope's count and types`
  - `shows Selected as unavailable with "Nothing selected" and uses the filter choice when nothing is selected`
  - `keeps Download waiting with the reason while the selection is saving`
  - `restores a lapsed selection through onRenewSelection and asks for the summary again`
  - `opens with the identifiers switch off every time`
  - `lists the columns, name first, in view order, without the image`
  - `shows the rows and left-out counts for Selected, and the row count for the filter`
  - `keeps Download disabled for the whole catalogue until it is confirmed, and resets the confirmation on reopening; the confirmation never appears for Selected`
  - `asks for the summary again when the choice, the token or the switch changes, and ignores a late answer`
  - `warns with the guarded SKU and name counts only when the switch is on`
  - `posts a form into the hidden frame with the nonce and the token in the body and never in action`
  - `posts the filter arguments, and no token, for All matching`
  - `closes, returns focus to Export, and stays busy until the frame settles`
  - `shows the plain sentence of a JSON refusal in the frame`
  - `tests/js/a11y.test.js` checks the open dialog; `tests/js/history.test.js` stays unchanged.
- [ ] **PHP:**
  - `GET /export` without a history source is refused (R9). Until then the GET still exports the whole catalogue without a confirmation, which bypasses the POST's server-side confirmation (Main Build, 29 September); the retirement closes it, with the CHANGELOG's REST-change line (amendment 3);
  - `ExportRouteTest`'s catalogue cases move to POST (R4);
  - add `test_a_catalogue_get_is_refused_with_a_reload_sentence`;
  - in `probe.py`, the `export`, `export-diff` and `export:query-nonce` rows move to POST, `read:export:switch-off-identical` becomes `read:export:get-retired`, and `perf.py`'s export group moves to POST.
- [ ] **End to end:** `tests/e2e/csv-export.spec.js`:
  - Tick rows, open Export: Selected is chosen. Turn the switch on and download. The file starts with `ID,SKU,Parent,Type`, and its rows are exactly the ticked rows in ascending ID order.
  - Build a selection across two searches, remove one off-page item through Review selected products, and export: the file holds exactly the remaining members. The request's URL carries no token: capture it with `page.on( 'request' )`.
  - Keyboard only, with nothing selected: Clear filters, open Export, choose All matching. Download is disabled until the whole-catalogue confirmation is ticked, then the download starts.
  - Axe finds no serious or critical issue in the open dialog at 1280 and 320 pixels wide, and the page does not scroll sideways at 320.
  - Import the downloaded selection file through **Products → Import** with **Update existing products** ticked:
    1. The mapping screen shows ID, SKU, Parent and Type mapped, and the "(for reference)" columns as **Do not import**.
    2. After the import, `GET /products` values and the product and term counts are unchanged.
    3. Change one regular price in the file and import again: only that value changes.
- [ ] **Docs:**
  - the `readme.txt` FAQ and a README "Re-import the file" section. They cover the ticket's six points; the guarded SKU and name limits; checking the mapping on a non-English site; re-importing only files made with the switch on; variation titles, if T102 confirms they are regenerated; and the host time-limit note.
  - The CHANGELOG, with the catalogue GET's retirement recorded as a REST change (R9, amendment 3), and `composer make-pot`.
- [ ] **Release checks:** `composer test`, `composer lint`; `npm run lint:js`, `npm test`, `npm run build` (under the 400 KB gate); `npm run e2e`; every probe chapter on both CI legs; the perf export rows; Plugin Check, with only the 11 documented warnings. Mark the TICKETS rows done with what was verified, and save the evidence with the delivery records.

**Gate:** everything green. Build commits follow the core AGENTS.md rule: commit `assets/build` only if the coordinator names this branch the release candidate. No merge, release or live change is implied.

## Acceptance traceability

| Ticket 10 criterion | Where it is proven |
|---|---|
| Selected is the default and exports exactly the selection, including off-screen rows; no unselected variations; nothing that matched later | T100, T101 (`token-exact`), T103 (Jest and e2e) |
| With nothing selected, Selected is unavailable; the filter choice exports every matching row in sort order | T101, T103 |
| The whole-catalogue confirmation, and the server refusal with no CSV byte | T99, T101, T103 |
| Deleted, binned and non-product members are left out and counted, with no blank rows | T99, T100, T101 |
| The summary shows the row and left-out counts | T101, T103 |
| Switch off is byte-identical to today, apart from the file name, with the baseline taken after ticket 04 | T96, T101 |
| The switch is off at every opening; the identifier block is first, in English under two languages, with no repeats | T97, T101, T103 |
| Parent and Type match WooCommerce's exporter | T98, T101; R2 is the only deviation, in T102 |
| Only the "for reference" headings change, and their values do not | T97 |
| The unedited round trip changes nothing and creates nothing, on WooCommerce 11.1.0 and 11.1.2, with both separators | T102, T103 |
| An edited round trip changes only the edited values | T102, T103 |
| The guarded count is right and warned about; a probe records the importer's behaviour | T98, T102, T103 (plus R3) |
| `CsvWriter` and the export tests are unchanged; the guard covers identifiers | T96–T98 (R4) |
| File names in site time, containing no request value | T99 |
| POST with the nonce and token in the body; GET and a token in the query string refused; anonymous, under-privileged and other users' tokens refused; nothing written | T100, T101, T103 |
| More than 1,000 IDs, or IDs with a token, refused | T99, T101 |
| History, audit and batch-list exports unchanged | T101, T103 |
| Performance: 20,000 rows by filter and by selection; 100,000 members under 128 MB; fixed queries per page | T101, T102 |
| Keyboard, focus, labels, axe, 320 pixels | T103 |
| The FAQ and README re-import steps | T103 |
| SPEC and TICKETS changed before code; no client names in core | T95 |

## Risks

- **Category, tag and shipping-class IDs against names.** The export writes term IDs. The importer would read them as names and create terms called "12". With the switch on, the headings get "(for reference)", so these columns are not imported. With the switch off they would still map, so the FAQ says to re-import only files made with the switch on. Writing names instead is a separate decision.
- **Price column mapping.** The rendered Price holds `wc_price()` HTML text, and WooCommerce's generic `Price` alias maps it to the regular price. That would overwrite real prices on re-import. The switch-on file suffixes it; a switch-off file still maps it (the same FAQ line). R5 keeps any future display-only column safe by default.
- **Locale collisions.** On a non-English site, a translated label can equal a WooCommerce translated heading, so the column is mapped, and a translated "Ja"/"Nein" would not read back as a boolean. The other way round, editable columns may not map at all, so edited prices are silently not imported. The mapping probe runs under a second locale, and the FAQ tells non-English sites to check the mapping screen.
- **Guarded values.** SKUs and names starting `=`, `+`, `-` or `@` keep the apostrophe on re-import, as they do with WooCommerce's own export. So does any value whose guard comes after leading spaces, a tab or a CR, because WooCommerce removes the apostrophe only before those four characters. SKUs that `wc_clean()` would change are also changed on re-import. The dialog counts all of these (R2, R3, amendment 1). Parent avoids the problem through `id:N`.
- **The importer saves every row.** Last modified always changes, and values WooCommerce derives (a variable product's price range and stock status, the lookup table) are recomputed. The round trip therefore excludes `date_modified` and uses CRUD-saved fixtures whose derived values are current.
  - WooCommerce regenerates a variation's title whenever it saves the variation (amendment 9). A variation whose stored title differs from the generated one changes on any re-import, from any file. This is WooCommerce's behaviour, not the export's. `read:export:variation-title` records it, and the FAQ names it if confirmed.
  - A product whose post parent is gone exports an empty Parent, as WooCommerce's own exporter does, and the importer then sets that post parent to 0. That is WooCommerce's rule; the fixture does not include the case.
- **Saved mappings.** **Use previous column mapping preferences** replaces automatic mapping by column position, so columns can shift. The FAQ covers it.
- **Lease and retention.** Exporting needs a live lease, as inspection does. The session renews a third of the way through the lease and when the tab becomes visible again. The dialog restores a lapsed lease through `renewAll()` (R13), which rotates the epoch, so an open bulk preview of the same selection must be checked again (amendment 5). A selection kept past seven days without use is gone (410), and the dialog shows the server's sentence.
- **Time limits.** Some hosts disable `set_time_limit`, or stop requests at the web server or in PHP-FPM, so a very large export can be cut off before it finishes (amendment 7). `serve()` raises PHP's own limit where it can, and T102 records the fixture's limits. The readme tells owners on such hosts to narrow the export with a filter or a smaller selection.
- **CSV injection.** The guard is kept stricter than WooCommerce's (amendment 6). The price is that guarded SKUs and names need correcting before a re-import, which the dialog counts and names.
- **Row lock.** Token resolution holds the snapshot row inside `FoundationStorage::atomic()` for one statement, as preview does. A composition running at the same moment waits for that statement only.
- **The nonce in a POST body.** It depends on `$_REQUEST` including POST data, which the default `request_order` "GP" does. The `post-auth` probe row proves it on the fixture.
- **Importer versions.** The line numbers above are for 11.1.0. T102's mapping and round-trip rows run on both CI legs, so a behaviour change fails CI rather than a customer's import.
- **Off/On.** WooCommerce's importer "Published" column is an outside writer (ticket 04). The export never writes a heading the importer maps to status, and the round trip asserts that Status and ticket 04's record are unchanged.
- **Conflicts with T85.** T85's commit `00c0abe` touches `catalogue/index.js` and the selection files. The export mount is one small change there, rebased at T103; the new files are separate.
- **Summary cost.** Counting guarded values on 20,000 rows takes 4 lean statements, and on 100,000 members 20. This is measured in T102, not assumed.

## Stopping point

This plan is documentation only. Nothing was built or run, and the worktree was only read. Main Build approved it at 19:05 on 28 September with R1–R13 and twelve amendments, all applied here; the version before the amendments is kept in the delivery tracker. Before T95, the owner confirms the start commit (accepted T85 plus ticket 04, or the [parallel start](#parallel-start) if Wade chooses it), and the coordinator copies the plan into the core `docs/plans/` without client references. Ticket 10's header and the delivery tracker's ticket row link to the delivery copy.
