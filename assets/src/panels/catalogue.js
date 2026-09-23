/**
 * The catalogue: a DataViews table over GET /products, paged, sorted and
 * searched on the server, its columns described by GET /fields. The grid's
 * full behaviour (SPEC §4.1) arrives in E4; this proves the bundle, the
 * routes and the nonce end to end.
 */
import { DataViews } from '@wordpress/dataviews';
import { Button, Notice } from '@wordpress/components';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { request } from '../api';

const DEFAULT_COLUMNS = [
	'sku',
	'status',
	'regular_price',
	'sale_price',
	'stock_status',
];

const PER_PAGE_SIZES = [ 25, 50, 100 ];

const LAYOUTS = { table: {} };

const NUMERIC = [ 'number', 'integer' ];

/**
 * The DataViews fields for the registry's descriptors. Filters wait for
 * E4's filter chips, so none is offered yet. A number is shown as the server
 * formats it, and an empty one (no sale price) as nothing rather than 0.
 *
 * @param {Array<Object>} descriptors The `fields` of GET /fields.
 * @return {Array<Object>} DataViews fields.
 */
export function toDataViewsFields( descriptors ) {
	return descriptors
		.filter( ( field ) => field.dataviews && field.key !== 'thumbnail' )
		.map( ( field ) => {
			const out = { ...field.dataviews, filterBy: false };
			if ( NUMERIC.includes( out.type ) ) {
				out.render = ( { item } ) => {
					const value = item[ out.id ];
					return value === null || value === undefined || value === ''
						? ''
						: String( value );
				};
			}
			return out;
		} );
}

/**
 * The /products query for a DataViews view.
 *
 * @param {Object} view DataViews view.
 * @return {Object} Query arguments.
 */
export function toQuery( view ) {
	const query = {
		page: view.page,
		per_page: view.perPage,
		fields: [ 'id', 'name', ...( view.fields || [] ) ],
	};
	if ( view.search ) {
		query.search = view.search;
	}
	if ( view.sort?.field ) {
		query.orderby = view.sort.field;
		query.order = view.sort.direction;
	}
	return query;
}

export default function Catalogue( { settings } ) {
	const [ fields, setFields ] = useState( null );
	const [ view, setView ] = useState( () => ( {
		type: 'table',
		page: 1,
		perPage: PER_PAGE_SIZES.includes( settings?.settings?.rows_per_page )
			? settings.settings.rows_per_page
			: 25,
		search: '',
		titleField: 'name',
		fields: DEFAULT_COLUMNS,
		sort: { field: 'name', direction: 'asc' },
		filters: [],
		layout: {},
	} ) );
	const [ rows, setRows ] = useState( [] );
	const [ pagination, setPagination ] = useState( {
		totalItems: 0,
		totalPages: 0,
	} );
	const [ loading, setLoading ] = useState( true );
	const [ failed, setFailed ] = useState( false );
	const [ attempt, setAttempt ] = useState( 0 );

	useEffect( () => {
		let live = true;
		request( 'fields' )
			.then( ( answer ) => {
				if ( live ) {
					setFields( toDataViewsFields( answer?.fields || [] ) );
				}
			} )
			.catch( () => live && setFailed( true ) );
		return () => {
			live = false;
		};
	}, [ attempt ] );

	useEffect( () => {
		if ( ! fields ) {
			return;
		}
		let live = true;
		setLoading( true );
		request( 'products', { query: toQuery( view ) } )
			.then( ( answer ) => {
				if ( ! live ) {
					return;
				}
				setRows( answer?.items || [] );
				setPagination( {
					totalItems: answer?.pagination?.total || 0,
					totalPages: answer?.pagination?.totalPages || 0,
				} );
				setFailed( false );
			} )
			.catch( () => live && setFailed( true ) )
			.finally( () => live && setLoading( false ) );
		return () => {
			live = false;
		};
	}, [ fields, view, attempt ] );

	const retry = useCallback( () => setAttempt( ( n ) => n + 1 ), [] );
	const config = useMemo( () => ( { perPageSizes: PER_PAGE_SIZES } ), [] );

	return (
		<div className="bcm-catalogue">
			{ failed && (
				<Notice
					status="error"
					isDismissible={ false }
					actions={ [
						{
							label: __(
								'Try again',
								'bulk-catalogue-manager-for-woocommerce'
							),
							onClick: retry,
						},
					] }
				>
					{ __(
						'The products could not be loaded.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</Notice>
			) }
			{ fields ? (
				<DataViews
					data={ rows }
					fields={ fields }
					view={ view }
					onChangeView={ setView }
					paginationInfo={ pagination }
					defaultLayouts={ LAYOUTS }
					isLoading={ loading }
					config={ config }
					searchLabel={ __(
						'Search by name or SKU',
						'bulk-catalogue-manager-for-woocommerce'
					) }
					empty={
						<p>
							{ __(
								'There are no products yet.',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</p>
					}
				/>
			) : (
				! failed && (
					<p className="bcm-catalogue__loading">
						{ __(
							'Loading products…',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</p>
				)
			) }
			{ failed && ! fields && (
				<Button variant="secondary" onClick={ retry }>
					{ __(
						'Try again',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</Button>
			) }
		</div>
	);
}
