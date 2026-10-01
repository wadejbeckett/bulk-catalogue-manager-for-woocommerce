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
| `src/Query/ProductQuery.php` | `PluginCheck.Security.DirectDB.UnescapedDBParameter` | 4 | The catalogue list, its count, bounded frozen ID/type capture and an export's present members (SPEC §10, §12.2). Values are prepared; only allow-listed clauses and placeholders are composed. |
| `src/Categories/Counts.php` | `PluginCheck.Security.DirectDB.UnescapedDBParameter` | 1 | Category product counts in one grouped read (SPEC §7). |
| `src/Engine/BatchFactory.php` | `PluginCheck.Security.DirectDB.UnescapedDBParameter` | 1 | The ids a filter selects, read through ProductQuery's own FROM and WHERE. |
| `src/Export/GuardedValues.php` | `PluginCheck.Security.DirectDB.UnescapedDBParameter` | 1 | The CSV export's guarded SKU and name counts over a filter's rows, read through ProductQuery's own FROM and WHERE (SPEC §10). |
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

### 1.1.1 — 1 October 2026 — private category-feedback update

This scoped update packages accepted category-feedback source/assets at
`2ce4f314af4952ede303d798d139c5208a337a94`, with release metadata only.
It changes category creation/readback notices, read-only refresh retries,
keyboard focus and announcement lifecycle. Backend visibility restrictions
remain outside this patch. Schema 8, server write behavior and extension
contract 1 are unchanged; optional providers keep their existing integration.

Retained accepted qualification: 25 native browser cases, 93 affected JS tests,
18 post-lint creation-flow checks and independent scoped review with no open
findings. Those are prior results on the identical shipped functional assets,
not newly repeated suites. Fresh release Plugin/Assets/I18n checks passed
29 tests / 499 assertions; Composer validation passes with only its existing
explicit-version advisory. The native-tested build is preserved through the
supported `BULKCAT_SKIP_BUILD=1` archive path. No new hosted CI matrix, broad
lifecycle suite or live category-write test is claimed for this private patch.

The operator records final ZIP identity, current-install backup, native upgrade,
installed-file/owner/version/schema checks and site smoke results separately.
File rollback restores the freshly backed-up 1.1.0 plugin after checking for
active manager work; retain the database and later product edits. No new
Status/receipt format or unfinished SDK implementation is included.
No public release, directory submission or main-branch merge is performed.


### 1.1.0 — 30 September 2026 — private installation candidate

Performed by: Codex. Functional runtime: `a7291ac`; integration fixture fixes:
`17571bd`. Version 1.1.0, schema 8. Archive:
`bulk-catalogue-manager-for-woocommerce-1.1.0.zip`, 280 files, 1,364,901 bytes.
SHA-256: `594b3a768731c007112272380c117cbb895915f2d6c9efa8cc3f1ed14fed2acb`.

Site for local checks: WordPress 7.1.2, WooCommerce 11.1.2, PHP 8.3.33,
MySQL 8.4.11. Measurements and limits: `tests/probe/PERF.md`. Sanitised result
logs are retained outside the repository.

1. Version alignment: done; five version locations agree, schema 8, current
   POT and committed assets. The normal archive build rebuilt the exported
   source and produced byte-identical compiled assets.
2. Local automated gates: done. PHP both runners: 1,068 tests / 23,854
   assertions. Without vendor: 1,061 passed and seven dependency/archive
   skips. PHPCS: 328 files clean. JavaScript lint clean; Jest: 576 tests in
   40 suites. Bundle: 319,000 compressed bytes, below 400 KB. Plugin Check
   2.1.0: zero errors, 13 allow-listed prepared-query warnings. Full HTTP
   probe: 237 passed, zero failed, one translation skip. Full scale-fixture
   browser suite: 61 passed, zero failed, seven documented skips. All native
   exact-selection/batch/lifecycle probes, races and provider checks passed.
   GitHub Actions quota was exhausted until 1 October: no new green CI or
   PHP 8.1–8.4 matrix is claimed. The owner authorised local release gates.
3. Fresh archive activation and upgrade: done on disposable sites and an
   isolated copy of a real catalogue. The archive upgraded 1.0.0/schema 6
   to schema 8 without changing catalogue/category fingerprints. The copy's
   18/18 journey checks cover reversible Off/On and price/variation changes.
   Populated schema migration: the HTTP lifecycle probe downgraded its schema
   marker to 5 then migrated to 8; all five table row counts were preserved.
   The optional browser fixture containing a legacy-1.0 batch was not present;
   that browser check was skipped, not reported as passed.
4. Deactivation/reactivation and both uninstall policies: the final native
   lifecycle probe passed, preserving or removing plugin-owned rows as
   requested while leaving product data intact. Network-wide two-site
   uninstall was not repeated in this local run; unit coverage remains.
5. Empty/no-match catalogue and access refusal: covered by the browser/native
   suites. Administrator/shop-manager access works; anonymous writes refused.
6. Publish: not performed. No main merge, public tag, GitHub release or
   WordPress.org submission is authorised. This record covers the private
   installation candidate; deployment evidence belongs with the operator.
