# Exact selections and preview/Apply follow-up

**Stage:** T81 accepted by Main Build on 25 September and T82 on 26 September. T83 is built and verified locally (28 September; rulings below; evidence `evidence-20260928/t83-runtime.md` in the client workspace) and awaits independent review and Main Build acceptance. T84–T85 are next. The branch is pushed to the private remote; no merge, release or production change is included.

**Goal:** accumulate an exact selection across searches, inspect it, preview a native change, Apply, and clear only genuinely completed scope while retaining recovery on partial failure.

**Contract:** [EXACT-SELECTION.md](../EXACT-SELECTION.md) and SPEC §§3, 4.1, 4.3, 9.1–9.2, 12.1, 12.6 and 15. Engineering choices are specified; no additional product confirmation is required. Runtime implementation follows focused failing regressions, then the existing release checks. Do not change the visibility action's meaning as part of this increment.

## Baseline and reproduction

Source `095db24350eb240a5c71b08d70e5e106f124332b`, isolated branch `codex/exact-selection-plan`. An unrelated accessibility-test edit in the primary checkout was not copied or edited.

Focused PHPUnit: **45 tests, 2,438 assertions, pass** (`SelectionTokenTest|SelectionRouteTest|PreviewRouteTest|PreviewTest`). Focused Jest: **2 suites, 34 tests, pass** (`catalogue-selection.test.js`, `bulk-panel.test.js`). A previously proposed `stale-selection.test.js` does not exist; it is not a third passing suite. Jest prints the existing jsdom CSS-parser warnings; they do not fail these suites. No changed-runtime or full-release verification is claimed.

Owned disposable fixture: WordPress 7.1, WooCommerce 11.1.2, PHP 8.3, MySQL 8, unchanged plugin 1.0.0, synthetic scale catalogue plus the existing small probe fixture. **20,012 products and 70,003 variations**. Loopback-only browser endpoint, separate database/network/volume; cron disabled. Existing unrelated local stacks were not reused.

The browser selected a product or all products, opened **Hide from site**, and pressed **Check the change**. It did not Apply a catalogue-wide change.

| Selection / viewport | Click to preview response | HTTP | State and footer |
|---|---:|---:|---|
| One product, 1440×900 | 214 ms | 200 | Ready; Apply to 1 product enabled |
| All 20,012 products, 1440×900 | 17,611 ms | 200 | Ready; Apply to 20,012 products enabled |
| All 20,012 products, 1024×800 | 14,065 ms | 200 | Ready; Apply to 20,012 products enabled |
| All 20,012 products, 1440×680 | 11,432 ms | 200 | Ready; Apply to 20,012 products enabled |

The short viewport is a conservative short-window check, not an asserted recording viewport. Ready screenshots show the footer inside the viewport. The preview clearly labels counts as scaled from the first 10,000 products. No browser page errors were recorded.

A separate native REST dispatch took **12,195 ms**, **15,918 queries**, with a **133.8 MiB PHP peak** for the request. Aggregate product/status/modified, metadata and term-relationship checksums were identical before/after preview. Those checks are evidence of no observed catalogue mutation, not a byte-level database backup. Native dispatch bypasses HTTP authentication/network timing; browser tests cover the actual logged-in request path.

These are individual local measurements, not p95 results or production guarantees. Warm cache/host load were not controlled; one native measurement overlapped the browser sequence, so differences cannot be attributed to viewport size. The cached image's WordPress 7.1 differs from the latest patched installation. No actual Apply/queue/undo, 100,000-member new-storage benchmark, keyboard-only sequence or injected-network failure was run in this planning increment.

**Finding:** the reported missing Apply/timeout was not reproduced. A significant computing wait was reproduced. Current evidence supports regression coverage and truthful waiting/retry behaviour; it does not establish a backend timeout, absent Apply implementation or need for asynchronous preview jobs. Keep the incident open for a timestamped failed request if it recurs.

## Source map

| Boundary | Current source | Planned responsibility |
|---|---|---|
| View vs selection | `assets/src/panels/catalogue/selection/use-selection.js`, `model.js` | Persistent opaque snapshot; serialized intents/grants; generation guard; suspended-tab recovery |
| Current-page membership | `selection/selection-bar.js`, table selection callbacks | Inspect visible IDs; never narrow all-matching to one page |
| Selection issuance | `src/Rest/SelectionController.php`, `Selection/SelectionToken.php` | Capture once; owner-bound v2 token with renewable authorization epoch |
| Lean membership capture | `src/Query/ProductQuery.php` | Single bounded prepared identity/type SELECT |
| Storage lifecycle | `src/Data/{Schema,Migrator,Registry}.php` | Schema 7 selection + receipt tables, installation epoch, two bounded cleanup hooks |
| Request replay | `src/Engine/BatchFactory.php`, `src/Data/BatchRepository.php`, `src/Rest/{BatchesController,UndoController}.php` | Durable owner/UUID/fingerprint resolution before lease/preview checks and header creation; remove 60-second legacy cutoff |
| Preview/batch targets | `src/Engine/{Preview,BatchFactory}.php` | Snapshot resolver and authenticated expanded scope; atomic acceptance then scheduling |
| Completion | `assets/src/panels/catalogue/index.js`, bulk hooks | Consume actual final report; preserve newer intent/recovery |
| Preview state | `assets/src/panels/bulk/{hooks,panel,preview,confirm}.js` | Test waiting, error, ready, confirmation and same-grant retry |

## 1. Frozen storage and durable request foundation (T81)

**Local implementation checkpoint (26 September):** T81 is accepted; T82 composition/inspection/renewal/release is implemented and independently reviewed. `selection.capture` and `selection.compose` grants are supported; Apply/undo validators/consumers remain T83. The legacy UI stays on its existing path until T84. TICKETS records verification. No production changes. *(28 September: T83 has since delivered the Apply/undo grants and consumers; see section 3.)*

- [x] Add `Selection\SnapshotRepository`: immutable typed members plus separate authorization epoch/lease, seven-day inactivity retention and first-release deadline; injectable clock/identity source. `Selection\SnapshotService` owns capture/composition and authorization rules; keep product writes out of it.
- [x] Add `Requests\RequestRepository` for durable owner/UUID/operation/fingerprint/outcome receipts; `Requests\RequestService` owns signed request grants, canonical intent and receipt-first resolution. Keep these responsibilities separate from selection payload pruning. Add `Rest\RequestsController` and `RequestResolveController` through `Routes`/`RouteSchema`/`Access`/`Refusal`.
- [x] `POST /requests` issues a new server UUID and signed, non-renewable 24-hour grant for canonical capture/compose/Apply/undo intent; no membership capture/product action. Return the normalized immutable intent to retain with the grant. Client-supplied or reissued old UUIDs cannot renew acceptance. `POST /requests/resolve` never creates work. Both routes require current authentication, nonce and capabilities.
- [x] Implement the contract's resolution order: durable owner/UUID/fingerprint before lease/preview/grant-expiry checks; matching receipt returns original result, changed input 409, gone result 410. With no receipt, a valid unexpired grant is mandatory for new acceptance; recheck deadline before commit. A missing receipt after cleanup cannot revive an expired signed grant.
- [x] Claim the unique owner/UUID key and create snapshot/result/accepted receipt in one transaction. Concurrent claims converge or get a retryable lock-timeout result. Rollback leaves no accepted result; ambiguous commit resolves by the same UUID. Require transactional storage; refuse new work when migration/storage prerequisites are incomplete.
- [x] Add `ProductQuery::snapshot_members()` sharing prepared conditions, with a single `LIMIT 100001` identity/type query, independent of view sort/page and cached count. No `WC_Product` objects or added core indexes.
- [x] Schema **6 → 7** adds `bulkcat_selections` and `bulkcat_requests`, five tables total, plus non-autoloaded random `bulkcat_request_epoch`. Register everything through `Data\Registry`; idempotent migration preserves existing headers/items/journal/worker arguments. Retain-data uninstall preserves receipts/epoch; opt-in deletion removes them and reinstall changes epoch, rejecting old grants.
- [x] Register hourly `bulkcat_prune_selections` / `bulkcat_prune_requests`, each at most 100 eligible rows per transaction with deadline recheck under lock. Payload deletion tombstones its receipt atomically; selection receipts/tombstones last eight days from issuance. Never delete an Apply/undo receipt whose batch header remains. Deactivate/reactivate unschedules/schedules both hooks once.
- [x] Write failing tests for capture A/B → later match C → retry returns only A/B; lost response → payload prune → gone; receipt prune → signed expiry refusal without recapture. Include grant tampering/other owner/site/install epoch, changed payload, lost issuance response, concurrent duplicate claims, rollback/ambiguous commit, zero/100,000/100,001 members and retained unavailable IDs.
- [x] Tests: new `tests/Unit/Selection/{SnapshotRepositoryTest,SnapshotServiceTest}.php`, `tests/Unit/Requests/{RequestRepositoryTest,RequestServiceTest}.php`, request route tests; extend selection token/route, query and lifecycle tests. Native transactional probes, not only SQL mocks, must prove capture/concurrency/pruning invariants.

**Gate:** no whole-ID response or catalogue mutation. Capture stays within the existing five-second scale target; measure maximum-size row encoding, transactional materialisation and PHP memory before merge. The unchanged-runtime timing is not evidence for the new store.

## 2. Compose, inspect, reauthorize and release (T82)

- [x] Implement capture plus composition/inspection/renewal/release transports (one shared thin `SelectionActionsController` avoids duplicating the same schema/authorization handling) to the exact schemas in EXACT-SELECTION.md. Capture/compose use T81 grants and resolve prior outcomes before lease checks. Inspect/renew/release are idempotent handle operations without new request identities.
- [x] Union IDs/snapshots and subtract IDs/snapshots; preserve predecessor, deduplicate before cap, allow empty successor. Reject unknown/multiple operations. Hydrate only an inspected page, with visible-ID intersection; unavailable members keep their slot and are removable without protected metadata.
- [x] Default authorization lease 15 minutes; existing TTL filter remains bounded 60–86,400 seconds. Keep immutable payload seven days since creation/authorized keepalive, independently of authorization. Live renew keeps token/epoch/preview valid; expired-lease renewal under current login/nonce/capabilities increments epoch and requires a fresh preview of the same IDs. Concurrent renewals converge; old token cannot authorize work in the new epoch.
- [x] On release of an unreferenced handle, shorten retention to the earlier deadline or one hour after first release; duplicate release cannot extend it. Keepalive can retain still-present, not-expired membership again. Missing release/unload falls back to seven-day inactivity cleanup. Retention expiry refuses rather than recapturing even if pruning has not run yet.
- [x] Regressions: overlapping union/off-page subtraction, stale/tampered/other-owner tokens, unavailable removals, page bounds, UUID conflict, lease/retention boundary races, permission loss/restoration, keepalive vs prune, idempotent release. Suspended 30-minute/overnight tab recovers A/B even after C starts matching; after genuine retention expiry it reports lost work honestly.
- [x] Native permission probes for every new route (anonymous/subscriber/manager, missing or stale nonce). No named saved-list, browser ID cache or cross-session loader is added.

## 3. Frozen preview and durable Apply/undo (T83)

- [x] Resolve v2 snapshots into exact typed members, preserving explicit-ID consumers. New grant-based bulk requests use an authenticated `scope_hash` for expanded IDs/per-step reach, including explicit IDs. Refuse old v1 tokens with reload/reselect guidance while existing jobs/history continue.
- [x] Preview hash binds current snapshot authorization epoch. Live renewal preserves it; expired-lease reauthorization requires preview again. Apply materialises exactly the verified expanded scope; same-count substitution/new child variation requires preview again. Missing/refused selected objects remain in scope/outcomes; never call a smaller result complete.
- [x] Replace the current 60-second replay decision with T81's durable resolver in bulk Apply and queued undo, including explicit-ID bulk callers. Preserve the controller's existing replay-before-selection ordering and extend it to full immutable fingerprints. Cache is only acceleration. Resolve accepted requests before selection retention/authorization, preview freshness or grant expiry can block the original result.
- [x] Commit unique receipt claim, batch header, full materialised item set and accepted outcome atomically. Enqueue only after commit; idempotently reconcile scheduling for that same batch after a commit/scheduling crash. Never requeue terminal jobs or mint fresh intent to recover uncertain acceptance.
- [x] Store Apply/undo receipts as long as batch headers; history item/journal pruning leaves them usable. Future header removal retains a gone tombstone through at least issuance plus eight days, after which the signed deadline still blocks new acceptance.
- [x] Remove the legacy `earlier_request` age cutoff and run it before new header creation. Do not add uniqueness to old batch UUID columns. Known unreceipted UUIDs return an exact provable match or a clear legacy-conflict/history reference; multiple old headers are ambiguous and must not create a third. Unknown bare legacy UUIDs require reload. Preserve queued old jobs, original history and undo through schema upgrade.
- [x] Write failing regressions: relative Apply accepted → response lost → transient evicted → >60-second retry returns same batch; repeat after authorization and membership expiry. Concurrent matching submission yields one header/items/queue; changed operation/selection/source/undo intent under same UUID conflicts. Cover rollback, ambiguous commit, scheduling gap, pruned history details/header tombstones, expired missing receipt and legacy duplicates. Assert one application of the relative change.
- [x] Extend preview/route/batch-factory/repository/undo tests. Native journey: select → drift fixture → preview (no catalogue write) → Apply → plugin queue only → values/journal readback → undo → restored values. Include a native category assignment, variation expansion and explicit-ID bulk path.

T83 verification (28 September): 781 PHP tests / 18,600 assertions and WPCS clean; 13 new unit regressions red against the step-2 source; native `exact-batch.php` 46, `exact-batch-routes.php` 48 and `exact-batch-faults.php` 118 checks, two overlapping-process races, `probe.py` write and variations 42 passed over HTTP, T81/T82 probes still green, Plugin Check clean apart from the 11 documented warnings. Not yet run here: the browser journeys and the full seven lifecycle groups through the screen (T84/T85) and a 100,000-member Apply timing (T85).

### T83 engineering rulings (28 September)

The contract left these open; each takes the conservative option.

- **Canonical intents are never normalised again.** Issuance runs the operation list through the registry once and returns it with every default written out; a submitted canonical intent is only shape-checked (exact keys, sorted unique ids, one to twenty `{ action, args }` steps, digests), so its fingerprint is exactly what was signed and a later settings or registry change cannot stop an accepted request resolving. Whether the registry would still produce the same list is asked only before a new acceptance (`BatchIntent::current()`): a refusal is returned, a difference is `bulkcat_preview_stale`.
- **`bulkcat_batch_created` fires on the durable path** (it is a documented public hook): once, after the acceptance transaction commits, for the request that committed the batch; never on a replay. Scheduling does not depend on it: `BatchRecovery::finish()` ensures a pending or running chunk action for a queued or running batch after every acceptance and replay. A crash between the commit and the action leaves the batch scheduled by the next resolution without the action firing; it can never fire twice.
- **A 1.0 screen cannot create work.** A bare `uuid` on `/batches` or `/undo` is resolved against the user's batch headers at any age (`BatchFactory::earlier()`): none is 409 `bulkcat_legacy_request` asking for a reload (empty `batch_ids`); exactly one that is the same request (same operations and ids or v1 filter conditions; for undo the same batch, plan hash and force) is its 200 replay; anything else is 409 naming the owner's batch ids as History references. Consequence: the unchanged 1.0 screen on this branch can preview explicit ids but cannot Apply or undo until T84 sends grants; the branch is not releasable between T83 and T84.
- **`BatchFactory::create()` / `create_undo()` stay as the trusted internal engine API** (no REST caller since T83; tests and probes use them). The 60-second transient and the create-then-discard race guard are gone; the any-age lookup runs before anything is written. The `bulkcat_batch_request_` prefix stays registered so uninstall still removes 1.0 transients.
- **Every preview is exact.** Explicit ids are sorted and deduplicated; `totals.matched` counts unavailable members, which stay in the scope and become batch items that finish failed with a plain reason, so such a batch ends `partial` or `failed`, never `complete`. A scope whose members are all unavailable is still recorded rather than silently refused. 1.0 transient tokens are refused on preview with the reselect refusal.
- **An object's kind is checked at write time.** `Writer::write()` takes the item's kind; an object that became a variation or a product since it was chosen fails with `bulkcat_changed_kind` and is not written. The preview never loads an unavailable scope member.
- **The preview shows nothing of an object the user may not edit**: every step is skipped with the permission reason, with no name, current or new value, and no warning is counted from it.
- **Scheduler compatibility:** if a unique enqueue is refused while no action exists for this batch (an Action Scheduler whose uniqueness ignores arguments), a plain enqueue follows; duplicate chunk actions are harmless because items are claimed individually.
- **The durable batch header's selection summary** records unavailable members as a count (the items hold their identities) and the snapshot id, never a whole-ID list for a snapshot.

## 4. Selection UX and completion (T84)

- [ ] Replace filter-change selection resets with view-only updates in `selection/use-selection.js`. Serialize mutation intents with UUID/proof/normalized payload, confirmed envelope and Clear generation. Resolve uncertain outcomes before reauthorization/new grants; `not_accepted` is not license to mint a different UUID while the original grant can still execute. Disable Preview/Apply while membership is uncertain.
- [ ] Update selection model/bar, toolbar/index and a paginated `selection/selected-products.js`. Route calls through existing `assets/src/api.js`. Use actual membership for checkboxes; preserve search replacement through empty text, filters and mode changes; deliberate Clear follows the accepted contract.
- [ ] Renew only current/submitted/additions/unresolved-recovery handles while active. On tab return, recover current authentication, resolve uncertain accepted requests, reauthorize retained membership and require preview after an expired lease. Release only acknowledged, unreferenced intermediates, never a submitted handle simply because the job is queued. Display genuine payload expiry without silent reselection or completion.
- [ ] Keep submitted snapshot with final-report callback. Full success clears only submitted scope while preserving newer additions/removals; partial/failed/cancelled jobs retain recovery. Renew retained handles before completion composition if needed; if retention is gone, leave newer work intact and explain incomplete cleanup.
- [ ] Jest/browser cases: successive SKU searches, add all matches, off-page untick, duplicate additions, missing member, empty typing, clear during delayed response, rapid toggles, new/re-added selection during older job, all final statuses, lost response/grant retry, >15-minute suspension and overnight recovery, permission restoration, released intermediate/lost unload and >7-day inactivity refusal.
- [ ] Keyboard-only review/removal, focus and status announcements. Preserve unrelated `tests/e2e/a11y.spec.js`; new journey in `persistent-selection.spec.js`. Update translations/POT when runtime strings are introduced.

## 5. Evidence-bounded integration (T85)

- [ ] Keep the reproduced 11–18-second wait as a regression scenario. Inject delayed/rejected/empty/superseded preview responses; show computing, useful failure retry and no-op reason. Older responses never enable an invalid Apply.
- [ ] Browser footer/confirmation checks at 1440×900, 1024×800 and 1440×680; record actual failed request if the reported incident recurs. No guessed timeout, asynchronous-preview architecture or CSS patch without a demonstrated failure.
- [ ] Run all **seven lifecycle regression groups** in EXACT-SELECTION.md on native transactional storage. Cover old schema/jobs/duplicate UUID upgrade, retained vs deleted-data reinstall, both cleanup hooks and bounds, receipt/payload pruning, authorization/retention separation, concurrent claims/renew/prune and commit/scheduling recovery.
- [ ] Run native preview/Apply/journal/undo above, assert unrelated products unchanged. Focused PHP/Jest after each implemented seam, then PHP/static, JS/i18n/build and native/browser checks for changed surfaces before merge. New-store capacity/transaction memory remain measured gates, not assumptions.
- [ ] Review security, one relative application per accepted request, canonical fingerprint normalization, storage retention, permissions on return, variation scope, current-value semantics and completion races. No auto-retry may obtain new intent until the prior request is definitively resolved.
- [ ] Do not commit generated bundles; maintainer rebuilds after merge. Record release/cutover separately; this plan does not authorize them.

## Review corrections and stopping point

The 25 September engineering review found three lifecycle gaps: pruning the sole snapshot receipt could permit recapture; the existing 60-second Apply deduplication cannot cover delayed uncertain retries; and authorization expiry alone must not discard a suspended open tab's selection. T81–T85 now specify independent durable receipts, bounded signed acceptance, and reauthorization of retained exact membership. These corrections refine the accepted workflow, without new business scope.

*(Historical, 25 September.)* Baseline/scale measurements above were **not rerun** for this documentation correction. All implementation checkboxes remained open at that point; no new runtime code, tests, schema execution, built bundle, commit, push or live change is claimed. Return the corrected contract/SPEC/plan/tickets for the coordinator's focused check and stop before runtime implementation.
