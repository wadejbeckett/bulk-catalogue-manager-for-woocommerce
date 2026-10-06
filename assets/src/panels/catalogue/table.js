/**
 * The DataViews table over a page of products: registry-built fields, the
 * row menu, and the table's own empty content per state.
 */
import { DataViews } from '@wordpress/dataviews';
import { useCallback, useEffect, useMemo, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { CellContext } from './cells';
import { useEditScope } from './edit';
import { onGridKeyDown } from './grid-keys';
import { LoadingRows } from './states';
import { useVariationRows } from './variations';

const LAYOUTS = { table: {} };
// Ticket 19: rows per page is the toolbar control, which needs no sort.
const CONFIG = { perPageSizes: [] };

/**
 * A row's id as DataViews keys its selection.
 *
 * @param {Object} item Row.
 * @return {string} Id.
 */
const itemId = ( item ) => String( item.id );

/**
 * What DataViews' table does not say itself (SPEC §14, T75): a caption, and
 * each row's product name cell as the row's header. DataViews owns the
 * table's markup, so these are set on it after each render.
 *
 * Every sortable column that is not the sorted one says so with
 * `aria-sort="none"` (SPEC §14: sort state on every sortable column).
 *
 * @param {Element}       wrapper  The table's wrapper.
 * @param {string}        caption  The caption's words.
 * @param {Array<string>} sortable Labels of the sortable columns.
 */
export function describeTable( wrapper, caption, sortable = [] ) {
	const table = wrapper?.querySelector( 'table' );
	if ( ! table ) {
		return;
	}
	let node = table.caption;
	if ( ! node ) {
		node = table.ownerDocument.createElement( 'caption' );
		node.className = 'bulkcat-sr-only';
		table.prepend( node );
	}
	if ( node.textContent !== caption ) {
		node.textContent = caption;
	}
	table.querySelectorAll( 'thead th' ).forEach( ( th ) => {
		const label = th.textContent.trim();
		if ( sortable.includes( label ) && ! th.hasAttribute( 'aria-sort' ) ) {
			th.setAttribute( 'aria-sort', 'none' );
		} else if (
			! sortable.includes( label ) &&
			th.getAttribute( 'aria-sort' ) === 'none'
		) {
			th.removeAttribute( 'aria-sort' );
		}
	} );
	table
		.querySelectorAll( 'tbody .dataviews-title-field' )
		.forEach( ( title ) => {
			const cell = title.closest( 'td' );
			if ( cell && cell.getAttribute( 'role' ) !== 'rowheader' ) {
				cell.setAttribute( 'role', 'rowheader' );
			}
		} );
}

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
 * @param {Element}       props.empty             What to show when a loaded page has no rows.
 * @param {Object}        props.termNames         source => Map( id => name ).
 * @param {Array<string>} props.selection         Selected row ids, as strings.
 * @param {Function}      props.onChangeSelection Selection change.
 * @param {Function}      props.onDrawn           Receives the ids of every row drawn (the page and opened variations), T84.
 * @param {Object}        props.edit              Shared editing scope.
 * @param {Object}        props.expand            Reference to variation expansion.
 * @return {Element} Table.
 */
function Table( {
	fields,
	view,
	onChangeView,
	items,
	pagination,
	isLoading,
	hasLoaded,
	empty,
	termNames,
	selection,
	onChangeSelection,
	onDrawn = null,
	edit,
	expand,
} ) {
	const tree = useVariationRows( {
		items: edit.items,
		fields,
		view,
		onChangeView,
		actions: edit.actions,
		pagination,
		selection,
		onChangeSelection,
	} ); // T67–T68.
	expand.current = tree.expand;
	// T84: the selection inspects exactly the rows drawn.
	const drawnKey = ( tree.props.data || [] )
		.map( ( row ) => String( row.id ) )
		.join( ',' );
	useEffect( () => {
		if ( onDrawn ) {
			onDrawn( drawnKey ? drawnKey.split( ',' ) : [] );
		}
	}, [ drawnKey, onDrawn ] );
	const wrapper = useRef( null );
	const caption = __(
		'Products. Sort with the column headings; arrow keys move between cells.',
		'bulk-catalogue-manager-for-woocommerce'
	);
	// Every render: DataViews may have redrawn its table.
	const sortable = useMemo(
		() =>
			fields
				.filter( ( field ) => field.enableSorting )
				.map( ( field ) => field.label ),
		[ fields ]
	);
	useEffect( () => describeTable( wrapper.current, caption, sortable ) );
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
			{ /* The arrows move between cells (grid-keys.js); Tab is untouched. */ }
			{ /* eslint-disable-next-line jsx-a11y/no-static-element-interactions */ }
			<div
				ref={ wrapper }
				onKeyDown={ onGridKeyDown }
				className={
					'bulkcat-catalogue__table' +
					( view.layout?.density
						? ` is-${ view.layout.density }`
						: '' )
				}
			>
				{ tree.before }
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
					{ ...tree.props }
				/>
				{ tree.after }
			</div>
		</CellContext.Provider>
	);
}

/**
 * Standalone tables own their edit scope; the catalogue supplies its shared
 * scope so switching layout cannot discard an unconfirmed write.
 *
 * @param {Object} props Table properties.
 * @return {Element} Table with an editing scope.
 */
export default function CatalogueTable( props ) {
	return props.edit ? <Table { ...props } /> : <EditingTable { ...props } />;
}

/**
 * @param {Object} props Table properties.
 * @return {Element} Independently scoped table.
 */
function EditingTable( props ) {
	const expand = useRef( null );
	const onExpandVariations = useCallback(
		( item ) => expand.current?.( item ),
		[]
	);
	const edit = useEditScope( { ...props, onExpandVariations } );
	return <Table { ...props } edit={ edit } expand={ expand } />;
}
