/**
 * Shared fixtures for the catalogue tests: the real /fields payload (the
 * snapshot PHP pins, tests/Unit/Fields/fields.snapshot.json), a page of rows
 * and a routed apiFetch mock. Not a test file itself.
 */
const { getQueryArgs } = require( '@wordpress/url' );

const REGISTRY = require( '../Unit/Fields/fields.snapshot.json' );

const ROWS = [
	{
		id: 11,
		parent_id: 0,
		name: 'Classic Cotton T-Shirt',
		thumbnail: 5,
		thumbnail_url: 'https://example.test/tee.jpg',
		sku: 'TSH-100',
		status: 'publish',
		regular_price: '12.50',
		sale_price: '',
		stock_status: 'instock',
		stock_quantity: 659,
		category_ids: [ 3 ],
	},
	{
		id: 12,
		parent_id: 0,
		name: 'Ladies Golf Shirt',
		thumbnail: 0,
		thumbnail_url: 'https://example.test/placeholder.png',
		sku: 'GLF-120',
		status: 'draft',
		regular_price: '26.00',
		sale_price: '22.10',
		stock_status: 'outofstock',
		stock_quantity: null,
		category_ids: [ 4 ],
	},
];

const CATEGORIES = [
	{
		id: 2,
		name: 'Clothing',
		children: [
			{ id: 3, name: 'T-Shirts', children: [] },
			{ id: 4, name: 'Golf Shirts', children: [] },
		],
	},
];

/**
 * A GET /products answer.
 *
 * @param {Array<Object>} items   Rows.
 * @param {number}        total   Total matching.
 * @param {number}        perPage Page size.
 * @return {Object} Answer.
 */
function page( items = ROWS, total = 60, perPage = 25 ) {
	return {
		items,
		pagination: {
			page: 1,
			perPage,
			total,
			totalPages: Math.ceil( total / perPage ),
		},
	};
}

/**
 * Route an apiFetch mock by path. Each handler receives the parsed query and
 * the request's options (its `method` and `data`), and returns a value or a
 * promise; a missing route never settles.
 *
 * @param {Function} apiFetch jest.fn from the module mock.
 * @param {Object}   routes   path => handler( query, options ).
 */
function route( apiFetch, routes ) {
	apiFetch.mockImplementation( ( options ) => {
		const { url } = options;
		const path = url.split( '?' )[ 0 ];
		const handler = routes[ path ];
		if ( ! handler ) {
			return new Promise( () => {} );
		}
		try {
			return Promise.resolve( handler( getQueryArgs( url ), options ) );
		} catch ( error ) {
			return Promise.reject( error );
		}
	} );
}

/**
 * The parsed query of every request made to a path, in order.
 *
 * @param {Function} apiFetch jest.fn.
 * @param {string}   path     Route path.
 * @return {Array<Object>} Queries.
 */
function calls( apiFetch, path ) {
	return apiFetch.mock.calls
		.map( ( [ { url } ] ) => url )
		.filter( ( url ) => url.split( '?' )[ 0 ] === path )
		.map( ( url ) => getQueryArgs( url ) );
}

module.exports = { REGISTRY, ROWS, CATEGORIES, page, route, calls };
