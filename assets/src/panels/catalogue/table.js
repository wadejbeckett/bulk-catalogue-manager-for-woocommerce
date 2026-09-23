/**
 * The DataViews table over a page of products: registry-built fields, the
 * row menu, and the table's own empty content per state.
 */
import { DataViews } from '@wordpress/dataviews';
import { useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { CellContext } from './cells';
import { PER_PAGE_SIZES } from './defaults';
import { useEditScope } from './edit';
import { LoadingRows } from './states';

const LAYOUTS = { table: {} };
const CONFIG = { perPageSizes: PER_PAGE_SIZES };

/**
 * A row's id as DataViews keys its selection.
 *
 * @param {Object} item Row.
 * @return {string} Id.
 */
const itemId = ( item ) => String( item.id );

/**
 * The table.
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
 * @return {Element} Table.
 */
export default function CatalogueTable( {
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
} ) {
	const edit = useEditScope( { items, fields, actions, pending } );
	const context = useMemo(
		() => ( { termNames, pending: edit.pending } ),
		[ termNames, edit.pending ]
	);
	const skeleton = (
		<LoadingRows
			rows={ Math.min( view.perPage || 10, 10 ) }
			columns={ ( view.fields || [] ).length }
		/>
	);
	// DataViews draws nothing, then a spinner, until its first load settles,
	// so the skeleton stands in for it until a page (or a failure) arrives.
	if ( ! hasLoaded ) {
		return skeleton;
	}
	return edit.wrap(
		<CellContext.Provider value={ context }>
			<div
				className={
					'bcm-catalogue__table' +
					( view.layout?.density
						? ` is-${ view.layout.density }`
						: '' )
				}
			>
				<DataViews
					data={ edit.items }
					fields={ fields }
					view={ view }
					onChangeView={ onChangeView }
					paginationInfo={ pagination }
					defaultLayouts={ LAYOUTS }
					isLoading={ isLoading }
					actions={ edit.actions }
					getItemId={ itemId }
					{ ...( onChangeSelection
						? { selection, onChangeSelection }
						: {} ) }
					config={ CONFIG }
					searchLabel={ __(
						'Search by name or SKU',
						'bulk-catalogue-manager-for-woocommerce'
					) }
					empty={ isLoading && ! items.length ? skeleton : empty }
				/>
			</div>
		</CellContext.Provider>
	);
}
