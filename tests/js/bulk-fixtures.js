/**
 * Shared fixtures for the bulk panel's tests (T48–T50): the real `/fields`
 * payload the PHP snapshot pins (tests/Unit/Fields/fields.snapshot.json), a
 * category tree, a /preview answer covering every block, and a routed
 * apiFetch mock.
 */
// The admin page carries the user's language on <html lang>; the tests read English.
document.documentElement.lang = 'en-US';

const REGISTRY = require( '../Unit/Fields/fields.snapshot.json' );

/**
 * A matcher result.
 *
 * @param {boolean} pass    Whether it passed.
 * @param {string}  message What was expected.
 * @return {Object} Result.
 */
const result = ( pass, message ) => ( { pass, message: () => message } );

// The few DOM matchers the tests use (jest-dom is not a dependency).
global.expect.extend( {
	toHaveAttribute( element, name, value ) {
		const actual = element.getAttribute( name );
		return result(
			value === undefined ? actual !== null : actual === String( value ),
			`expected [${ name }] to be ${ value }, got ${ actual }`
		);
	},
	toBeVisible( element ) {
		return result(
			!! element && element.ownerDocument.body.contains( element ),
			'expected the element to be in the document'
		);
	},
	toBeDisabled( element ) {
		return result( element.disabled === true, 'expected it disabled' );
	},
	toBeEnabled( element ) {
		return result( element.disabled !== true, 'expected it enabled' );
	},
	toBeChecked( element ) {
		return result( element.checked === true, 'expected it checked' );
	},
	toHaveValue( element, value ) {
		return result(
			element.value === value,
			`expected value ${ value }, got ${ element.value }`
		);
	},
	toHaveTextContent( element, text ) {
		return result(
			element.textContent.includes( text ),
			`expected "${ element.textContent }" to contain "${ text }"`
		);
	},
} );

const HASH = 'a'.repeat( 64 );
const HASH_2 = 'b'.repeat( 64 );

const SETTINGS = {
	confirm_threshold: 200,
	variations_status: true,
	variations_visibility: true,
	variations_price: false,
	variations_stock: false,
};

const CATEGORIES = [
	{
		id: 1,
		name: 'Clothing',
		children: [
			{ id: 2, name: 'Shirts', children: [] },
			{ id: 3, name: 'Hats', children: [] },
		],
	},
	{ id: 4, name: 'Sale', children: [] },
];

const IDS = Array.from( { length: 24 }, ( _, index ) => 101 + index );

const REASON =
	'A variable product takes its price from its variations. Change the variations instead.';

/**
 * A /preview answer with every block filled.
 *
 * @param {Object} overrides Top-level overrides.
 * @return {Object} Answer.
 */
function previewResponse( overrides = {} ) {
	return {
		totals: {
			matched: 24,
			computed: 24,
			estimated: false,
			would_change: 20,
			already: 2,
			would_fail: { [ REASON ]: 2 },
			rows: { changed: 20, already: 2, skipped: 2, failed: 0 },
		},
		sample: [
			{
				object_id: 101,
				object_type: 'product',
				name: 'Blue Tee',
				field: 'regular_price',
				current: '100.00',
				new: '110.00',
				state: 'changed',
				reason: '',
				code: '',
				replaces: false,
			},
			{
				object_id: 131,
				object_type: 'variation',
				name: 'Blue Tee - Large',
				field: 'regular_price',
				current: '50.00',
				new: '55.00',
				state: 'changed',
				reason: '',
				code: '',
				replaces: false,
			},
			{
				object_id: 102,
				object_type: 'product',
				name: 'Red Cap',
				field: 'regular_price',
				current: '20.00',
				new: '20.00',
				state: 'already',
				reason: '',
				code: '',
				replaces: false,
			},
			{
				object_id: 103,
				object_type: 'product',
				name: 'Hoodie',
				field: 'regular_price',
				current: '',
				new: '',
				state: 'skipped',
				reason: REASON,
				code: 'bcm_not_available',
				replaces: false,
			},
			{
				object_id: 104,
				object_type: 'product',
				name: 'Green Scarf',
				field: 'category_ids',
				current: [ 1 ],
				new: [ 2, 3 ],
				state: 'changed',
				reason: '',
				code: '',
				replaces: true,
			},
		],
		warnings: {
			sale_above_regular: 1,
			price_not_positive: 2,
			stock_negative: 3,
			sku_duplicate: 4,
		},
		unreadable: [ 999 ],
		selection: { kind: 'ids', count: 24 },
		hash: HASH,
		...overrides,
	};
}

/**
 * Route apiFetch calls: `routes` maps "METHOD url" (or a function of the
 * options) to an answer, a function returning one, or an Error to reject.
 *
 * @param {Function} apiFetch The mocked apiFetch.
 * @param {Object}   routes   Answers by "METHOD url".
 */
function routeApi( apiFetch, routes ) {
	apiFetch.mockImplementation( ( options ) => {
		const key = ( options.method || 'GET' ) + ' ' + options.url;
		const answer = routes[ key ];
		if ( answer === undefined ) {
			return new Promise( () => {} );
		}
		const value = typeof answer === 'function' ? answer( options ) : answer;
		return value instanceof Error || value?.reject
			? Promise.reject( value.reject || value )
			: Promise.resolve( value );
	} );
}

/**
 * The calls made to one route.
 *
 * @param {Function} apiFetch The mocked apiFetch.
 * @param {string}   key      "METHOD url".
 * @return {Array<Object>} Their options.
 */
function callsTo( apiFetch, key ) {
	return apiFetch.mock.calls
		.map( ( [ options ] ) => options )
		.filter(
			( options ) =>
				( options.method || 'GET' ) + ' ' + options.url === key
		);
}

/**
 * A REST error as apiFetch rejects with it.
 *
 * @param {string} code    Error code.
 * @param {string} message Sentence.
 * @param {Object} data    Data.
 * @return {Object} `{ reject }` for routeApi.
 */
function restError( code, message, data = {} ) {
	return { reject: { code, message, data } };
}

module.exports = {
	CATEGORIES,
	HASH,
	HASH_2,
	IDS,
	REASON,
	REGISTRY,
	SETTINGS,
	callsTo,
	previewResponse,
	restError,
	routeApi,
};
