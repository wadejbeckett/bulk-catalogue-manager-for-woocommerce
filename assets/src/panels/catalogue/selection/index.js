/**
 * The catalogue's selection (T42): useSelection(), the sticky SelectionBar
 * and the quick actions' prefilled step.
 */
export { default as useSelection } from './use-selection';
export { default as SelectionBar } from './selection-bar';
export { mergePageSelection, isPageSelected, panelSelection } from './model';
export { visibilityOperation } from './quick-actions';
import './style.scss';
