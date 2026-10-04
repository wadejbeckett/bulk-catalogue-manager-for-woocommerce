import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { request } from '../api';
import { readSettings } from '../settings';
import BulkChangePanel from '../panels/bulk/panel';
import { chooseAction, emptyOperation } from '../panels/bulk/operations';

/**
 * Host adapter only: discovery feeds the existing wizard and its single confirmation.
 * @param {Object}   root0             Root0.
 * @param {Object}   root0.entry       Entry.
 * @param {Function} root0.onClose     OnClose.
 * @param {Function} root0.onCompleted OnCompleted.
 */
export default function ProviderActionPanel( { entry, onClose, onCompleted } ) {
	const key = JSON.stringify( entry );
	const [ retry, setRetry ] = useState( 0 );
	const [ state, setState ] = useState( {
		key: '',
		registry: null,
		error: false,
	} );
	useEffect( () => {
		let stopped = false;
		const current = JSON.parse( key );
		setState( { key, registry: null, error: false } );
		request( 'provider-actions', {
			method: 'POST',
			data: { location: current.location, selection: current.selection },
		} )
			.then( ( registry ) => {
				if ( ! stopped ) {
					setState( { key, registry, error: false } );
				}
			} )
			.catch( () => {
				if ( ! stopped ) {
					setState( { key, registry: null, error: true } );
				}
			} );
		return () => {
			stopped = true;
		};
	}, [ key, retry ] );
	if ( state.key !== key || ! state.registry ) {
		return (
			<div
				role="dialog"
				aria-label={ __(
					'Choose a change',
					'bulk-catalogue-manager-for-woocommerce'
				) }
			>
				<p role="status">
					{ state.error
						? __(
								'Actions could not be loaded. Try again.',
								'bulk-catalogue-manager-for-woocommerce'
							)
						: __(
								'Loading actions…',
								'bulk-catalogue-manager-for-woocommerce'
							) }
				</p>
				{ state.error && (
					<button
						type="button"
						onClick={ () => setRetry( ( value ) => value + 1 ) }
					>
						{ __(
							'Try again',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</button>
				) }
				<button type="button" onClick={ onClose }>
					{ __( 'Close', 'bulk-catalogue-manager-for-woocommerce' ) }
				</button>
			</div>
		);
	}
	const registry = state.registry;
	const action = registry.actions.find(
		( item ) => item.id === entry.actionId && item.available
	);
	const settings = readSettings();
	return (
		<BulkChangePanel
			key={ key }
			entry={ entry }
			selection={ { ...entry.selection, count: registry.count } }
			registry={ registry }
			settings={ settings }
			onClose={ onClose }
			initialOperations={
				action
					? [
							chooseAction(
								registry,
								settings,
								emptyOperation( 1 ),
								action.id,
								action.group
							),
						]
					: null
			}
			onFinished={ ( report ) => {
				// A host may subtract only this submitted selection after full durable completion.
				if (
					report.status === 'complete' &&
					! report.failed_items &&
					report.external_finalisation?.pending === 0 &&
					report.external_finalisation?.failed === 0 &&
					report.external_finalisation?.unresolved === 0
				) {
					onCompleted?.( report, entry );
				}
			} }
		/>
	);
}
export { ProviderActionPanel };
