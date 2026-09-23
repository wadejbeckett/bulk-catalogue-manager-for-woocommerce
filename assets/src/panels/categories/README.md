# Category panel

The product category tree for the Catalogue panel and the Categories tab
(SPEC §4.1, §4.7, §7, §14; tickets T61–T66, client half). Everything is
exported from `index.js`. Import it by the folder's `index` explicitly —
`panels/categories.js` (the Categories tab placeholder) shadows the bare
folder path:

```js
import {
	CategoryPanel,
	CategoryManager,
	useCategoryTree,
	SELECTION_DRAG_TYPE,
} from './categories/index';
```

## `<CategoryPanel>`

The panel left of the table: collapsible, resizable by dragging its edge (or
the arrow keys, Home and End on the focused edge), with width and collapsed
state remembered per user in `localStorage` under
`bcm-category-panel:{userId}`. The first visit takes its collapsed state from
the *Show the category panel by default* setting (`settings.category_panel`).
Below 960 pixels it is a button (labelled with the active sentence, or
"Categories") opening the same tree as a sheet.

| Prop | Type | Meaning |
| --- | --- | --- |
| `value` | `{ id, ids?, includeChildren } \| null` | The category filter. `ids` is present only when several categories are chosen (Ctrl or Cmd click); `id` is always the first. `null` is all products. |
| `onChange` | `( value \| null, sentence ) => void` | Called when the owner selects a node, toggles *Include sub-categories*, Ctrl/Cmd-clicks, chooses All products or Show all. `sentence` is the chip text: "Category is Shoes", "Category is Shoes or its sub-categories", "Category is Shoes or Hats", "Category is Shoes or Hats, or their sub-categories"; `''` for `null`. |
| `query` | `Object` | The grid's `search`, `mode` and per-field filter arguments (`{ operator, value }` objects named after the field), passed to `GET /categories` so the counts follow the active filters. Leave the category filter out or in: the server ignores it for the counts. |
| `tree` | `useCategoryTree()` result | Optional. Pass the catalogue's own instance to share one load (and to name the chip with `categorySentence( value, tree.byId )`). Without it the panel loads its own from `query`. |
| `settings` | `window.bcmSettings` | Supplies `user.id` (the storage key), `settings.category_panel` (initial open state) and the capability flag below. |
| `canManage` | `boolean` | Optional override of `canManageTerms( settings )`. |
| `total` | `number` | Optional count shown on the All products row. |
| `onDropSelection` | `( categoryId ) => void` | Called when the current product selection is dropped on a category (T65). The panel writes nothing; the catalogue opens the bulk action panel pre-filled with **Add categories** for that category and its own current scope (explicit ids or all rows matching the filter). Without this prop the tree does not accept a product drop. |
| `narrowWidth` | `number` | Breakpoint for the sheet; 960 by default. |

Each node shows its own count or its count including descendants, following
the toggle (`count` / `countWithDescendants`). The include-children toggle
defaults to on.

### Capabilities (read-only mode)

`canManageTerms( settings )` is true when the localised settings carry
`user.capabilities.manage_product_terms === true` (or `user.canManageTerms
=== true`). Otherwise the tree is read-only: it browses, filters and shows
counts, and the menu, rename, drag, Move to… and **+ Add category** are
absent, replaced by one line saying why. The server checks
`Access::can_manage_terms` on every write regardless.

> `src/Admin/Screen.php::client_settings()` does not yet localise the
> capability. Until it adds `user.capabilities.manage_product_terms`, pass
> `canManage` explicitly or every user sees the read-only tree.

### Dropping a product selection (T65 contract)

The catalogue makes its selection draggable and, in `dragstart`, sets
`event.dataTransfer.setData( SELECTION_DRAG_TYPE, anything )` —
`SELECTION_DRAG_TYPE` is `application/x-bcm-selection`. The panel only checks
that the type is present (browsers hide drag values until the drop) and
calls `onDropSelection( categoryId )`; the value is never read. A dragged
category uses `CATEGORY_DRAG_TYPE` (`application/x-bcm-category`) instead.

## `<CategoryManager>`

The details pane that creates a category or edits one (T63). The panel opens
it in a modal from **+ Add category**, **Create your first category** and a
node's **Edit details…**; the Categories tab can render it beside the tree.

| Prop | Type | Meaning |
| --- | --- | --- |
| `tree` | `useCategoryTree()` result | Required: the parent choices, and `reload()` after a save. |
| `categoryId` | `number \| null` | The category to edit; `null` (default) creates one. |
| `parentId` | `number` | Parent a new category starts under; 0 (default) is the top level. |
| `canManage` | `boolean` | Without it the pane shows the read-only line instead of a form. |
| `onSaved` | `( item, answer ) => void` | After a successful save; `item` is the route's category, `answer` the whole `{ item, batch_id, status, results }`. |
| `onCancel` | `() => void` | Shows a Cancel button; also called when an edit changed nothing. |

Create sends `POST /categories { name, slug?, description?, parent }`; edit
sends `PATCH /categories/{id}` with only the properties that changed. An
empty name is refused before any request, in the server's own sentence. A
refusal (`bcm_category_exists`, `bcm_slug_taken`, `bcm_slug_invalid`,
`bcm_parent_missing`, `bcm_category_cycle`, …) shows the server's §4.8
sentence; the code and HTTP status sit behind **Show details**.

The tree nodes carry no description, so an edit shows an empty description
(unless this session saved one) and sends it only if the owner types one.

## `useCategoryTree( query, { enabled } )`

Loads `GET /categories` with `query` and reloads whenever `query` changes
(compared by value). Returns:

| Key | Meaning |
| --- | --- |
| `nodes` | Top-level nodes as the route answers them, each with `children`. |
| `byId` | `Map` of id to `{ node, parent, depth, children }` (`parent` 0 at the top level). |
| `loading`, `loaded`, `error` | Request state; `error` is apiFetch's rejection. |
| `reload()` | Load again, as every write does. |
| `descriptions`, `remember( item )` | Descriptions returned by writes, so the details pane can show them. |

## Moving

Drag a row onto another to put it inside (last), or near a row's top or
bottom edge to place it before or after (a line shows where); onto *All
products* to move it to the top level. While dragging, a line under the tree
shows the new path, or "A category cannot be moved inside itself." over the
category's own branch, where the drop is refused. **Move to…** in each node's
menu does the same move from the keyboard or on touch: choose the new parent
and the position among its categories. Both send
`POST /categories/{id}/move { parent, position? }`, with `position` counted
among the new siblings without the category itself, and omitted for last.

## Keyboard (ARIA tree)

One treeitem is in the tab order (roving tab index). Up and Down move, Right
opens a branch or enters it, Left closes it or goes to the parent, Home and
End go to the first and last rows, typing letters jumps to the next matching
name, Enter or Space selects (with Ctrl or Cmd to add or remove it from a
multiple choice). With `manage_product_terms`, Shift+F10 or the context menu
key opens the node's menu (Rename, Edit details…, Move to…; arrows, Home,
End, Escape), and F2 renames in place (Enter saves, Escape cancels).
Expansion, selection, every move and every refusal are announced through
`wp.a11y.speak` (`window.wp.a11y`), falling back to a live region of the
panel's own when the host script is absent.
