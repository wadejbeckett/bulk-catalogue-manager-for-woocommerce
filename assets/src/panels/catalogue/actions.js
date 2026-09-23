/**
 * The row menu (SPEC §4.1 Columns). Edit details opens the detail panel once
 * T47 lands; View on site and History for this product join with the row's
 * permalink and the History panel's product filter.
 */
import { __ } from '@wordpress/i18n';

import { editUrl } from './cells';

/**
 * DataViews actions for a row.
 *
 * @param {Object}   handlers               Handlers.
 * @param {Function} handlers.onEditDetails Open the detail panel for a row.
 * @param {Function} handlers.navigate      Go to a URL.
 * @param {Function} handlers.onMakeChange  Open the bulk change panel for the selected rows' ids.
 * @return {Array<Object>} Actions.
 */
export function rowActions( {
	onEditDetails = () => {},
	navigate = ( url ) => window.location.assign( url ),
	onMakeChange = null,
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
			callback: ( [ item ] ) => onEditDetails( item ),
		},
		{
			id: 'open-in-editor',
			label: __(
				'Open in product editor',
				'bulk-catalogue-manager-for-woocommerce'
			),
			isPrimary: false,
			callback: ( [ item ] ) => navigate( editUrl( item ) ),
		},
	];
}
