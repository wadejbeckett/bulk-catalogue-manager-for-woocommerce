# Variations in the grid (T67, T68)

Expandable variation rows under variable products, the Products | Variations mode switch, and the inheritance display (SPEC §4.2, §5.2, §8). Everything here is read from `GET /fields`; no field key is named (the stock cell is the one `defaults.js` gives a companion).

## How the table uses it

`CatalogueTable` calls `useVariationRows( props )` and gets `{ props, before, after }`: it spreads `props` (`data`, `fields`, `actions`, `paginationInfo`, and `selection`/`onChangeSelection` when the table is controlled) onto `DataViews`, renders `before` (the mode switch, Open all with variations, the Show all notice) above it and `after` (the count line) below it. `fieldsFromRegistry()` puts each field's registry `descriptor` on its DataViews field, and `toQuery()` passes its arguments through `withVariations()`.

## The view

The grid mode lives in the view object as `view.mode`: `products` (the default; not sent), `products_and_variations` or `variations`, sent to `GET /products` as `mode`. A saved view or an export reads it from there. In the product modes the query also asks for the variation count and type fields (so a variable row knows it is one), the rendered price when a price column is shown, and each shown conditional field's condition (manage stock for stock quantity).

Expansion is never part of the view and is not persisted. Opening a product fetches `GET /products/{id}/variations?per_page=50&page=1` with the page's `fields` once; the answer is kept until the page is read again or the mode changes. At most 50 are inserted; **Show all N options** switches to Variations mode listing that one product's options (read from the same route, page by page, until **Show every option** or a mode change).

## Selection contract addition

Variation rows are selectable like products and feed the same selection: the ids in `selection` (strings, DataViews' own) may now include variation ids, so the bulk action receives them as items. `onChangeSelection` is called with a second argument:

```js
onChangeSelection( ids, { selectedProductIds, selectedVariationIds } );
```

Both are number arrays that split `ids`: a variation id is one the table has drawn as a variation row (open under its product, a flat Variations row, or chosen through **Select all options**). A caller that only reads the first argument is unaffected. Selecting a product never selects its variations (SPEC §8 rule 2); footer rows are never selectable.

## Cell hooks for the inline editor (T46)

`cellState( row, descriptor, descriptors )` answers `{ kind, editable, reason }` for a drawn row (rows carry their tree place under the `META` symbol; `metaOf()` reads it):

| kind | where | editable |
| --- | --- | --- |
| `own` | the row's own value | unless the field is read-only |
| `derived` | a variable parent's price (the registry's `variableParentNote`), a variation's name | no |
| `range` | a derived parent value where the variations' numbers differ | no |
| `summary` | a variable parent's stock cell | no |
| `mixed` | the variations disagree in a way no range shows; `reason` says how | no |
| `parent_only` | a parent-only field on a variation row, shown with the product's value greyed; `reason` is the field's `variationNote` | no |
| `not_applicable` | a conditional field whose condition is off on that variation (stock quantity without managed stock) | no |

Every non-`own` cell renders inside `.bcm-locked` with `data-bcm-editable="false"` and `data-bcm-cell="<kind>"`, its reason as the tooltip and as screen-reader text; it is focusable and swallows Enter. The editor must open only where `editable` is true.
