/**
 * The History panel (T57): the list's filter-to-query mapping, every §4.6
 * state rendered from the route's own fields, a batch expanding to its
 * changes in place, and the undo flow — preview first, conflicts first
 * with the three-way choice, Revert anyway off by default, apply with
 * uuid, plan_hash and force, and the notice when it finishes.
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
	toBatchQuery,
	defaultBatchView,
	retentionLine,
} = require( '../../assets/src/panels/history/batches.js' );
const {
	describeBatch,
	dateRange,
} = require( '../../assets/src/panels/history/describe.js' );
const {
	confirmSentence,
	newUuid,
} = require( '../../assets/src/panels/history/undo.js' );

const ROOT = 'https://example.test/wp-json/bcm/v1/';

const SETTINGS = {
	root: ROOT,
	nonce: '',
	settings: { history_days: 90, csv_separator: 'comma' },
	user: { id: 1, name: 'Mia Fourie' },
};

const FIELDS = {
	fields: [
		{ key: 'name', label: 'Name', type: 'text', readOnly: false },
		{
			key: 'regular_price',
			label: 'Regular price',
			type: 'money',
			readOnly: false,
			group: 'pricing',
		},
		{
			key: 'sale_price',
			label: 'Sale price',
			type: 'money',
			readOnly: false,
			group: 'pricing',
		},
		{
			key: 'stock_status',
			label: 'Stock status',
			type: 'enum',
			readOnly: false,
			group: 'stock',
			choices: [
				{ value: 'instock', label: 'In stock' },
				{ value: 'outofstock', label: 'Out of stock' },
			],
		},
		{ key: 'sku', label: 'SKU', type: 'text', readOnly: false },
		{ key: 'id', label: 'ID', type: 'id', readOnly: true },
	],
	actions: [
		{ id: 'adjust', label: 'Increase or decrease' },
		{ id: 'set_value', label: 'Set a value' },
	],
	groups: [],
};

const ADJUST = {
	action: 'adjust',
	args: {
		field: 'regular_price',
		direction: 'increase',
		by: 'percent',
		amount: '8',
	},
};

const base = ( id, extra = {} ) => ( {
	batch_id: id,
	status: 'complete',
	source: 'bulk',
	user_id: 1,
	undo_of: null,
	created_gmt: '2026-09-23 12:32:00',
	finished_gmt: '2026-09-23 12:40:00',
	total_items: 120,
	done_items: 120,
	changed_items: 120,
	failed_items: 0,
	pending_items: 0,
	operations: [ ADJUST ],
	actions: [ 'adjust' ],
	stalled: false,
	stalled_message: '',
	selection_difference: '',
	reversibility: { state: 'reversible', conflicts: null, reason: null },
	undo: null,
	...extra,
} );

const BATCHES = [
	base( 1, { variations_included: true } ),
	base( 2, {
		user_id: 2,
		operations: [
			{
				action: 'set_value',
				args: { field: 'stock_status', value: 'instock' },
			},
		],
		reversibility: {
			state: 'partially_reversible',
			conflicts: 3,
			reason: null,
		},
	} ),
	base( 3, {
		status: 'running',
		done_items: 40,
		total_items: 100,
		reversibility: { state: 'in_progress', conflicts: null, reason: null },
	} ),
	base( 4, {
		status: 'partial',
		failed_items: 2,
		reversibility: {
			state: 'completed_with_errors',
			conflicts: null,
			reason: null,
		},
	} ),
	base( 5, {
		user_id: 9,
		user_name: 'Lee Adams',
		reversibility: {
			state: 'not_reversible',
			conflicts: null,
			reason: 'pruned',
		},
	} ),
	base( 6, {
		source: 'undo',
		undo_of: 1,
		operations: [],
		undo: { undo_of: 1, label: 'Undo of #1', sentence: '' },
	} ),
	base( 7, {
		status: 'undone',
		undone_by: { batch_id: 42, user_id: 2, user_name: 'Sam Naidoo' },
		reversibility: {
			state: 'not_reversible',
			conflicts: null,
			reason: 'already_undone',
		},
	} ),
];

const CHANGES = {
	items: [
		{
			change_id: 11,
			object_id: 501,
			object_type: 'product',
			name: 'A5 Hardcover Notebook',
			field: 'regular_price',
			field_label: 'Regular price',
			old_value: '7.31',
			new_value: '7.90',
			result: 'applied',
			message: '',
			detail: '',
			applied_gmt: '2026-09-23 12:32:07',
		},
		{
			change_id: 12,
			object_id: 502,
			object_type: 'product',
			name: 'A6 Notepad',
			field: 'regular_price',
			field_label: 'Regular price',
			old_value: '2.69',
			new_value: '2.90',
			result: 'applied',
			message: '',
			detail: '',
			applied_gmt: '2026-09-23 12:32:20',
		},
	],
	pagination: { page: 1, perPage: 25, total: 2, totalPages: 1 },
};

const FAILED = {
	items: [
		{
			change_id: 21,
			object_id: 601,
			object_type: 'product',
			name: 'Classic Cotton T-Shirt',
			field: 'regular_price',
			field_label: 'Regular price',
			old_value: '11.57',
			new_value: '12.50',
			result: 'failed',
			message:
				'Another user was editing this product. Try again in a moment.',
			detail: 'product 601, field regular_price, result failed',
			applied_gmt: '2026-09-23 12:33:00',
		},
	],
	pagination: { page: 1, perPage: 25, total: 1, totalPages: 1 },
};

let calls;
let routes;

/**
 * Answer apiFetch from a table of `METHOD path` handlers.
 *
 * @param {Object} table Handlers: a value, or fn( query, data ).
 */
function serve( table ) {
	routes = table;
	calls = [];
	apiFetch.mockImplementation( ( { url, method = 'GET', data } ) => {
		const parsed = new URL( url, ROOT );
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
		calls.push( { method, route, query, data } );
		const handler = routes[ `${ method } ${ route }` ];
		if ( handler === undefined ) {
			return new Promise( () => {} );
		}
		try {
			const answer =
				typeof handler === 'function'
					? handler( query, data )
					: handler;
			return answer instanceof Error
				? Promise.reject( answer )
				: Promise.resolve( answer );
		} catch ( error ) {
			return Promise.reject( error );
		}
	} );
}

const DEFAULT_ROUTES = () => ( {
	'GET bcm/v1/fields': FIELDS,
	'GET bcm/v1/batches/users': {
		items: [
			{ id: 1, name: 'Mia Fourie' },
			{ id: 2, name: 'Sam Naidoo' },
		],
	},
	'GET bcm/v1/batches': {
		items: BATCHES,
		pagination: { page: 1, perPage: 25, total: 7, totalPages: 1 },
	},
	'GET bcm/v1/batches/3': base( 3, {
		status: 'running',
		done_items: 40,
		total_items: 100,
		reversibility: { state: 'in_progress', conflicts: null, reason: null },
	} ),
	'GET bcm/v1/batches/4': base( 4, {
		status: 'partial',
		summary: [
			{
				message: 'Another user was editing this product.',
				count: 2,
				sentence: '2 products: Another user was editing this product.',
			},
		],
	} ),
	'GET bcm/v1/batches/1/changes': CHANGES,
	'GET bcm/v1/batches/4/changes': FAILED,
} );

/**
 * The card of one batch, by its title and number.
 *
 * @param {number} id Batch.
 * @return {HTMLElement} The article.
 */
const card = ( id ) =>
	document.getElementById( `bcm-batch-${ id }` ).closest( 'article' );

/**
 * The screen notice's text, or null when there is none.
 *
 * @return {string|null} Text.
 */
const noticeText = () =>
	document.querySelector( '.bcm-history__notice .components-notice__content' )
		?.childNodes[ 0 ]?.textContent ?? null;

async function renderHistory() {
	render( <History settings={ SETTINGS } /> );
	await waitFor( () =>
		expect( document.getElementById( 'bcm-batch-1' ) ).not.toBeNull()
	);
}

beforeAll( () => {
	global.ResizeObserver = class {
		observe() {}
		unobserve() {}
		disconnect() {}
	};
	configureApi( SETTINGS );
} );

let errors;

beforeEach( () => {
	serve( DEFAULT_ROUTES() );
	// jsdom cannot parse some of the bundled components' CSS; nothing else may complain.
	errors = jest.spyOn( console, 'error' ).mockImplementation( () => {} );
} );

afterEach( () => {
	const other = errors.mock.calls.filter(
		( [ first ] ) =>
			! String( first?.message ?? first ).includes(
				'Could not parse CSS stylesheet'
			)
	);
	errors.mockRestore();
	if ( other.length ) {
		throw new Error(
			'Unexpected console.error: ' + other.map( String ).join( '\n' )
		);
	}
} );

describe( 'history query', () => {
	const NOW = Date.UTC( 2026, 8, 23, 12, 0, 0 );

	it( 'opens on the last 30 days, first page', () => {
		expect( toBatchQuery( defaultBatchView(), NOW ) ).toEqual( {
			page: 1,
			per_page: 25,
			after: '2026-08-24T12:00:00Z',
		} );
	} );

	it( 'maps user, action, status and page to the route arguments', () => {
		const view = {
			...defaultBatchView(),
			page: 3,
			perPage: 50,
			filters: [
				{ field: 'user_id', operator: 'is', value: 2 },
				{ field: 'action', operator: 'is', value: 'adjust' },
				{ field: 'status', operator: 'is', value: 'partial' },
			],
		};
		expect( toBatchQuery( view, NOW ) ).toEqual( {
			page: 3,
			per_page: 50,
			user: 2,
			action: 'adjust',
			status: 'partial',
		} );
	} );

	it( 'maps a date range to after and before, the end day inclusive', () => {
		const range = dateRange( {
			operator: 'between',
			value: [ '2026-09-01', '2026-09-10' ],
		} );
		expect( new Date( range.after ).getTime() ).toBe(
			new Date( 2026, 8, 1 ).getTime()
		);
		expect( new Date( range.before ).getTime() ).toBe(
			new Date( 2026, 8, 11 ).getTime()
		);
		const on = dateRange( { operator: 'on', value: '2026-09-05' } );
		expect(
			new Date( on.before ).getTime() - new Date( on.after ).getTime()
		).toBe( 86400000 );
		expect( dateRange( undefined ) ).toEqual( {} );
	} );

	it( 'sends the filters to GET /batches', async () => {
		await renderHistory();
		const list = calls.find( ( c ) => c.route === 'bcm/v1/batches' );
		expect( list.query.page ).toBe( '1' );
		expect( list.query.per_page ).toBe( '25' );
		expect( list.query.after ).toMatch( /^\d{4}-\d{2}-\d{2}T/ );
	} );

	it( 'states the retention window, or that history is kept', () => {
		expect( retentionLine( 90 ) ).toBe(
			'Older batches are removed automatically after 90 days. Change this in Settings.'
		);
		expect( retentionLine( 0 ) ).toBe(
			'History is kept indefinitely. Change this in Settings.'
		);
	} );
} );

describe( 'history words', () => {
	it( 'says what a batch did in plain words, never a storage key', () => {
		const byKey = {
			regular_price: { label: 'Regular price', type: 'money' },
			sku: { label: 'SKU', type: 'text' },
		};
		expect( describeBatch( BATCHES[ 0 ], byKey ) ).toBe(
			'Increased the regular price by 8%'
		);
		expect(
			describeBatch(
				{
					source: 'bulk',
					operations: [
						{
							action: 'affix_sku',
							args: {
								field: 'sku',
								position: 'prefix',
								text: 'LM-',
							},
						},
					],
				},
				byKey
			)
		).toBe( 'Added “LM-” to the start of the SKU' );
		expect( describeBatch( BATCHES[ 5 ], byKey ) ).toBe( 'Undo of #1' );
	} );

	it( 'confirms in one plain sentence', () => {
		expect( confirmSentence( 120, 'prices' ) ).toBe(
			'This puts 120 products back to their old prices. Continue?'
		);
		expect( confirmSentence( 1, 'values' ) ).toBe(
			'This puts 1 product back to its old values. Continue?'
		);
	} );

	it( 'makes a v4 request id', () => {
		expect( newUuid() ).toMatch(
			/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/
		);
	} );
} );

describe( 'history states', () => {
	it( 'renders every §4.6 state from the route fields', async () => {
		await renderHistory();

		// Reversible: Undo offered, the who by name.
		const one = within( card( 1 ) );
		expect( one.getByRole( 'button', { name: 'Undo' } ).disabled ).toBe(
			false
		);
		expect( one.getByText( 'Mia Fourie' ) ).toBeTruthy();
		expect( one.getByText( '120 products' ) ).toBeTruthy();

		// Partially reversible with its conflict count.
		const two = within( card( 2 ) );
		expect( two.getByText( '3 changed since' ) ).toBeTruthy();
		expect(
			two.getByText( 'Set the stock status to In stock' )
		).toBeTruthy();
		await waitFor( () =>
			expect( two.getByText( 'Sam Naidoo' ) ).toBeTruthy()
		);

		// In progress: a progress row in place of Undo.
		const three = within( card( 3 ) );
		expect( three.queryByRole( 'button', { name: 'Undo' } ) ).toBeNull();
		await waitFor( () =>
			expect( three.getByText( '40 of 100 done' ) ).toBeTruthy()
		);
		expect( card( 3 ).querySelector( 'progress' ) ).not.toBeNull();

		// Completed with errors: marked, still undoable for the applied part.
		const four = within( card( 4 ) );
		expect( four.getByText( '2 failed' ) ).toBeTruthy();
		expect( four.getByRole( 'button', { name: 'Undo' } ).disabled ).toBe(
			false
		);

		// Not reversible, with its reason.
		const five = within( card( 5 ) );
		expect(
			five.getByText(
				'The saved values for this change were removed when old history was cleared, so it cannot be undone.'
			)
		).toBeTruthy();
		expect(
			five
				.getByRole( 'button', { name: 'Undo' } )
				.getAttribute( 'aria-disabled' )
		).toBe( 'true' );

		// An undo batch reads "Undo of #1" and offers Redo.
		const six = within( card( 6 ) );
		expect(
			six.getByRole( 'heading', { name: 'Undo of #1' } )
		).toBeTruthy();
		expect( six.getByRole( 'button', { name: 'Redo' } ) ).toBeTruthy();

		// Already undone.
		const seven = within( card( 7 ) );
		expect(
			seven
				.getByRole( 'button', { name: 'Undone' } )
				.getAttribute( 'aria-disabled' )
		).toBe( 'true' );

		expect(
			screen.getByText(
				'Older batches are removed automatically after 90 days. Change this in Settings.'
			)
		).toBeTruthy();
	} );

	it( 'expands a batch to its changes in place, searchable within the page', async () => {
		await renderHistory();
		const one = within( card( 1 ) );
		fireEvent.click(
			one.getByRole( 'button', { name: 'See every change' } )
		);
		await waitFor( () =>
			expect( one.getByText( 'A5 Hardcover Notebook' ) ).toBeTruthy()
		);
		const request = calls.find(
			( c ) => c.route === 'bcm/v1/batches/1/changes'
		);
		expect( request.query ).toEqual( { page: '1', per_page: '25' } );
		expect( one.getByText( '7.31' ) ).toBeTruthy();
		expect( one.getByText( '7.90' ) ).toBeTruthy();
		expect( one.getAllByText( 'Changed' ).length ).toBe( 2 );

		fireEvent.change(
			one.getByRole( 'searchbox', { name: 'Search these changes' } ),
			{ target: { value: 'notepad' } }
		);
		expect( one.queryByText( 'A5 Hardcover Notebook' ) ).toBeNull();
		expect( one.getByText( 'A6 Notepad' ) ).toBeTruthy();
		expect(
			one.getByRole( 'button', { name: 'Hide the changes' } )
		).toBeTruthy();
	} );

	it( 'opens a batch with errors on its failing rows and reasons', async () => {
		await renderHistory();
		const four = within( card( 4 ) );
		fireEvent.click(
			four.getByRole( 'button', { name: 'See every change' } )
		);
		await waitFor( () =>
			expect( four.getByText( 'Classic Cotton T-Shirt' ) ).toBeTruthy()
		);
		const request = calls.find(
			( c ) => c.route === 'bcm/v1/batches/4/changes'
		);
		expect( request.query.result ).toEqual( [ 'failed', 'skipped' ] );
		expect(
			four.getByText(
				'Another user was editing this product. Try again in a moment.'
			)
		).toBeTruthy();
		await waitFor( () =>
			expect(
				four.getByText(
					'2 products: Another user was editing this product.'
				)
			).toBeTruthy()
		);
		// The technical line waits behind Show details.
		expect(
			four.queryByText(
				'product 601, field regular_price, result failed'
			)
		).toBeNull();
		fireEvent.click( four.getByRole( 'button', { name: 'Show details' } ) );
		expect(
			four.getByText( 'product 601, field regular_price, result failed' )
		).toBeTruthy();
	} );
} );

/**
 * The hidden export frame's URL, and its `columns[]` in order.
 *
 * @return {{url: URL, columns: Array<string>}} URL and columns.
 */
function exportFrame() {
	const frame = document.querySelector( 'iframe.bcm-export__frame' );
	const url = new URL( frame.src );
	const columns = [];
	url.searchParams.forEach( ( value, key ) => {
		if ( key.startsWith( 'columns[' ) ) {
			columns.push( value );
		}
	} );
	return { url, columns };
}

describe( 'history wire-ins', () => {
	afterEach( () => {
		document
			.querySelectorAll( 'iframe.bcm-export__frame' )
			.forEach( ( frame ) => frame.remove() );
	} );

	it( "names who from the row, else from GET /batches/users, never core's users route", async () => {
		await renderHistory();
		expect( within( card( 5 ) ).getByText( 'Lee Adams' ) ).toBeTruthy();
		await waitFor( () =>
			expect( within( card( 2 ) ).getByText( 'Sam Naidoo' ) ).toBeTruthy()
		);
		expect(
			calls.some( ( c ) => c.route === 'bcm/v1/batches/users' )
		).toBe( true );
		expect( calls.some( ( c ) => c.route.startsWith( 'wp/v2/' ) ) ).toBe(
			false
		);
	} );

	it( 'marks a batch that reached variations and says who put one back', async () => {
		await renderHistory();
		expect(
			within( card( 1 ) ).getByText( 'Includes variations' )
		).toBeTruthy();
		expect(
			within( card( 2 ) ).queryByText( 'Includes variations' )
		).toBeNull();
		expect(
			within( card( 7 ) ).getByText(
				'Put back by Sam Naidoo in change #42'
			)
		).toBeTruthy();
		expect( within( card( 1 ) ).queryByText( /Put back by/ ) ).toBeNull();
	} );

	it( 'downloads the list from GET /export?source=batches with its filters', async () => {
		await renderHistory();
		const button = screen.getByRole( 'button', {
			name: 'Export log (CSV)',
		} );
		await act( async () => fireEvent.click( button ) );
		const { url, columns } = exportFrame();
		expect( url.pathname ).toBe( '/wp-json/bcm/v1/export' );
		expect( url.searchParams.get( 'source' ) ).toBe( 'batches' );
		expect( url.searchParams.get( 'after' ) ).toMatch( /Z$/ );
		expect( url.searchParams.get( 'page' ) ).toBeNull();
		expect( columns ).toContain( 'user_name' );
		expect( columns ).toContain( 'undone_by' );
		expect( button.getAttribute( 'aria-disabled' ) ).toBe( 'true' );
	} );

	it( "downloads a batch's changes with the result filter on screen", async () => {
		await renderHistory();
		const four = within( card( 4 ) );
		fireEvent.click(
			four.getByRole( 'button', { name: 'See every change' } )
		);
		const link = await four.findByRole( 'button', {
			name: 'Download these changes (CSV)',
		} );
		await act( async () => fireEvent.click( link ) );
		const { url, columns } = exportFrame();
		expect( url.searchParams.get( 'source' ) ).toBe( 'changes' );
		expect( url.searchParams.get( 'batch' ) ).toBe( '4' );
		const result = [];
		url.searchParams.forEach( ( value, key ) => {
			if ( key.startsWith( 'result[' ) ) {
				result.push( value );
			}
		} );
		expect( result ).toEqual( [ 'failed', 'skipped' ] );
		expect( columns[ 0 ] ).toBe( 'applied_gmt' );
		expect( columns ).not.toContain( 'user_name' );
	} );

	it( 'shows a refused export as a notice in plain words', async () => {
		const {
			useHistoryExport,
		} = require( '../../assets/src/panels/history/export.js' );
		const notices = [];
		let hook;
		let started = '';
		function Probe() {
			hook = useHistoryExport( ( n ) => notices.push( n ), {
				getNonce: () => 'abc',
				start: ( url, { onError } ) => {
					started = url;
					onError(
						Object.assign( new Error( 'Too many rows.' ), {
							code: 'bcm_export_too_large',
						} )
					);
					return () => {};
				},
			} );
			return null;
		}
		render( <Probe /> );
		await act( async () => hook.run( 'audit', { user: 2, page: 3 } ) );
		expect( notices[ 0 ].error.message ).toBe( 'Too many rows.' );
		const url = new URL( started );
		expect( url.searchParams.get( '_wpnonce' ) ).toBe( 'abc' );
		expect( url.searchParams.get( 'user' ) ).toBe( '2' );
		expect( url.searchParams.get( 'page' ) ).toBeNull();
	} );
} );

describe( 'undo flow', () => {
	const PLAN = {
		batch_id: 1,
		preview: true,
		counts: { revertible: 120, conflicted: 0, skipped: 0 },
		total: 120,
		items: [],
		plan_hash: 'a'.repeat( 64 ),
		sentence: '',
	};

	it( 'previews, confirms in a sentence, applies and reports', async () => {
		serve( {
			...DEFAULT_ROUTES(),
			'POST bcm/v1/batches/1/undo': ( query, data ) =>
				data.preview
					? PLAN
					: {
							batch_id: 99,
							status: 'queued',
							total_items: 120,
							replayed: false,
							undo: { undo_of: 1, label: 'Undo of #1' },
						},
			'GET bcm/v1/batches/99': base( 99, {
				source: 'undo',
				undo_of: 1,
				status: 'complete',
				changed_items: 120,
				undo: { undo_of: 1, label: 'Undo of #1', sentence: '' },
			} ),
		} );
		await renderHistory();
		const undo = within( card( 1 ) ).getByRole( 'button', {
			name: 'Undo',
		} );
		await act( async () => fireEvent.click( undo ) );

		const preview = calls.find(
			( c ) => c.method === 'POST' && c.route === 'bcm/v1/batches/1/undo'
		);
		expect( preview.data ).toEqual( { preview: true, per_page: 100 } );

		const dialog = await screen.findByRole( 'dialog', {
			name: 'Undo this change?',
		} );
		expect(
			within( dialog ).getByText(
				'This puts 120 products back to their old prices. Continue?'
			)
		).toBeTruthy();
		await act( async () =>
			fireEvent.click(
				within( dialog ).getByRole( 'button', { name: 'Undo' } )
			)
		);

		const applied = calls.filter(
			( c ) => c.method === 'POST' && c.route === 'bcm/v1/batches/1/undo'
		)[ 1 ];
		expect( applied.data.plan_hash ).toBe( PLAN.plan_hash );
		expect( applied.data.force ).toBe( false );
		expect( applied.data.uuid ).toMatch( /^[0-9a-f-]{36}$/ );

		await waitFor( () =>
			expect( noticeText() ).toBe(
				'Undone: Increased the regular price by 8%. 120 products were put back.'
			)
		);
		expect( calls.some( ( c ) => c.route === 'bcm/v1/batches/99' ) ).toBe(
			true
		);
		// The notice is dismissible.
		fireEvent.click(
			screen.getByRole( 'button', { name: /Close|Dismiss/ } )
		);
		expect( noticeText() ).toBeNull();
	} );

	it( 'lists conflicts first and offers the three-way choice, Revert anyway off by default', async () => {
		const plan = {
			...PLAN,
			counts: { revertible: 117, conflicted: 3, skipped: 0 },
			sentence:
				'3 of 120 were changed again after this batch and will be left alone unless you choose Revert anyway.',
			items: [
				{
					object_id: 601,
					name: 'Changed Since Tee',
					state: 'conflicted',
					fields: [],
					conflicts: [
						{
							field: 'stock_status',
							field_label: 'Stock status',
							expected: 'instock',
							current: 'outofstock',
						},
					],
				},
				{
					object_id: 602,
					name: 'Plain Tee',
					state: 'revertible',
					fields: [],
					conflicts: [],
				},
			],
		};
		serve( {
			...DEFAULT_ROUTES(),
			'POST bcm/v1/batches/2/undo': ( query, data ) =>
				data.preview
					? plan
					: {
							batch_id: 98,
							status: 'queued',
							total_items: 117,
							replayed: false,
						},
			'GET bcm/v1/batches/98': base( 98, {
				source: 'undo',
				undo_of: 2,
				status: 'complete',
				changed_items: 117,
				undo: {
					undo_of: 2,
					label: 'Undo of #2',
					sentence:
						'3 of 120 were changed again after this batch and were left alone.',
				},
			} ),
		} );
		await renderHistory();
		const two = within( card( 2 ) );
		await act( async () =>
			fireEvent.click( two.getByRole( 'button', { name: 'Undo' } ) )
		);

		// The warning, not the dialog, comes first.
		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		expect(
			two.getByRole( 'heading', {
				name: '3 of these products have changed since',
			} )
		).toBeTruthy();
		expect( two.getByText( plan.sentence ) ).toBeTruthy();

		fireEvent.click(
			two.getByRole( 'button', { name: 'See the 3 that changed' } )
		);
		const table = two.getByRole( 'table' );
		expect( within( table ).getByText( 'Changed Since Tee' ) ).toBeTruthy();
		expect( within( table ).getByText( 'In stock' ) ).toBeTruthy();
		expect( within( table ).getByText( 'Out of stock' ) ).toBeTruthy();
		expect( within( table ).queryByText( 'Plain Tee' ) ).toBeNull();
		expect(
			two.getByRole( 'button', { name: 'Undo all 120 anyway' } )
		).toBeTruthy();

		// The safe choice: force stays off.
		fireEvent.click(
			two.getByRole( 'button', { name: 'Undo the other 117' } )
		);
		const dialog = await screen.findByRole( 'dialog' );
		expect(
			within( dialog ).getByText(
				'This puts 117 products back to their old stock. Continue?'
			)
		).toBeTruthy();
		expect(
			within( dialog ).getByText(
				'The 3 products that changed since will be left alone.'
			)
		).toBeTruthy();
		await act( async () =>
			fireEvent.click(
				within( dialog ).getByRole( 'button', { name: 'Undo' } )
			)
		);
		const applied = calls.filter(
			( c ) => c.method === 'POST' && c.route === 'bcm/v1/batches/2/undo'
		)[ 1 ];
		expect( applied.data.force ).toBe( false );
		await waitFor( () =>
			expect( noticeText() ).toBe(
				'Undone: Set the stock status to In stock. 117 products were put back. 3 of 120 were changed again after this batch and were left alone.'
			)
		);
	} );

	it( 'sends force only when the owner chooses to undo all anyway', async () => {
		const plan = {
			...PLAN,
			counts: { revertible: 117, conflicted: 3, skipped: 0 },
		};
		serve( {
			...DEFAULT_ROUTES(),
			'POST bcm/v1/batches/2/undo': ( query, data ) =>
				data.preview
					? plan
					: { batch_id: 97, status: 'queued', replayed: false },
		} );
		await renderHistory();
		const two = within( card( 2 ) );
		await act( async () =>
			fireEvent.click( two.getByRole( 'button', { name: 'Undo' } ) )
		);
		fireEvent.click(
			two.getByRole( 'button', { name: 'Undo all 120 anyway' } )
		);
		const dialog = await screen.findByRole( 'dialog' );
		expect(
			within( dialog ).getByText(
				'This puts 120 products back to their old stock. Continue?'
			)
		).toBeTruthy();
		await act( async () =>
			fireEvent.click(
				within( dialog ).getByRole( 'button', { name: 'Undo' } )
			)
		);
		const applied = calls.filter(
			( c ) => c.method === 'POST' && c.route === 'bcm/v1/batches/2/undo'
		)[ 1 ];
		expect( applied.data.force ).toBe( true );
		expect( applied.data.plan_hash ).toBe( plan.plan_hash );
	} );

	it( 'reports a refused undo in plain words with the code behind Show details', async () => {
		const refusal = Object.assign( new Error( 'x' ), {
			code: 'bcm_not_reversible',
			message: 'This change has already been undone.',
			data: { status: 409, reason: 'already_undone' },
		} );
		serve( {
			...DEFAULT_ROUTES(),
			'POST bcm/v1/batches/1/undo': refusal,
		} );
		await renderHistory();
		const one = within( card( 1 ) );
		await act( async () =>
			fireEvent.click( one.getByRole( 'button', { name: 'Undo' } ) )
		);
		expect(
			one.getByText( 'This change has already been undone.' )
		).toBeTruthy();
		expect( one.queryByText( /bcm_not_reversible/ ) ).toBeNull();
		fireEvent.click( one.getByRole( 'button', { name: 'Show details' } ) );
		expect( one.getByText( /bcm_not_reversible/ ) ).toBeTruthy();
	} );
} );
