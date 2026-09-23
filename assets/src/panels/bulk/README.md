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
| `selection` | yes | What to change. `{ kind: 'ids', ids: number[] }` for ticked rows (optionally with `token` when the ids were exchanged at `POST /selection`), or `{ kind: 'filter', token: string, count: number, filters?: Array<{ label, value }> }` for *all rows matching the current filter* (T42). `filters` are listed under the scope line as the owner reads them. A new selection object with different ids, token or count makes a preview stale. |
| `registry` | yes | The `GET /bcm/v1/fields` payload, `{ fields, actions, groups }`, as is. The chooser, the argument controls, the reasons shown in place and the variations rule all come from it; no action is listed in the panel. |
| `settings` | yes | The `bcm_settings` values (`confirm_threshold`, `variations_status`, `variations_visibility`, `variations_price`, `variations_stock`), or the screen's `window.bcmSettings` object, which carries them under `settings`. Changing them makes a preview stale. |
| `onClose` | no | Called by the close button, **Change the selection**, **Back to products** and Escape. The panel returns focus to the element focused when it opened when it unmounts. |
| `onApplied` | no | Called with the new batch id once `POST /batches` succeeds. The panel then shows the Done step with progress; the caller may close it and show progress in the selection bar instead (§4.3 "After apply"). |
| `categories` | no | The `GET /categories` tree (`[ { id, name, children } ]`), for the categories picker of add, remove and replace. |
| `tags` | no | Tags as `[ { id, name } ]`, for the tags picker. |
| `shippingClasses` | no | Shipping classes as `[ { id, name } ]`, for **Set a value** on Shipping class. |
| `onUndo` | no | Called with the batch id by **Undo this change** on the Done step; the button is hidden without it. |
| `onViewHistory` | no | Called with the batch id by **View in History**; hidden without it. |
| `onDownloadSample` | no | Called with the preview request (`{ operations, ids \| token, sample_size }`) by **Download sample CSV**. Until the export route takes a preview (T22's writer and formula guard), the button is disabled with its reason. |
| `renderStalled` | no | `( report ) => Element`, rendered in place of the progress bar when `GET /batches/{id}` reports `stalled` (T44's notice). Without it the server's `stalled_message` is shown. |
| `pollInterval` | no | Milliseconds between progress reads; 2000 by default. |

The panel exposes its §4.3 state on `data-state`: `idle`, `configuring`,
`blocked`, `previewing`, `ready`, `empty`, `stale`, `applying`, `done`.

## Shared confirmation (T48)

`useConfirmApply( { count, threshold, reasons, onApply } )` and
`<ConfirmApply count threshold reasons onApply label? confirmLabel? disabled? disabledReason? busy? />`
gate every Apply: one click below the threshold; at or above it the count is
restated and a second click is needed; anything in `reasons` always confirms.
`alwaysConfirmReasons( registry, operations, count )` names what a change will
do when it sets status to draft or pending, switches Visible on site off (or
catalogue visibility to hidden) or empties a field. The undo and category
batches reuse both. Nothing ever asks for a typed word, and a preview never
confirms.

## Requests

- `POST /preview` with `operations`, `ids` or `token`, and `sample_size`
  (25, 100 or `all`).
- `POST /batches` with `uuid` (one per previewed change, reused when the same
  preview is retried, fresh after a new preview), `operations`, `ids` or
  `token`, `source: 'bulk'`, `preview_hash`, and for a filter `preview_count`
  (the preview's `selection.current_count`). A 409 `bcm_selection_changed`
  states both counts and offers **Apply anyway** (`confirm_changed: true`) or
  **Preview again**; a 409 `bcm_preview_stale` runs the preview again.
- `GET /batches/{id}` every `pollInterval` until the batch finishes.

## Variations (T69)

- **Also apply to variations** shows for fields whose variations rule allows
  it, starting where the setting for the field's panel group puts it
  (Publishing status, Pricing price, Stock stock), and is sent as the step's
  `apply_to_variations` argument.
- The preview shows the server's `summary` sentence and, when variations are
  counted, `totals.by_type` split into products and variations.

## Mounted by the catalogue

`panels/catalogue` opens the panel from **Make a change to these** on the
table's selection (ticked ids; T42 brings all rows matching the filter),
passes the settings from `useSettings()` and the category tree, renders
T44's `<StalledNotice>` through `renderStalled`, and shows `<BatchProgress>`
above the table once the panel is closed on an applied change.
