/**
 * The catalogue's selection (T42, T84): useSelection() over the screen's
 * selection session, the sticky SelectionBar, the Selected products review
 * and the quick actions' prefilled steps.
 */
export { default as useSelection } from './use-selection';
export { default as SelectionBar } from './selection-bar';
export { default as SelectedProducts } from './selected-products';
export {
	countPhrase,
	isPageSelected,
	panelSelection,
	selectedWords,
} from './model';
export {
	createSelectionSession,
	getSelectionSession,
	resetSelectionSession,
} from './session';
export { addCategoryOperation, visibilityOperation } from './quick-actions';
import './style.scss';
