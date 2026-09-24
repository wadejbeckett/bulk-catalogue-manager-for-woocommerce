/**
 * The History panel (SPEC §4.6; T57–T59): the change history with undo,
 * and a separate Audit log tab, each carrying the scope line. A finished
 * undo or an export problem is reported in a dismissible notice at the top.
 * A `product` argument in the page URL (the product drawer's "See history"
 * link, T47) opens the Audit log filtered to that product, the one list
 * that can be filtered by product (GET /audit `product`).
 */
import { Notice, TabPanel } from '@wordpress/components';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { request } from '../../api';
import AuditLog from './audit';
import Batches from './batches';
import { fieldsByKey } from './describe';
import ErrorNotice from './error-notice';
import ScopeNotice from './scope-notice';
import { userElements, useBatchUsers } from './users';
import './style.scss';

export { StalledNotice, BatchProgress, isStalled } from './stalled-notice';
export { default as ScopeNotice } from './scope-notice';

const EMPTY_META = { byKey: {}, fields: [], actions: [], actionsById: {} };

/**
 * The registry the history words come from: field labels and choices, and
 * the actions' labels.
 *
 * @return {Object} `{ byKey, fields, actions, actionsById }`.
 */
function useRegistry() {
	const [ meta, setMeta ] = useState( EMPTY_META );
	useEffect( () => {
		let live = true;
		request( 'fields' )
			.then( ( answer ) => {
				if ( ! live ) {
					return;
				}
				const actions = answer?.actions || [];
				const actionsById = {};
				actions.forEach( ( action ) => {
					actionsById[ action.id ] = action;
				} );
				setMeta( {
					byKey: fieldsByKey( answer?.fields ),
					fields: answer?.fields || [],
					actions,
					actionsById,
				} );
			} )
			.catch( () => {} );
		return () => {
			live = false;
		};
	}, [] );
	return meta;
}

/**
 * The product a URL's `product` argument names.
 *
 * @param {string} search A location's search string.
 * @return {number|null} Product id, or null.
 */
export function productFrom( search ) {
	const id = Number( new URLSearchParams( search ).get( 'product' ) );
	return Number.isInteger( id ) && id > 0 ? id : null;
}

/**
 * The batch a URL's `batch` argument names, and whether `undo` asks for its
 * undo to start: the bulk panel's Undo this change and View in History (T75).
 *
 * @param {string} search A location's search string.
 * @return {{id: number, undo: boolean}|null} The linked batch, or null.
 */
export function batchFrom( search ) {
	const args = new URLSearchParams( search );
	const id = Number( args.get( 'batch' ) );
	return Number.isInteger( id ) && id > 0
		? { id, undo: args.get( 'undo' ) === '1' }
		: null;
}

/**
 * Take the `batch` and `undo` arguments off the page URL once read.
 */
function forgetBatch() {
	const url = new URL( window.location.href );
	if ( url.searchParams.has( 'batch' ) || url.searchParams.has( 'undo' ) ) {
		url.searchParams.delete( 'batch' );
		url.searchParams.delete( 'undo' );
		window.history.replaceState( window.history.state, '', url.toString() );
	}
}

/**
 * Take the `product` argument off the page URL, leaving the panel in place.
 */
function forgetProduct() {
	const url = new URL( window.location.href );
	if ( url.searchParams.has( 'product' ) ) {
		url.searchParams.delete( 'product' );
		window.history.replaceState( window.history.state, '', url.toString() );
	}
}

export default function History( { settings } ) {
	const meta = useRegistry();
	const [ notice, setNotice ] = useState( null );
	const [ linkedProduct, setLinkedProduct ] = useState( () =>
		productFrom( window.location.search )
	);
	const clearLinked = useCallback( () => {
		setLinkedProduct( null );
		forgetProduct();
	}, [] );
	const [ linkedBatch ] = useState( () =>
		batchFrom( window.location.search )
	);
	useEffect( () => forgetBatch(), [] );
	const names = useBatchUsers( settings?.user );
	const users = useMemo(
		() => userElements( names, settings?.user ),
		[ names, settings?.user ]
	);
	const onNotice = useCallback( ( next ) => setNotice( next ), [] );
	const shared = {
		settings,
		meta,
		names,
		users,
		onNotice,
		scope: <ScopeNotice />,
	};

	return (
		<section className="bulkcat-history">
			<h2>
				{ __( 'History', 'bulk-catalogue-manager-for-woocommerce' ) }
			</h2>
			{ notice && notice.error && (
				<ErrorNotice
					error={ notice.error }
					fallback={ __(
						'The file could not be made. Try again in a moment.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
					onRemove={ () => setNotice( null ) }
				/>
			) }
			{ notice && ! notice.error && (
				<Notice
					status={ notice.status || 'success' }
					onRemove={ () => setNotice( null ) }
					className="bulkcat-history__notice"
				>
					{ notice.text }
				</Notice>
			) }
			<TabPanel
				className="bulkcat-history__tabs"
				initialTabName={ linkedProduct ? 'audit' : 'changes' }
				tabs={ [
					{
						name: 'changes',
						title: __(
							'Changes',
							'bulk-catalogue-manager-for-woocommerce'
						),
					},
					{
						name: 'audit',
						title: __(
							'Audit log',
							'bulk-catalogue-manager-for-woocommerce'
						),
					},
				] }
			>
				{ ( tab ) =>
					tab.name === 'audit' ? (
						<AuditLog
							{ ...shared }
							linkedProduct={ linkedProduct }
							onClearLinked={ clearLinked }
						/>
					) : (
						<Batches { ...shared } linkedBatch={ linkedBatch } />
					)
				}
			</TabPanel>
		</section>
	);
}
