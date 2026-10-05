=== Bulk Catalogue Manager for WooCommerce ===
Contributors: wadejbeckett
Tags: woocommerce, bulk edit, products, catalogue, variations
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.1
WC requires at least: 11.1
WC tested up to: 11.1
Stable tag: 1.3.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Change many WooCommerce products and variations at once. Every bulk change is previewed first, recorded and can be undone.

== Description ==

Bulk Catalogue Manager adds one screen under Products for changing many products and variations at once. It edits WooCommerce's own product data only and works on a plain WooCommerce shop with nothing else installed.

**Find the products.** See the whole catalogue in one table, 25, 50 or 100 rows at a time. Search by name or SKU, filter by category, tag, type, status, visibility, stock, price, tax class, shipping class or date modified, and save the result as a named view. Show products, products with their variations, or variations only.

**Change them.** Edit a price, stock level, SKU or Status directly in the table, or select products (or every product matching your filters) and open the bulk change panel. You can:

* set a value, or clear it;
* increase or decrease prices, stock, weight or dimensions by an amount or a percentage, and round prices, for example to end in .95 or .99;
* copy the regular price to the sale price, or back, or clear the sale price;
* find and replace text in names or SKUs, or add a prefix or suffix to SKUs;
* add, remove or replace categories and tags;
* change status, visibility, featured, stock settings, shipping class and tax settings.

Up to 20 changes can run in order as one step, such as "increase the regular price by 12 per cent, then round to .95".

**See it first.** Nothing is saved until a preview has shown how many products will change, a sample of old and new values, and any warnings, such as a sale price above the regular price or a duplicate SKU.

**Change native Status.** Choose Published, Pending Review, Draft or Private. A stored future publication date can make a Published request become Scheduled; the preview and saved row show that result. Draft does not remember a status, and Published does not restore one. Individual edits use one Save; bulk Status changes use Preview then Apply, with one additional count confirmation at or above your configured threshold; setting Draft or Pending Review always asks first. Password consequences appear as redacted warnings in Preview, while optional-provider reviews remain.

**Export it.** Export CSV downloads the selected products, or every product matching your filters, in the columns the table shows. With **Include identifiers for re-import** on, the file goes back through WooCommerce's own Products → Import to update the same products.

**Undo it.** Every change is recorded with the value it replaced. The History tab lists each change with who made it and when, and **Undo this change** puts the old values back. If a product was changed again since, it is left alone unless you choose otherwise.

**Variations.** Variations are rows of their own. A variable product opens to show them, each editable on its own, and **Also apply to variations** carries a bulk change down to them.

**Categories.** Create, rename and move categories in a tree, by dragging or with a keyboard-friendly Move to… menu.

The work runs in the background in small groups, so large catalogues do not time out and you can leave the screen while it runs. The plugin makes no request outside your site and collects no data.

== Installation ==

1. Install and activate WooCommerce 11.1 or later.
2. In Plugins → Add New, search for Bulk Catalogue Manager and install it, or upload the ZIP through Plugins → Add New → Upload Plugin.
3. Activate the plugin.
4. Open Products → Bulk Catalogue Manager.

Shop managers and administrators can use the screen. To limit it to administrators, change **Who can use this screen** on its Settings tab.

== Frequently Asked Questions ==

= What does the plugin change on my site? =

Only the product fields you choose to change: name, status, catalogue visibility, featured, categories, tags, regular and sale price, stock status, manage stock, stock quantity, SKU, weight and dimensions, shipping class, tax status and class, sold individually and reviews. It saves them the way WooCommerce's own product editor does. Field edits leave a product's slug unchanged. The row menu also offers native **Duplicate** and **Move to Trash** actions: WooCommerce creates the copy, and WordPress moves the original to Trash. These actions use the site's native permissions and do not permanently delete products.

= What does it store? =

Five database tables hold change history, exact selections and request receipts; settings, saved views, category order and short-lived caches support the screen. Change history records old and new values for Undo. Existing legacy status records (`_bulkcat_availability_off`) are retained for old work and history; new native Draft edits do not create them.

= How do I undo a change? =

Open the History tab, find the change and choose **Undo this change**. You will see a preview first. Any product changed again since the original change is listed and left alone unless you choose **Revert anyway**; so is a product changed after you confirm the undo, before its turn comes. An undo can itself be undone. Undo works for a change of any size, up to 100,000 products and variations; for the largest, preparing the preview takes a couple of minutes.

Duplicate and Move to Trash are native admin actions and do not create entries in this plugin's History. Use WordPress's **Undo** link after moving a product to Trash, or restore it from Products → Trash. A duplicate is a separate product managed through the native product editor. Variations use **Edit details**; the native product actions apply to the parent.

= How long is history kept? =

90 days by default. You can choose from 7 to 730 days, or keep it indefinitely, on the Settings tab. Older changes stay listed but can no longer be undone. To keep a copy, use **Export log (CSV)** on the History tab before they are removed.

= What does changing Status to Draft do? =

Setting a product's Status to Draft takes it off sale through native WordPress and WooCommerce behavior. Choosing Published later requests publication, subject to the stored date and your permissions. It does not restore a remembered Private or Pending Review status. Explicit Undo restores recorded before/after values with conflict checks. Shown in shop and search and Catalogue visibility only change listings; they are separate from publication Status.

= What happens to old Off/On history and filters? =

Accepted legacy changes retain their original interpretation, receipts and Undo behavior. History labels legacy Status requests separately from new native Status changes. Saved Off and Changed outside this screen filters still match their exact original conditions and are labelled as legacy status filters; they are not offered for new filters. New Status edits ignore remembered targets.

= Can I install an older version again? =

Before downgrading to 1.1.0, stop new changes and finish or explicitly handle every queued native Status change and its recovery work. Version 1.1.0 cannot interpret the new `set_status` action. Replacing plugin files alone does not make pending work compatible. Preserve a backup and the matching history; do not uninstall or delete records as an upgrade step.

= How do I export products as CSV? =

On the Catalogue tab, choose **Export CSV**. When products are selected, **Selected** is chosen: the file holds exactly the selected products and variations, including any the list is not showing, in ID order. **All matching the current filters** exports every row the current search, filters, category and mode match; with nothing narrowing the list that is the whole catalogue, and you confirm it first. The file has the name, then the columns the table shows, in the same order. Before the download the dialog says how many rows the file will have, and how many selected items are no longer available and will be left out.

= How do I update products by re-importing the file? =

1. In **Export CSV**, turn on **Include identifiers for re-import**. The file then starts with ID, SKU, Parent and Type, which WooCommerce's importer uses to find each product. Re-import only files made with this switch on: without it the importer cannot match rows that have no SKU, and it maps some columns, such as the displayed price, onto real fields.
2. Change the values you want to change. Do not edit ID, Parent or Type. Columns marked "(for reference)" (categories, tags and shipping class, which the file holds as IDs, and the displayed price) are not imported.
3. Go to **Products → Import**, choose the file and tick **Update existing products**. If the CSV separator on the Settings tab is a semicolon, open **Show advanced options** and set the CSV delimiter to `;`.
4. Leave **Use previous column mapping preferences** unticked, or check the mapping screen: ID, SKU, Parent and Type mapped to themselves, and every "(for reference)" column set to **Do not import**. On a site in another language, always check the mapping screen: a translated heading may not be mapped, or may be mapped to another field.
5. Run the importer.

Limits:

* A SKU or a name that starts with -, +, = or @ is written with an apostrophe in front, so a spreadsheet does not run it as a formula. WooCommerce's importer keeps that apostrophe. The importer also removes line breaks, repeated or edge spaces, tags and % codes from a SKU. The dialog counts such rows: correct them, or remove the apostrophe, before re-importing.
* Legacy saved-status records are never exported. The file carries the actual Status, such as Draft, and an unchanged re-import preserves the existing record. New Status edits still request the native target rather than restoring that record.
* WooCommerce regenerates a variation's title from its product and attributes when it saves it, so a variation with a title of its own gets the generated title back on any import.
* An import made with WooCommerce's importer is WooCommerce's: it is not recorded in History and cannot be undone here. Keep the unedited file if you may want to go back.

= Why is a very large export cut short? =

The export lifts PHP's time limit where the host allows it. Some hosts do not, or stop long requests at the web server. If a large export stops early, narrow it with a filter, or export a smaller selection at a time.

= Are changes made elsewhere recorded? =

No. Only changes made through this screen are recorded. Edits on the product edit page, through an import or by other tools are not, and the History tab says so.

= Does it work with variable products and variations? =

Yes. Variations appear as rows of their own and can be edited one by one or in bulk. Fields that belong to the product, such as categories, are not offered on a variation, and the screen says why.

= Does it need any other plugin? =

No. It needs only WooCommerce 11.1 or later.

= Who can use it? =

Shop managers and administrators by default, or administrators only. Each product is also checked against the user's own permission to edit it. Only administrators can change the settings.

= What happens when I deactivate or delete the plugin? =

Deactivating stops the daily history clean-up and leaves everything else in place. Deleting the plugin keeps your history, settings and saved views by default, so they are there if you reinstall. To remove everything when the plugin is deleted, turn on **Remove all data when the plugin is deleted** on the Settings tab first. Your product data itself is never removed. With that setting on, deletion also removes legacy saved-status records while leaving product statuses unchanged. Review the products and history before removing retained data.

= Does it work on a multisite network? =

Yes. Each site keeps its own history and settings, and deleting the plugin with **Remove all data** on cleans up each site under its own setting.

== Screenshots ==

1. The catalogue: every product in one table, with the category panel, saved views and search.
2. A variable product opened to show its variations as rows of their own.
3. The bulk change panel checking a change before it is applied.
4. History: each change with who made it, when, and its progress.
5. Settings.

== Changelog ==

= 1.3.0 =
* Open the native product editor, view a product in a separate tab, Duplicate or Move to Trash from its row menu.
* Keep exact selection through native navigation; report unavailable selected products honestly in CSV exports.
* Use native permissions, warnings, handlers and Trash recovery. Show a read-only retry if selection refresh fails.

= 1.2.0 =
* Native Status controls replace Off/On for new changes; existing accepted work and history retain their recorded meaning.
* Ordinary edits use one Save and reconcile the current list. Lost responses can recover recorded results without repeating the change.
* An uncertain interrupted save remains held pending verified reconciliation. No automatic takeover or retry is introduced.

= 1.1.1 =
* Clear category-save feedback, read-only refresh retry and improved focus and narrow-layout controls.

= 1.1.0 =
* Keep an exact selection across searches and pages; preview and apply it safely, with truthful progress and undo.
* Status switches products Off and restores their previous status when switched On.
* Export selected products and chosen columns as CSV, with optional identifiers for re-import.
* Separately installed add-ons can provide actions through the same preview, history and undo.
* Stream undo plans at any supported catalogue size; improve keyboard access and clear outcome messages.

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

= 1.3.0 =
Adds native product row actions without a database schema or history-format change. Duplicate and Trash use WooCommerce/WordPress recovery, not this plugin's History. Back up files and data before updating.

= 1.2.0 =
Back up files and data. Drain new-format Status and recovery/Undo work before any binary downgrade to 1.1.x; replacing files alone is insufficient. Category access restrictions are not repaired by this release.

= 1.1.0 =
Needs WooCommerce 11.1 or later. Status becomes the Off/On switch: Draft saves a product's status, Published restores it. Before going back to 1.0.0, let every change finish and switch Off products On: 1.0.0's Published ignores the saved status.

= 1.0.0 =
First release.
