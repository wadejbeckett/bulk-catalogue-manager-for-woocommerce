/**
 * The Audit log tab (SPEC §4.6, §5.1; T58): field changes across every
 * batch from GET /audit, newest first, in a DataViews table, filtered by
 * product (found by name through GET /products?search), field (by its
 * registry label, never its storage key), user and date range. Product
 * names are the route's own `name`, primed once per page on the server.
 */
import { DataViews } from '@wordpress/dataviews';
import { Button, ComboboxControl, Notice } from '@wordpress/components';
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { request } from '../../api';
import { resultLabel } from './changes';
import { useHistoryExport } from './export';
import {
	DATE_OPERATORS,
	dateRange,
	fieldLabel,
	formatValue,
	formatWhen,
	singleValue,
} from './describe';
import ErrorNotice from './error-notice';
import { rowUserName } from './users';

const LAYOUTS = { table: {} };

const PER_PAGE_SIZES = [ 25, 50, 100 ];

/** The table opens on every change, newest first. */
export function defaultAuditView() {
	return {
		type: 'table',
		page: 1,
		perPage: 25,
		search: '',
		titleField: 'name',
		fields: [
			'applied_gmt',
			'field',
			'old_value',
			'new_value',
			'result',
			'user_id',
			'batch_id',
		],
		filters: [],
		layout: {},
	};
}

/**
 * GET /audit arguments for a view and the chosen product.
 *
 * @param {Object}      view    DataViews view.
 * @param {number|null} product Chosen product id.
 * @param {number}      now     Current time in ms.
 * @return {Object} Query.
 */
export function toAuditQuery( view, product = null, now = Date.now() ) {
	const query = { page: view.page || 1, per_page: view.perPage || 25 };
	const byField = {};
	( view.filters || [] ).forEach( ( filter ) => {
		byField[ filter.field ] = filter;
	} );
	if ( product ) {
		query.product = Number( product );
	}
	const field = singleValue( byField.field );
	if ( field !== undefined ) {
		query.field = String( field );
	}
	const user = singleValue( byField.user_id );
	if ( user !== undefined ) {
		query.user = Number( user );
	}
	Object.assign( query, dateRange( byField.applied_gmt, now ) );
	return query;
}

/**
 * The fields a change can be recorded against, as filter choices by label:
 * the editable registry fields (read-only columns are never written).
 *
 * @param {Array<Object>} descriptors GET /fields `fields`.
 * @return {Array<{value: string, label: string}>} Elements.
 */
export function fieldElements( descriptors ) {
	return ( descriptors || [] )
		.filter( ( field ) => ! field.readOnly )
		.map( ( field ) => ( { value: field.key, label: field.label } ) );
}

/**
 * The audit table's DataViews fields.
 *
 * @param {Object} meta  `{ byKey, fields }` from GET /fields.
 * @param {Object} names User names by id.
 * @param {Array}  users User filter elements.
 * @return {Array<Object>} Fields.
 */
export function auditFields( meta, names, users ) {
	const byKey = meta.byKey || {};
	return [
		{
			id: 'name',
			label: __( 'Product', 'bulk-catalogue-manager-for-woocommerce' ),
			type: 'text',
			enableSorting: false,
			enableHiding: false,
			filterBy: false,
		},
		{
			id: 'applied_gmt',
			label: __( 'When', 'bulk-catalogue-manager-for-woocommerce' ),
			type: 'date',
			enableSorting: false,
			render: ( { item } ) => formatWhen( item.applied_gmt ),
			filterBy: { operators: DATE_OPERATORS, isPrimary: true },
		},
		{
			id: 'field',
			label: __( 'Field', 'bulk-catalogue-manager-for-woocommerce' ),
			type: 'text',
			elements: fieldElements( meta.fields ),
			enableSorting: false,
			render: ( { item } ) =>
				fieldLabel( item.field, byKey, item.field_label ),
			filterBy: { operators: [ 'is' ], isPrimary: true },
		},
		{
			id: 'old_value',
			label: __( 'Before', 'bulk-catalogue-manager-for-woocommerce' ),
			type: 'text',
			enableSorting: false,
			filterBy: false,
			render: ( { item } ) =>
				formatValue( item.old_value, byKey[ item.field ] ),
		},
		{
			id: 'new_value',
			label: __( 'After', 'bulk-catalogue-manager-for-woocommerce' ),
			type: 'text',
			enableSorting: false,
			filterBy: false,
			render: ( { item } ) =>
				formatValue( item.new_value, byKey[ item.field ] ),
		},
		{
			id: 'result',
			label: __( 'Result', 'bulk-catalogue-manager-for-woocommerce' ),
			type: 'text',
			enableSorting: false,
			filterBy: false,
			render: ( { item } ) =>
				item.result === 'applied' || ! item.message
					? resultLabel( item.result )
					: sprintf(
							/* translators: 1: a result, such as "Skipped"; 2: its reason. */
							__(
								'%1$s: %2$s',
								'bulk-catalogue-manager-for-woocommerce'
							),
							resultLabel( item.result ),
							item.message
						),
		},
		{
			id: 'user_id',
			label: __( 'Who', 'bulk-catalogue-manager-for-woocommerce' ),
			type: 'integer',
			elements: users,
			enableSorting: false,
			render: ( { item } ) => rowUserName( item, names ),
			filterBy: { operators: [ 'is' ], isPrimary: true },
		},
		{
			id: 'batch_id',
			label: __( 'Change', 'bulk-catalogue-manager-for-woocommerce' ),
			type: 'integer',
			enableSorting: false,
			filterBy: false,
			render: ( { item } ) =>
				sprintf(
					/* translators: %s: the change's number, such as "418". */
					__( '#%s', 'bulk-catalogue-manager-for-woocommerce' ),
					String( item.batch_id )
				),
		},
	];
}

/**
 * Find products by name for the product filter.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.value    Chosen `{ id, name }` or null.
 * @param {Function} props.onChange Called with `{ id, name }` or null.
 * @return {Element} Control.
 */
function ProductFilter( { value, onChange } ) {
	const [ options, setOptions ] = useState( [] );
	const timer = useRef( null );
	const known = value
		? [ { value: String( value.id ), label: value.name } ]
		: [];

	const search = ( text ) => {
		clearTimeout( timer.current );
		if ( ! text || text.length < 2 ) {
			return;
		}
		timer.current = setTimeout( () => {
			request( 'products', {
				query: { search: text, per_page: 20, fields: [ 'id', 'name' ] },
			} )
				.then( ( answer ) =>
					setOptions(
						( answer?.items || [] ).map( ( item ) => ( {
							value: String( item.id ),
							label: item.name,
						} ) )
					)
				)
				.catch( () => setOptions( [] ) );
		}, 300 );
	};

	useEffect( () => () => clearTimeout( timer.current ), [] );

	const all = [
		...known,
		...options.filter(
			( option ) => ! known.some( ( k ) => k.value === option.value )
		),
	];

	return (
		<ComboboxControl
			__nextHasNoMarginBottom
			__next40pxDefaultSize
			className="bcm-audit__product"
			label={ __( 'Product', 'bulk-catalogue-manager-for-woocommerce' ) }
			help={ __(
				'Type part of a product name to find it.',
				'bulk-catalogue-manager-for-woocommerce'
			) }
			value={ value ? String( value.id ) : null }
			options={ all }
			onFilterValueChange={ search }
			onChange={ ( chosen ) => {
				const option = all.find( ( each ) => each.value === chosen );
				onChange(
					option
						? { id: Number( option.value ), name: option.label }
						: null
				);
			} }
		/>
	);
}

/**
 * The audit log tab.
 *
 * @param {Object}   props               Props.
 * @param {Object}   props.meta          `{ byKey, fields }` from GET /fields.
 * @param {Object}   props.names         User names by id.
 * @param {Array}    props.users         User filter elements.
 * @param {Function} props.onNotice      Show a screen notice.
 * @param {Element}  props.scope         The scope line.
 * @param {number}   props.linkedProduct A product id the page URL names, or null.
 * @param {Function} props.onClearLinked Forget the URL's product.
 * @return {Element} Tab.
 */
export default function AuditLog( {
	meta,
	names,
	users,
	onNotice,
	scope,
	linkedProduct = null,
	onClearLinked,
} ) {
	const [ view, setView ] = useState( defaultAuditView );
	const [ product, setProduct ] = useState( () =>
		linkedProduct
			? {
					id: linkedProduct,
					linked: true,
					name: sprintf(
						/* translators: %s: a product id, such as "55". */
						__(
							'Product #%s',
							'bulk-catalogue-manager-for-woocommerce'
						),
						String( linkedProduct )
					),
				}
			: null
	);
	const [ items, setItems ] = useState( [] );
	const [ pagination, setPagination ] = useState( {
		totalItems: 0,
		totalPages: 0,
	} );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const exporter = useHistoryExport( onNotice );

	const productId = product?.id || null;

	useEffect( () => {
		let live = true;
		setLoading( true );
		request( 'audit', { query: toAuditQuery( view, productId ) } )
			.then( ( answer ) => {
				if ( ! live ) {
					return;
				}
				const rows = answer?.items || [];
				setItems( rows );
				setPagination( {
					totalItems: answer?.pagination?.total || 0,
					totalPages: answer?.pagination?.totalPages || 0,
				} );
				setError( null );
			} )
			.catch( ( caught ) => live && setError( caught ) )
			.finally( () => live && setLoading( false ) );
		return () => {
			live = false;
		};
	}, [ view, productId ] );

	const fields = useMemo(
		() => auditFields( meta, names, users ),
		[ meta, names, users ]
	);
	const config = useMemo( () => ( { perPageSizes: PER_PAGE_SIZES } ), [] );

	const exportLog = () =>
		exporter.run( 'audit', toAuditQuery( view, productId ) );

	const chooseProduct = ( chosen ) => {
		setProduct( chosen );
		setView( ( was ) => ( { ...was, page: 1 } ) );
		if ( product?.linked && onClearLinked ) {
			onClearLinked();
		}
	};

	return (
		<div className="bcm-audit">
			<div className="bcm-history__bar">
				<ProductFilter value={ product } onChange={ chooseProduct } />
				<div className="bcm-history__bar-right">
					<Button
						variant="secondary"
						onClick={ exportLog }
						isBusy={ exporter.busy }
						disabled={ exporter.busy }
						accessibleWhenDisabled
					>
						{ __(
							'Export log (CSV)',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</Button>
				</div>
			</div>
			{ product?.linked && (
				<Notice
					status="info"
					isDismissible={ false }
					className="bcm-audit__linked"
					actions={ [
						{
							label: __(
								'Show every product',
								'bulk-catalogue-manager-for-woocommerce'
							),
							onClick: () => chooseProduct( null ),
							variant: 'link',
						},
					] }
				>
					{ sprintf(
						/* translators: %s: a product id, such as "55". */
						__(
							'Showing changes to product #%s.',
							'bulk-catalogue-manager-for-woocommerce'
						),
						String( product.id )
					) }
				</Notice>
			) }
			{ scope }
			{ error && (
				<ErrorNotice
					error={ error }
					fallback={ __(
						'The audit log could not be loaded. Try again in a moment.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				/>
			) }
			<DataViews
				data={ items }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				paginationInfo={ pagination }
				defaultLayouts={ LAYOUTS }
				isLoading={ loading }
				config={ config }
				search={ false }
				getItemId={ ( item ) => String( item.change_id ) }
				empty={
					<div className="bcm-history__empty">
						<p>
							{ __(
								'No field changes match these filters.',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</p>
					</div>
				}
			/>
		</div>
	);
}
