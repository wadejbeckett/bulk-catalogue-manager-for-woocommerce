/**
 * Views of the catalogue (T51–T53): the layout switch and the grid layout,
 * the saved views row with its built-ins, and the Export control. These are
 * self-contained; the catalogue panel places them:
 *
 * - `<SavedViewsBar registry view category baseView onApply />` above the
 *   toolbar, `onApply( view, category )` setting both states (it applies the
 *   user's default view once the list arrives, so the screen opens with it);
 * - `<LayoutSwitch view onChangeView />` and `<ExportControl query view
 *   registry selection onRenewSelection />` in the toolbar, `query` being
 *   useCatalogue().query, `selection` the selection's panelSelection() and
 *   `onRenewSelection` its session's renewAll() (the export dialog, T103);
 * - `<CatalogueLayout …CatalogueTable props… renderSwitch? />` in place of
 *   CatalogueTable, drawing the table or the cards by the view's layout.
 */
import './style.scss';

export { default as SavedViewsBar } from './chips';
export { default as LayoutSwitch, LAYOUT_TYPES, withLayout } from './layout';
export { default as CatalogueLayout, CatalogueCards } from './cards';
export { default as ExportControl, exportArgs, exportColumns } from './export';
export { useSavedViews } from './use-saved-views';
export { builtInViews } from './presets';
export { toSaved, fromSaved } from './serialize';
