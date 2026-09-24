# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-09-23

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
