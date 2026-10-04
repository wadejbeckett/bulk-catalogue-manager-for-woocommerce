/** Native single-row actions and the existing bulk-change entry. */
import { __ } from '@wordpress/i18n';

import { ROW } from './defaults';

/**
 * Native destinations come only from the current actor's uncached row response.
 * @param {Object}   handlers               Navigation boundaries.
 * @param {Function} handlers.navigate      Same-tab native navigation.
 * @param {Function} handlers.openNewTab    Isolated live-product tab.
 * @param {Function} handlers.confirmNative Native sold-product warning.
 */
export function nativeRowActions( {
	navigate = ( url ) => window.location.assign( url ),
	openNewTab = ( url ) => window.open( url, '_blank', 'noopener,noreferrer' ),
	// Native WooCommerce's sold-product warning is a blocking browser confirmation.
	confirmNative = ( message ) => window.confirm( message ), // eslint-disable-line no-alert
} = {} ) {
	const followed = new WeakSet();
	return [
		[
			'editor',
			'open-in-editor',
			__(
				'Open product editor',
				'bulk-catalogue-manager-for-woocommerce'
			),
		],
		[
			'view',
			'view-product',
			__(
				'View product (new tab)',
				'bulk-catalogue-manager-for-woocommerce'
			),
		],
		[
			'duplicate',
			'duplicate-product',
			__( 'Duplicate', 'bulk-catalogue-manager-for-woocommerce' ),
		],
		[
			'trash',
			'trash-product',
			__( 'Move to Trash', 'bulk-catalogue-manager-for-woocommerce' ),
		],
	].map( ( [ kind, id, label ] ) => ( {
		id,
		label,
		isPrimary: false,
		supportsBulk: false,
		isEligible: ( item ) => !! item?.[ ROW.nativeActions ]?.[ kind ]?.url,
		callback: ( items ) => {
			if ( items.length !== 1 ) {
				return;
			}
			const action = items[ 0 ]?.[ ROW.nativeActions ]?.[ kind ];
			if ( ! action?.url || followed.has( action ) ) {
				return;
			}
			if ( kind === 'view' ) {
				openNewTab( action.url );
				return;
			}
			const opener = document.activeElement; // eslint-disable-line @wordpress/no-global-active-element -- DataViews callbacks provide rows only, not a DOM ref; preserve the current menu focus on native confirmation cancellation.
			if (
				kind === 'trash' &&
				action.confirm &&
				! confirmNative( action.confirm )
			) {
				opener?.focus();
				return;
			}
			// Never automatically replay native mutations. A fresh row read issues new objects.
			followed.add( action );
			navigate( action.url );
		},
	} ) );
}

/**
 * DataViews actions for a row.
 *
 * @param {Object}   handlers               Handlers.
 * @param {Function} handlers.onEditDetails Open the detail panel for a row.
 * @param {Function} handlers.navigate      Go to a URL.
 * @param {Function} handlers.onMakeChange  Open the bulk change panel for the selected rows' ids.
 * @param {Function} handlers.openNewTab    Open the live page in an isolated tab.
 * @param {Function} handlers.confirmNative Show the native sold-product warning.
 * @return {Array<Object>} Actions.
 */
export function rowActions( {
	onEditDetails = () => {},
	navigate = ( url ) => window.location.assign( url ),
	onMakeChange = null,
	openNewTab,
	confirmNative,
} = {} ) {
	const bulk = onMakeChange
		? [
				{
					id: 'make-change',
					label: __(
						'Make a change to these',
						'bulk-catalogue-manager-for-woocommerce'
					),
					isPrimary: false,
					supportsBulk: true,
					callback: ( items ) =>
						onMakeChange( items.map( ( item ) => item.id ) ),
				},
			]
		: [];
	return [
		...bulk,
		{
			id: 'edit-details',
			label: __(
				'Edit details',
				'bulk-catalogue-manager-for-woocommerce'
			),
			isPrimary: false,
			supportsBulk: false,
			isEligible: ( item ) =>
				item?.[ ROW.nativeActions ]?.details !== false,
			callback: ( items ) => {
				if (
					items.length === 1 &&
					items[ 0 ]?.[ ROW.nativeActions ]?.details !== false
				) {
					onEditDetails( items[ 0 ] );
				}
			},
		},
		...nativeRowActions( { navigate, openNewTab, confirmNative } ),
		// Qualified provider row entries belong here only after their separate runtime gate.
		{
			id: 'native-actions-unavailable',
			label: ( items ) =>
				items[ 0 ]?.[ ROW.nativeActions ]?.unavailable || '',
			isPrimary: false,
			supportsBulk: false,
			disabled: true,
			isEligible: ( item ) => !! item?.[ ROW.nativeActions ]?.unavailable,
			callback: () => {},
		},
	];
}
