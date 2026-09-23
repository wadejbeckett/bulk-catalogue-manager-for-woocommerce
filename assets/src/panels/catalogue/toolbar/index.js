/**
 * The catalogue's search and filters (T41): the registry's filter
 * definitions for DataViews, the view-to-route mapping and the chips.
 */
export {
	viewToQuery,
	selectionQuery,
	withFilters,
	filterSources,
	filterArg,
	filterArgs,
	filterDefinition,
	withoutFilter,
} from './filters';
export { filterChips, chipSentence, FilterChips } from './chips';
import './style.scss';
