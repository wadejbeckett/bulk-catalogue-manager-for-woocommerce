/**
 * The built-in views shown before any saved one (the prototype's chips):
 * All products, On sale, Hidden from site and Low stock. They are presets
 * defined here, not stored, and each is built from what the field registry
 * says: a preset whose field or filter operator the registry does not offer
 * is left out rather than sent as a filter the server would refuse. Field
 * keys come from the presentation preset (../defaults.js), never from here.
 */
import { __ } from '@wordpress/i18n';

import { COMPANIONS, HIDING_CHOICES } from '../defaults';
import { descriptorsOf } from '../fields';

/**
 * WooCommerce's default low stock threshold, used by the Low stock preset:
 * in stock with this many or fewer.
 */
export const LOW_STOCK_AMOUNT = 2;

/** The id prefix of a built-in, which a stored view id (hex) never has. */
export const BUILT_IN = 'builtin:';

/**
 * The operators a descriptor's filter offers.
 *
 * @param {Object} field Descriptor.
 * @return {Array<string>} Operators.
 */
function operators( field ) {
	return field?.dataviews?.filterBy?.operators || [];
}

/**
 * The built-in views the registry supports, in chip order. Each carries only
 * the filters it sets: applying one keeps the owner's columns, layout and
 * page size, and clears the search and the category.
 *
 * @param {Object|Array} registry GET /fields answer.
 * @return {Array<{id: string, name: string, filters: Array<Object>}>} Presets.
 */
export function builtInViews( registry ) {
	const descriptors = descriptorsOf( registry );
	const byKey = new Map(
		descriptors.map( ( field ) => [ field.key, field ] )
	);
	const out = [
		{
			id: `${ BUILT_IN }all`,
			name: __(
				'All products',
				'bulk-catalogue-manager-for-woocommerce'
			),
			filters: [],
		},
	];

	// On sale: the sale price is the money field WooCommerce can copy from
	// the regular price, filtered above zero once the registry offers that.
	const sale = descriptors.find(
		( field ) =>
			field.type === 'money' &&
			( field.editKinds || [] ).includes( 'copy_from_regular' )
	);
	const saleOperator = [ 'greaterThan', 'greaterThanOrEqual' ].find( ( op ) =>
		operators( sale ).includes( op )
	);
	if ( sale && saleOperator ) {
		out.push( {
			id: `${ BUILT_IN }on-sale`,
			name: __( 'On sale', 'bulk-catalogue-manager-for-woocommerce' ),
			filters: [
				{
					field: sale.key,
					operator: saleOperator,
					value: saleOperator === 'greaterThan' ? 0 : 0.01,
				},
			],
		} );
	}

	// Hidden from site: the first hiding choice whose field filters by isAny.
	const hiding = Object.keys( HIDING_CHOICES ).find( ( key ) =>
		operators( byKey.get( key ) ).includes( 'isAny' )
	);
	if ( hiding ) {
		out.push( {
			id: `${ BUILT_IN }hidden`,
			name: __(
				'Hidden from site',
				'bulk-catalogue-manager-for-woocommerce'
			),
			filters: [
				{
					field: hiding,
					operator: 'isAny',
					value: HIDING_CHOICES[ hiding ],
				},
			],
		} );
	}

	// Low stock: in stock, with the stock field's companion quantity at or
	// below the threshold.
	const stock = Object.keys( COMPANIONS ).find( ( key ) => {
		const status = byKey.get( key );
		const quantity = byKey.get( COMPANIONS[ key ] );
		return (
			operators( status ).includes( 'isAny' ) &&
			( status.choices || status.dataviews?.elements || [] ).some(
				( choice ) => choice.value === 'instock'
			) &&
			operators( quantity ).includes( 'lessThanOrEqual' )
		);
	} );
	if ( stock ) {
		out.push( {
			id: `${ BUILT_IN }low-stock`,
			name: __( 'Low stock', 'bulk-catalogue-manager-for-woocommerce' ),
			filters: [
				{ field: stock, operator: 'isAny', value: [ 'instock' ] },
				{
					field: COMPANIONS[ stock ],
					operator: 'lessThanOrEqual',
					value: LOW_STOCK_AMOUNT,
				},
			],
		} );
	}
	return out;
}

/**
 * The state a built-in opens: the current view with the preset's filters,
 * no search, page 1, and all categories.
 *
 * @param {Object} preset A builtInViews() entry.
 * @param {Object} view   Current view.
 * @return {{view: Object, category: null}} State.
 */
export function applyBuiltIn( preset, view ) {
	return {
		view: { ...view, filters: preset.filters, search: '', page: 1 },
		category: null,
	};
}

/**
 * Whether the screen shows a built-in: its filters, no search, no category.
 *
 * @param {Object}      preset   A builtInViews() entry.
 * @param {Object}      view     Current view.
 * @param {Object|null} category Current category.
 * @return {boolean} True when it matches.
 */
export function showsBuiltIn( preset, view, category ) {
	return (
		! category &&
		! ( view.search || '' ).trim() &&
		JSON.stringify( view.filters || [] ) ===
			JSON.stringify( preset.filters )
	);
}
