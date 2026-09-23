# Bulk Catalogue Manager for WooCommerce

A simple, beautiful screen inside wp-admin for managing many WooCommerce products at once. Standalone, always free, GPL-3.0-or-later. Edits native WooCommerce product data only; works on a bare WooCommerce site.

Scope: `docs/SPEC.md`. Work: `docs/TICKETS.md`. Prototype: `docs/prototype/`.

## JavaScript build

`npm ci && npm run build` (Node 20.19 or later, npm 10) builds `assets/src/` into `assets/build/` with `@wordpress/scripts` 35.0.0, the last release supporting Node 20, and fails if `assets/build/index.js` is 400 KB or more compressed. The built files are committed beside their source.

DataViews is not a core script handle, so it is bundled at one tag per supported WordPress major (SPEC §15): `@wordpress/dataviews` 12.0.1, the npm `wp-7.0` dist-tag, with `@wordpress/components` 32.2.1 from the same tag, both pinned exactly in `package.json`. It serves every WordPress 7 release: npm publishes no `wp-7.1` tag for any `@wordpress` package (checked 23 September 2026). On any other WordPress major the screen shows a notice naming the mismatch instead of mounting. To move to a new major, pin the packages from its `wp-X.Y` tag and update `Admin\Screen::DATAVIEWS_VERSION` and `DATAVIEWS_TAG`.
