/**
 * The History panel (T57): the list's filter-to-query mapping, every §4.6
 * state rendered from the route's own fields, a batch expanding to its
 * changes in place, and the undo flow — preview first, conflicts first
 * with the three-way choice, Revert anyway off by default, apply as a
 * durable request (a batch.undo grant over the batch, plan_hash and force,
 * T84), a retry with the same grant, and the notice when it finishes.
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

import {
	coreJson,
	expectNoRawText,
	expectPlain,
	html500,
} from './error-fixtures';

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
	putBackTypes,
	undoKey,
	undoneNotice,
} = require( '../../assets/src/panels/history/undo.js' );

const ROOT = 'https://example.test/wp-json/bulkcat/v1/';

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

let grants = 0;

const DEFAULT_ROUTES = () => ( {
	// T84: every undo is a durable request, issued a grant first.
	'POST bulkcat/v1/requests': ( query, data ) => {
		grants += 1;
		return {
			uuid: `00000000-0000-4000-8000-${ String( grants ).padStart(
				12,
				'0'
			) }`,
			request_proof: `proof.${ grants }`,
			accept_until: 1790000000,
			operation: data.operation,
			intent: { version: 1, ...data.intent },
		};
	},
	'GET bulkcat/v1/fields': FIELDS,
	'GET bulkcat/v1/batches/users': {
		items: [
			{ id: 1, name: 'Mia Fourie' },
			{ id: 2, name: 'Sam Naidoo' },
		],
	},
	'GET bulkcat/v1/batches': {
		items: BATCHES,
		pagination: { page: 1, perPage: 25, total: 7, totalPages: 1 },
	},
	'GET bulkcat/v1/batches/3': base( 3, {
		status: 'running',
		done_items: 40,
		total_items: 100,
		reversibility: { state: 'in_progress', conflicts: null, reason: null },
	} ),
	'GET bulkcat/v1/batches/4': base( 4, {
		status: 'partial',
		summary: [
			{
				message: 'Another user was editing this product.',
				count: 2,
				sentence: '2 products: Another user was editing this product.',
			},
		],
	} ),
	'GET bulkcat/v1/batches/1/changes': CHANGES,
	'GET bulkcat/v1/batches/4/changes': FAILED,
} );

/**
 * The card of one batch, by its title and number.
 *
 * @param {number} id Batch.
 * @return {HTMLElement} The article.
 */
const card = ( id ) =>
	document.getElementById( `bulkcat-batch-${ id }` ).closest( 'article' );

/**
 * The screen notice's text, or null when there is none.
 *
 * @return {string|null} Text.
 */
const noticeText = () =>
	document.querySelector(
		'.bulkcat-history__notice .components-notice__content'
	)?.childNodes[ 0 ]?.textContent ?? null;

async function renderHistory() {
	render( <History settings={ SETTINGS } /> );
	await waitFor( () =>
		expect( document.getElementById( 'bulkcat-batch-1' ) ).not.toBeNull()
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
		const list = calls.find( ( c ) => c.route === 'bulkcat/v1/batches' );
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

	it( 'says what an undo puts back: products, variations or both (T114)', () => {
		const plan = {
			counts: { revertible: 120, conflicted: 5, skipped: 1 },
			by_type: {
				product: { revertible: 100, conflicted: 5, skipped: 1 },
				variation: { revertible: 20, conflicted: 0, skipped: 0 },
			},
		};
		expect( putBackTypes( plan, false ) ).toEqual( {
			products: 100,
			variations: 20,
			categories: 0,
		} );
		expect( putBackTypes( plan, true ) ).toEqual( {
			products: 105,
			variations: 20,
			categories: 0,
		} );
		// Ticket 133: an undo of category changes says categories.
		expect(
			confirmSentence(
				2,
				'values',
				putBackTypes(
					{
						by_type: {
							term: {
								revertible: 2,
								conflicted: 0,
								skipped: 0,
							},
						},
					},
					false
				)
			)
		).toBe( 'This puts 2 categories back as they were before. Continue?' );
		expect( putBackTypes( { counts: plan.counts }, false ) ).toBeNull();
		expect(
			confirmSentence( 120, 'prices', putBackTypes( plan, false ) )
		).toBe(
			'This puts 100 products and 20 variations back to their old prices. Continue?'
		);
		expect(
			confirmSentence( 1, 'stock', { products: 0, variations: 1 } )
		).toBe( 'This puts 1 variation back to its old stock. Continue?' );

		const report = ( byType ) => ( {
			changed_items: 120,
			undo: {
				undo_of: 1,
				label: 'Undo of #1',
				sentence: '',
				by_type: byType,
			},
		} );
		expect(
			undoneNotice(
				'Increased the regular price by 8%',
				report( { products: 100, variations: 20 } )
			)
		).toBe(
			'Undone: Increased the regular price by 8%. 120 items were put back.'
		);
		expect(
			undoneNotice(
				'Set the stock',
				report( { products: 0, variations: 120 } ),
				true
			)
		).toBe( 'Redone. 120 variations were put back.' );
		expect(
			undoneNotice(
				'Set the stock',
				report( { products: 120, variations: 0 } )
			)
		).toBe( 'Undone: Set the stock. 120 products were put back.' );
		// An undo recorded before the split was kept: it may hold either.
		expect( undoneNotice( 'Set the stock', report( undefined ) ) ).toBe(
			'Undone: Set the stock. 120 items were put back.'
		);
		// Main Build's F2: an undo that put nothing back is not called done.
		const sentence =
			'Nothing was put back, because everything had been changed again by the time the undo reached it. The change can still be undone: choose Revert anyway to put the old values back regardless.';
		expect(
			undoneNotice( 'Set the stock', {
				changed_items: 0,
				done_items: 3,
				undo: {
					undo_of: 1,
					sentence,
					by_type: { products: 3, variations: 0 },
				},
			} )
		).toBe( sentence );
	} );

	it( 'names one undo by its batch, plan and force, so a retry keeps its grant', () => {
		const plan = { plan_hash: 'a'.repeat( 64 ) };
		expect( undoKey( 1, plan, false ) ).toBe( undoKey( '1', plan, false ) );
		expect( undoKey( 1, plan, true ) ).not.toBe(
			undoKey( 1, plan, false )
		);
		expect( undoKey( 1, { plan_hash: 'b'.repeat( 64 ) }, false ) ).not.toBe(
			undoKey( 1, plan, false )
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
		// The row's count holds products and variations together (ticket 11).
		expect( one.getByText( '120 items' ) ).toBeTruthy();

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
			( c ) => c.route === 'bulkcat/v1/batches/1/changes'
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
			( c ) => c.route === 'bulkcat/v1/batches/4/changes'
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

describe( 'rows skipped as previewed (1.3.4)', () => {
	const SKIPPED = base( 8, {
		status: 'partial',
		total_items: 2,
		done_items: 2,
		changed_items: 2,
		failed_items: 0,
		reversibility: { state: 'reversible', conflicts: null, reason: null },
	} );
	const SKIP_REASON = {
		message: 'Stock quantity only applies where stock is managed.',
		count: 1,
		sentence:
			'1 product: Stock quantity only applies where stock is managed.',
	};

	it( 'shows a change with nothing failed as done with rows skipped, never with errors, and opens on its skipped rows', async () => {
		serve( {
			...DEFAULT_ROUTES(),
			'GET bulkcat/v1/batches': {
				items: [ base( 1 ), SKIPPED, BATCHES[ 3 ] ],
				pagination: { page: 1, perPage: 25, total: 3, totalPages: 1 },
			},
			'GET bulkcat/v1/batches/8': {
				...SKIPPED,
				summary: [ SKIP_REASON ],
			},
			'GET bulkcat/v1/batches/8/changes': {
				items: [
					{
						...FAILED.items[ 0 ],
						change_id: 31,
						field: 'stock_quantity',
						field_label: 'Stock quantity',
						result: 'skipped',
						message: SKIP_REASON.message,
					},
				],
				pagination: { page: 1, perPage: 25, total: 1, totalPages: 1 },
			},
		} );
		await renderHistory();
		const eight = within( card( 8 ) );
		expect( eight.getByText( 'Done, some rows skipped' ) ).toBeTruthy();
		expect( eight.queryByText( 'Done with errors' ) ).toBeNull();
		expect( eight.queryByText( /failed/ ) ).toBeNull();
		expect( eight.getByRole( 'button', { name: 'Undo' } ).disabled ).toBe(
			false
		);
		// A change that did fail still says so.
		const four = within( card( 4 ) );
		expect( four.getByText( 'Done with errors' ) ).toBeTruthy();
		expect( four.getByText( '2 failed' ) ).toBeTruthy();

		fireEvent.click(
			eight.getByRole( 'button', { name: 'See every change' } )
		);
		await waitFor( () =>
			expect( eight.getByText( SKIP_REASON.sentence ) ).toBeTruthy()
		);
		const request = calls.find(
			( c ) => c.route === 'bulkcat/v1/batches/8/changes'
		);
		expect( request.query.result ).toEqual( [ 'failed', 'skipped' ] );
	} );

	it( 'filters on both kinds of partial outcome by a name that says so', () => {
		const {
			statusElements,
		} = require( '../../assets/src/panels/history/describe.js' );
		expect(
			statusElements().find( ( each ) => each.value === 'partial' ).label
		).toBe( 'Done with skipped or failed rows' );
	} );
} );

/**
 * The hidden export frame's URL, and its `columns[]` in order.
 *
 * @return {{url: URL, columns: Array<string>}} URL and columns.
 */
/**
 * The export form the screen posted (SECURITY-1): its address, which must
 * carry nothing, and its fields, the nonce among them.
 *
 * @return {Object} `{ url, params, columns }`: `url` is the form's address,
 *                  `params` its fields.
 */
function exportForm() {
	const form = document.querySelector( 'form.bulkcat-export__form' );
	const url = new URL( form.getAttribute( 'action' ) );
	const params = new URLSearchParams();
	form.querySelectorAll( 'input' ).forEach( ( input ) =>
		params.append( input.name, input.value )
	);
	expect( form.method ).toBe( 'post' );
	expect( url.search ).toBe( '' );
	expect( params.get( '_wpnonce' ) ).toBeTruthy();
	return {
		url,
		params,
		columns: ( params.get( 'columns' ) || '' ).split( ',' ),
	};
}

describe( 'following a change in progress (FRONTEND-8)', () => {
	it( 'stops reading a running change after a sign-out', async () => {
		serve( {
			...DEFAULT_ROUTES(),
			'GET bulkcat/v1/batches/3': () => {
				throw Object.assign( new Error( 'Not logged in.' ), {
					code: 'rest_not_logged_in',
					data: { status: 401 },
				} );
			},
		} );
		await renderHistory();
		const reads = () =>
			calls.filter( ( call ) => call.route === 'bulkcat/v1/batches/3' )
				.length;
		await waitFor( () => expect( reads() ).toBe( 1 ) );
		await act(
			() => new Promise( ( resolve ) => setTimeout( resolve, 3500 ) )
		);
		expect( reads() ).toBe( 1 );
	} );
} );

describe( 'history wire-ins', () => {
	beforeEach( () => {
		// jsdom cannot send a form; the posted form is read back instead.
		jest.spyOn(
			window.HTMLFormElement.prototype,
			'submit'
		).mockImplementation( () => {} );
		// The nonce apiFetch's middleware holds (currentNonce()).
		require( '@wordpress/api-fetch' ).default.nonceMiddleware = {
			nonce: 'n0nce12345',
		};
	} );

	afterEach( () => {
		jest.restoreAllMocks();
		delete require( '@wordpress/api-fetch' ).default.nonceMiddleware;
		document
			.querySelectorAll(
				'iframe.bulkcat-export__frame, form.bulkcat-export__form'
			)
			.forEach( ( element ) => element.remove() );
	} );

	it( "names who from the row, else from GET /batches/users, never core's users route", async () => {
		await renderHistory();
		expect( within( card( 5 ) ).getByText( 'Lee Adams' ) ).toBeTruthy();
		await waitFor( () =>
			expect( within( card( 2 ) ).getByText( 'Sam Naidoo' ) ).toBeTruthy()
		);
		expect(
			calls.some( ( c ) => c.route === 'bulkcat/v1/batches/users' )
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

	it( 'downloads the list by posting /export with source=batches and its filters, the nonce in the body (SECURITY-1)', async () => {
		await renderHistory();
		const button = screen.getByRole( 'button', {
			name: 'Export log (CSV)',
		} );
		await act( async () => fireEvent.click( button ) );
		const { url, params, columns } = exportForm();
		expect( url.pathname ).toBe( '/wp-json/bulkcat/v1/export' );
		expect( params.get( 'source' ) ).toBe( 'batches' );
		expect( params.get( 'after' ) ).toMatch( /Z$/ );
		expect( params.get( 'page' ) ).toBeNull();
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
		const { params, columns } = exportForm();
		expect( params.get( 'source' ) ).toBe( 'changes' );
		expect( params.get( 'batch' ) ).toBe( '4' );
		const result = [];
		params.forEach( ( value, key ) => {
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
		let started = [];
		function Probe() {
			hook = useHistoryExport( ( n ) => notices.push( n ), {
				getNonce: () => 'abc',
				start: ( fields, { onError } ) => {
					started = fields;
					onError(
						Object.assign( new Error( 'Too many rows.' ), {
							code: 'bulkcat_export_too_large',
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
		const posted = new URLSearchParams( started );
		expect( started[ 0 ] ).toEqual( [ '_wpnonce', 'abc' ] );
		expect( posted.get( 'source' ) ).toBe( 'audit' );
		expect( posted.get( 'user' ) ).toBe( '2' );
		expect( posted.get( 'page' ) ).toBeNull();
		expect( posted.get( 'confirm_all' ) ).toBeNull();
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
			'POST bulkcat/v1/batches/1/undo': ( query, data ) =>
				data.preview
					? PLAN
					: {
							batch_id: 99,
							status: 'queued',
							total_items: 120,
							replayed: false,
							undo: { undo_of: 1, label: 'Undo of #1' },
						},
			'GET bulkcat/v1/batches/99': base( 99, {
				source: 'undo',
				undo_of: 1,
				status: 'complete',
				changed_items: 120,
				undo: {
					undo_of: 1,
					label: 'Undo of #1',
					sentence: '',
					// T114: the route says what the undo held.
					by_type: { products: 120, variations: 0 },
				},
			} ),
		} );
		await renderHistory();
		const undo = within( card( 1 ) ).getByRole( 'button', {
			name: 'Undo',
		} );
		await act( async () => fireEvent.click( undo ) );

		const preview = calls.find(
			( c ) =>
				c.method === 'POST' && c.route === 'bulkcat/v1/batches/1/undo'
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

		// A batch.undo grant, then the durable body: no 1.0 keys.
		const issued = calls.find(
			( c ) => c.method === 'POST' && c.route === 'bulkcat/v1/requests'
		);
		expect( issued.data ).toEqual( {
			operation: 'batch.undo',
			intent: {
				source: 'undo',
				batch_id: 1,
				plan_hash: PLAN.plan_hash,
				force: false,
			},
		} );
		const applied = calls.filter(
			( c ) =>
				c.method === 'POST' && c.route === 'bulkcat/v1/batches/1/undo'
		)[ 1 ];
		expect( applied.data ).toEqual( {
			uuid: expect.stringMatching( /^[0-9a-f-]{36}$/ ),
			request_proof: expect.any( String ),
			intent: {
				version: 1,
				source: 'undo',
				batch_id: 1,
				plan_hash: PLAN.plan_hash,
				force: false,
			},
		} );

		await waitFor( () =>
			expect( noticeText() ).toBe(
				'Undone: Increased the regular price by 8%. 120 products were put back.'
			)
		);
		expect(
			calls.some( ( c ) => c.route === 'bulkcat/v1/batches/99' )
		).toBe( true );
		// The notice is dismissible.
		fireEvent.click(
			screen.getByRole( 'button', { name: /Close|Dismiss/ } )
		);
		expect( noticeText() ).toBeNull();
	} );

	it( 'offers and runs the undo of a 100,000-object change (T112: no size limit, the undo is streamed)', async () => {
		const large = base( 1, {
			variations_included: true,
			total_items: 100000,
			done_items: 100000,
			changed_items: 100000,
		} );
		serve( {
			...DEFAULT_ROUTES(),
			'GET bulkcat/v1/batches': {
				items: [ large ],
				pagination: { page: 1, perPage: 25, total: 1, totalPages: 1 },
			},
			'POST bulkcat/v1/batches/1/undo': ( query, data ) =>
				data.preview
					? {
							...PLAN,
							counts: {
								revertible: 100000,
								conflicted: 0,
								skipped: 0,
							},
							total: 100000,
						}
					: {
							batch_id: 99,
							status: 'queued',
							total_items: 100000,
							replayed: false,
							undo: { undo_of: 1, label: 'Undo of #1' },
						},
			'GET bulkcat/v1/batches/99': base( 99, {
				source: 'undo',
				undo_of: 1,
				status: 'queued',
				total_items: 100000,
				done_items: 0,
				changed_items: 0,
				undo: { undo_of: 1, label: 'Undo of #1', sentence: '' },
			} ),
		} );
		await renderHistory();
		const undo = within( card( 1 ) ).getByRole( 'button', {
			name: 'Undo',
		} );
		expect( undo.disabled ).toBe( false );
		expect( card( 1 ).textContent ).not.toContain( 'not yet supported' );
		await act( async () => fireEvent.click( undo ) );
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Undo this change?',
		} );
		await act( async () =>
			fireEvent.click(
				within( dialog ).getByRole( 'button', { name: 'Undo' } )
			)
		);
		const applied = calls.filter(
			( c ) =>
				c.method === 'POST' && c.route === 'bulkcat/v1/batches/1/undo'
		);
		expect( applied ).toHaveLength( 2 );
		expect( applied[ 1 ].data.intent ).toEqual( {
			version: 1,
			source: 'undo',
			batch_id: 1,
			plan_hash: PLAN.plan_hash,
			force: false,
		} );
		await waitFor( () =>
			expect(
				calls.some( ( c ) => c.route === 'bulkcat/v1/batches/99' )
			).toBe( true )
		);
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
			'POST bulkcat/v1/batches/2/undo': ( query, data ) =>
				data.preview
					? plan
					: {
							batch_id: 98,
							status: 'queued',
							total_items: 117,
							replayed: false,
						},
			'GET bulkcat/v1/batches/98': base( 98, {
				source: 'undo',
				undo_of: 2,
				status: 'complete',
				changed_items: 117,
				undo: {
					undo_of: 2,
					label: 'Undo of #2',
					sentence:
						'3 of 120 were changed again after this batch and were left alone.',
					by_type: { products: 117, variations: 0 },
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
				name: '3 products have changed since',
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
			two.getByRole( 'button', { name: 'Revert anyway' } )
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
			( c ) =>
				c.method === 'POST' && c.route === 'bulkcat/v1/batches/2/undo'
		)[ 1 ];
		expect( applied.data.intent.force ).toBe( false );
		expect( applied.data.force ).toBeUndefined();
		await waitFor( () =>
			expect( noticeText() ).toBe(
				'Undone: Set the stock status to In stock. 117 products were put back. 3 of 120 were changed again after this batch and were left alone.'
			)
		);
	} );

	it( 'sends force only when the owner chooses Revert anyway', async () => {
		const plan = {
			...PLAN,
			counts: { revertible: 117, conflicted: 3, skipped: 0 },
		};
		serve( {
			...DEFAULT_ROUTES(),
			'POST bulkcat/v1/batches/2/undo': ( query, data ) =>
				data.preview
					? plan
					: { batch_id: 97, status: 'queued', replayed: false },
		} );
		await renderHistory();
		const two = within( card( 2 ) );
		await act( async () =>
			fireEvent.click( two.getByRole( 'button', { name: 'Undo' } ) )
		);
		fireEvent.click( two.getByRole( 'button', { name: 'Revert anyway' } ) );
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
			( c ) =>
				c.method === 'POST' && c.route === 'bulkcat/v1/batches/2/undo'
		)[ 1 ];
		expect( applied.data.intent ).toMatchObject( {
			force: true,
			plan_hash: plan.plan_hash,
			batch_id: 2,
		} );
	} );

	it( 'resends the same undo grant after a lost answer, and follows the undo it made', async () => {
		let sends = 0;
		serve( {
			...DEFAULT_ROUTES(),
			'POST bulkcat/v1/batches/1/undo': ( query, data ) => {
				if ( data.preview ) {
					return PLAN;
				}
				sends += 1;
				return sends === 1
					? Object.assign( new Error( 'x' ), {
							code: 'fetch_error',
							message: 'You are probably offline.',
						} )
					: {
							batch_id: 96,
							status: 'queued',
							total_items: 120,
							replayed: true,
						};
			},
			'GET bulkcat/v1/batches/96': base( 96, {
				source: 'undo',
				undo_of: 1,
				status: 'complete',
				changed_items: 120,
				undo: {
					undo_of: 1,
					label: 'Undo of #1',
					sentence: '',
					// T114: the route says what the undo held.
					by_type: { products: 120, variations: 0 },
				},
			} ),
		} );
		await renderHistory();
		const one = within( card( 1 ) );
		await act( async () =>
			fireEvent.click( one.getByRole( 'button', { name: 'Undo' } ) )
		);
		const dialog = await screen.findByRole( 'dialog' );
		await act( async () =>
			fireEvent.click(
				within( dialog ).getByRole( 'button', { name: 'Undo' } )
			)
		);
		expect(
			await one.findByText(
				'We could not confirm whether the undo started. Try again: the same request is sent again, so it can only happen once.'
			)
		).toBeTruthy();
		await act( async () =>
			fireEvent.click( one.getByRole( 'button', { name: 'Try again' } ) )
		);
		const sent = calls.filter(
			( c ) =>
				c.method === 'POST' &&
				c.route === 'bulkcat/v1/batches/1/undo' &&
				! c.data.preview
		);
		expect( sent ).toHaveLength( 2 );
		expect( sent[ 1 ].data.uuid ).toBe( sent[ 0 ].data.uuid );
		expect( sent[ 1 ].data.request_proof ).toBe(
			sent[ 0 ].data.request_proof
		);
		expect(
			calls.filter( ( c ) => c.route === 'bulkcat/v1/requests' )
		).toHaveLength( 1 );
		await waitFor( () =>
			expect( noticeText() ).toBe(
				'Undone: Increased the regular price by 8%. 120 products were put back.'
			)
		);
	} );

	it( 'ends the attempt on a refusal, so Undo starts again from a new preview and grant', async () => {
		let previews = 0;
		serve( {
			...DEFAULT_ROUTES(),
			'POST bulkcat/v1/batches/1/undo': ( query, data ) => {
				if ( data.preview ) {
					previews += 1;
					return {
						...PLAN,
						plan_hash: ( previews === 1 ? 'a' : 'b' ).repeat( 64 ),
					};
				}
				return data.intent.plan_hash === 'a'.repeat( 64 )
					? Object.assign( new Error( 'x' ), {
							code: 'bulkcat_preview_stale',
							message:
								'This undo no longer matches its preview: the products or the settings have changed since. Preview the undo again, then confirm it.',
							data: { status: 409 },
						} )
					: new Promise( () => {} );
			},
		} );
		await renderHistory();
		const one = within( card( 1 ) );
		await act( async () =>
			fireEvent.click( one.getByRole( 'button', { name: 'Undo' } ) )
		);
		const first = await screen.findByRole( 'dialog' );
		await act( async () =>
			fireEvent.click(
				within( first ).getByRole( 'button', { name: 'Undo' } )
			)
		);
		expect(
			await one.findByText(
				'This undo no longer matches its preview: the products or the settings have changed since. Preview the undo again, then confirm it.'
			)
		).toBeTruthy();
		expect( one.queryByRole( 'button', { name: 'Try again' } ) ).toBeNull();
		await act( async () =>
			fireEvent.click( one.getByRole( 'button', { name: 'Undo' } ) )
		);
		const second = await screen.findByRole( 'dialog' );
		await act( async () =>
			fireEvent.click(
				within( second ).getByRole( 'button', { name: 'Undo' } )
			)
		);
		const issued = calls.filter(
			( c ) => c.route === 'bulkcat/v1/requests'
		);
		expect( issued ).toHaveLength( 2 );
		expect( issued[ 1 ].data.intent.plan_hash ).toBe( 'b'.repeat( 64 ) );
	} );

	it( 'reports a refused undo in plain words with the code behind Show details', async () => {
		const refusal = Object.assign( new Error( 'x' ), {
			code: 'bulkcat_not_reversible',
			message: 'This change has already been undone.',
			data: { status: 409, reason: 'already_undone' },
		} );
		serve( {
			...DEFAULT_ROUTES(),
			'POST bulkcat/v1/batches/1/undo': refusal,
		} );
		await renderHistory();
		const one = within( card( 1 ) );
		await act( async () =>
			fireEvent.click( one.getByRole( 'button', { name: 'Undo' } ) )
		);
		expect(
			one.getByText( 'This change has already been undone.' )
		).toBeTruthy();
		expect( one.queryByText( /bulkcat_not_reversible/ ) ).toBeNull();
		fireEvent.click( one.getByRole( 'button', { name: 'Show details' } ) );
		expect( one.getByText( /bulkcat_not_reversible/ ) ).toBeTruthy();
	} );
} );

describe( 'plain messages (ticket 11)', () => {
	it( 'says a server’s HTML error page in plain words, never its text', async () => {
		serve( {
			...DEFAULT_ROUTES(),
			'GET bulkcat/v1/batches': () => {
				throw html500();
			},
		} );
		render( <History settings={ SETTINGS } /> );
		const sentence =
			'The history could not be loaded. Try again in a moment.';
		const box = (
			await screen.findByText( sentence, { selector: 'p' } )
		).closest( '.components-notice' );
		expectPlain( box, sentence );
		fireEvent.click(
			within( box ).getByRole( 'button', { name: 'Show details' } )
		);
		expect(
			within( box ).getByText( /internal_server_error/ )
		).toBeTruthy();
		expectNoRawText();
	} );

	it( 'says a JSON error that is not the plugin’s own refusal in plain words', async () => {
		serve( {
			...DEFAULT_ROUTES(),
			'POST bulkcat/v1/batches/1/undo': () => {
				throw coreJson();
			},
		} );
		await renderHistory();
		const one = within( card( 1 ) );
		await act( async () =>
			fireEvent.click( one.getByRole( 'button', { name: 'Undo' } ) )
		);
		expectPlain(
			card( 1 ),
			'The undo could not be started. Reload the page and try again.'
		);
		fireEvent.click( one.getByRole( 'button', { name: 'Show details' } ) );
		expect( one.getByText( /rest_forbidden/ ) ).toBeTruthy();
		expectNoRawText();
	} );
} );

it( 'distinguishes native Status requests from accepted legacy Status actions', () => {
	const {
		describeOperation,
		fieldsByKey,
	} = require( '../../assets/src/panels/history/describe' );
	const registry = require( '../Unit/Fields/fields.snapshot.json' );
	const fields = fieldsByKey( registry.fields );
	expect(
		describeOperation(
			{
				action: 'set_status',
				args: { field: 'status', value: 'publish' },
			},
			fields
		)
	).toBe( 'Set the status to Published' );
	expect(
		describeOperation(
			{
				action: 'set_value',
				args: { field: 'status', value: 'publish' },
			},
			fields
		)
	).toBe( 'Legacy Status change requested Published' );
	const {
		formatValue,
	} = require( '../../assets/src/panels/history/describe' );
	expect( formatValue( 'future', fields.status ) ).toBe( 'Scheduled' );
	expect( formatValue( 'Private, saved 28 Sep 2026 14:03' ) ).toBe(
		'Private, saved 28 Sep 2026 14:03'
	);
	expect( formatValue( 'Password protected' ) ).toBe( 'Password protected' );
} );

it.each( [
	{ source: 'inline', status: 'running', finished_gmt: null },
	{
		source: 'bulk',
		status: 'failed',
		inline_receipt: {
			receipt_state: 'unclosed',
			batch_id: 80,
			replayed: true,
		},
	},
] )(
	'unclosed inline History suppresses queue advice and Undo even with stale reversibility: %j',
	async ( extra ) => {
		const BatchCard =
			require( '../../assets/src/panels/history/batch-card' ).default;
		render(
			<BatchCard
				batch={ base( 80, { ...extra, stalled: true } ) }
				what="Change"
				who="Mia"
				byKey={ {} }
				onChanged={ () => {} }
				onNotice={ () => {} }
			/>
		);
		expect( screen.getByText( /save outcome.*unconfirmed/i ) ).toBeTruthy();
		expect(
			screen.queryByRole( 'button', { name: /undo|replay|cancel/i } )
		).toBeNull();
		expect(
			screen.queryByRole( 'link', { name: /Scheduled Actions/i } )
		).toBeNull();
		expect( screen.queryByRole( 'progressbar' ) ).toBeNull();
	}
);

it( 'a closed inline History receipt retains supported Undo', () => {
	const BatchCard =
		require( '../../assets/src/panels/history/batch-card' ).default;
	render(
		<BatchCard
			batch={ base( 80, {
				source: 'inline',
				inline_receipt: {
					receipt_state: 'closed',
					write_status: 'complete',
					steps: [],
					batch_id: 80,
					replayed: true,
				},
			} ) }
			what="Change"
			who="Mia"
			byKey={ {} }
			onChanged={ () => {} }
			onNotice={ () => {} }
		/>
	);
	expect(
		screen
			.getByRole( 'button', { name: 'Undo', exact: true } )
			.getAttribute( 'aria-disabled' )
	).not.toBe( 'true' );
} );
