=== Bulk Catalogue Manager for WooCommerce ===
Contributors: wadejbeckett
Tags: woocommerce, bulk edit, products, catalogue, variations
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.1
WC requires at least: 10.0
WC tested up to: 11.1
Stable tag: 1.0.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Change many WooCommerce products and variations at once. Every bulk change is previewed first, recorded and can be undone.

== Description ==

Bulk Catalogue Manager adds one screen under Products for changing many products and variations at once. It edits WooCommerce's own product data only and works on a plain WooCommerce shop with nothing else installed.

**Find the products.** See the whole catalogue in one table, 25, 50 or 100 rows at a time. Search by name or SKU, filter by category, tag, type, status, visibility, stock, price, tax class, shipping class or date modified, and save the result as a named view. Show products, products with their variations, or variations only.

**Change them.** Edit a price, stock level, SKU or the Visible on site switch directly in the table, or select products (or every product matching your filters) and open the bulk change panel. You can:

* set a value, or clear it;
* increase or decrease prices, stock, weight or dimensions by an amount or a percentage, and round prices, for example to end in .95 or .99;
* copy the regular price to the sale price, or back, or clear the sale price;
* find and replace text in names or SKUs, or add a prefix or suffix to SKUs;
* add, remove or replace categories and tags;
* change status, visibility, featured, stock settings, shipping class and tax settings.

Up to 20 changes can run in order as one step, such as "increase the regular price by 12 per cent, then round to .95".

**See it first.** Nothing is saved until a preview has shown how many products will change, a sample of old and new values, and any warnings, such as a sale price above the regular price or a duplicate SKU.

**Undo it.** Every change is recorded with the value it replaced. The History tab lists each change with who made it and when, and **Undo this change** puts the old values back. If a product was changed again since, it is left alone unless you choose otherwise.

**Variations.** Variations are rows of their own. A variable product opens to show them, each editable on its own, and **Also apply to variations** carries a bulk change down to them.

**Categories.** Create, rename and move categories in a tree, by dragging or with a keyboard-friendly Move to… menu.

The work runs in the background in small groups, so large catalogues do not time out and you can leave the screen while it runs. The plugin makes no request outside your site and collects no data.

== Installation ==

1. Install and activate WooCommerce 10.0 or later.
2. In Plugins → Add New, search for Bulk Catalogue Manager and install it, or upload the ZIP through Plugins → Add New → Upload Plugin.
3. Activate the plugin.
4. Open Products → Bulk Catalogue Manager.

Shop managers and administrators can use the screen. To limit it to administrators, change **Who can use this screen** on its Settings tab.

== Frequently Asked Questions ==

= What does the plugin change on my site? =

Only the product fields you choose to change: name, status, catalogue visibility, featured, categories, tags, regular and sale price, stock status, manage stock, stock quantity, SKU, weight and dimensions, shipping class, tax status and class, sold individually and reviews. It saves them the way WooCommerce's own product editor does. It never changes a product's slug and never creates or deletes products.

= What does it store? =

Three database tables holding the history of changes made through the screen, with each field's old and new value so they can be undone; one settings option; each user's saved views; the order of categories on its own screen; and a few short-lived caches.

= How do I undo a change? =

Open the History tab, find the change and choose **Undo this change**. You will see a preview first. Any product changed again since the original change is listed and left alone unless you choose **Revert anyway**. An undo can itself be undone.

= How long is history kept? =

90 days by default. You can choose from 7 to 730 days, or keep it indefinitely, on the Settings tab. Older changes stay listed but can no longer be undone. To keep a copy, use **Export log (CSV)** on the History tab before they are removed.

= Are changes made elsewhere recorded? =

No. Only changes made through this screen are recorded. Edits on the product edit page, through an import or by other tools are not, and the History tab says so.

= Does it work with variable products and variations? =

Yes. Variations appear as rows of their own and can be edited one by one or in bulk. Fields that belong to the product, such as categories, are not offered on a variation, and the screen says why.

= Does it need any other plugin? =

No. It needs only WooCommerce 10.0 or later.

= Who can use it? =

Shop managers and administrators by default, or administrators only. Each product is also checked against the user's own permission to edit it. Only administrators can change the settings.

= What happens when I deactivate or delete the plugin? =

Deactivating stops the daily history clean-up and leaves everything else in place. Deleting the plugin keeps your history, settings and saved views by default, so they are there if you reinstall. To remove everything when the plugin is deleted, turn on **Remove all data when the plugin is deleted** on the Settings tab first. Your product data itself is never removed.

= Does it work on a multisite network? =

Yes. Each site keeps its own history and settings, and deleting the plugin with **Remove all data** on cleans up each site under its own setting.

== Screenshots ==

1. The catalogue: every product in one table, with the category panel, saved views and search.
2. A variable product opened to show its variations as rows of their own.
3. The bulk change panel checking a change before it is applied.
4. History: each change with who made it, when, and its progress.
5. Settings.

== Changelog ==

= 1.0.0 =
* First release.
* Catalogue table and grid with search, filters, sorting, column choice, saved views and CSV export.
* Products, products and variations, and variations only modes.
* Inline editing and a details panel for one product.
* Bulk changes: set, clear, increase or decrease, round, copy between regular and sale price, find and replace, SKU prefix and suffix, and add, remove or replace categories and tags, up to 20 in order.
* A preview before every change, with counts, sample rows, warnings and CSV download.
* History and audit log with undo, redo and CSV export, and history kept for a set number of days.
* Category tree with create, rename, move and reorder.
* Settings for access, page size, confirmation, variation defaults, background batch sizes, history and data removal.

== Upgrade Notice ==

= 1.0.0 =
First release.
