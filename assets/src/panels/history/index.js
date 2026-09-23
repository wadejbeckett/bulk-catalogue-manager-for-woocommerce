/**
 * The History panel (SPEC §4.6; T57–T59): the change history with undo,
 * and a separate Audit log tab, each carrying the scope line. A finished
 * undo or an export problem is reported in a dismissible notice at the top.
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
import { userElements, useUserNames } from './users';
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

export default function History( { settings } ) {
	const meta = useRegistry();
	const [ seen, setSeen ] = useState( [] );
	const [ notice, setNotice ] = useState( null );
	const names = useUserNames( settings?.user, seen );
	const users = useMemo(
		() => userElements( names, settings?.user ),
		[ names, settings?.user ]
	);
	const onSeenUsers = useCallback(
		( ids ) =>
			setSeen( ( was ) => {
				const next = [ ...new Set( [ ...was, ...ids ] ) ];
				return next.length === was.length ? was : next;
			} ),
		[]
	);
	const onNotice = useCallback( ( next ) => setNotice( next ), [] );
	const shared = {
		settings,
		meta,
		names,
		users,
		onSeenUsers,
		onNotice,
		scope: <ScopeNotice />,
	};

	return (
		<section className="bcm-history">
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
					className="bcm-history__notice"
				>
					{ notice.text }
				</Notice>
			) }
			<TabPanel
				className="bcm-history__tabs"
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
						<AuditLog { ...shared } />
					) : (
						<Batches { ...shared } />
					)
				}
			</TabPanel>
		</section>
	);
}
