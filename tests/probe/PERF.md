# Performance at 20,000 products (T76)

The numbers `perf.py` measured against SPEC §12.5. How to run it, and what
each number means, is in `README.md` (Performance). Rerun it before a release
and replace this record.

## Last run

- Date: 24 September 2026, branch `t76-perf`.
- Stack: throwaway docker stack on a 12-core workstation shared with other
  build jobs (load average 6 to 8 during the run). `wordpress:php8.3-apache`
  (PHP 8.3.33, OPcache on), `mysql:8` (MySQL 8.4.11, defaults: 128 MB buffer
  pool), WordPress 7.1, WooCommerce 11.1.0, no persistent object cache,
  `DISABLE_WP_CRON`.
- Catalogue: `seed.sh 20000`, 20,013 products and 70,063 variations (the
  scale catalogue plus the probe fixtures and a 60-variation product).
  Seeding took 1 min 56 s for 18,000 products (bulk inserts, 1,000 a run);
  CRUD saves had measured 3 min 24 s per 200.
- `perf.py --runs 20`: 82 metrics, 33 passed, 3 failed, 46 recorded without
  a budget; 14 min 21 s. Exit 1 (the three failures are the products and
  variations mode below, which §12.5 did not then budget but the T76 ticket
  did). §12.5 now gives that mode its own budget (p95 < 750 ms, cold < 1 s;
  2026-09-24); against it the same numbers give 36 passed, 0 failed.

Server time is PHP's start to the end of the REST dispatch; WordPress and
WooCommerce's own bootstrap (about 60 queries and 110 to 140 ms on this host,
10 ms of it the plugin's) is included. Queries are those inside the dispatch.

## Ticket 04: Off and On at scale (E9)

`perf.py --only offon --runs 20` on 29 September 2026, branch
`codex/global-off-on`: the ticket's throwaway docker stack
(`wordpress:php8.3-apache`, PHP 8.3.33, MariaDB 11.8.9, WordPress 7.1.2,
WooCommerce 11.1.0, no persistent object cache, `DISABLE_WP_CRON` and the
measurement mu-plugin for the run only) with `seed.sh 20000` (20,078
products and 70,084 variations, including the probe fixtures), on a
12-core workstation shared with other builds. 20 metrics: 16 passed,
0 failed, 4 recorded; 15 min 25 s. Every batch went through the durable
routes: a captured selection of the 5,000 simple products in the scale
category with stock 1 to 19, an exact preview, a `/requests` grant, then
`wp action-scheduler run` at the default chunk (50).

| Metric | Measured | Budget | Result |
| --- | --- | --- | --- |
| Off (Status set to Draft), 5,000 products | 1,179 a minute (255 s wall, 100 chunks, 5,000 changed, 0 failed); preview counted `off` 5,000 | ≥ 300 a minute | PASS |
| Same, peak memory per chunk | 17.8 MB (16.4 MB request + 1.5 MB the chunk adds) | < 96 MB | PASS |
| Undo of that Off | 1,553 a minute; 17.9 MB a chunk | as the apply | PASS |
| Off again | 1,587 a minute; 17.8 MB a chunk | as the apply | PASS |
| On (Status set to Published), 5,000 products | 1,320 a minute; 17.8 MB a chunk; preview counted `on` 5,000 | as the apply | PASS |
| Memory kept across 100 chunks in one `wp action-scheduler run` | +3.3 MB in each of the four runs | flat (< 8 MB) | PASS |
| Grid, 25 rows, the default columns (Status among them) with the Off filter, 5,000 Off: p95 of 20 warm requests | 127 ms (median 118); 25 of 25 rows read Off | < 400 ms | PASS |
| Same, queries in the dispatch | 12 | ≤ 20 | PASS |
| Off filter cold (count transients cleared; page + count) | 198 ms, 20 queries | < 500 ms | PASS |
| Off records left in the scale category after On | 0 | 0 | PASS |
| Queue each batch (`POST /batches`) | 375 ms first, 228 ms after; 58 queries | none | INFO |

A chunk of Off or On runs about 3,600 to 3,700 queries (median) for 50
products: each product's status save, its record written in the same
save, the journal rows and the stored status read back (SPEC §5.5, §9.3).

## §12.5 budgets

| Metric | Measured | Budget | Result |
| --- | --- | --- | --- |
| Grid, products, 25 rows, three filters (category, in stock, price 10–40): p95 server time, 20 warm requests | 283 ms (median 225, dispatch 123) | < 400 ms | PASS |
| Same, queries in the dispatch | 12 | ≤ 20 | PASS |
| Query count at 25, 50 and 100 rows (every mode, with and without filters) | 12 / 12 / 12 (variations only: 11) | constant | PASS |
| Filtered count, cold (count transients cleared; whole request incl. page) | 324 ms at 25 rows, 216 at 50, 277 at 100 | < 500 ms | PASS |
| Category tree, cold (tree transient cleared), worst of 3 | 261 ms, 11 queries | < 300 ms | PASS |
| Whole-catalogue selection, products (20,013) | 144 ms | < 5 s | PASS |
| Whole-catalogue selection, products and variations (90,076) | 195 ms | < 5 s | PASS |
| Bulk apply, 10 % regular price increase, 5,000 products, default chunk (50) | 1,005 a minute (299 s wall, 100 chunks) | ≥ 300 a minute | PASS |
| Same, peak memory per chunk | 17.8 MB (16.2 MB request + 1.5 MB the chunk adds) | < 96 MB | PASS |
| Undo of that batch | 1,009 a minute; 17.8 MB a chunk | same as apply | PASS |
| Memory kept across 100 chunks in one `wp action-scheduler run` | +3.2 MB | flat (< 8 MB) | PASS |
| CSV export, 20,000 rows: first byte | 363 ms | < 2 s | PASS |
| Same, complete, every row present | 17.1 s (10.0 s in a quieter run), 1,067 queries | < 60 s | PASS |
| Same, peak memory | 17.0 MB, +0.23 MB over a 2,000-row export | flat | PASS |

The bundle-size budget (under 400 KB compressed, this screen only) is
asserted by `npm run build` and `AssetsTest`, not here.

## T76 extensions and recorded numbers

| Metric | Measured | Budget | Result |
| --- | --- | --- | --- |
| Grid, variations only, 25 rows, three filters: p95 / cold / queries | 332 ms / 430 ms / 11 | as the products grid | PASS |
| Grid, products and variations, 25 rows, three filters: p95 / cold / queries | 514 ms (median 361, dispatch 241) / 669 ms / 12 | < 750 ms / < 1 s / ≤ 20 (§12.5, set 2026-09-24) | PASS |
| Same at 50 and 100 rows, cold | 494 ms / 590 ms | < 1 s | PASS |
| 50-variation expansion (`/products/{id}/variations?per_page=50`), one request | p95 160 ms, 15 queries | < 400 ms, ≤ 20 | PASS |
| Preview of a 10 % increase over 10,000 simple products | 13.0 s, 10,910 queries, peak 19.9 MB | none | INFO |
| Queue a 5,000-product batch (`POST /batches`) | 574 ms, 28 queries | none | INFO |
| `GET /batches` with 234 batches, 25 and 100 a page | p95 170 / 178 ms, 8 queries each | none (count constant: PASS) | INFO |
| History prune of 50,000 journal rows past retention | 14.3 s over 12 runs, longest run 1.8 s | none | INFO |

Unfiltered pages (recorded, no budget) ran 140–200 ms median in products
mode and 300–450 ms in the modes that read variations.

## What was fixed

Each fix has a unit test that fails without it.

1. **Lookup join only when needed** (`Query\ProductQuery`). The
   `wc_product_meta_lookup` LEFT JOIN is added only when a condition or sort
   key reads it; joined on its primary key it never changes the rows. The
   category tree's count went from 240 to 100 ms with it (EXPLAIN ANALYZE).
2. **Join-order hints** (`Query\ProductQuery`). With a lookup condition MySQL
   8 turned the LEFT JOIN inner and drove the query from the lookup's
   one-value `stock_status` index, reading all 90,000 lookup rows (variations
   included) to count 20,000 products. Products mode now carries
   `JOIN_PREFIX(p)`: the filtered count fell from 630 to 180 ms and the page
   from 660 to 210 ms. The variation modes carry `NO_INDEX(l stock_status,
   min_max_price, stock_quantity)`, one pass over the lookup: 200 to 125 ms
   for the count, 250 to 195 ms for the mixed-mode page. A selective lookup
   filter loses its index (13 to 26 ms, 17 to 50 ms): a bounded worst case in
   place of a lucky best case. Servers without optimiser hints read them as
   comments.
3. **Tree counts per mode** (`Categories\Counts`). The sub-query names the
   row's product through `ProductQuery::product_owner()` (`p.ID` in products
   mode) in place of a CASE over every post row, so it reads the type/status
   index only.
4. **Preview memory** (`Engine\Preview`, new `Engine\Memory`). A preview over
   10,000 objects died at the 128 MB limit (HTTP 500). Objects are now
   primed and loaded 100 at a time and the runtime object cache released
   between pages: peak 19.9 MB.
5. **Undo plan memory** (`Undo\Planner`). `GET /batches/{id}` on a finished
   5,000-product batch counts conflicts with a full plan and died at 128 MB
   (so did the undo preview). Paged the same way: 2.0 s, 38.8 MB.
6. **Export memory** (`Export\CsvExport`). A 20,000-row export died at 128 MB
   after about 12,800 rows (a truncated CSV with status 200). The runtime
   cache is released after each 100-row page: 17.0 MB for 20,000 rows.
7. **Chunk memory under WP-CLI** (`Engine\ChunkRunner`). Action Scheduler's
   WP-CLI runner frees memory every 50 actions of one claim, and a chained
   batch claims one at a time, so `wp action-scheduler run` kept every
   chunk's objects: +0.7 MB a chunk, +70 MB over 5,000 products. Each chunk
   now releases the runtime cache after its writes: +3.2 MB over 100 chunks.
   It does not prime its items, so the journal's "before" value is still read
   just before each write (§12.6).

Before the fixes (same stack, heavier load): filtered products count cold
839 ms, category tree cold 558 ms, preview over 10,000 and the 5,000-product
batch read failed with 500, export truncated.

## The products-and-variations budget

The products-and-variations grid with three non-selective filters matches
53,960 of 90,000 rows. Sorting by name needs every match's title, so the
statement reads each matching post and lookup row (190 ms of SQL here);
hydration and the bootstrap bring the request to 360 ms median and 510 ms at
the 95th percentile on this shared host. Without an index on `post_title`
(SPEC §12.2: no index on a core table) the work is proportional to the
matches. The products grid (§12.5's budget) and the variations-only grid
pass. The mixed mode has its own §12.5 budget since 2026-09-24: p95 under
750 ms and cold under 1 s, which the numbers above meet with room for a
busier host.

The T76 row's proof case (forcing the term-relationships join on to show the
suite fails) and a `performance.json` CI job are not done: `perf.py --json`
writes the file, and the suite is too slow for CI as it stands.

## CSV export by POST (ticket 10, T102)

Run on 29 September 2026 on the CSV slice's throwaway stack (PHP 8.3.33,
MySQL 8.4.11, WordPress 7.1, WooCommerce 11.1.0, no persistent object
cache, `DISABLE_WP_CRON`, the measurement mu-plugin above), with
`perf.py --only export,export_post` at `seed.sh 20000`, then
`--only export_scale` at `seed.sh 22223` (the scale category then holds
exactly 100,000 products and variations). The identifier switch is on in
every POST row. Budgets are §12.5's.

| Metric | Result | Budget |
| --- | --- | --- |
| GET, 20,000 rows (unchanged group) | first byte 355 ms, complete 18.2 s, peak 17.1 MB (+0.23 MB over 2,000) | < 2 s, < 60 s, flat |
| POST by filter, 20,000 rows | first byte 108 ms, complete 13.0 s, peak 17.1 MB (+0.23 MB) | < 2 s, < 60 s, flat |
| POST by selection, 20,000 members | first byte 253 ms (token resolution and presence included; capture 0.3 s before it), complete 3.2 s, peak 22.2 MB (+5.26 MB) | < 2 s, < 60 s, flat |
| POST by selection, 100,000 members | peak 48.8 MB, first byte 0.7 to 0.9 s, complete 14.5 to 17.7 s, 4,952 queries | < 128 MB |
| Summary with identifiers, 100,000 members | 1.55 s, 129 queries (presence 100, guarded counts 20), peak 48.8 MB | recorded |
| Summary with identifiers, 22,223 rows by filter | 0.98 s, 9 queries | recorded |

A selection streams faster than a filter: its pages are hydrated straight
from the present members, without the grid's sort and count per page. The
selection's peak over its 2,000-member run is the members decoded while the
token resolves (dropped before presence is read), not the streamed pages.
