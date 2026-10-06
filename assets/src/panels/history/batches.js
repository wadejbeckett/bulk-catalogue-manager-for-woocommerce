/**
 * The history list (SPEC §4.6; T57): batches newest first from GET
 * /batches, server-side paged and filtered by date range, user, action
 * type and status through DataViews' own filter bar and pagination, each
 * batch a card that expands to its changes in place.
 */
import { DataViews } from '@wordpress/dataviews';
import { Button } from '@wordpress/components';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { request } from '../../api';
import BatchCard from './batch-card';
import { useHistoryExport } from './export';
import {
	DATE_OPERATORS,
	dateRange,
	describeBatch,
	singleValue,
	statusElements,
} from './describe';
import ErrorNotice from './error-notice';
import { rowUserName } from './users';
import { formatNumber } from '../bulk/words';

export const PER_PAGE_SIZES = [ 10, 25, 50, 100 ];

const LAYOUTS = { list: {} };

/** The list opens on the last 30 days, as the prototype does. */
export function defaultBatchView() {
	return {
		type: 'list',
		page: 1,
		perPage: 25,
		search: '',
		fields: [],
		filters: [
			{
				field: 'created_gmt',
				operator: 'inThePast',
				value: { value: 30, unit: 'days' },
			},
		],
		layout: {},
	};
}

/**
 * GET /batches arguments for a DataViews view.
 *
 * @param {Object} view DataViews view.
 * @param {number} now  Current time in ms.
 * @return {Object} Query.
 */
export function toBatchQuery( view, now = Date.now() ) {
	const query = { page: view.page || 1, per_page: view.perPage || 25 };
	const byField = {};
	( view.filters || [] ).forEach( ( filter ) => {
		byField[ filter.field ] = filter;
	} );
	Object.assign( query, dateRange( byField.created_gmt, now ) );
	const user = singleValue( byField.user_id );
	if ( user !== undefined ) {
		query.user = Number( user );
	}
	const action = singleValue( byField.action );
	if ( action !== undefined ) {
		query.action = String( action );
	}
	const status = singleValue( byField.status );
	if ( status !== undefined ) {
		query.status = String( status );
	}
	return query;
}

/**
 * The DataViews fields that carry the list's filters.
 *
 * @param {Array<Object>} users   User elements.
 * @param {Array<Object>} actions Action descriptors (GET /fields `actions`).
 * @return {Array<Object>} Fields.
 */
export function batchFields( users, actions ) {
	return [
		{
			id: 'created_gmt',
			label: __( 'Date', 'bulk-catalogue-manager-for-woocommerce' ),
			type: 'date',
			enableSorting: false,
			enableHiding: false,
			filterBy: { operators: DATE_OPERATORS, isPrimary: true },
		},
		{
			id: 'user_id',
			label: __( 'Who', 'bulk-catalogue-manager-for-woocommerce' ),
			type: 'integer',
			elements: users,
			enableSorting: false,
			enableHiding: false,
			filterBy: { operators: [ 'is' ], isPrimary: true },
		},
		{
			id: 'action',
			label: __( 'Action', 'bulk-catalogue-manager-for-woocommerce' ),
			type: 'text',
			elements: ( actions || [] ).map( ( action ) => ( {
				value: action.id,
				label: action.label,
			} ) ),
			getValue: ( { item } ) => item.actions?.[ 0 ] ?? '',
			enableSorting: false,
			enableHiding: false,
			filterBy: { operators: [ 'is' ], isPrimary: true },
		},
		{
			id: 'status',
			label: __( 'Outcome', 'bulk-catalogue-manager-for-woocommerce' ),
			type: 'text',
			elements: statusElements(),
			enableSorting: false,
			enableHiding: false,
			filterBy: { operators: [ 'is' ], isPrimary: true },
		},
	];
}

/**
 * The retention line under the list.
 *
 * @param {number} days `history_days` (0 keeps history indefinitely).
 * @return {string} Line.
 */
export function retentionLine( days ) {
	const n = Number( days );
	if ( n === 0 ) {
		return __(
			'History is kept indefinitely. Change this in Settings.',
			'bulk-catalogue-manager-for-woocommerce'
		);
	}
	const kept = n > 0 ? n : 90;
	return sprintf(
		/* translators: %s: number of days, such as "90". */
		_n(
			'Older batches are removed automatically after %s day. Change this in Settings.',
			'Older batches are removed automatically after %s days. Change this in Settings.',
			kept,
			'bulk-catalogue-manager-for-woocommerce'
		),
		formatNumber( kept )
	);
}

/**
 * The list.
 *
 * @param {Object}   props             Props.
 * @param {Object}   props.settings    Page settings.
 * @param {Object}   props.meta        `{ byKey, actions, actionsById }` from GET /fields.
 * @param {Object}   props.names       User names by id.
 * @param {Array}    props.users       User filter elements.
 * @param {Function} props.onNotice    Show a screen notice.
 * @param {Element}  props.scope       The scope line.
 * @param {Object}   props.linkedBatch The batch the URL names, `{ id, undo }` (T75).
 * @return {Element} List.
 */
export default function Batches( {
	settings,
	meta,
	names,
	users,
	onNotice,
	scope,
	linkedBatch = null,
} ) {
	const [ view, setView ] = useState( defaultBatchView );
	const [ items, setItems ] = useState( [] );
	const [ pagination, setPagination ] = useState( {
		totalItems: 0,
		totalPages: 0,
	} );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( null );
	const [ attempt, setAttempt ] = useState( 0 );
	const exporter = useHistoryExport( onNotice );

	useEffect( () => {
		let live = true;
		setLoading( true );
		request( 'batches', { query: toBatchQuery( view ) } )
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
	}, [ view, attempt ] );

	const reload = useCallback( () => setAttempt( ( n ) => n + 1 ), [] );
	const fields = useMemo(
		() => batchFields( users, meta.actions ),
		[ users, meta.actions ]
	);
	const config = useMemo( () => ( { perPageSizes: PER_PAGE_SIZES } ), [] );
	const what = ( batch ) =>
		describeBatch( batch, meta.byKey, meta.actionsById );
	const exportList = () => exporter.run( 'batches', toBatchQuery( view ) );

	return (
		<div className="bulkcat-history__list">
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
				getItemId={ ( item ) => String( item.batch_id ) }
			>
				<div className="bulkcat-history__bar">
					<DataViews.FiltersToggle />
					<div className="bulkcat-history__bar-right">
						<Button
							variant="secondary"
							onClick={ exportList }
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
				<DataViews.FiltersToggled className="dataviews-filters__container" />
				{ scope }
				{ error && (
					<ErrorNotice
						error={ error }
						fallback={ __(
							'The history could not be loaded. Try again in a moment.',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					/>
				) }
				{ error && (
					<Button variant="secondary" onClick={ reload }>
						{ __(
							'Try again',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</Button>
				) }
				{ ! error && loading && ! items.length && (
					<p className="bulkcat-history__loading">
						{ __(
							'Loading the history…',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</p>
				) }
				{ ! error && ! loading && ! items.length && (
					<div className="bulkcat-history__empty">
						<p>
							{ __(
								'No changes match these filters yet.',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</p>
					</div>
				) }
				<div className="bulkcat-history__batches">
					{ items.map( ( batch ) => (
						<BatchCard
							key={ batch.batch_id }
							batch={ batch }
							what={ what( batch ) }
							who={ rowUserName( batch, names ) }
							byKey={ meta.byKey }
							onChanged={ reload }
							onNotice={ onNotice }
							linked={
								linkedBatch?.id === batch.batch_id
									? linkedBatch
									: null
							}
						/>
					) ) }
				</div>
				<DataViews.Pagination />
			</DataViews>
			<p className="bulkcat-history__retention">
				{ retentionLine( settings?.settings?.history_days ) }
			</p>
		</div>
	);
}
