/**
 * T46: inline cell editing. A cell the registry marks inline-editable opens
 * on click or Enter, commits on Enter or blur and reverts on Escape; a value
 * the registry's rules refuse (client pre-check or the server's 400) shows
 * the §4.8 sentence in place with the typed value kept; a commit is one PATCH,
 * the row is pending until it returns, a failure keeps the edit open, and
 * the answer's item replaces the row. A field that does not apply says why.
 */
import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
} from '@testing-library/react';

import Catalogue from '../../assets/src/panels/catalogue';

jest.mock( '@wordpress/api-fetch', () => {
	const apiFetch = jest.fn();
	apiFetch.use = jest.fn();
	apiFetch.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: apiFetch };
} );

const apiFetch = require( '@wordpress/api-fetch' ).default;
const { getQueryArgs } = require( '@wordpress/url' );
const { REGISTRY, ROWS, CATEGORIES, page } = require( './catalogue-fixture' );

const SETTINGS = { settings: { rows_per_page: 25 } };

// Rendering the whole grid is slow when every suite runs in parallel.
jest.setTimeout( 20000 );
const TEE = 'Classic Cotton T-Shirt';

const ITEMS = [
	{ ...ROWS[ 0 ], type: 'simple' },
	{ ...ROWS[ 1 ], type: 'simple' },
	{
		...ROWS[ 0 ],
		id: 13,
		name: 'Hoodie',
		sku: 'HOD-130',
		type: 'variable',
		regular_price: '',
	},
	// T92: products switched Off through Status, one changed elsewhere since.
	{
		...ROWS[ 0 ],
		id: 14,
		name: 'Wool Beanie',
		sku: 'BNE-140',
		type: 'simple',
		status: 'draft',
		off_state: {
			state: 'off',
			saved: 'private',
			saved_label: 'Private',
			switched_off_gmt: '2026-09-28 14:03:11',
			batch_id: 418,
		},
	},
	{
		...ROWS[ 0 ],
		id: 15,
		name: 'Rain Jacket',
		sku: 'JKT-150',
		type: 'simple',
		status: 'private',
		off_state: {
			state: 'changed_outside',
			saved: 'publish',
			saved_label: 'Published',
			switched_off_gmt: '2026-09-28 14:03:11',
			batch_id: 418,
		},
	},
	// T114: a scheduled product (post status future) reads as Scheduled.
	{
		...ROWS[ 0 ],
		id: 17,
		name: 'Linen Scarf',
		sku: 'SCF-170',
		type: 'simple',
		status: 'future',
	},
	{
		...ROWS[ 0 ],
		id: 16,
		name: 'Sun Hat',
		sku: 'HAT-160',
		type: 'simple',
		status: 'draft',
		off_state: {
			state: 'off',
			saved: 'publish',
			saved_label: 'Published',
			switched_off_gmt: '2026-09-28 14:03:11',
			batch_id: 418,
		},
	},
];

let storedItems;
let patch;

beforeEach( () => {
	storedItems = [ ...ITEMS ];
	apiFetch.mockReset();
	patch = jest.fn();
	apiFetch.mockImplementation( ( { url, method, data } ) => {
		const path = url.split( '?' )[ 0 ];
		if ( method === 'PATCH' ) {
			return patch( path, data, getQueryArgs( url ) ).then(
				( answer ) => {
					if ( answer?.item ) {
						storedItems = storedItems.map( ( row ) =>
							row.id === answer.item.id
								? { ...row, ...answer.item }
								: row
						);
					}
					return answer;
				}
			);
		}
		if ( path === 'fields' ) {
			return Promise.resolve( REGISTRY );
		}
		if ( path === 'products' ) {
			return Promise.resolve( page( storedItems ) );
		}
		if ( path === 'categories' ) {
			return Promise.resolve( CATEGORIES );
		}
		return new Promise( () => {} );
	} );
} );

const patches = () =>
	apiFetch.mock.calls.filter( ( [ options ] ) => options.method === 'PATCH' );

async function renderLoaded() {
	render( <Catalogue settings={ SETTINGS } /> );
	await screen.findByText( 'Hoodie' );
}

const trigger = ( field, name = TEE ) =>
	screen.getByRole( 'button', { name: `Edit ${ field }, ${ name }` } );
const editor = ( field, name = TEE ) =>
	screen.getByRole( 'textbox', { name: `${ field }, ${ name }` } );

function answerWith( changes ) {
	return {
		item: { ...ITEMS[ 0 ], ...changes },
		batch_id: 21,
		status: 'complete',
		results: [],
	};
}

describe( 'inline cell editing', () => {
	it( 'opens on click and commits on Enter with one PATCH, replacing the row', async () => {
		patch.mockResolvedValue( answerWith( { sku: 'TSH-101' } ) );
		await renderLoaded();
		fireEvent.click( trigger( 'SKU' ) );
		const input = editor( 'SKU' );
		expect( input.value ).toBe( 'TSH-100' );
		fireEvent.change( input, { target: { value: 'TSH-101' } } );
		fireEvent.keyDown( input, { key: 'Enter' } );
		await waitFor( () => expect( patches() ).toHaveLength( 1 ) );
		expect( patch ).toHaveBeenCalledWith(
			'products/11',
			{ sku: 'TSH-101' },
			expect.objectContaining( {
				fields: expect.arrayContaining( [ 'sku', 'id' ] ),
			} )
		);
		expect( await screen.findByText( 'TSH-101' ) ).toBeTruthy();
		expect( screen.getByText( 'Saved' ) ).toBeTruthy();
		expect(
			screen.queryByRole( 'textbox', { name: `SKU, ${ TEE }` } )
		).toBeNull();
		expect( document.activeElement ).toBe( trigger( 'SKU' ) );
	} );

	it( 'opens on Enter and commits on blur', async () => {
		patch.mockResolvedValue( answerWith( { regular_price: '14.00' } ) );
		await renderLoaded();
		fireEvent.keyDown( trigger( 'Regular price' ), { key: 'Enter' } );
		const input = editor( 'Regular price' );
		expect( input.value ).toBe( '12.50' );
		fireEvent.change( input, { target: { value: '14' } } );
		fireEvent.blur( input );
		await waitFor( () => expect( patches() ).toHaveLength( 1 ) );
		expect( patch.mock.calls[ 0 ][ 1 ] ).toEqual( { regular_price: '14' } );
		expect( await screen.findByText( '14.00' ) ).toBeTruthy();
	} );

	it( 'reverts on Escape without a request', async () => {
		await renderLoaded();
		fireEvent.click( trigger( 'SKU' ) );
		const input = editor( 'SKU' );
		fireEvent.change( input, { target: { value: 'NOPE' } } );
		fireEvent.keyDown( input, { key: 'Escape' } );
		expect(
			screen.queryByRole( 'textbox', { name: `SKU, ${ TEE }` } )
		).toBeNull();
		expect( screen.getByText( 'TSH-100' ) ).toBeTruthy();
		expect( patches() ).toHaveLength( 0 );
		expect( document.activeElement ).toBe( trigger( 'SKU' ) );
	} );

	it( 'refuses a value the registry rules reject, in place, keeping what was typed', async () => {
		await renderLoaded();
		fireEvent.click( trigger( 'Regular price' ) );
		const input = editor( 'Regular price' );
		fireEvent.change( input, { target: { value: '12,50,00' } } );
		fireEvent.keyDown( input, { key: 'Enter' } );
		expect(
			screen.getByText(
				'Regular price has to be a price of 0 or more, like 12.50, or left empty.'
			)
		).toBeTruthy();
		expect( editor( 'Regular price' ).value ).toBe( '12,50,00' );
		expect( editor( 'Regular price' ).getAttribute( 'aria-invalid' ) ).toBe(
			'true'
		);
		fireEvent.blur( editor( 'Regular price' ) );
		expect( editor( 'Regular price' ) ).toBeTruthy();
		expect( patches() ).toHaveLength( 0 );
	} );

	it( 'shows the server 400 naming the field in place and stays in edit', async () => {
		patch.mockRejectedValue( {
			code: 'rest_invalid_param',
			message: 'Invalid parameter(s): sku',
			data: {
				status: 400,
				params: {
					sku: 'A product with the SKU GLF-120 already exists, so this SKU was not applied.',
				},
			},
		} );
		await renderLoaded();
		fireEvent.click( trigger( 'SKU' ) );
		fireEvent.change( editor( 'SKU' ), { target: { value: 'GLF-120' } } );
		fireEvent.keyDown( editor( 'SKU' ), { key: 'Enter' } );
		expect(
			await screen.findByText(
				'A product with the SKU GLF-120 already exists, so this SKU was not applied.'
			)
		).toBeTruthy();
		expect( editor( 'SKU' ).value ).toBe( 'GLF-120' );
	} );

	it( 'keeps a transport-uncertain row locked without suggesting a blind retry', async () => {
		let fail;
		patch.mockReturnValue(
			new Promise( ( resolve, reject ) => {
				fail = reject;
			} )
		);
		await renderLoaded();
		fireEvent.click( trigger( 'SKU' ) );
		fireEvent.change( editor( 'SKU' ), { target: { value: 'TSH-200' } } );
		fireEvent.keyDown( editor( 'SKU' ), { key: 'Enter' } );
		await waitFor( () => expect( patches() ).toHaveLength( 1 ) );
		expect( editor( 'SKU' ).getAttribute( 'aria-busy' ) ).toBe( 'true' );
		// The rest of the row is pending too.
		const row = editor( 'SKU' ).closest( 'tr' );
		expect(
			row.querySelector( '.bulkcat-cell.is-pending' )
		).not.toBeNull();
		await act( async () => {
			fail( {
				code: 'internal_server_error',
				message: 'Boom',
				data: { status: 500 },
			} );
		} );
		expect(
			screen.getAllByText( /outcome.*unconfirmed/i ).length
		).toBeGreaterThan( 0 );
		expect(
			row.querySelector( '.bulkcat-cell.is-pending' )
		).not.toBeNull();
		fireEvent.click( trigger( 'SKU' ) );
		expect( patches() ).toHaveLength( 1 );
		expect(
			screen.getByRole( 'button', { name: 'Read current product' } )
		).toBeTruthy();
	} );

	it( 'says a failure that is not the plugin’s own refusal in plain words, never its text (T114)', async () => {
		const { expectPlain } = require( './error-fixtures' );
		// A 4xx HTML page from something in front of WordPress, in the message (T114, ticket 11 item 3).
		patch.mockRejectedValue(
			Object.assign(
				new Error(
					'<p>There has been a critical error on this website.</p><p><a href="https://wordpress.org/">Learn more about troubleshooting WordPress.</a></p>'
				),
				{
					code: 'forbidden',
					message:
						'<p>There has been a critical error on this website.</p><p><a href="https://wordpress.org/">Learn more about troubleshooting WordPress.</a></p>',
					data: { status: 403 },
				}
			)
		);
		await renderLoaded();
		fireEvent.click( trigger( 'SKU' ) );
		fireEvent.change( editor( 'SKU' ), { target: { value: 'TSH-300' } } );
		fireEvent.keyDown( editor( 'SKU' ), { key: 'Enter' } );
		const sentence = await screen.findByText(
			`We could not save SKU for ${ TEE }. Try again in a moment.`
		);
		expectPlain( sentence, 'Try again in a moment.' );
		expect( document.body.textContent ).not.toContain( 'critical error' );

		// Core's own JSON refusal is not shown in its words either; a plugin refusal is.
		patch.mockRejectedValue( {
			code: 'rest_forbidden',
			message: 'Sorry, you are not allowed to do that.',
			data: { status: 403 },
		} );
		fireEvent.keyDown( editor( 'SKU' ), { key: 'Enter' } );
		await waitFor( () => expect( patches() ).toHaveLength( 2 ) );
		// The request has started; wait for its rejection to finish before
		// submitting again, as the editor ignores Enter while it is saving.
		await waitFor( () =>
			expect( editor( 'SKU' ).getAttribute( 'aria-busy' ) ).toBeNull()
		);
		expect( document.body.textContent ).not.toContain(
			'not allowed to do that'
		);
		patch.mockRejectedValue( {
			code: 'bulkcat_cannot_edit',
			message:
				'You do not have permission to change this product. Ask an administrator for access to it.',
			data: { status: 403 },
		} );
		fireEvent.keyDown( editor( 'SKU' ), { key: 'Enter' } );
		expect(
			await screen.findByText(
				`We could not save SKU for ${ TEE }. You do not have permission to change this product. Ask an administrator for access to it.`
			)
		).toBeTruthy();
	} );

	it( 'says why a field does not apply instead of opening', async () => {
		await renderLoaded();
		// T67 decides first: a variable product's price is its locked,
		// derived cell with the reason, never an editor (variations/README).
		expect(
			screen.queryByRole( 'button', {
				name: 'Edit Regular price, Hoodie',
			} )
		).toBeNull();
		const locked = screen
			.getByText( 'Hoodie' )
			.closest( 'tr' )
			.querySelector( '[data-bulkcat-editable="false"]' );
		expect( locked.getAttribute( 'data-bulkcat-cell' ) ).toBe( 'derived' );
		expect( locked.getAttribute( 'title' ) ).toMatch( /variations/ );
		fireEvent.keyDown( locked, { key: 'Enter' } );
		expect(
			screen.queryByRole( 'textbox', { name: 'Regular price, Hoodie' } )
		).toBeNull();
		expect( patches() ).toHaveLength( 0 );
	} );
} );

describe( 'native Status in the cell', () => {
	const select = ( name ) =>
		screen.getByRole( 'combobox', { name: `Status, ${ name }` } );
	const choose = ( name, value ) => {
		fireEvent.click( trigger( 'Status', name ) );
		fireEvent.change( select( name ), { target: { value } } );
		fireEvent.keyDown( select( name ), { key: 'Enter' } );
	};

	it( 'saves Published once despite a legacy Private record and displays the returned Scheduled state', async () => {
		patch.mockResolvedValue( {
			item: {
				...ITEMS.find( ( row ) => row.id === 14 ),
				status: 'future',
				off_state: null,
			},
			batch_id: 31,
			status: 'complete',
			results: [
				{
					field: 'status',
					state: 'changed',
					new: 'future',
					context: {
						requested_status: 'publish',
						effective_status: 'future',
					},
				},
			],
		} );
		await renderLoaded();
		expect( trigger( 'Status', 'Wool Beanie' ).textContent ).toBe(
			'Draft'
		);
		choose( 'Wool Beanie', 'publish' );
		await waitFor( () =>
			expect( trigger( 'Status', 'Wool Beanie' ).textContent ).toBe(
				'Scheduled'
			)
		);
		expect( patches() ).toHaveLength( 1 );
		expect( patch.mock.calls[ 0 ][ 1 ] ).toEqual( { status: 'publish' } );
		expect(
			screen.queryByRole( 'button', {
				name: /Switch On|Keep current status/,
			} )
		).toBeNull();
	} );

	it( 'saves Draft once without an Off confirmation', async () => {
		patch.mockResolvedValue( {
			item: { ...ITEMS[ 0 ], status: 'draft' },
			batch_id: 32,
			status: 'complete',
			results: [],
		} );
		await renderLoaded();
		choose( TEE, 'draft' );
		await waitFor( () =>
			expect( trigger( 'Status' ).textContent ).toBe( 'Draft' )
		);
		expect( patches() ).toHaveLength( 1 );
		expect( patch.mock.calls[ 0 ][ 1 ] ).toEqual( { status: 'draft' } );
		expect(
			document.querySelector( '.bulkcat-status-confirm' )
		).toBeNull();
	} );

	it( 'keeps a stored schedule readable without offering it as a writable target', async () => {
		await renderLoaded();
		fireEvent.click( trigger( 'Status', 'Linen Scarf' ) );
		const control = select( 'Linen Scarf' );
		expect( control.selectedOptions[ 0 ].textContent ).toBe( 'Scheduled' );
		expect(
			Array.from( control.options )
				.filter( ( option ) => ! option.disabled )
				.map( ( option ) => option.value )
		).toEqual( [ 'publish', 'draft', 'pending', 'private' ] );
		fireEvent.keyDown( control, { key: 'Enter' } );
		expect( patches() ).toHaveLength( 0 );
		fireEvent.click( trigger( 'Status', 'Linen Scarf' ) );
		fireEvent.blur( select( 'Linen Scarf' ) );
		expect( patches() ).toHaveLength( 0 );
	} );

	it( 'shows safe server refusals without a restoration retry', async () => {
		patch.mockRejectedValue( {
			code: 'bulkcat_variation_password_unsupported',
			message:
				'This change was left alone to protect the stored password.',
			data: { status: 400, field: 'status' },
		} );
		await renderLoaded();
		choose( 'Wool Beanie', 'publish' );
		expect( ( await screen.findByRole( 'alert' ) ).textContent ).toContain(
			'left alone to protect the stored password'
		);
		expect( patches() ).toHaveLength( 1 );
		expect( select( 'Wool Beanie' ).value ).toBe( 'publish' );
		expect(
			document.querySelector( '.bulkcat-status-confirm' )
		).toBeNull();
	} );
} );
