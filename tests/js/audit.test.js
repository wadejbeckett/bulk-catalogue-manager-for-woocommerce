/**
 * The Audit log tab (T58): its filters' mapping to GET /audit, product
 * search through GET /products?search, fields shown by their registry
 * label and never a storage key, product and user names from the route,
 * its empty state, the export through GET /export?source=audit with the
 * same filters, and the `product` URL argument the product drawer links
 * with.
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
	user_name: 'Sam Naidoo',
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
			'GET bcm/v1/batches/users': { items: [] },
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

describe( 'audit export and product link', () => {
	afterEach( () => {
		document
			.querySelectorAll( 'iframe.bcm-export__frame' )
			.forEach( ( frame ) => frame.remove() );
		window.history.replaceState( null, '', '/' );
	} );

	it( 'downloads GET /export?source=audit with the filters on screen', async () => {
		serve( {
			'GET bcm/v1/fields': { fields: FIELDS, actions: [] },
			'GET bcm/v1/batches': page( [] ),
			'GET bcm/v1/audit': page( [ ROW ] ),
		} );
		window.history.replaceState(
			null,
			'',
			'/wp-admin/admin.php?page=bcm&panel=history&product=55'
		);
		await openAudit();
		const button = screen.getByRole( 'button', {
			name: 'Export log (CSV)',
		} );
		await act( async () => fireEvent.click( button ) );
		const frame = document.querySelector( 'iframe.bcm-export__frame' );
		expect( frame ).not.toBeNull();
		const url = new URL( frame.src );
		expect( url.pathname ).toBe( '/wp-json/bcm/v1/export' );
		expect( url.searchParams.get( 'source' ) ).toBe( 'audit' );
		expect( url.searchParams.get( 'product' ) ).toBe( '55' );
		expect( url.searchParams.get( 'page' ) ).toBeNull();
		expect( url.searchParams.get( 'per_page' ) ).toBeNull();
		const columns = [];
		url.searchParams.forEach( ( value, key ) => {
			if ( key.startsWith( 'columns[' ) ) {
				columns.push( value );
			}
		} );
		expect( columns ).toContain( 'user_name' );
		expect( columns[ 0 ] ).toBe( 'applied_gmt' );
		expect( button.getAttribute( 'aria-disabled' ) ).toBe( 'true' );
	} );

	it( 'opens on the product the URL names, and can show every product again', async () => {
		serve( {
			'GET bcm/v1/fields': { fields: FIELDS, actions: [] },
			'GET bcm/v1/batches': page( [] ),
			'GET bcm/v1/audit': page( [ ROW ] ),
		} );
		window.history.replaceState(
			null,
			'',
			'/wp-admin/admin.php?page=bcm&panel=history&product=55'
		);
		render(
			<History
				settings={ {
					root: ROOT,
					user: { id: 1, name: 'Mia Fourie' },
					settings: {},
				} }
			/>
		);
		await waitFor( () =>
			expect(
				document.querySelector( '.bcm-audit__linked' )?.textContent
			).toContain( 'Showing changes to product #55.' )
		);
		expect(
			screen
				.getByRole( 'tab', { name: 'Audit log' } )
				.getAttribute( 'aria-selected' )
		).toBe( 'true' );
		await waitFor( () =>
			expect(
				calls.some(
					( c ) =>
						c.route === 'bcm/v1/audit' && c.query.product === '55'
				)
			).toBe( true )
		);
		await act( async () =>
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Show every product' } )
			)
		);
		expect( document.querySelector( '.bcm-audit__linked' ) ).toBeNull();
		expect( window.location.search ).toBe( '?page=bcm&panel=history' );
		const last = calls.filter( ( c ) => c.route === 'bcm/v1/audit' ).pop();
		expect( last.query.product ).toBeUndefined();
	} );
} );
