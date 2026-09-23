/**
 * The Audit log tab (T58): its filters' mapping to GET /audit, product
 * search through GET /products?search, fields shown by their registry
 * label and never a storage key, product names from the route, its empty
 * state, and the export — a stopgap in the browser until GET /export takes
 * change rows — following the filter across every page with the writer's
 * rules (byte-order mark, every field quoted, formulas neutralised).
 */
import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';

jest.mock( '@wordpress/api-fetch', () => {
	const apiFetch = jest.fn();
	apiFetch.use = jest.fn();
	apiFetch.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: apiFetch };
} );

const apiFetch = require( '@wordpress/api-fetch' ).default;
const { configureApi } = require( '../../assets/src/api' );
const History = require( '../../assets/src/panels/history/index.js' ).default;
const {
	toAuditQuery,
	defaultAuditView,
	fieldElements,
} = require( '../../assets/src/panels/history/audit.js' );
const {
	csvField,
	toCsv,
	readAll,
	MAX_ROWS,
} = require( '../../assets/src/panels/history/csv.js' );

const ROOT = 'https://example.test/wp-json/bcm/v1/';
const NOW = Date.UTC( 2026, 8, 23, 12, 0, 0 );

const FIELDS = [
	{ key: 'regular_price', label: 'Regular price', type: 'money' },
	{
		key: 'stock_status',
		label: 'Stock status',
		type: 'enum',
		choices: [
			{ value: 'instock', label: 'In stock' },
			{ value: 'outofstock', label: 'Out of stock' },
		],
	},
	{ key: 'id', label: 'ID', type: 'id', readOnly: true },
];

const ROW = {
	change_id: 7,
	batch_id: 418,
	user_id: 2,
	object_id: 55,
	object_type: 'variation',
	name: 'Classic Cotton T-Shirt — S / Navy',
	field: 'stock_status',
	field_label: 'stock_status',
	old_value: 'instock',
	new_value: 'outofstock',
	result: 'applied',
	message: '',
	detail: '',
	applied_gmt: '2026-09-23 10:00:01',
};

let calls;

/**
 * Answer apiFetch by path.
 *
 * @param {Object} table Answers by `METHOD path` (a value or fn( query )).
 */
function serve( table ) {
	calls = [];
	apiFetch.mockImplementation( ( { url, method = 'GET' } ) => {
		const parsed = new URL( url );
		const route = parsed.pathname.replace( /^\/wp-json\//, '' );
		const query = {};
		parsed.searchParams.forEach( ( value, key ) => {
			const list = /^(.+)\[\d*\]$/.exec( key );
			if ( list ) {
				query[ list[ 1 ] ] = [ ...( query[ list[ 1 ] ] || [] ), value ];
			} else {
				query[ key ] = value;
			}
		} );
		calls.push( { method, route, query } );
		const handler = table[ `${ method } ${ route }` ];
		if ( handler === undefined ) {
			return Promise.resolve( [] );
		}
		return Promise.resolve(
			typeof handler === 'function' ? handler( query ) : handler
		);
	} );
}

const page = ( items, total = items.length, totalPages = 1 ) => ( {
	items,
	pagination: { page: 1, perPage: 25, total, totalPages },
} );

async function openAudit() {
	render(
		<History
			settings={ {
				root: ROOT,
				user: { id: 1, name: 'Mia Fourie' },
				settings: {},
			} }
		/>
	);
	await act( async () =>
		fireEvent.click( screen.getByRole( 'tab', { name: 'Audit log' } ) )
	);
}

let errors;

beforeAll( () => {
	global.ResizeObserver = class {
		observe() {}
		unobserve() {}
		disconnect() {}
	};
	// jsdom has no layout; the suggestion list scrolls its choice into view.
	window.HTMLElement.prototype.scrollIntoView = () => {};
	configureApi( { root: ROOT } );
} );

beforeEach( () => {
	errors = jest.spyOn( console, 'error' ).mockImplementation( () => {} );
} );

afterEach( () => {
	errors.mockRestore();
} );

describe( 'audit query', () => {
	it( 'asks for the newest changes, a page at a time, with no filter', () => {
		expect( toAuditQuery( defaultAuditView(), null, NOW ) ).toEqual( {
			page: 1,
			per_page: 25,
		} );
	} );

	it( 'maps product, field, user and date range to the route arguments', () => {
		const view = {
			...defaultAuditView(),
			page: 2,
			filters: [
				{ field: 'field', operator: 'is', value: 'regular_price' },
				{ field: 'user_id', operator: 'is', value: 2 },
				{
					field: 'applied_gmt',
					operator: 'inThePast',
					value: { value: 7, unit: 'days' },
				},
			],
		};
		expect( toAuditQuery( view, 55, NOW ) ).toEqual( {
			page: 2,
			per_page: 25,
			product: 55,
			field: 'regular_price',
			user: 2,
			after: '2026-09-16T12:00:00Z',
		} );
	} );

	it( 'offers fields by their registry label, editable ones only', () => {
		expect( fieldElements( FIELDS ) ).toEqual( [
			{ value: 'regular_price', label: 'Regular price' },
			{ value: 'stock_status', label: 'Stock status' },
		] );
	} );
} );

describe( 'audit tab', () => {
	it( 'lists changes with product names from the route and field labels, never keys', async () => {
		serve( {
			'GET bcm/v1/fields': { fields: FIELDS, actions: [] },
			'GET bcm/v1/batches': page( [] ),
			'GET bcm/v1/audit': page( [ ROW ] ),
			'GET wp/v2/users': [ { id: 2, name: 'Sam Naidoo' } ],
		} );
		await openAudit();
		await waitFor( () =>
			expect(
				screen.getByText( 'Classic Cotton T-Shirt — S / Navy' )
			).toBeTruthy()
		);
		const table = screen.getByRole( 'table' );
		expect( within( table ).getByText( 'Stock status' ) ).toBeTruthy();
		expect( within( table ).queryByText( 'stock_status' ) ).toBeNull();
		expect( within( table ).getByText( 'In stock' ) ).toBeTruthy();
		expect( within( table ).getByText( 'Out of stock' ) ).toBeTruthy();
		expect( within( table ).getByText( '#418' ) ).toBeTruthy();
		await waitFor( () =>
			expect( within( table ).getByText( 'Sam Naidoo' ) ).toBeTruthy()
		);
		const audit = calls.find( ( c ) => c.route === 'bcm/v1/audit' );
		expect( audit.query ).toEqual( { page: '1', per_page: '25' } );
	} );

	it( 'finds a product by name and filters the log by it', async () => {
		serve( {
			'GET bcm/v1/fields': { fields: FIELDS, actions: [] },
			'GET bcm/v1/batches': page( [] ),
			'GET bcm/v1/audit': ( query ) =>
				page( query.product === '55' ? [ ROW ] : [] ),
			'GET bcm/v1/products': page( [ { id: 55, name: 'Blue Widget' } ] ),
		} );
		jest.useFakeTimers();
		try {
			await openAudit();
			const input = screen.getByRole( 'combobox', { name: 'Product' } );
			await act( async () => {
				fireEvent.focus( input );
				fireEvent.change( input, { target: { value: 'blue' } } );
			} );
			await act( async () => {
				jest.advanceTimersByTime( 400 );
			} );
			const search = calls.find( ( c ) => c.route === 'bcm/v1/products' );
			expect( search.query.search ).toBe( 'blue' );
			expect( search.query.fields ).toEqual( [ 'id', 'name' ] );
			const option = await screen.findByRole( 'option', {
				name: 'Blue Widget',
			} );
			await act( async () => fireEvent.click( option ) );
		} finally {
			jest.useRealTimers();
		}
		await waitFor( () =>
			expect(
				calls.some(
					( c ) =>
						c.route === 'bcm/v1/audit' && c.query.product === '55'
				)
			).toBe( true )
		);
	} );

	it( 'says so when nothing matches', async () => {
		serve( {
			'GET bcm/v1/fields': { fields: FIELDS, actions: [] },
			'GET bcm/v1/batches': page( [] ),
			'GET bcm/v1/audit': page( [] ),
		} );
		await openAudit();
		await waitFor( () =>
			expect(
				screen.getByText( 'No field changes match these filters.' )
			).toBeTruthy()
		);
	} );
} );

describe( 'history export (stopgap)', () => {
	it( 'quotes every field and neutralises a formula', () => {
		expect( csvField( '=cmd|calc' ) ).toBe( '"\'=cmd|calc"' );
		expect( csvField( '+1' ) ).toBe( '"\'+1"' );
		expect( csvField( '-2' ) ).toBe( '"\'-2"' );
		expect( csvField( '@SUM' ) ).toBe( '"\'@SUM"' );
		expect( csvField( 'Say "hi"' ) ).toBe( '"Say ""hi"""' );
		expect( csvField( null ) ).toBe( '""' );
	} );

	it( 'writes a byte-order mark, the header and the rows in column order', () => {
		const csv = toCsv(
			[
				{ label: 'Product', value: ( row ) => row.name },
				{ label: 'After', value: ( row ) => row.after },
			],
			[ { name: '=Bad', after: '9.50' } ],
			';'
		);
		expect( csv ).toBe( '﻿"Product";"After"\r\n"\'=Bad";"9.50"\r\n' );
	} );

	it( 'follows the filter across every page, not only the one on screen', async () => {
		serve( {
			'GET bcm/v1/audit': ( query ) =>
				page( [ { ...ROW, change_id: Number( query.page ) } ], 3, 3 ),
		} );
		const { rows, truncated } = await readAll( 'audit', { field: 'sku' } );
		expect( rows.map( ( row ) => row.change_id ) ).toEqual( [ 1, 2, 3 ] );
		expect( truncated ).toBe( false );
		expect( calls.map( ( c ) => c.query ) ).toEqual( [
			{ field: 'sku', page: '1', per_page: '100' },
			{ field: 'sku', page: '2', per_page: '100' },
			{ field: 'sku', page: '3', per_page: '100' },
		] );
		expect( MAX_ROWS ).toBe( 10000 );
	} );
} );
