/**
 * The grid layout (SPEC §4.1, T51): the same registry-built fields as the
 * table, drawn as cards by DataViews' own grid layout, which carries the
 * selection checkbox on each card and moves between cards with the arrow
 * keys, as the table does between rows. Each card shows the thumbnail (the
 * view's media field), the name (its title field) and the visible columns,
 * which by default include the price and the stock status.
 *
 * The Visible on site switch has a slot: pass `renderSwitch( item )` and the
 * card shows it in place of that field's plain value, in the table's column
 * order or at the end when the column is hidden. Without it the field shows
 * its registry badge. (T45's switch fills the slot.)
 *
 * CatalogueLayout picks the table or the cards by the view's `type`.
 */
import { DataViews } from '@wordpress/dataviews';
import { useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { CellContext } from '../cells';
import { HIDING_CHOICES, PER_PAGE_SIZES } from '../defaults';
import { LoadingRows } from '../states';
import CatalogueTable from '../table';

const LAYOUTS = { grid: {} };
const CONFIG = { perPageSizes: PER_PAGE_SIZES };
const itemId = ( item ) => String( item.id );

/**
 * The field the switch slot replaces: the first hiding field the registry
 * has (defaults.js names it).
 *
 * @param {Array<Object>} fields DataViews fields.
 * @return {string|undefined} Field id.
 */
export function switchFieldId( fields ) {
	const ids = new Set( fields.map( ( field ) => field.id ) );
	return Object.keys( HIDING_CHOICES ).find( ( key ) => ids.has( key ) );
}

/**
 * The fields and view the grid draws: with a switch, its field renders the
 * slot and is always shown.
 *
 * @param {Array<Object>} fields       DataViews fields.
 * @param {Object}        view         DataViews view.
 * @param {Function}      renderSwitch Slot renderer, or undefined.
 * @return {{fields: Array<Object>, view: Object, added: string|undefined}} What DataViews gets, and the field added only for the slot.
 */
export function gridOf( fields, view, renderSwitch ) {
	const slot = renderSwitch ? switchFieldId( fields ) : undefined;
	const shown = view.fields || [];
	const added = slot && ! shown.includes( slot ) ? slot : undefined;
	return {
		added,
		fields: slot
			? fields.map( ( field ) =>
					field.id === slot
						? {
								...field,
								render: ( { item } ) => renderSwitch( item ),
							}
						: field
				)
			: fields,
		view: {
			...view,
			type: 'grid',
			fields: added ? [ ...shown, added ] : shown,
		},
	};
}

/**
 * The cards. Takes CatalogueTable's props plus `renderSwitch`.
 *
 * @param {Object}        props                   Props.
 * @param {Array<Object>} props.fields            DataViews fields (fieldsFromRegistry()).
 * @param {Object}        props.view              DataViews view.
 * @param {Function}      props.onChangeView      View change.
 * @param {Array<Object>} props.items             Rows.
 * @param {Object}        props.pagination        { totalItems, totalPages }.
 * @param {boolean}       props.isLoading         A read is in flight.
 * @param {boolean}       props.hasLoaded         A page (or a failure) has arrived at least once.
 * @param {Array<Object>} props.actions           Row actions.
 * @param {Element}       props.empty             What to show when a loaded page has no rows.
 * @param {Object}        props.termNames         source => Map( id => name ).
 * @param {Set}           props.pending           Row ids with a change in flight.
 * @param {Array<string>} props.selection         Selected row ids, as strings.
 * @param {Function}      props.onChangeSelection Selection change.
 * @param {Function}      props.renderSwitch      The Visible on site switch for a row (T45), or undefined.
 * @return {Element} Grid.
 */
export function CatalogueCards( {
	fields,
	view,
	onChangeView,
	items,
	pagination,
	isLoading,
	hasLoaded,
	actions,
	empty,
	termNames,
	pending,
	selection,
	onChangeSelection,
	renderSwitch,
} ) {
	const context = useMemo(
		() => ( { termNames: termNames || {}, pending: pending || new Set() } ),
		[ termNames, pending ]
	);
	const grid = useMemo(
		() => gridOf( fields, view, renderSwitch ),
		[ fields, view, renderSwitch ]
	);
	if ( ! hasLoaded ) {
		return (
			<LoadingRows
				rows={ Math.min( view.perPage || 10, 10 ) }
				columns={ ( view.fields || [] ).length }
			/>
		);
	}
	return (
		<CellContext.Provider value={ context }>
			<div className="bcm-catalogue__cards">
				<DataViews
					data={ items }
					fields={ grid.fields }
					view={ grid.view }
					onChangeView={ ( next ) =>
						// The slot's field is not saved into the view's columns.
						onChangeView(
							grid.added
								? {
										...next,
										fields: ( next.fields || [] ).filter(
											( key ) => key !== grid.added
										),
									}
								: next
						)
					}
					paginationInfo={ pagination }
					defaultLayouts={ LAYOUTS }
					isLoading={ isLoading }
					actions={ actions }
					getItemId={ itemId }
					{ ...( onChangeSelection
						? { selection, onChangeSelection }
						: {} ) }
					config={ CONFIG }
					searchLabel={ __(
						'Search by name or SKU',
						'bulk-catalogue-manager-for-woocommerce'
					) }
					empty={ empty }
				/>
			</div>
		</CellContext.Provider>
	);
}

/**
 * The table or the cards, by the view's layout.
 *
 * @param {Object}   props              CatalogueTable's props plus `renderSwitch`.
 * @param {Function} props.renderSwitch The Visible on site switch for a row, or undefined.
 * @return {Element} Layout.
 */
export default function CatalogueLayout( { renderSwitch, ...props } ) {
	return props.view?.type === 'grid' ? (
		<CatalogueCards { ...props } renderSwitch={ renderSwitch } />
	) : (
		<CatalogueTable { ...props } />
	);
}
