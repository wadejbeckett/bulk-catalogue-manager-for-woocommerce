/**
 * Variations in the grid (SPEC §4.2, §5.2, §8; T67, T68): expandable
 * variation rows under variable products, the Products | Variations mode
 * switch, and the inheritance display (ranges, stock summaries, Mixed,
 * greyed parent-only and not-applicable cells). See README.md for the
 * contract the rest of the catalogue relies on.
 */
import './style.scss';

export { useVariationRows, modeOf, splitSelection } from './use-variation-rows';
export { cellState, STATES } from './cell-state';
export { buildRows, reducer, INITIAL, KINDS, META, metaOf } from './model';
export { MODES, CAP, variationRoles, withVariations } from './registry';
