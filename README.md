# Bulk Catalogue Manager for WooCommerce

One screen inside wp-admin for changing many WooCommerce products and variations at once. Every bulk change is shown to you before it is saved, recorded with the value it replaced, and can be undone.

It edits WooCommerce's own product data only, through WooCommerce's own product classes, and works on a plain WordPress and WooCommerce site with nothing else installed. It makes no request outside your site, collects no data and has no paid version. Free under GPL-3.0-or-later.

- Requires WordPress 7.0 or later (tested up to 7.1), PHP 8.1 or later and WooCommerce 11.1 or later (tested up to 11.1).
- Scope and design: [`docs/SPEC.md`](docs/SPEC.md). Work log: [`docs/TICKETS.md`](docs/TICKETS.md). Release checklist: [`docs/RELEASE.md`](docs/RELEASE.md).

## What the screen does

The screen is **Products → Bulk Catalogue Manager**. It has four tabs.

- **Catalogue.** Every product in one table (or a grid of cards), paged on the server at 25, 50 or 100 rows. Search by name or SKU; filter by category, tag, type, status, visibility, featured, stock, price, tax class, shipping class and date modified; sort, choose columns and save the result as a named view. A category panel on the left filters by category. The mode switch shows **Products**, **Products and variations** (a variable product opens to show its variations as rows of their own) or **Variations only**. Many cells can be edited in place; **Edit details** opens every editable field for one product. **Export CSV** downloads the current view.
- **Bulk change.** Tick rows, and add every product matching the filters, across as many searches, filters and pages as you like: the selection is kept on the server until you clear it (**Clear selection**, or **Clear search and selection** on the search chip, which clears both and can be restored for a while), untick a row, or a change made to it finishes in full. **Review selected products** lists everything selected, including rows the list is not showing, and removes any of them. Then open the side panel: choose one or more changes (up to 20, applied in order), check the preview of exactly those products, then apply. The work runs in the background, so you can leave the screen; a reload of the page keeps the selection.
- **History.** Every change made through this screen, newest first, with who made it, when, how many products it touched and whether it can still be undone. Each one expands to show every field it changed, from what, to what. The **Audit log** tab lists field changes across all batches. Both export as CSV.
- **Categories.** The product category tree: create, rename, change slug and description, move under another parent and reorder, by dragging or through **Move to…**. Dropping selected products on a category adds it to them as an ordinary previewed change.
- **Settings.** See [Settings](#settings).

## Fields it writes and how each is stored

Every write goes through WooCommerce's product classes (`wc_get_product()`, the typed setters, `save()`), or `wp_set_object_terms()` and `wp_update_term()` for terms, never through direct SQL or `update_post_meta()`. WooCommerce therefore keeps its lookup table, term counts, caches and its own save hooks correct.

| Field | Stored in | Variations |
| --- | --- | --- |
| Name | `post_title` (the slug is never changed) | Not editable; WooCommerce builds it from the attributes |
| Status | `post_status` | Published or private only |
| Catalogue visibility, and the **Visible on site** switch | `product_visibility` terms `exclude-from-catalog` and `exclude-from-search` | Product only |
| Featured | `product_visibility` term `featured` | Product only |
| Categories, tags | `product_cat`, `product_tag` terms | Product only |
| Regular price | `_regular_price` | Each variation; a variable product's price comes from its variations |
| Sale price | `_sale_price` (clearing writes an empty value, not zero) | Each variation |
| Stock status | `_stock_status` | Each variation |
| Manage stock | `_manage_stock` | Each variation |
| Stock quantity | `_stock` | Only where that variation manages its own stock |
| SKU | `_sku`, unique across products and variations together | Each variation |
| Weight, length, width, height | `_weight`, `_length`, `_width`, `_height` | Each variation |
| Shipping class | `product_shipping_class` term | Each variation, including "same as parent" |
| Tax status, tax class | `_tax_status`, `_tax_class` | Each variation |
| Sold individually | `_sold_individually` | Product only |
| Reviews | `comment_status` | Product only |

The screen never shows these storage names; it uses the plain labels in the first column. Category moves and order use `wp_update_term()` and one term meta value of the plugin's own, `bulkcat_order`, which orders the tree on this screen only and changes nothing on the shop.

After a variation is saved, WooCommerce's own data store resynchronises the parent's price range and stock; the plugin never writes a parent's price itself.

## How a change is previewed, recorded and reversed

1. **Preview.** Nothing is written until a preview has shown what will change: a one-sentence summary, how many rows will change, how many already hold the new value and how many cannot change (with the reason), a sample of rows showing the current and new value, and warnings such as a sale price above the regular price, a price at or below zero, stock going negative or a duplicate SKU. The preview can be downloaded as CSV. If the selection or the settings change afterwards, **Apply** becomes **Preview again**; if the number of products matching a filter has moved since the preview, the screen says so and asks again.
2. **Confirm.** Apply is one click below 200 rows (a setting). Above it, Apply restates the count and needs a second click. A change that drafts, hides or empties a field always asks.
3. **Apply.** The plugin records the batch and the exact list of products and variations it will touch before any work starts, then works through them in the background in small groups using WooCommerce's built-in queue. A product that fails (for example a duplicate SKU) is reported with its reason and does not stop the rest.
4. **Record.** Each field that actually changes is recorded with its value before and after, in the same step that saves the product. Rows that already hold the new value are skipped and not recorded, unless **Record unchanged rows in the audit log** is on. Inline edits and **Edit details** saves are recorded the same way, as a batch of one.
5. **Undo.** **Undo this change** in History prepares an undo with its own preview. Before putting a value back it checks that the field still holds the value the batch wrote; any product changed again since is listed first and left alone, unless you choose **Revert anyway**. The undo is itself a recorded batch, shown as "Undo of #418", and can be reversed in turn with **Redo**. A batch that finished with errors, or was cancelled part-way, can be undone for the part that was applied.

History is kept for 90 days by default (7 to 730 days, or indefinitely). A daily task removes the detail of older batches. The batch stays listed in History, marked as no longer reversible. Batches that are still running are never pruned.

### What is not recorded

Only changes made through this screen are recorded. Edits made elsewhere, such as on the product edit page, through an import or by another plugin, are not recorded and cannot be undone here. The History tab says so. The undo check above still protects them: if a field was changed elsewhere after a batch, undo leaves it alone unless you choose **Revert anyway**.

## Who can use it

| To | You need |
| --- | --- |
| Open the screen and read products, history and categories | `manage_woocommerce` (shop managers and administrators), or `manage_options` when **Who can use this screen** is set to administrators only. Filterable through `bulkcat_capability`. |
| Change products, or undo a batch | The above plus `edit_products`, and `edit_post` on every product (and a variation's parent) it touches, so `edit_others_products` is honoured. Changing categories, tags or shipping class also needs the taxonomy's `assign_terms`. |
| Change categories | The above plus `manage_product_terms`, and `edit_term` on the category, or the taxonomy's `edit_terms` to create one |
| Change settings | `manage_options` |

A product you may not edit is skipped and reported, never silently dropped. Background work runs with no logged-in user, so every object is checked again against the user who started the batch before it is written; a batch cannot give that person more rights than they had, and an item whose permission was removed in the meantime is skipped and recorded. Any user allowed to change products may undo any batch; the check is made against the person undoing, not the person who made the batch.

Requests use the logged-in cookie and WordPress's REST nonce (`bulkcat/v1` namespace). There is no API key, application password or public route.

## Settings

One option, `bulkcat_settings`, checked against an explicit schema on every save. Only administrators can change it.

| Setting | Key | Default | Allowed |
| --- | --- | --- | --- |
| Who can use this screen | `administrators_only` | Shop managers and administrators (`false`) | Or administrators only |
| Rows per page | `rows_per_page` | 25 | 25, 50 or 100 |
| Show the category panel by default | `category_panel` | On | On or off |
| Confirm above this many rows | `confirm_threshold` | 200 | 0 or more |
| Apply status changes to variations by default | `variations_status` | On | On or off |
| Apply visibility changes to variations by default | `variations_visibility` | On | On or off (catalogue visibility belongs to the product, so this reaches no variation today) |
| Apply price changes to variations by default | `variations_price` | Off | On or off |
| Apply stock changes to variations by default | `variations_stock` | Off | On or off |
| Products per background batch | `batch_size` | 50 | 10 to 100; halved automatically if a run nears the PHP time limit |
| Variations per background batch | `variation_batch_size` | 100 | 10 to 100 |
| Keep history for | `history_days` | 90 days | 7 to 730, or 0 for indefinitely |
| Record unchanged rows in the audit log | `record_unchanged` | Off | On or off |
| CSV separator | `csv_separator` | Comma | Comma or semicolon |
| Remove all data when the plugin is deleted | `remove_data_on_delete` | Off | On or off |

The four "apply to variations" settings only set where the **Also apply to variations** switch starts; it can be changed for each change.

## What the plugin stores, and what uninstall removes

| What | Name |
| --- | --- |
| Tables | `{prefix}bulkcat_batches`, `{prefix}bulkcat_batch_items`, `{prefix}bulkcat_changes` (the history and undo records) |
| Options | `bulkcat_settings`, `bulkcat_db_version` |
| User meta | `bulkcat_views` (each user's saved views) |
| Term meta | `bulkcat_order` (category order on this screen) |
| Transients | `bulkcat_category_tree_*`, `bulkcat_count_*`, `bulkcat_batch_request_*`, `bulkcat_selection_*` (short-lived caches) |
| Scheduled actions | `bulkcat_run_chunk` and the daily `bulkcat_prune_history`, in the `bulk-catalogue-manager-for-woocommerce` group |
| Browser storage | The category panel's width and open state, per user, in `localStorage` |

- **Activation** creates or updates the tables with `dbDelta()` and schedules the daily prune once.
- **Deactivation** only unschedules the daily prune. History, settings and views stay.
- **Deletion** removes nothing by default, so history and undo records survive a reinstall. With **Remove all data when the plugin is deleted** on, deleting the plugin drops the three tables and removes every option, user meta, term meta, transient and scheduled action listed above, on every site of a network.

## Hooks

| Hook | Type | Arguments | Use |
| --- | --- | --- | --- |
| `bulkcat_capability` | filter | `string $capability`, `bool $administrators_only` | The capability that opens the screen and its read routes |
| `bulkcat_batch_size` | filter | `int $size`, `int $batch_id`, `string $kind` (`product` or `variation`) | Objects per background run; clamped to 10–100 |
| `bulkcat_selection_ttl` | filter | `int $seconds` | How long a "select all matching" token lasts; default 900, clamped to 60–86,400 |
| `bulkcat_batch_created` | action | `int $batch_id` | Fires once a batch and its items are recorded, before any work. A bulk change or undo sent from the screen fires it once, after it is safely recorded, and never again when the same request is retried |

## Development

The same commands continuous integration runs (`.github/workflows/ci.yml`, on every push and pull request; no step may fail softly):

| What | Command | CI |
| --- | --- | --- |
| PHP coding standards (WordPress-Extra, PHPCompatibilityWP, escaping and SQL sniffs as errors) | `composer install && composer lint` | PHP 8.1, 8.2, 8.3, 8.4 |
| PHP unit tests | `composer test` | PHP 8.1, 8.2, 8.3, 8.4 |
| Unit tests with no `vendor/` (the bundled runner) | `composer test:standalone` | PHP 8.1, fresh checkout |
| JavaScript install, lint (ESLint + Prettier, zero warnings) and Jest | `npm ci && npm run lint:js && npm test` | Node 20 |
| Bundle build with the 400 KB compressed size check | `npm run build` (then `git diff --exit-code -- assets/build`) | Node 20 |
| Translation template | `composer make-pot` (commit the refreshed `languages/*.pot`; `I18nTest` fails on a stale one) | |
| Live seed and probe on WordPress with WooCommerce | `npx wp-env start`, then `WP_CMD="npx wp-env run cli wp" bash tests/probe/seed.sh` and `WP_CMD="npx wp-env run cli wp" python3 tests/probe/probe.py --base-url http://localhost:8888` | WordPress 7.1.2 and 7.0.6 × WooCommerce 11.1.2 and 11.1.0 |

wp-env needs Docker with the Compose v2 plugin; CI installs `@wordpress/env` 11.16.0 globally rather than as a project dependency.

### The probe without wp-env (docker)

Without Compose, [`tests/probe/README.md`](tests/probe/README.md) gives the equivalent throwaway `docker run` stack (WordPress on `wordpress:php8.3-apache`, `mariadb:11`, WP-CLI from `wordpress:cli-php8.3`, the repository mounted read-only as the plugin), which is the local route on this project's workstation. Once `WP_CMD` is exported as that file shows, from the repository root:

```sh
bash tests/probe/seed.sh && python3 tests/probe/probe.py --base-url http://localhost:8888
```

The probe prints one PASS, FAIL or SKIP row per surface and exits non-zero on any failure. Its lifecycle chapter uninstalls and reactivates the plugin, so seed again before the next run. Tear the stack down with the commands at the end of that file.

### Browser tests and performance

- **Browser (end-to-end) tests.** The Playwright suite from T75 (keyboard-only bulk change and undo, expanding a variable product, a category move through **Move to…**, and an axe-core check of every screen) runs against the same seeded site as the probe. Its command and CI job are listed in `package.json` and `.github/workflows/ci.yml` once T75 lands.
- **Performance.** `bash tests/probe/seed.sh 20000` adds the scale catalogue (20,000 products and 70,000 variations) in resumable runs. The T76 performance suite measures the SPEC §12.5 budgets against it on PHP 8.3 and MySQL 8 and writes `performance.json`; its command and CI job are listed in `.github/workflows/ci.yml` once T76 lands.

### JavaScript build

`npm ci && npm run build` (Node 20.19 or later, npm 10) builds `assets/src/` into `assets/build/` with `@wordpress/scripts` 35.0.0, the last release supporting Node 20, and fails if `assets/build/index.js` is 400 KB or more compressed. The built files are committed beside their readable source and build configuration, so anyone can rebuild and compare them.

DataViews is not a core script handle, so it is bundled at one tag per supported WordPress major (SPEC §15): `@wordpress/dataviews` 12.0.1, the npm `wp-7.0` dist-tag, with `@wordpress/components` 32.2.1 from the same tag, both pinned exactly in `package.json`. It serves every WordPress 7 release: npm publishes no `wp-7.1` tag for any `@wordpress` package (checked 23 September 2026). Supported WordPress majors: 7 (7.0 and later). On any other major the screen shows a notice naming the mismatch instead of mounting. To move to a new major, pin the packages from its `wp-X.Y` tag and update `Admin\Screen::DATAVIEWS_VERSION` and `DATAVIEWS_TAG`.

### Releasing

[`docs/RELEASE.md`](docs/RELEASE.md) is the checklist performed and recorded for every release: the automated gates, the manual checks (fresh install, upgrade from the previous tag, deactivate and reactivate, uninstall both ways, an empty catalogue) and the version alignment. `Plugin::VERSION`, the plugin header's `Version`, `composer.json`, the readme's stable tag and the top entry of [`CHANGELOG.md`](CHANGELOG.md) must agree.

WordPress.org listing screenshots are in `assets-wporg/` (`screenshot-1.png` onwards), captioned in `readme.txt`'s Screenshots section in the same order.

## Reporting issues

Report a problem or request a change through the GitHub repository's issues: what you did, what you expected, what happened, and your WordPress, WooCommerce and PHP versions. A batch number from History helps. Report a security problem privately through the repository's **Security → Report a vulnerability** form, not in a public issue.

## Licence

GPL-3.0-or-later. See [`LICENSE`](LICENSE). Bundled JavaScript dependencies are GPL-compatible.
