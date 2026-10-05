/** Native single-row actions and the existing bulk-change entry. */
import { __, sprintf } from '@wordpress/i18n';

import { ROW } from './defaults';
import { chooseAction, emptyOperation } from '../bulk/operations';

/**
 * Add-on actions offered on one row (contract version 2 row entry,
 * docs/EXTENSIONS-v2.md §6): each `/fields` action marked `row`, on a
 * product row, or on a variation row only for a per-variation value. One
 * that cannot be used now is listed disabled with its reason.
 *
 * @param {Object}   registry The `/fields` payload.
 * @param {Function} onChoose Receives ( action, row ).
 * @return {Array<Object>} DataViews actions.
 */
export function providerRowActions( registry, onChoose ) {
	return ( registry?.actions || [] )
		.filter(
			( action ) =>
				action.provider &&
				action.row === true &&
				( action.target || 'product' ) === 'product'
		)
		.map( ( action ) => {
			const usable = action.available !== false;
			return {
				id: `provider-${ action.id }`,
				label: usable
					? sprintf(
							/* translators: %s: an add-on action, such as "Add recipients". */
							__(
								'%s…',
								'bulk-catalogue-manager-for-woocommerce'
							),
							action.label
						)
					: sprintf(
							/* translators: 1: an add-on action, such as "Add recipients"; 2: why it cannot be used now. */
							__(
								'%1$s (%2$s)',
								'bulk-catalogue-manager-for-woocommerce'
							),
							action.label,
							action.unavailableReason || ''
						),
				isPrimary: false,
				supportsBulk: false,
				disabled: ! usable,
				isEligible: ( item ) =>
					! item?.[ ROW.parentId ] ||
					action.variations === 'per_variation',
				callback: ( items ) => {
					if ( usable && items.length === 1 ) {
						onChoose( action, items[ 0 ] );
					}
				},
			};
		} );
}

/**
 * The change panel's opening for an add-on action on one row: that row's
 * id only (never the retained selection), the action chosen at its starting
 * arguments, and the row's object for its lookups.
 *
 * @param {Object} registry The `/fields` payload.
 * @param {Object} settings Settings.
 * @param {Object} action   The `/fields` action.
 * @param {Object} row      The row.
 * @return {Object} `{ selection, initialOperations, object }` for the panel.
 */
export function providerRowChange( registry, settings, action, row ) {
	const id = Number( row.id );
	return {
		selection: { kind: 'ids', ids: [ id ] },
		initialOperations: [
			chooseAction(
				registry,
				settings,
				emptyOperation( 1 ),
				action.id,
				action.group
			),
		],
		object: `${ row?.[ ROW.parentId ] ? 'variation' : 'product' }:${ id }`,
	};
}

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
 * @param {Object}   handlers                 Handlers.
 * @param {Function} handlers.onEditDetails   Open the detail panel for a row.
 * @param {Function} handlers.navigate        Go to a URL.
 * @param {Function} handlers.onMakeChange    Open the bulk change panel for the selected rows' ids.
 * @param {Function} handlers.openNewTab      Open the live page in an isolated tab.
 * @param {Function} handlers.confirmNative   Show the native sold-product warning.
 * @param {Array}    handlers.providerActions Add-on row entries (providerRowActions()).
 * @return {Array<Object>} Actions.
 */
export function rowActions( {
	onEditDetails = () => {},
	navigate = ( url ) => window.location.assign( url ),
	onMakeChange = null,
	openNewTab,
	confirmNative,
	providerActions = [],
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
		// Add-on row entries (contract version 2), after the native ones.
		...providerActions,
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
