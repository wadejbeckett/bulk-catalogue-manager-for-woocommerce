# Bulk change panel (T48–T50)

The slide-over of SPEC §4.3 with the preview of §4.4 and the confirmation
policy of §4.5. It is self-contained: the catalogue panel mounts it once the
owner has a selection, and nothing else in the screen needs to change.

```js
import BulkChangePanel from './panels/bulk';

<BulkChangePanel
	selection={ selection }
	registry={ fieldsPayload }
	settings={ settings }
	categories={ categoryTree }
	onClose={ closePanel }
	onApplied={ ( batchId ) => showProgressInSelectionBar( batchId ) }
/>;
```

## Props

| Prop | Required | What it is |
| --- | --- | --- |
| `selection` | yes | What to change (T84). `{ kind: 'snapshot', token, key, count, byType: { products, variations }, waiting }` for the catalogue's exact selection (`panelSelection()` in `catalogue/selection/model.js`: the snapshot's current token, the handle's `key` that a renewed token keeps, the server's counts, and `waiting`, the reason the selection is not settled yet, which blocks Check the change), or `{ kind: 'ids', ids: number[] }` for a row's own Make a change (sent sorted). A new selection object with a different token or ids makes a preview stale. |
| `registry` | yes | The `GET /bulkcat/v1/fields` payload, `{ fields, actions, groups }`, as is. The chooser, the argument controls, the reasons shown in place and the variations rule all come from it; no action is listed in the panel. |
| `settings` | yes | The `bulkcat_settings` values (`confirm_threshold`, `variations_status`, `variations_visibility`, `variations_price`, `variations_stock`), or the screen's `window.bulkcatSettings` object, which carries them under `settings`. Changing them makes a preview stale. |
| `onClose` | no | Called by the close button, **Change the selection**, **Back to products** and Escape. The panel returns focus to the element focused when it opened when it unmounts. |
| `onApplied` | no | Called with the batch id and `{ selection, replayed }` once `POST /batches` answers a batch: `selection` is the panel's selection (null for an earlier attempt that turned out to have gone through), `replayed` whether the server answered a request it had already accepted. The panel then shows the Done step with progress; the caller may close it and show progress above the table instead (§4.3 "After apply"). |
| `onFinished` | no | Called once with the batch's final report when the Done step sees it finish (T84: the catalogue clears the applied selection only on full success). |
| `onReview` | no | Opens Review selected products; shown in the scope card when given. |
| `onRenewSelection` | no | Restores a snapshot whose lease lapsed (`bulkcat_selection_renew_required`), offered as **Restore the selection**. |
| `emptyPrompt` | no | Shown in place of the scope while nothing is selected: the catalogue's prompt with **Select all matching** (FRONTEND-1). The panel never selects anything by itself. |
| `applyHeld` | no | Why Apply must wait, as a sentence (another change from the catalogue is still running, FRONTEND-3); Apply stays disabled with it until it is `''`. |
| `categories` | no | The `GET /categories` tree (`[ { id, name, children } ]`), for the categories picker of add, remove and replace. |
| `tags` | no | Tags as `[ { id, name } ]`, for the tags picker. |
| `shippingClasses` | no | Shipping classes as `[ { id, name } ]`, for **Set a value** on Shipping class. |
| `onUndo` | no | Called with the batch id by **Undo this change** on the Done step; the button is hidden without it. |
| `onViewHistory` | no | Called with the batch id by **View in History**; hidden without it. |
| `onDownloadSample` | no | Called with the preview request (`{ operations, ids \| token, sample_size }`) by **Download sample CSV**. Until the export route takes a preview (T22's writer and formula guard), the button is disabled with its reason. |
| `renderStalled` | no | `( report ) => Element`, rendered in place of the progress bar when `GET /batches/{id}` reports `stalled` (T44's notice). Without it the server's `stalled_message` is shown. |
| `pollInterval` | no | Milliseconds between progress reads; 2000 by default. |
| `initialOperations` | no | Steps to open with instead of one empty step, in the panel's operation shape (`chooseAction()` / `changeArg()` from `operations.js`). The catalogue's selection bar opens **Show in shop and search** and **Hide from shop and search** this way (T42); read once when the panel mounts, and previewed like any other change. |

The panel exposes its §4.3 state on `data-state`: `idle`, `configuring`,
`blocked`, `previewing`, `ready`, `empty`, `stale`, `applying`, `done`.

## Shared confirmation (T48)

`useConfirmApply( { count, threshold, reasons, onApply } )` and
`<ConfirmApply count threshold reasons onApply label? confirmLabel? disabled? disabledReason? busy? />`
gate every Apply: one click below the threshold; at or above it the count is
restated and a second click is needed; anything in `reasons` always confirms.
`alwaysConfirmReasons( registry, operations, count )` names what a change will
do when it sets status to draft or pending, switches Shown in shop and search off (or
catalogue visibility to hidden) or empties a field. The undo and category
batches reuse both. Nothing ever asks for a typed word, and a preview never
confirms.

## Requests

- `POST /preview` with `operations`, the snapshot's `token` or sorted `ids`,
  and `sample_size` (25, 100 or `all`). The answer's `operations` (the change
  with every default written out), `scope_hash` and `hash` are what Apply
  sends; `unavailable_count` counts chosen objects that stay in the change and
  are recorded as not changed.
- Apply is a durable request (T83, T84): `POST /requests` with
  `{ operation: 'batch.apply', intent: { source: 'bulk', operations,
  scope_hash, token | ids } }` issues a grant, then `POST /batches` sends
  `uuid`, `request_proof`, the canonical `intent`, `preview_hash` and, for a
  snapshot, its current `token`. The grant is held for the screen (not one
  opening of the panel) until the answer is definite: a retry of the same
  change (same operations, scope and selection handle) resends it, and the
  server answers the batch it already made (`replayed: true`). A different
  change first resolves an uncertain grant (`POST /requests/resolve`) and
  shows its batch if it had gone through. Nothing 1.0 is sent: no bare `uuid`,
  `source`, `preview_count` or `confirm_changed`.
- Refusals, in the server's words (§4.8): an unknown outcome offers **Try
  again** (same grant); `bulkcat_preview_stale` previews again (the same grant
  is reused when the scope is unchanged); `bulkcat_selection_changed` offers
  **Preview again**; `bulkcat_legacy_request` links to the batch History
  names; `bulkcat_selection_renew_required` offers **Restore the selection**;
  `bulkcat_request_gone` and `bulkcat_request_expired` are stated.
- `GET /batches/{id}` every `pollInterval` until the batch finishes.

## Variations (T69)

- **Also apply to variations** shows for fields whose variations rule allows
  it, starting where the setting for the field's panel group puts it
  (Publishing status, Pricing price, Stock stock), and is sent as the step's
  `apply_to_variations` argument.
- The preview shows the server's `summary` sentence and, when variations are
  counted, `totals.by_type` split into products and variations.

## External actions (T105)

An add-on registered on the external action contract (`docs/EXTENSIONS.md`)
adds entries to the same `/fields` payload, and the panel lists them from the
descriptors alone: its chooser groups after the native ones, its actions in
its order (an action may also sit in a native group), a `multichoice`
argument as a checkbox list sent as a list of values, and an action whose
add-on is unavailable, or that the user may not use, disabled with the
server's reason. One change holds native steps or one add-on's steps: a step
of another kind says so in place and the change cannot be checked or
applied (`mixesKinds()`). An add-on action that always confirms restates its
own `confirmReason`. Every add-on string is rendered as text.

## Mounted by the catalogue

`panels/catalogue` opens the panel from **Make a change to these** on the
exact selection (T84: the live snapshot, so a renewal or a tick reaches it),
passes the settings from `useSettings()` and the category tree, renders
T44's `<StalledNotice>` through `renderStalled`, and shows `<BatchProgress>`
above the table once the panel is closed on an applied change.
