# Exact selection contract

Status: follow-up to 1.0.0 on branch `codex/exact-selection-plan`. T81 (capture and receipts) was accepted locally on 25 September and T82 (composition and lifecycle) on 26 September; T83 (exact preview, durable Apply and undo, 1.0 request ids) is built and verified locally as of 28 September, its independent review's findings are closed, and it awaits Main Build acceptance; T84 (the screen) and T85 (integration proof) are next. Engineering rulings made during T83 are in the plan. No merge, release or production rollout is implied.

## Behaviour

Selection and the current view are independent. Ticked rows and **Select all matching** both add exact identities to the same selection. Search, filters, sorting, paging, category browsing and product/variation modes do not clear it. Editing a search through an empty string does not clear it. **Clear search** explicitly clears the search and selection; **Clear selection** clears only the selection. Removing a row removes only that identity, including after selecting every matching result.

The bar reports the entire selection, including rows outside the current view, and offers **Review selected products**. The review is paginated and supports removal. Page checkboxes use actual membership, never a blanket “all matching” flag. Selecting every match captures membership at the server's selection query, not later at preview or Apply. A new match cannot enter an existing selection.

This is an editing-session selection, not a saved list or cross-session recovery feature. The API's expiry and renewal rules below make that boundary explicit.

## Storage decision

Schema migration **6 → 7** adds two plugin-owned tables: `{prefix}bulkcat_selections` for immutable membership and `{prefix}bulkcat_requests` for request receipts. Keep the existing batch/items/changes tables: five tables in total. Interactive membership and replay protection have different lifetimes and must not share a single prunable row. Do not put whole ID sets in the browser, autoloaded options or a cache entry.

Transients can disappear before their advertised expiry, so they cannot be the only copy of selected work or accepted-request identity. See the [WordPress Transients API](https://developer.wordpress.org/apis/transients/).

### Selection table

| Column | Definition / purpose |
|---|---|
| `id` | `CHAR(32)` primary key; cryptographically random snapshot identity |
| `user_id` | `BIGINT UNSIGNED NOT NULL`; owner on this site |
| `members` | `MEDIUMTEXT NOT NULL`; JSON `{products:[ids],variations:[ids]}`, sorted, unique, disjoint |
| `object_count` | `INT UNSIGNED NOT NULL`; exact count including subsequently unavailable objects |
| `created_at` | `DATETIME NOT NULL`, UTC |
| `authorization_epoch` | `INT UNSIGNED NOT NULL`; starts at 1, changes on reauthorization after expiry |
| `authorization_expires_at` | `DATETIME NOT NULL`, UTC |
| `retained_until` | `DATETIME NOT NULL`, UTC; independent payload-retention deadline, indexed |
| `released_at` | `DATETIME NULL`, UTC; first explicit release, for bounded intermediate cleanup |

Index `(user_id, retained_until)` supports owner cleanup. Request identity/fingerprint belong to the receipt table below. A composition creates a successor rather than changing its base's members.

The token is `v2.<id>.<authorization_epoch>.<HMAC>`, bound to version, site, installation epoch, owner and snapshot. It is opaque to callers, never placed in URLs/logs. A valid signature identifies retained work; it is not permanent authorization to act. All access also requires the current login, nonce and capabilities.

### Authorization and retained work

- **Authorization lease:** 15 minutes by default. Preserve the existing TTL filter bounded 60–86,400 seconds. Active screens renew at most every one third of that lease (five minutes by default). Extending a live lease keeps its epoch/token, so ordinary renewal does not invalidate preview.
- **Retained exact membership:** seven days from creation or the last authorized keepalive. Retention is independent of authorization expiry. Renew only the current, submitted and post-submission-additions snapshots held by the open screen, plus an unresolved mutation result while needed for recovery.
- **Suspended tab:** on return, refresh the login/nonce if necessary, then reauthorize the retained snapshot through `/selection/renew`. If its authorization expired, atomically increment the epoch, issue a fresh lease/token and require a new preview. Keep precisely the original IDs; inspect missing/revoked objects under current permissions. No old query is replayed. Revoked access refuses without exposing metadata or declaring success; it does not erase retained work.
- **Intermediate/abandoned work:** after a successor is acknowledged and an old token is no longer held as current, submitted, additions or unresolved recovery, the serialized client queue may `/selection/release` it. First release shortens payload retention to the earlier of its current deadline and one hour after release; repeat release does not extend it. A subsequent authorized keepalive before deletion can retain that still-existing exact set again. Clear/unmount sends best-effort release for unreferenced tokens. Missing unload events/crashed tabs fall back to the seven-day inactivity bound.
- **Beyond retention:** an open tab returning after more than seven days without an authorized keepalive may find payload removed. Return 410 `bulkcat_selection_expired`, preserve its visible summary with an explicit expired-work notice and require deliberate reselection. This bounded abandoned-work rule is separate from routine 15-minute tab suspension. Never present it as empty or completed work.

No named saved lists, catalogue of sessions, local-storage token recovery or automatic cross-session loading is added. The page keeps its opaque handles in memory; the retained data allows that same open page to survive normal suspension.

Hourly `bulkcat_prune_selections` processes at most 100 eligible payloads per transaction, rechecking `retained_until` under lock. It tombstones any receipt pointing at a deleted payload in the same transaction. A keepalive that wins the race prevents deletion; a cleanup that wins produces an explicit gone response. Register both tables, their cleanup and the non-autoloaded `bulkcat_request_epoch` option in `Data\Registry`. That random installation epoch is created once during migration, preserved with retained plugin data and removed by opt-in data uninstall, so old request grants cannot execute after a delete-and-reinstall reset. Pending batches already own their materialised IDs.

## Request identities and durable replay

A bare client UUID cannot prove its age after all rows containing it have been pruned. Use a **server-issued, non-renewable request grant**, distinct from a selection authorization token, plus a durable outcome receipt. This applies to capture, compose, bulk Apply (snapshot and explicit-ID selection) and queued undo.

### Issuance and acceptance window

`POST /requests` receives an operation kind (`selection.capture`, `selection.compose`, `batch.apply`, `batch.undo`) and the intended payload. It validates/canonicalizes the intent and returns a fresh server-generated UUID and a signed `request_proof` binding the UUID, owner, site, installation epoch, operation, canonical fingerprint/normalization version, issuance time and **24-hour acceptance deadline**. Return the canonical intent with defaults explicit for the client to retain; an unsupported older normalization version refuses instead of silently reinterpreting it. It does not resolve filter membership or enqueue work. Caller-chosen UUIDs and requests to renew/reissue an existing UUID are refused. Losing an issuance response cannot have performed a product action.

The client stores that UUID/proof with the immutable intended payload before submitting it. A retry reuses them. It never silently obtains a new grant to retry an uncertain operation. New intent, or a deliberate Apply after resolving an expired unaccepted attempt, gets a new grant.

The fingerprint covers operation kind/source, stable selection identity or canonical explicit IDs/query, normalized ordered operations with defaults made explicit, expanded `scope_hash` for Apply, and the original batch/plan/force inputs for undo. Exclude transport values (nonce, renewed authorization token/epoch) and derived preview authorization proof. Freeze normalization at issuance; do not recalculate old intent using changed settings/defaults when resolving a retry. Same UUID with changed semantic input is 409 `bulkcat_request_conflict`, even for a completed job.

### Receipt table

| Column | Definition / purpose |
|---|---|
| `user_id`, `uuid` | `BIGINT UNSIGNED` and `CHAR(36)`; composite primary/unique key across operation kinds |
| `operation` | `VARCHAR(32) NOT NULL` |
| `fingerprint` | `CHAR(64) NOT NULL`; canonical immutable intent hash |
| `issued_at`, `accept_until` | `DATETIME NOT NULL`, UTC; copied from verified grant |
| `state` | `VARCHAR(16) NOT NULL`; `accepted` or `gone` for committed receipts |
| `result_kind`, `result_id` | `VARCHAR(16)` and `VARCHAR(32)`; snapshot or batch identity |
| `retain_until` | `DATETIME NULL`, UTC; indexed; NULL for a receipt retained with its batch header |

For fresh acceptance, claim the unique owner/UUID key, create the result and store the accepted receipt **in the same database transaction**. A selection's one-statement capture and snapshot insert occur under that claim. For Apply/undo, the batch header, complete item materialisation and accepted receipt commit together before scheduling any work. Concurrent duplicate keys wait for that transaction then resolve its receipt; a bounded lock timeout returns a retryable in-progress refusal, never starts another batch. Failed transactions leave no accepted result. An ambiguous database/commit outcome is resolved by the same UUID, never by creating new intent. Require transactional tables for these operations; fail closed with an actionable storage error if that precondition is unavailable.

After commit, ensure the existing batch is scheduled idempotently. A crash between commit and scheduling must be recoverable by enqueue reconciliation for that **same batch**, without another header or another relative change. Do not restart or requeue a terminal batch when replaying its receipt.

### Resolution order and cleanup

1. Authenticate the current user/nonce and required screen/write capabilities; validate request syntax and immutable intent shape.
2. Resolve `(user_id, uuid)` durably and compare operation/fingerprint **before checking selection lease, payload retention, preview freshness, request-grant deadline or creating a new batch**. Matching accepted requests return the original snapshot identity (without extending its lease/retention) or existing batch/status with `replayed: true`; a retained snapshot whose lease expired is flagged for renewal, and elapsed payload retention is gone even before physical pruning; gone results return 410, never re-execute. Renewed authorization/expired selection cannot hide an already accepted Apply. Permission loss still refuses access.
3. If no receipt exists, perform the legacy-batch lookup described below. Only a genuinely new request with a valid signature/installation epoch and an unexpired 24-hour acceptance window may run selection/preview checks and attempt acceptance. Recheck that deadline immediately before commit.
4. A missing receipt plus an expired grant returns 410 `bulkcat_request_expired`. The signed deadline remains verifiable after cleanup, so pruning cannot turn a previously used identity into a fresh capture. A missing/invalid grant refuses new acceptance. Grant issuance never accepts an old UUID to refresh that deadline.

Selection receipts/tombstones remain for **eight days from grant issuance**, independent of payload release. Thus payload cleanup can return a definite gone result while the receipt exists, and receipt cleanup occurs well after the latest acceptance deadline. After receipt pruning the expired signed grant still refuses instead of recapturing. The same rule covers grants issued but never accepted.

Apply/undo receipts remain for **as long as their batch header**. Existing history cleanup removes items/journal and keeps the header, so replay continues to identify that batch even when undo is no longer available. If a later supported operation removes a header, retain a gone tombstone until at least issuance plus eight days; after that, the grant is expired and still cannot create another batch. Neither ordinary selection cleanup nor history-detail pruning deletes a live batch receipt. Hourly `bulkcat_prune_requests` deletes at most 100 eligible selection/gone receipts per transaction, and never receipts with retained batch headers.

`POST /requests/resolve` accepts the saved UUID/proof and original intent and uses this same resolution path without ever creating work. It returns the original outcome, a definite expired/gone refusal, or `not_accepted` for a still-valid grant absent from committed storage. That response may race an in-flight acceptance and authorizes only resolution/retry with the same grant, never a new UUID. Once a client has an uncertain response it uses resolution/same-grant retry, not a new UUID. HTTP or cache timeouts are never evidence that the original batch failed.

### Migration and existing jobs

`BatchFactory::REPLAY_TTL` is currently 60 seconds, and `BatchRepository::earlier_request()` has the same time cutoff. The controller already attempts replay before parsing selection; preserve that ordering but replace the short cache-only answer with the durable fingerprint-aware resolver. A 60-second cache may accelerate lookup, never decide that no prior request exists. Remove the cutoff from the legacy durable lookup; do not create a new batch then discover its duplicate.

Do not add a unique constraint to legacy batch UUID columns, because older repeated UUIDs may already exist. For an old UUID without a v2 receipt/grant, look up retained headers by owner/UUID with **no age cutoff**. If an exact original fingerprint can be established, return the matching existing batch; otherwise return 409 `bulkcat_legacy_request` with an owner-authorized history reference. Multiple candidate headers are ambiguous, never silently choose or enqueue another. An unknown legacy bare UUID cannot create new work: ask the old screen to reload. This conservative path does not guess that a changed payload is a replay.

Schema 7 adds the two tables/installation epoch without rewriting existing batches/items/journal or their worker payloads. Existing queued work and undo history continue to operate. New runtime code issues grants for new bulk/undo requests, including explicit-ID bulk callers. Activation is idempotent; partially migrated storage cannot accept new grants/writes. Deactivation unschedules both cleanup hooks; opt-in uninstall handles all five tables and the epoch option through the registry. The ordinary “retain data” uninstall setting preserves replay protection too.

## API

Namespace remains `bulkcat/v1`. Register through `Rest\Routes`; input schemas through `RouteSchema`, refusals through `Refusal`. All routes below use POST, cookie authentication, the REST nonce and `Access::can_write`. Unknown mutation keys are refused. Tokens are opaque to callers.

Capture and compose include the server-issued `uuid` and `request_proof` above. Use the durable receipt resolver before reading a selection lease. Inspection, keepalive and release are idempotent handle operations and do not mint a new mutation identity. Missing/tampered/other-user selection tokens use a uniform invalid-selection refusal without revealing another user's state; a known retained snapshot with an expired authorization lease requires renewal, not recapture.

### Capture — `POST /selection`

Issue with `POST /requests` body `{operation:"selection.capture", intent:{query:{…}}}` or `intent:{ids:[…]}`. Submit the resulting **`uuid`, `request_proof`, and canonical `intent`** to `/selection`. Query/IDs are inside that intent, never top-level beside the grant; mixed query and IDs are refused. The UI submits at most 1,000 explicit IDs per capture (page selections normally contain at most 100). Existing internal one-row/explicit-ID preview and batch paths remain supported; the new manager uses snapshots.

For a query, `ProductQuery::snapshot_members()` executes one bounded SELECT of ID and post type, sharing the existing prepared FROM/WHERE, ordered by ID with `LIMIT 100001`. It ignores view paging and sort. One statement defines the capture point; do not count first and then walk a mutable filter page by page. No `WC_Product` objects, new core indexes or cache-derived counts. Explicit additions must be valid catalogue products/variations at capture; report unavailable additions without silently dropping them.

Refuse more than 100,000 objects. Zero matches is valid: create an empty snapshot. Response:

```json
{
  "token": "v2.<opaque>",
  "kind": "snapshot",
  "count": 1250,
  "by_type": { "products": 1200, "variations": 50 },
  "authorization_epoch": 1,
  "authorization_expires_at": "2026-09-25T15:30:00Z",
  "retained_until": "2026-10-02T15:15:00Z",
  "requires_preview": false
}
```

All mutation responses use this envelope. IDs never accompany a whole-catalogue response.

### Compose — `POST /selection/compose`

Issue through `POST /requests` with `operation:"selection.compose"` and an intent containing `token` (base) and exactly one mutation:

- `add_ids: [ids]`, at most 1,000;
- `add_token: <snapshot token>`, union with another captured set;
- `remove_ids: [ids]`, at most 1,000, including unavailable or off-page selected objects;
- `remove_token: <snapshot token>`, subtract that frozen set.

Submit the returned `uuid`, `request_proof`, canonical `intent`, and current base `token` to `/selection/compose`; include the current `add_token` or `remove_token` credential for a snapshot operand. The canonical intent contains `{version:1,base:<stable id>,add_ids|remove_ids|add_snapshot|remove_snapshot:…}`. Renewed tokens never change that intent. `/requests/resolve` takes `operation:"selection.compose"` with the saved UUID/proof/intent and needs no selection credentials.

Both tokens must belong to the requester, have payloads within their retention deadlines and have current authorization for new composition; replay of an accepted composition resolves before those lease checks. Union deduplicates; subtraction never adds. The combined cap is checked before any successor is stored. Empty successors are valid. Even an unchanged composition gets its own idempotent result row; identical request replay returns that row. A failed composition leaves the original usable. **Select all matching** captures a new query set, then unions it with the current base: a retry uses those same two request UUIDs/proofs and the same captured set.

### Inspect — `POST /selection/inspect`

Input: `token`, `page` (default 1), `per_page` (default 25, max 100), optional `probe_ids` (max 100). Response contains snapshot count, authorization epoch/expiry and retention deadline, a page of members, pagination and the intersection with the supplied visible IDs. Stable order is ascending ID across both types.

Hydrate only that page using the existing lean read/hydration layer and per-object read checks. Return names/SKUs/type/parent labels only where permitted. A missing or no-longer-readable member occupies its original slot as `unavailable`, with a removal handle scoped to this token; do not expose protected names, prices or other metadata. Unavailability does not silently reduce the selection count. Inspection never resolves old search criteria.

### Renew — `POST /selection/renew`

Input: the previously issued snapshot `token`, even if its authorization lease expired. Verify its signature/owner and current login, nonce and capabilities; require the immutable payload to exist and be within its retention deadline, even if cleanup has not yet removed an expired row. Extend retention to seven days from this authorized keepalive. If authorization is still live, extend its lease without changing epoch/token. If it expired, atomically issue the next authorization epoch and lease and return `requires_preview: true`. Concurrent renewals converge on the same current epoch. A caller presenting an older epoch receives the current token and `requires_preview: true`, even when another renewal already made its new lease live; an old preview cannot become valid through a renewal race. The old preview cannot enqueue new work after reauthorization; an already accepted Apply still resolves by receipt first.

No query replay or snapshot replacement occurs. Unavailable objects remain selected but are exposed only as permitted placeholders. A lost login or lost capability requires authorization recovery without erasing the retained selection. Pruned membership is 410; it cannot be renewed into an empty or newly matched set. Keepalive is bounded to the screen's current/submitted/additions and unresolved-recovery handles.

### Release — `POST /selection/release`

Input: at most four unreferenced snapshot tokens. Check ownership/current authorization; shorten each retention deadline as specified above and return its resulting deadline. It never writes products or deletes a payload synchronously. The client only releases tokens after acknowledged replacement and after checking every local current/submitted/additions/recovery reference. It must not release a submitted token merely because Apply returned queued. Duplicate release is idempotent; cleanup remains conditional under lock. Unreliable unload/release delivery falls back to the seven-day inactivity deadline.

## Preview, Apply and completion

Preview and batch endpoints retain the operation payload and preview hash; new bulk Apply/undo requests add the server-issued UUID/proof and durable replay resolution above. Preview hashes bind the current snapshot authorization epoch, so returning after an expired lease requires preview again; routine live renewal keeps it valid. Their token resolver accepts v2 snapshots and converts members to the explicit-identity materialisation path. It never turns a snapshot back into a filter. Version-1 transient tokens receive a reselect/reload refusal after upgrade; already queued jobs and stored history remain valid.

Remove the live-filter `preview_count` / `confirm_changed` escape path from v2 selection semantics. `current_count` is the frozen selection count, not the count of a fresh search. Missing/inaccessible members remain represented in the scope and outcome, with skipped/refused reasons; they cannot make a smaller batch claim that the whole selection succeeded.

Variation expansion is a second scope boundary for snapshot and explicit-ID bulk requests alike. Preview computes an authenticated `scope_hash` over the ordered expanded IDs, their per-step operation assignments and unavailable selected IDs. Apply recomputes that lean scope immediately before materialising items and refuses 409 `bulkcat_preview_stale` if it differs. The batch uses exactly the materialised scope it checked. Newly added variations cannot be silently introduced between preview and Apply, and a count-preserving substitution still invalidates it. This is a scope check, **not** a claim that all field values are locked until the job runs; current values and permissions are still checked by the writer.

The client keeps the submitted token and batch ID. POST acceptance, polling, queueing and the broad existing `FINISHED` list do not clear selection. Only `complete` with zero failed/skipped/unavailable/pending objects is automatic success; an already-correct item is successful if the engine records it as such. Partial, failed and cancelled outcomes retain the submitted scope and link to recoverable details. Recovery is not a blind replay of already-applied relative operations.

On full success, if selection is unchanged, clear it locally. If the operator has built a newer selection, subtract the submitted snapshot through `/selection/compose`, preserving new IDs. Serialize this subtraction with other mutations. A submitted ID removed and explicitly added again after enqueue is new intent. Track explicit additions after submission in a separate server snapshot using the same capture/compose API; subtract removals from it too. Completion computes `(current minus submitted) union post-submission-additions`, committing the new UI envelope only after both compositions succeed. Keep request UUIDs and intermediate tokens for retry; never accumulate a whole-catalogue exception list in the browser. A clear/unmount generation prevents late responses from restoring discarded selection. If authorization expired, reauthorize retained handles before composing completion cleanup; if payload retention elapsed, leave the newer selection intact and explain the unresolved cleanup instead of pretending it was cleared.

## Client sequencing

`use-selection.js` owns a serialized mutation queue, the current snapshot envelope and a generation counter. View changes only trigger visible-membership inspection. A page toggle is a bounded add/remove intent; it must not narrow a former all-matching selection to the current page. Explicit Clear increments the generation and discards queued local intents; unreferenced snapshots are released after receipt/acknowledgement, with seven-day inactivity cleanup as fallback.

Each pending mutation retains its server-issued UUID, request proof and immutable intent until a definite result, including after the selection authorization expires. A request that may already have been accepted is resolved before reauthorization or obtaining another grant. Network failure keeps the previous confirmed snapshot and the unapplied intent, with Retry. While selection membership is uncertain, disable Preview/Apply and explain why. Ignore out-of-generation responses. Do not use last-response-wins for compositions. Two rapid row toggles, a query capture followed by another search, and a completion callback arriving during a new selection must preserve intent in tests.

## Limits and review gates

- Selection / expanded target ceiling: 100,000; mutation ID lists: 1,000; page/probe list: 100; operations: 20. Validate integers and duplicate/type inconsistencies before database work.
- Materialisation target remains <5 seconds on the 20,000-product/70,000-variation fixture. Measure the actual SELECT, encoding/storage, request size and peak memory at 100,000 members before merge. The current cached-count timing is not evidence for the new path.
- Full sets exist only in the database/server memory. Browser state and traffic grow with a visible page and bounded pending intents, not catalogue size.
- Register both new tables, both cleanup hooks and installation epoch; test migration, duplicate-key concurrency/transaction recovery, separated authorization/retention/replay deadlines, deactivation/reactivation and opt-in uninstall with existing history preserved.
- No product, price, access or term write occurs during grant issuance/resolution, capture, composition, inspection, renewal, release or preview.
- Core remains native and independent. This contract contains no audience-policy engine, vendor-specific adapter, saved range feature, live-site configuration or rollout authority.

## Lifecycle regressions required before implementation is accepted

1. Capture, lose its response, drift catalogue membership, release/expire and prune payload, retry the same grant: recover the original set if retained or return 410. Then prune its eight-day receipt and retry again: signed acceptance deadline refuses; no second capture under that UUID.
2. Apply a relative operation, lose the response, evict the transient, advance more than 60 seconds and retry: same batch, one application. Expire authorization and then prune selection payload before retry: accepted result still resolves before selection validation.
3. Send the same Apply concurrently: one receipt/header/item set and one queued action. Send changed operations/targets under its UUID: 409, no additional batch. Inject rollback/ambiguous commit and crash-after-commit-before-scheduling; recovery resolves/enqueues only the original committed batch.
4. Prune batch journal/items: matching request still resolves the retained header. Exercise the gone-header tombstone, then prune that tombstone after its deadline: no replay can turn into new acceptance. Data-retaining upgrade/uninstall preserves protection; data-removing reinstall changes epoch and refuses old grants.
5. Upgrade with old jobs and two historical headers sharing a UUID: retain both and queued work/history; an ambiguous legacy retry refuses rather than guessing or making a third batch. Unknown bare UUID from an old screen cannot initiate a new request.
6. Select A/B, suspend an open tab for 30 minutes and again overnight (both within seven days), make C newly match, return: reauthorize A/B under current rights, rotate expired authorization, require preview again, never include C. Permission loss denies metadata/action while retention survives; restoration within retention recovers the same set.
7. Release intermediate snapshots and lose unload/release responses: cleanup honors the one-hour/seven-day bounds. Keepalive vs prune and reauthorization races preserve the winning state, never silently replace a selection. Returning after the documented inactivity retention gets a clear gone-work state rather than claimed success.
