/**
 * T103: the export dialog (ticket 10; SPEC §10). Export CSV opens a dialog
 * whose rows are the exact selection by default or everything matching the
 * current filters, whose columns are the view's, and whose identifier switch
 * is off at every opening. Its summary comes from `POST /export` with
 * `summary`, asked again whenever the choice, the token or the switch
 * changes; the file comes from a form posted into a hidden frame, with the
 * nonce and the token in the body only. Failures are said in plain words
 * (ticket 11).
 */
import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';

import { ExportControl } from '../../assets/src/panels/catalogue/views';
import {
	formFields,
	postDownload,
} from '../../assets/src/panels/catalogue/views/export';
import {
	defaultView,
	descriptorsOf,
} from '../../assets/src/panels/catalogue/fields';
import { toQuery } from '../../assets/src/panels/catalogue/query';
import { waitingReason } from '../../assets/src/panels/catalogue/selection/model';
import {
	coreJson,
	expectNoRawText,
	expectPlain,
	html500,
} from './error-fixtures';

jest.mock( '@wordpress/api-fetch', () => {
	const apiFetch = jest.fn();
	apiFetch.use = jest.fn();
	apiFetch.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: apiFetch };
} );

const apiFetch = require( '@wordpress/api-fetch' ).default;
const { REGISTRY } = require( './catalogue-fixture' );

const BASE = defaultView( REGISTRY, { settings: { rows_per_page: 25 } } );
const VIEW = {
	...BASE,
	page: 3,
	search: 'golf',
	sort: { field: 'regular_price', direction: 'desc' },
	fields: [ 'thumbnail', 'stock_status', 'sku', 'regular_price' ],
	mode: 'products',
};
const CATEGORY = { category_ids: { operator: 'isAny', value: [ 3, 4 ] } };
const QUERY = toQuery( VIEW, descriptorsOf( REGISTRY ), CATEGORY );
const EMPTY_VIEW = { ...VIEW, search: '', page: 1 };
const EMPTY_QUERY = toQuery( EMPTY_VIEW, descriptorsOf( REGISTRY ), null );
const COLUMNS = [ 'name', 'stock_status', 'sku', 'regular_price' ];

const SELECTED = {
	kind: 'snapshot',
	token: 'v2.token-one',
	key: 'k1',
	count: 28,
	byType: { products: 25, variations: 3 },
	waiting: '',
};
const NOTHING = {
	kind: 'snapshot',
	token: '',
	key: '',
	count: 0,
	byType: { products: 0, variations: 0 },
	waiting: '',
};

/**
 * A summary answer.
 *
 * @param {Object} over Fields to change.
 * @return {Object} Answer.
 */
function summary( over = {} ) {
	return {
		rows: 26,
		left_out: 2,
		guarded_identifiers: 0,
		guarded_names: 0,
		whole_catalogue: false,
		filename: 'catalogue-selected-2026-09-29-1200.csv',
		...over,
	};
}

/**
 * A promise the test settles.
 *
 * @return {Object} `{ promise, resolve, reject }`.
 */
function deferred() {
	let resolve;
	let reject;
	const promise = new Promise( ( yes, no ) => {
		resolve = yes;
		reject = no;
	} );
	return { promise, resolve, reject };
}

/** The summary requests apiFetch was asked for. */
const summaries = () =>
	apiFetch.mock.calls
		.map( ( [ options ] ) => options )
		.filter( ( options ) => options.data?.summary === true );

function setup( props = {}, answer = () => Promise.resolve( summary() ) ) {
	apiFetch.mockImplementation( answer );
	const started = [];
	const start = jest.fn( ( fields, handlers ) => {
		started.push( { fields, ...handlers } );
		return jest.fn();
	} );
	const all = {
		query: QUERY,
		view: VIEW,
		registry: REGISTRY,
		selection: SELECTED,
		onRenewSelection: jest.fn( () => Promise.resolve() ),
		getNonce: () => 'nonce00001',
		start,
		...props,
	};
	const view = render( <ExportControl { ...all } /> );
	const rerender = ( more ) =>
		view.rerender( <ExportControl { ...all } { ...more } /> );
	return { start, started, rerender, props: all };
}

function open() {
	const button = screen.getByRole( 'button', { name: 'Export CSV' } );
	button.focus();
	fireEvent.click( button );
	return screen.getByRole( 'dialog', { name: 'Export CSV' } );
}

const download = () => screen.getByRole( 'button', { name: 'Download' } );
const isDisabled = ( button ) =>
	button.disabled || button.getAttribute( 'aria-disabled' ) === 'true';

afterEach( () => {
	apiFetch.mockReset();
	delete apiFetch.nonceMiddleware;
	jest.useRealTimers();
	jest.restoreAllMocks();
} );

describe( 'the export dialog', () => {
	it( 'chooses Selected when anything is selected, reading the envelope’s count and types', async () => {
		setup();
		const dialog = open();
		const selected = within( dialog ).getByRole( 'radio', {
			name: 'Selected: 25 products and 3 variations',
		} );
		expect( selected.checked ).toBe( true );
		expect(
			within( dialog ).getByRole( 'radio', {
				name: 'All matching the current filters',
			} ).checked
		).toBe( false );
		await waitFor( () => expect( summaries() ).toHaveLength( 1 ) );
		const [ asked ] = summaries();
		expect( asked.method ).toBe( 'POST' );
		expect( asked.url ).not.toContain( 'v2.' );
		expect( asked.data ).toEqual( {
			selection: 'v2.token-one',
			columns: COLUMNS,
			identifiers: false,
			summary: true,
		} );
	} );

	it( 'shows Selected as unavailable with "Nothing selected" and uses the filter choice when nothing is selected', async () => {
		setup( { selection: NOTHING } );
		const dialog = open();
		const selected = within( dialog ).getByRole( 'radio', {
			name: 'Selected',
		} );
		expect( selected.disabled ).toBe( true );
		expect( selected.checked ).toBe( false );
		expect( within( dialog ).getByText( 'Nothing selected' ) ).toBeTruthy();
		expect(
			within( dialog ).getByRole( 'radio', {
				name: 'All matching the current filters',
			} ).checked
		).toBe( true );
		await waitFor( () => expect( summaries() ).toHaveLength( 1 ) );
		const { data } = summaries()[ 0 ];
		expect( data.selection ).toBeUndefined();
		expect( data ).toMatchObject( {
			search: 'golf',
			orderby: [ 'regular_price' ],
			order: [ 'desc' ],
			category_ids: { operator: 'isAny', value: [ 3, 4 ] },
			columns: COLUMNS,
			identifiers: false,
			summary: true,
		} );
		expect( data.page ).toBeUndefined();
		expect( data.per_page ).toBeUndefined();
	} );

	it( 'keeps Download waiting with the reason while the selection is saving', async () => {
		const { rerender } = setup( {
			selection: {
				...SELECTED,
				waiting: waitingReason( { status: 'saving' } ),
			},
		} );
		const dialog = open();
		expect(
			within( dialog ).getByRole( 'radio', {
				name: 'Selected: 25 products and 3 variations',
			} ).checked
		).toBe( true );
		expect(
			within( dialog ).getByText(
				'Your selection is still being saved. Download is available once it is.'
			)
		).toBeTruthy();
		expect( isDisabled( download() ) ).toBe( true );
		expect( summaries() ).toHaveLength( 0 );
		rerender( { selection: { ...SELECTED, token: 'v2.token-two' } } );
		await waitFor( () => expect( isDisabled( download() ) ).toBe( false ) );
		expect( summaries() ).toHaveLength( 1 );
		expect( summaries()[ 0 ].data.selection ).toBe( 'v2.token-two' );
		expect( screen.queryByText( /still being saved/ ) ).toBeNull();
		// A reason the dialog has no words of its own for is shown as it is.
		rerender( {
			selection: {
				...SELECTED,
				token: 'v2.token-two',
				waiting: 'Sign in again, then try again.',
			},
		} );
		expect(
			screen.getByText( 'Sign in again, then try again.' )
		).toBeTruthy();
		expect( isDisabled( download() ) ).toBe( true );
	} );

	it( 'opened while the first ticks are still being saved, it follows the selection once saved instead of the filter (1.3.4)', async () => {
		const SAVING = waitingReason( { status: 'saving' } );
		const { rerender } = setup( {
			selection: { ...NOTHING, waiting: SAVING },
		} );
		const dialog = open();
		const filter = () =>
			within( dialog ).getByRole( 'radio', {
				name: 'All matching the current filters',
			} );
		expect( filter().checked ).toBe( false );
		expect(
			within( dialog ).getByText(
				'Your selection is still being saved. Download is available once it is.'
			)
		).toBeTruthy();
		expect( within( dialog ).queryByText( 'Nothing selected' ) ).toBeNull();
		expect( isDisabled( download() ) ).toBe( true );
		expect( summaries() ).toHaveLength( 0 );

		// One tick saved, the next still on its way: still the selection, still waiting.
		rerender( {
			selection: {
				...SELECTED,
				token: 'v2.token-two',
				count: 2,
				byType: { products: 2, variations: 0 },
				waiting: SAVING,
			},
		} );
		expect(
			within( dialog ).getByRole( 'radio', {
				name: 'Selected: 2 products',
			} ).checked
		).toBe( true );
		expect( isDisabled( download() ) ).toBe( true );

		// Every tick saved: the dialog reads the selection as it is now.
		rerender( {
			selection: {
				...SELECTED,
				token: 'v2.token-three',
				count: 3,
				byType: { products: 3, variations: 0 },
			},
		} );
		expect(
			within( dialog ).getByRole( 'radio', {
				name: 'Selected: 3 products',
			} ).checked
		).toBe( true );
		expect( filter().checked ).toBe( false );
		await waitFor( () => expect( isDisabled( download() ) ).toBe( false ) );
		expect( summaries().map( ( asked ) => asked.data.selection ) ).toEqual(
			[ 'v2.token-three' ]
		);
	} );

	it( 'chooses the filter when the save leaves nothing selected, then keeps whichever choice it made (1.3.4)', async () => {
		const SAVING = waitingReason( { status: 'saving' } );
		const filter = ( dialog ) =>
			within( dialog ).getByRole( 'radio', {
				name: 'All matching the current filters',
			} );
		const { rerender } = setup( {
			selection: { ...SELECTED, waiting: SAVING },
		} );
		const dialog = open();
		rerender( { selection: NOTHING } );
		expect( filter( dialog ).checked ).toBe( true );
		// Made once: a selection arriving later does not move it.
		rerender( { selection: SELECTED } );
		expect( filter( dialog ).checked ).toBe( true );
		await waitFor( () =>
			expect( summaries().length ).toBeGreaterThan( 0 )
		);
		await waitFor( () => expect( isDisabled( download() ) ).toBe( false ) );
	} );

	it( 'keeps a choice the operator made while the selection was being saved (1.3.4)', async () => {
		const SAVING = waitingReason( { status: 'saving' } );
		const { rerender } = setup( {
			selection: { ...SELECTED, waiting: SAVING },
		} );
		const dialog = open();
		fireEvent.click(
			within( dialog ).getByRole( 'radio', {
				name: 'All matching the current filters',
			} )
		);
		rerender( { selection: SELECTED } );
		expect(
			within( dialog ).getByRole( 'radio', {
				name: 'All matching the current filters',
			} ).checked
		).toBe( true );
		await waitFor( () => expect( isDisabled( download() ) ).toBe( false ) );
		expect( summaries().pop().data.selection ).toBeUndefined();
	} );

	it( 'restores a lapsed selection through onRenewSelection and asks for the summary again', async () => {
		const renewed = deferred();
		const onRenewSelection = jest.fn( () => renewed.promise );
		let calls = 0;
		const { rerender } = setup( { onRenewSelection }, () => {
			calls += 1;
			return calls === 1
				? Promise.reject( {
						code: 'bulkcat_selection_renew_required',
						message: 'Your selection needs renewing.',
						data: { status: 409 },
					} )
				: Promise.resolve( summary() );
		} );
		open();
		await waitFor( () =>
			expect( onRenewSelection ).toHaveBeenCalledTimes( 1 )
		);
		expect( isDisabled( download() ) ).toBe( true );
		rerender( {
			onRenewSelection,
			selection: { ...SELECTED, token: 'v2.token-renewed' },
		} );
		await act( async () => renewed.resolve() );
		await waitFor( () => expect( isDisabled( download() ) ).toBe( false ) );
		const asked = summaries();
		expect( asked[ asked.length - 1 ].data.selection ).toBe(
			'v2.token-renewed'
		);
		expect( onRenewSelection ).toHaveBeenCalledTimes( 1 );
		expect( document.querySelector( '.bulkcat-error' ) ).toBeNull();
		expect(
			screen.getByText( /The file will have 26 rows\./ )
		).toBeTruthy();
	} );

	it( 'says the selection is gone when the renewal after a lapsed lease finds nothing to keep', async () => {
		const renewed = deferred();
		const onRenewSelection = jest.fn( () => renewed.promise );
		const { rerender } = setup( { onRenewSelection }, ( { data } ) =>
			data.selection
				? Promise.reject( {
						code: 'bulkcat_selection_renew_required',
						message: 'Your selection needs renewing.',
						data: { status: 409 },
					} )
				: Promise.resolve( summary( { left_out: 0 } ) )
		);
		const dialog = open();
		await waitFor( () =>
			expect( onRenewSelection ).toHaveBeenCalledTimes( 1 )
		);
		// The renewal found the selection gone: the session now holds nothing.
		rerender( { onRenewSelection, selection: NOTHING } );
		await act( async () => renewed.resolve() );
		const selected = within( dialog ).getByRole( 'radio', {
			name: 'Selected',
		} );
		expect( selected.checked ).toBe( true );
		expect( selected.disabled ).toBe( true );
		expect( isDisabled( download() ) ).toBe( true );
		expect(
			within( dialog ).getByText(
				'Your selection is no longer kept, so there is nothing selected to export. Choose the products again, or export all matching the current filters.'
			)
		).toBeTruthy();
		expect( summaries() ).toHaveLength( 1 );
		expectNoRawText();
		// The other choice still works.
		fireEvent.click(
			within( dialog ).getByRole( 'radio', {
				name: 'All matching the current filters',
			} )
		);
		await within( dialog ).findByText( 'The file will have 26 rows.' );
		expect( summaries() ).toHaveLength( 2 );
		expect( dialog.textContent ).not.toContain( 'no longer kept' );
		expect( isDisabled( download() ) ).toBe( false );
	} );

	it( 'shows the server’s sentence for an expired selection and keeps Selected chosen', async () => {
		setup( {}, () =>
			Promise.reject( {
				code: 'bulkcat_selection_expired',
				message:
					'This selection is no longer kept. Choose the products again.',
				data: { status: 410 },
			} )
		);
		const dialog = open();
		expect(
			await within( dialog ).findByText(
				'This selection is no longer kept. Choose the products again.'
			)
		).toBeTruthy();
		expect(
			within( dialog ).getByRole( 'radio', {
				name: 'Selected: 25 products and 3 variations',
			} ).checked
		).toBe( true );
		expect( isDisabled( download() ) ).toBe( true );
		expect( summaries() ).toHaveLength( 1 );
	} );

	it( 'opens with the identifiers switch off every time', async () => {
		setup();
		let dialog = open();
		const toggle = within( dialog ).getByRole( 'checkbox', {
			name: 'Include identifiers for re-import',
		} );
		expect( toggle.checked ).toBe( false );
		expect(
			within( dialog ).getByText(
				'Puts ID, SKU, Parent and Type first, so the file can go back through Products → Import to update the same products.'
			)
		).toBeTruthy();
		fireEvent.click( toggle );
		expect( toggle.checked ).toBe( true );
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		dialog = open();
		expect(
			within( dialog ).getByRole( 'checkbox', {
				name: 'Include identifiers for re-import',
			} ).checked
		).toBe( false );
		await waitFor( () =>
			expect( summaries().length ).toBeGreaterThan( 0 )
		);
	} );

	it( 'lists the columns, name first, in view order, without the image', async () => {
		setup();
		const dialog = open();
		expect( dialog.textContent ).toMatch(
			/Columns: Name, Stock status, SKU,? and Regular price, as the table shows them\. Change them with the column chooser\./
		);
		expect( dialog.textContent ).not.toContain( 'Image' );
		await waitFor( () => expect( summaries() ).toHaveLength( 1 ) );
	} );

	it( 'shows the rows and left-out counts for Selected, and the row count for the filter', async () => {
		setup( {}, ( { data } ) =>
			Promise.resolve(
				data.selection
					? summary()
					: summary( {
							rows: 1284,
							left_out: 0,
							filename: 'catalogue-filtered-2026-09-29-1200.csv',
						} )
			)
		);
		const dialog = open();
		expect(
			await within( dialog ).findByText(
				'The file will have 26 rows. 2 selected items will be left out.'
			)
		).toBeTruthy();
		expect(
			within( dialog ).getByText(
				'File name: catalogue-selected-2026-09-29-1200.csv'
			)
		).toBeTruthy();
		fireEvent.click(
			within( dialog ).getByRole( 'radio', {
				name: 'All matching the current filters',
			} )
		);
		expect(
			await within( dialog ).findByText(
				/^The file will have 1\D?284 rows\.$/
			)
		).toBeTruthy();
		expect( dialog.textContent ).not.toContain( 'left out' );
		expect(
			within( dialog ).getByText(
				'File name: catalogue-filtered-2026-09-29-1200.csv'
			)
		).toBeTruthy();
	} );

	it( 'keeps Download disabled for the whole catalogue until it is confirmed, and resets the confirmation on reopening; the confirmation never appears for Selected', async () => {
		setup( { query: EMPTY_QUERY, view: EMPTY_VIEW }, ( { data } ) =>
			Promise.resolve(
				data.selection
					? summary()
					: summary( {
							rows: 90,
							left_out: 0,
							whole_catalogue: true,
							filename: 'catalogue-all-2026-09-29-1200.csv',
						} )
			)
		);
		let dialog = open();
		await waitFor( () => expect( isDisabled( download() ) ).toBe( false ) );
		expect(
			within( dialog ).queryByRole( 'checkbox', {
				name: 'Yes, export the whole catalogue',
			} )
		).toBeNull();
		fireEvent.click(
			within( dialog ).getByRole( 'radio', {
				name: 'All matching the current filters',
			} )
		);
		const confirm = await within( dialog ).findByRole( 'checkbox', {
			name: 'Yes, export the whole catalogue',
		} );
		expect(
			within( dialog ).getByRole( 'radio', {
				name: 'The whole catalogue (90 products)',
			} ).checked
		).toBe( true );
		expect( confirm.checked ).toBe( false );
		expect( isDisabled( download() ) ).toBe( true );
		fireEvent.click( confirm );
		expect( isDisabled( download() ) ).toBe( false );
		// Back to Selected: no confirmation there.
		fireEvent.click(
			within( dialog ).getByRole( 'radio', {
				name: 'Selected: 25 products and 3 variations',
			} )
		);
		expect(
			within( dialog ).queryByRole( 'checkbox', {
				name: 'Yes, export the whole catalogue',
			} )
		).toBeNull();
		// Chosen again, the confirmation starts unticked; ticked, closed and
		// opened again, it is unticked too.
		fireEvent.click(
			within( dialog ).getByRole( 'radio', {
				name: 'The whole catalogue (90 products)',
			} )
		);
		await waitFor( () => expect( summaries() ).toHaveLength( 4 ) );
		expect(
			within( dialog ).getByRole( 'checkbox', {
				name: 'Yes, export the whole catalogue',
			} ).checked
		).toBe( false );
		fireEvent.click(
			within( dialog ).getByRole( 'checkbox', {
				name: 'Yes, export the whole catalogue',
			} )
		);
		expect( isDisabled( download() ) ).toBe( false );
		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel' } ) );
		dialog = open();
		fireEvent.click(
			within( dialog ).getByRole( 'radio', {
				name: 'All matching the current filters',
			} )
		);
		const again = await within( dialog ).findByRole( 'checkbox', {
			name: 'Yes, export the whole catalogue',
		} );
		expect( again.checked ).toBe( false );
		expect( isDisabled( download() ) ).toBe( true );
		await within( dialog ).findByText( /The file will have 90 rows/ );
	} );

	it( 'asks for the summary again when the choice, the token or the switch changes, and ignores a late answer', async () => {
		const pending = [];
		const { rerender } = setup( {}, ( options ) => {
			const answer = deferred();
			pending.push( { options, ...answer } );
			return answer.promise;
		} );
		const dialog = open();
		await waitFor( () => expect( pending ).toHaveLength( 1 ) );
		fireEvent.click(
			within( dialog ).getByRole( 'checkbox', {
				name: 'Include identifiers for re-import',
			} )
		);
		await waitFor( () => expect( pending ).toHaveLength( 2 ) );
		expect( pending[ 1 ].options.data.identifiers ).toBe( true );
		await act( async () =>
			pending[ 1 ].resolve(
				summary( {
					rows: 7,
					left_out: 0,
					filename: 'catalogue-selected-with-ids-2026-09-29-1200.csv',
				} )
			)
		);
		await act( async () =>
			pending[ 0 ].resolve( summary( { rows: 99 } ) )
		);
		expect(
			within( dialog ).getByText(
				'The file will have 7 rows. 0 selected items will be left out.'
			)
		).toBeTruthy();
		expect( dialog.textContent ).not.toContain( '99 rows' );
		rerender( { selection: { ...SELECTED, token: 'v2.token-two' } } );
		await waitFor( () => expect( pending ).toHaveLength( 3 ) );
		expect( pending[ 2 ].options.data.selection ).toBe( 'v2.token-two' );
		expect( isDisabled( download() ) ).toBe( true );
		fireEvent.click(
			within( dialog ).getByRole( 'radio', {
				name: 'All matching the current filters',
			} )
		);
		await waitFor( () => expect( pending ).toHaveLength( 4 ) );
		expect( pending[ 3 ].options.data.selection ).toBeUndefined();
		await act( async () => pending[ 2 ].resolve( summary( { rows: 5 } ) ) );
		expect( dialog.textContent ).not.toContain( '5 rows' );
		expect( isDisabled( download() ) ).toBe( true );
	} );

	it( 'warns with the guarded SKU and name counts only when the switch is on', async () => {
		setup( {}, ( { data } ) =>
			Promise.resolve(
				summary( {
					guarded_identifiers: data.identifiers ? 3 : 0,
					guarded_names: data.identifiers ? 1 : 0,
				} )
			)
		);
		const dialog = open();
		await within( dialog ).findByText( /The file will have 26 rows/ );
		expect( dialog.textContent ).not.toMatch( /SKU that WooCommerce/ );
		fireEvent.click(
			within( dialog ).getByRole( 'checkbox', {
				name: 'Include identifiers for re-import',
			} )
		);
		expect(
			await within( dialog ).findByText(
				'3 rows have a SKU that WooCommerce’s importer would not keep as it is. It starts with -, +, = or @, or has line breaks, repeated or edge spaces, tags or % codes that the importer removes. Correct these SKUs, or remove the leading apostrophe, before re-importing.'
			)
		).toBeTruthy();
		expect(
			within( dialog ).getByText(
				'1 row has a name that starts with -, +, = or @, so the file puts an apostrophe in front of it. WooCommerce’s importer keeps that apostrophe: remove it before re-importing.'
			)
		).toBeTruthy();
		fireEvent.click(
			within( dialog ).getByRole( 'checkbox', {
				name: 'Include identifiers for re-import',
			} )
		);
		await within( dialog ).findByText( /The file will have 26 rows/ );
		expect( dialog.textContent ).not.toMatch( /SKU that WooCommerce/ );
		expect( dialog.textContent ).not.toMatch( /name that starts/ );
	} );

	it( 'posts a form into the hidden frame with the nonce and the token in the body and never in action', async () => {
		const submitted = [];
		jest.spyOn(
			window.HTMLFormElement.prototype,
			'submit'
		).mockImplementation( function () {
			submitted.push( this );
		} );
		setup( { start: undefined } );
		const dialog = open();
		fireEvent.click(
			within( dialog ).getByRole( 'checkbox', {
				name: 'Include identifiers for re-import',
			} )
		);
		await waitFor( () => expect( isDisabled( download() ) ).toBe( false ) );
		fireEvent.click( download() );
		expect( submitted ).toHaveLength( 1 );
		const [ form ] = submitted;
		const frame = document.querySelector( 'iframe.bulkcat-export__frame' );
		expect( frame.hidden ).toBe( true );
		// Sandboxed: no script runs in the answer; the page can still read a
		// refusal (same origin) and the browser can still save the file.
		expect( frame.getAttribute( 'sandbox' ) ).toBe(
			'allow-same-origin allow-downloads'
		);
		expect( form.method ).toBe( 'post' );
		expect( form.getAttribute( 'action' ) ).toBe( 'export' );
		expect( form.target ).toBe( frame.name );
		expect( frame.name ).toMatch( /^bulkcat-export-\d+$/ );
		const fields = Object.fromEntries(
			[ ...form.elements ].map( ( input ) => [ input.name, input.value ] )
		);
		expect( fields ).toEqual( {
			_wpnonce: 'nonce00001',
			selection: 'v2.token-one',
			columns: COLUMNS.join( ',' ),
			identifiers: '1',
		} );
		[ ...form.elements ].forEach( ( input ) =>
			expect( input.type ).toBe( 'hidden' )
		);
		expect( form.getAttribute( 'action' ) ).not.toContain( 'v2.' );
		expect( form.getAttribute( 'action' ) ).not.toContain( 'nonce' );
		expect( window.location.href ).not.toContain( 'v2.' );
	} );

	it( 'posts the filter arguments, and no token, for All matching', async () => {
		const { started } = setup( {}, ( { data } ) =>
			Promise.resolve(
				data.selection ? summary() : summary( { left_out: 0 } )
			)
		);
		const dialog = open();
		fireEvent.click(
			within( dialog ).getByRole( 'radio', {
				name: 'All matching the current filters',
			} )
		);
		await waitFor( () => expect( summaries() ).toHaveLength( 2 ) );
		await waitFor( () => expect( isDisabled( download() ) ).toBe( false ) );
		fireEvent.click( download() );
		expect( started ).toHaveLength( 1 );
		const names = started[ 0 ].fields.map( ( [ name ] ) => name );
		expect( names ).not.toContain( 'selection' );
		expect( started[ 0 ].fields[ 0 ] ).toEqual( [
			'_wpnonce',
			'nonce00001',
		] );
		const fields = new Map( started[ 0 ].fields );
		expect( fields.get( 'mode' ) ).toBe( 'products' );
		expect( fields.get( 'search' ) ).toBe( 'golf' );
		expect( fields.get( 'orderby' ) ).toBe( 'regular_price' );
		expect( fields.get( 'order' ) ).toBe( 'desc' );
		expect( fields.get( 'columns' ) ).toBe( COLUMNS.join( ',' ) );
		expect( fields.get( 'category_ids[operator]' ) ).toBe( 'isAny' );
		expect( fields.get( 'category_ids[value][0]' ) ).toBe( '3' );
		expect( fields.get( 'category_ids[value][1]' ) ).toBe( '4' );
		expect( fields.get( 'identifiers' ) ).toBe( '0' );
		expect( fields.get( 'confirm_all' ) ).toBe( '0' );
	} );

	it( 'closes, returns focus to Export, and stays busy until the frame settles', async () => {
		const { started } = setup();
		const other = document.createElement( 'button' );
		document.body.appendChild( other );
		open();
		await waitFor( () => expect( isDisabled( download() ) ).toBe( false ) );
		fireEvent.click( download() );
		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		const busy = screen.getByRole( 'button', { name: 'Preparing CSV…' } );
		await waitFor( () => expect( document.activeElement ).toBe( busy ) );
		expect( busy.getAttribute( 'aria-disabled' ) ).toBe( 'true' );
		expect( other.disabled ).toBe( false );
		act( () => started[ 0 ].onDone() );
		expect(
			screen.getByRole( 'button', { name: 'Export CSV' } )
		).toBeTruthy();
		other.remove();
	} );

	it( 'closes on Escape and returns focus to Export', async () => {
		setup();
		const dialog = open();
		await within( dialog ).findByText( /The file will have 26 rows/ );
		fireEvent.keyDown( dialog, { key: 'Escape', code: 'Escape' } );
		await waitFor( () =>
			expect( screen.queryByRole( 'dialog' ) ).toBeNull()
		);
		expect( document.activeElement ).toBe(
			screen.getByRole( 'button', { name: 'Export CSV' } )
		);
	} );

	it( 'shows the plain sentence of a JSON refusal in the frame', async () => {
		const { started } = setup();
		open();
		await waitFor( () => expect( isDisabled( download() ) ).toBe( false ) );
		fireEvent.click( download() );
		act( () =>
			started[ 0 ].onError(
				Object.assign(
					new Error(
						'This selection is no longer kept. Choose the products again.'
					),
					{ code: 'bulkcat_selection_expired' }
				)
			)
		);
		expect(
			screen.getByText(
				'This selection is no longer kept. Choose the products again.'
			)
		).toBeTruthy();
		expect( screen.getByText( 'Show details' ) ).toBeTruthy();
		expect( screen.getByText( 'bulkcat_selection_expired' ) ).toBeTruthy();
		// Try again opens the dialog again, where the rows are chosen.
		fireEvent.click( screen.getByRole( 'button', { name: 'Try again' } ) );
		const dialog = screen.getByRole( 'dialog', { name: 'Export CSV' } );
		expect( document.querySelector( '.bulkcat-error' ) ).toBeNull();
		await within( dialog ).findByText( /The file will have 26 rows/ );
	} );

	it( 'gives focus back to Export when the dialog Try again opened is closed, by Cancel or by Escape', async () => {
		const { started } = setup();
		const refuse = ( n ) =>
			act( () =>
				started[ n ].onError(
					Object.assign(
						new Error(
							'This selection is no longer kept. Choose the products again.'
						),
						{ code: 'bulkcat_selection_expired' }
					)
				)
			);
		open();
		await waitFor( () => expect( isDisabled( download() ) ).toBe( false ) );
		fireEvent.click( download() );
		refuse( 0 );
		// A pointer or Enter on Try again focuses it; the notice then goes as the dialog opens.
		let again = screen.getByRole( 'button', { name: 'Try again' } );
		again.focus();
		fireEvent.click( again );
		let dialog = screen.getByRole( 'dialog', { name: 'Export CSV' } );
		await within( dialog ).findByText( /The file will have 26 rows/ );
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Cancel' } )
		);
		await waitFor( () =>
			expect( screen.queryByRole( 'dialog' ) ).toBeNull()
		);
		expect( document.activeElement ).toBe(
			screen.getByRole( 'button', { name: 'Export CSV' } )
		);
		// The same through Escape, after a second refusal.
		fireEvent.click( screen.getByRole( 'button', { name: 'Export CSV' } ) );
		dialog = screen.getByRole( 'dialog', { name: 'Export CSV' } );
		await waitFor( () => expect( isDisabled( download() ) ).toBe( false ) );
		fireEvent.click( download() );
		refuse( 1 );
		again = screen.getByRole( 'button', { name: 'Try again' } );
		again.focus();
		fireEvent.click( again );
		dialog = screen.getByRole( 'dialog', { name: 'Export CSV' } );
		await within( dialog ).findByText( /The file will have 26 rows/ );
		fireEvent.keyDown( dialog, { key: 'Escape', code: 'Escape' } );
		await waitFor( () =>
			expect( screen.queryByRole( 'dialog' ) ).toBeNull()
		);
		expect( document.activeElement ).toBe(
			screen.getByRole( 'button', { name: 'Export CSV' } )
		);
	} );
} );

describe( 'failures in plain words (ticket 11)', () => {
	async function refusedWith( error ) {
		const { started } = setup();
		open();
		await waitFor( () => expect( isDisabled( download() ) ).toBe( false ) );
		fireEvent.click( download() );
		act( () => started[ 0 ].onError( error ) );
		return document.querySelector( '.bulkcat-error' );
	}

	it( 'words a refusal from core in its own sentence', async () => {
		await refusedWith(
			Object.assign( new Error( 'Cookie check failed' ), {
				code: 'rest_cookie_invalid_nonce',
			} )
		);
		expect(
			screen.getByText(
				'The CSV file could not be made. Reload the page and try again.'
			)
		).toBeTruthy();
		expect( screen.queryByText( 'Cookie check failed' ) ).toBeNull();
	} );

	it( 'says a server’s HTML error page in plain words, never its text', async () => {
		const box = await refusedWith( html500() );
		expectPlain(
			box,
			'The CSV file could not be made. Reload the page and try again.'
		);
		fireEvent.click( screen.getByText( 'Show details' ) );
		expect( box.textContent ).toContain( 'internal_server_error' );
		expectNoRawText();
	} );

	it( 'says a JSON error that is not the plugin’s own refusal in plain words, details included', async () => {
		const box = await refusedWith( coreJson() );
		expectPlain(
			box,
			'The CSV file could not be made. Reload the page and try again.'
		);
		expect( box.textContent ).toContain( 'rest_forbidden' );
		expectNoRawText();
	} );

	it( 'says a summary that failed on the server in plain words, and asks again', async () => {
		let calls = 0;
		setup( {}, () => {
			calls += 1;
			return calls === 1
				? Promise.reject( html500() )
				: Promise.resolve( summary() );
		} );
		const dialog = open();
		const box = await waitFor( () => {
			const found = dialog.querySelector( '.bulkcat-error' );
			expect( found ).not.toBeNull();
			return found;
		} );
		expectPlain( box, 'The rows could not be counted. Try again.' );
		expectNoRawText();
		expect( isDisabled( download() ) ).toBe( true );
		fireEvent.click(
			within( box ).getByRole( 'button', { name: 'Try again' } )
		);
		await within( dialog ).findByText( /The file will have 26 rows/ );
		expect( isDisabled( download() ) ).toBe( false );
	} );
} );

describe( 'the download form', () => {
	it( 'writes lists as one field, switches as 1 or 0, and nested filters as PHP reads them', () => {
		expect(
			formFields(
				{
					columns: [ 'a', 'b' ],
					identifiers: true,
					search: 'golf & tee',
					stock_status: { operator: 'isAny', value: [ 'instock' ] },
				},
				{ nonce: 'n1', confirmAll: true }
			)
		).toEqual( [
			[ '_wpnonce', 'n1' ],
			[ 'columns', 'a,b' ],
			[ 'identifiers', '1' ],
			[ 'search', 'golf & tee' ],
			[ 'stock_status[operator]', 'isAny' ],
			[ 'stock_status[value][0]', 'instock' ],
			[ 'confirm_all', '1' ],
		] );
		expect(
			formFields( { selection: 'v2.x', columns: [ 'a' ] }, { nonce: '' } )
		).toEqual( [
			[ 'selection', 'v2.x' ],
			[ 'columns', 'a' ],
		] );
	} );

	it( 'ignores the frame’s first blank page, reads a refusal, and removes the frame and form', () => {
		const onDone = jest.fn();
		const onError = jest.fn();
		const submit = jest.fn();
		const stop = postDownload( [ [ 'selection', 'v2.x' ] ], {
			onDone,
			onError,
			submit,
			action: 'https://example.test/wp-json/bulkcat/v1/export',
		} );
		expect( submit ).toHaveBeenCalledTimes( 1 );
		const frame = document.querySelector( 'iframe.bulkcat-export__frame' );
		const form = document.querySelector( 'form.bulkcat-export__form' );
		expect( form.getAttribute( 'action' ) ).toBe(
			'https://example.test/wp-json/bulkcat/v1/export'
		);
		// The first blank page is not a report.
		frame.dispatchEvent( new window.Event( 'load' ) );
		expect( onError ).not.toHaveBeenCalled();
		expect( onDone ).not.toHaveBeenCalled();
		// The answer to the post, at the route's address.
		const loaded = frame.contentWindow;
		Object.defineProperty( frame, 'contentWindow', {
			configurable: true,
			get: () => ( {
				location: {
					href: 'https://example.test/wp-json/bulkcat/v1/export',
				},
			} ),
		} );
		frame.contentDocument.body.textContent = JSON.stringify( {
			code: 'bulkcat_export_confirm_all',
			message: 'Confirm the whole catalogue.',
		} );
		frame.dispatchEvent( new window.Event( 'load' ) );
		expect( loaded ).toBeTruthy();
		expect( onError ).toHaveBeenCalledTimes( 1 );
		expect( onError.mock.calls[ 0 ][ 0 ].code ).toBe(
			'bulkcat_export_confirm_all'
		);
		expect( onDone ).not.toHaveBeenCalled();
		stop();
		expect(
			document.querySelector( 'iframe.bulkcat-export__frame' )
		).toBeNull();
		expect(
			document.querySelector( 'form.bulkcat-export__form' )
		).toBeNull();
	} );

	it( 'settles when the browser reports nothing', () => {
		jest.useFakeTimers();
		const onDone = jest.fn();
		const stop = postDownload( [], {
			onDone,
			onError: jest.fn(),
			submit: jest.fn(),
			settle: 1000,
		} );
		jest.advanceTimersByTime( 999 );
		expect( onDone ).not.toHaveBeenCalled();
		jest.advanceTimersByTime( 1 );
		expect( onDone ).toHaveBeenCalledTimes( 1 );
		stop();
	} );
} );
