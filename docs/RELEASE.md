# Release checklist

Perform this checklist for every release and record it here: copy the **Record** template at the end into the **Releases** section, tick each step as it is done, and write what was seen, not what was expected. A step that cannot be performed is recorded as not done with the reason; the release waits until it is done. SPEC §16.7 sets the manual checks, T79 the upgrade step and T80 the release itself.

Making the repository public and submitting to the WordPress.org directory are performed only on the owner's authorisation, never as part of a release merge.

## 1. Version alignment

- [ ] The new version is the same in all five places: the plugin header `Version:` in `bulk-catalogue-manager-for-woocommerce.php`, `Plugin::VERSION` in `src/Plugin.php`, `"version"` in `composer.json` (then `composer update --lock` to refresh the lock file's hash), `Stable tag:` in `readme.txt`, and the top entry of `CHANGELOG.md`, dated with the release day. `composer test` fails when the header and `Plugin::VERSION` disagree.
- [ ] `readme.txt`: `Tested up to` is the current WordPress release, `WC tested up to` (also in the plugin header) the current WooCommerce release, the changelog has the new entry and the Upgrade Notice says what an owner needs to know.
- [ ] If the schema changed, `Data\Migrator::SCHEMA_VERSION` moved on and the changelog says so.
- [ ] `composer make-pot` has been run and the `.pot` committed; `assets/build` has been rebuilt on `main` with `npm run build` and committed.

## 2. Automated gates

- [ ] The CI workflow is green on the release commit: PHPCS and PHPUnit on PHP 8.1 to 8.4, the standalone runner, ESLint, Jest, the bundle build and size check, the probe on the WordPress and WooCommerce matrix, Playwright, the performance job and Plugin Check.
- [ ] `bin/build-zip.sh` builds `dist/bulk-catalogue-manager-for-woocommerce-<version>.zip`, and Plugin Check on the archive shows no ERROR rows and only the allowed WARNING rows.

Record the workflow run link.

## 3. Manual checks (SPEC §16.7)

Use the release archive from step 2, not the working copy, on a throwaway site (wp-env or the docker stack in `tests/probe/README.md`) with the current WordPress and WooCommerce. Keep the site's debug log on (`WP_DEBUG` and `WP_DEBUG_LOG`) and check it is empty of plugin notices after each step.

### 3.1 Fresh install

- [ ] On a site that has never had the plugin, install the archive through Plugins → Add New → Upload Plugin and activate it.
- [ ] The three tables exist, `bulkcat_db_version` is set, and one `bulkcat_prune_history` action is scheduled in the `bulk-catalogue-manager-for-woocommerce` group (Tools → Scheduled Actions).
- [ ] Products → Bulk Catalogue Manager opens for an administrator and a shop manager, and is refused to a subscriber.
- [ ] Seed with `tests/probe/seed.sh`, run a bulk change through preview and apply, check the products changed, undo it from History and check the products are back as they were.

### 3.2 Upgrade from the previous tag (T79)

- [ ] Install the previous released tag's archive on a clean site and activate it.
- [ ] Run a bulk change and an inline edit, so the three tables hold rows. Note the number of rows in each table and the batch numbers.
- [ ] Upgrade in place to the candidate archive (upload it and choose **Replace current with uploaded**), without deactivating first.
- [ ] Load any admin page (the schema update runs on the first admin request after an update).
- [ ] The schema update ran: `bulkcat_db_version` equals the candidate's `Data\Migrator::SCHEMA_VERSION`, and the tables have the columns SPEC §9.2 lists.
- [ ] No history was lost: every table has at least as many rows as before, and every earlier batch is still listed in History with the same counts.
- [ ] `bulkcat_prune_history` is scheduled exactly once.
- [ ] An earlier batch is still undoable: undo it, and check the products are back to their values before that batch.

The automated version of this sequence (T79) runs in the release job between the last released tag and the current build; record its result as well.

For the first release (1.0.0) there is no previous tag. Record this step as not applicable, with that reason.

### 3.3 Deactivate and reactivate

- [ ] Deactivate: the screen and its menu entry are gone, `bulkcat_prune_history` is no longer scheduled, and the tables, settings and saved views are still there.
- [ ] Reactivate: History shows the earlier batches, an earlier batch can still be undone, settings and saved views are as they were, and `bulkcat_prune_history` is scheduled exactly once.

### 3.4 Uninstall with history kept

- [ ] With **Remove all data when the plugin is deleted** off (the default), deactivate and delete the plugin.
- [ ] The three tables, `bulkcat_settings`, `bulkcat_db_version` and users' `bulkcat_views` are all still present.
- [ ] Reinstall and activate: History shows the earlier batches and an earlier batch can still be undone.

### 3.5 Uninstall with history removed

- [ ] Turn on **Remove all data when the plugin is deleted**, deactivate and delete the plugin.
- [ ] Nothing of the plugin is left: no `bulkcat_` table, option, user meta, term meta or transient, and no scheduled action in the `bulk-catalogue-manager-for-woocommerce` group. (The probe's lifecycle chapter checks the same list.)
- [ ] Products still hold the values they had just before deletion; nothing product-related was removed.
- [ ] On a multisite network, repeat on a network with two sites and check each site is cleaned under its own setting.

### 3.6 Empty catalogue

- [ ] On a site with WooCommerce and no products, open the screen: the catalogue shows "There are no products yet." with a link to add a product, and nothing breaks.
- [ ] History, Categories (with no categories other than WooCommerce's default) and Settings open without errors.
- [ ] With no products selected, no bulk change can be applied.

## 4. Publish

- [ ] Merge to `main`, tag `v<version>` on the release commit and push the tag.
- [ ] Create the GitHub release from the tag with the `CHANGELOG.md` entry and the archive from `bin/build-zip.sh` attached.
- [ ] WordPress.org (only once the owner has authorised the directory listing): commit the release to the plugin's SVN `trunk`, tag it, and copy `assets-wporg/` to the SVN `assets/` folder so the screenshots match `readme.txt`.

## Known notices

Plugin Check on the archive reports these WARNING rows, and `bin/plugin-check-allowed.tsv` accepts them (with at most the count shown). Each is a direct database read whose SQL the plugin's own query builders assemble from literal fragments and placeholders; Plugin Check cannot trace a template through a method or an array. Every value is bound by `$wpdb->prepare()` and every identifier comes from an allow-list. A new row, or a higher count, fails `bin/plugin-check.sh` until it is reviewed and added to both places.

| File | Code | Rows | Why |
| --- | --- | --- | --- |
| `src/Query/ProductQuery.php` | `PluginCheck.Security.DirectDB.UnescapedDBParameter` | 3 | The catalogue list, its count and bounded frozen ID/type capture (SPEC §12.2). Values are prepared; only allow-listed clauses and placeholders are composed. |
| `src/Categories/Counts.php` | `PluginCheck.Security.DirectDB.UnescapedDBParameter` | 1 | Category product counts in one grouped read (SPEC §7). |
| `src/Engine/BatchFactory.php` | `PluginCheck.Security.DirectDB.UnescapedDBParameter` | 1 | The ids a filter selects, read through ProductQuery's own FROM and WHERE. |
| `src/Data/ChangeRepository.php` | `PluginCheck.Security.DirectDB.UnescapedDBParameter` | 4 | History and audit feeds (SPEC §9.3). |
| `src/Data/BatchRepository.php` | `PluginCheck.Security.DirectDB.UnescapedDBParameter` | 2 | The batch list and its count (SPEC §9.3). |

## Record template

```
### <version> — <date>

Performed by: <name>
Release commit: <hash>   CI run: <link>   Archive: <file name and size>
Site: WordPress <x.y.z>, WooCommerce <x.y.z>, PHP <x.y>, <MySQL or MariaDB x.y>

1. Version alignment ............ done / not done — notes
2. Automated gates .............. done / not done — notes
3.1 Fresh install ............... done / not done — notes
3.2 Upgrade from <previous tag> . done / not done / not applicable — notes
3.3 Deactivate and reactivate ... done / not done — notes
3.4 Uninstall, history kept ..... done / not done — notes
3.5 Uninstall, history removed .. done / not done — notes
3.6 Empty catalogue ............. done / not done — notes
4. Publish ...................... done / not done — notes
```

## Releases

None recorded yet.
