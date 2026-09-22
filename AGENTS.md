# Bulk Catalogue Manager for WooCommerce — agent entry point

Read in this order, then start work: `docs/SPEC.md` (the agreed shared understanding; change it there before changing code), `docs/TICKETS.md` (80 tickets in build order; each ends with tests green), `docs/prototype/index.html` (open in a browser; it is the design the code must match), `docs/SCOPE-2026-09-21.md` (the owner's scoping answers).

## Hard rules from the owner (Wade Beckett)
- Standalone: works on a bare WordPress + WooCommerce site with nothing else installed and zero site-specific code. Edits native WooCommerce product data only, through WooCommerce CRUD classes.
- No dependency on, module for, detection of, or mention of any third-party plugin in code, docs or UI. Per-customer or per-group product access is out of scope permanently. No client names or client fields anywhere.
- Always free, GPL-3.0-or-later, built to WordPress.org directory standards from the first commit (WPCS, translation-ready, no external calls, uninstall cleanup, Plugin Check clean).
- "Beautiful" means simple and easy for a non-technical shop owner. Plain-English labels; never show a raw meta key.
- Variations are first-class. Every bulk write is previewed first, journaled, and reversible.
- Do not ask the owner to define these further. Fix it, do not ask.

## Identifiers (must match SPEC §15)
Slug and text domain `bulk-catalogue-manager-for-woocommerce`; prefixes `bcm_` / `BCM_` / `bcm-`; PHP namespace `BulkCatalogueManager`; REST namespace `bcm/v1`; PHP >= 8.1, WordPress >= 7.0, WooCommerce >= 10.0. UI on `@wordpress/dataviews` bundled via wp-scripts, server-side paged.

## House conventions
Copy the layout and tooling of the sibling repo `(local path)` (formerly hide-prices-from-guests-for-woocommerce) (composer.json, package.json, phpcs, PHPUnit with the standalone runner `tests/run-tests.php`, `.wp-env.json`, GitHub Actions, `bin/build-zip.sh`, `.distignore`, `readme.txt`, `docs/TICKETS.md` note style). Its `docs/TICKETS.md` Notes section records what does and does not run on this workstation (Node 18, Docker group membership, wp-env).

Commit as the repo's configured author. Mark a ticket done in `docs/TICKETS.md` with the date and a note of what was verified, in the sibling's style. Remote: `origin` = github.com/wadejbeckett/bulk-catalogue-manager-for-woocommerce (private); push `main`.

## Client workspace
Acme (the first install target) keeps its own records at `(local path)`; nothing from that workspace belongs in this repo. Installation on example.com is authorised as a live build by the owner, but only after he signs off the prototype and answers SPEC §18, and only with every write reversible.

## Current state (23 September 2026)
T1–T2 done: plugin skeleton, autoloader, PHPUnit and PHPCS harness (`composer lint`, `composer test`, `composer test:standalone`). T3–T5 need no owner answers and can start; later work waits on SPEC §18 answers and prototype feedback.
