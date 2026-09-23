# Bulk Catalogue Manager for WooCommerce

A simple, beautiful screen inside wp-admin for managing many WooCommerce products at once. Standalone, always free, GPL-3.0-or-later. Edits native WooCommerce product data only; works on a bare WooCommerce site.

Scope: `docs/SPEC.md`. Work: `docs/TICKETS.md`. Prototype: `docs/prototype/`.

## Development

The same commands continuous integration runs (`.github/workflows/ci.yml`, on every push and pull request; no step may fail softly):

| What | Command | CI |
| --- | --- | --- |
| PHP coding standards (WordPress-Extra, PHPCompatibilityWP, escaping and SQL sniffs as errors) | `composer install && composer lint` | PHP 8.1, 8.2, 8.3, 8.4 |
| PHP unit tests | `composer test` | PHP 8.1, 8.2, 8.3, 8.4 |
| Unit tests with no `vendor/` (the bundled runner) | `composer test:standalone` | PHP 8.1, fresh checkout |
| JavaScript install, lint (ESLint + Prettier, zero warnings) and Jest | `npm ci && npm run lint:js && npm test` | Node 20 |
| Bundle build with the 400 KB compressed size check | `npm run build` (then `git diff --exit-code -- assets/build`) | Node 20 |
| Live seed and probe on WordPress with WooCommerce | `npx wp-env start`, then `WP_CMD="npx wp-env run cli wp" bash tests/probe/seed.sh` and `WP_CMD="npx wp-env run cli wp" python3 tests/probe/probe.py --base-url http://localhost:8888` | WordPress 7.1.2 and 7.0.6 × WooCommerce 11.1.2 and 10.9.4 |

wp-env needs Docker with the Compose v2 plugin; CI installs `@wordpress/env` 11.16.0 globally rather than as a project dependency. Without Compose, `tests/probe/README.md` gives the equivalent throwaway `docker run` stack, which is the local route on this project's workstation.

## JavaScript build

`npm ci && npm run build` (Node 20.19 or later, npm 10) builds `assets/src/` into `assets/build/` with `@wordpress/scripts` 35.0.0, the last release supporting Node 20, and fails if `assets/build/index.js` is 400 KB or more compressed. The built files are committed beside their source.

DataViews is not a core script handle, so it is bundled at one tag per supported WordPress major (SPEC §15): `@wordpress/dataviews` 12.0.1, the npm `wp-7.0` dist-tag, with `@wordpress/components` 32.2.1 from the same tag, both pinned exactly in `package.json`. It serves every WordPress 7 release: npm publishes no `wp-7.1` tag for any `@wordpress` package (checked 23 September 2026). On any other WordPress major the screen shows a notice naming the mismatch instead of mounting. To move to a new major, pin the packages from its `wp-X.Y` tag and update `Admin\Screen::DATAVIEWS_VERSION` and `DATAVIEWS_TAG`.
