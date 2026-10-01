import { withInlineReceipts } from './inline-transport';
/**
 * T45: the "Shown in shop and search" switch column (named "Visible on site"
 * before T93; not a default column since, so these tests show it as an owner
 * who adds it would). Three positions from the
 * registry's switch field, a Partly tooltip naming the exact state, a Partly
 * click that offers the two real choices and writes nothing until one is
 * chosen, a grey status badge beside an operable switch, one PATCH of the
 * catalogue visibility with the row pending until it returns, and a failure
 * that restores the previous position with the §4.8 sentence.
 */
import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
	within,
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
const {
	REGISTRY: FIELDS,
	ROWS,
	CATEGORIES,
	page,
} = require( './catalogue-fixture' );

// T93: the switch is not a default column; show it where it was, as an
// owner's view that adds it would.
const REGISTRY = {
	...FIELDS,
	fields: FIELDS.fields.map( ( field ) =>
		field.key === 'visible_on_site'
			? { ...field, defaultColumn: true, defaultOrder: 6 }
			: field
	),
};

const SETTINGS = { settings: { rows_per_page: 25 } };

// Rendering the whole grid is slow when every suite runs in parallel.
jest.setTimeout( 20000 );

const ITEMS = [
	{
		...ROWS[ 0 ],
		visible_on_site: 'on',
		catalog_visibility: 'visible',
	},
	{
		...ROWS[ 1 ],
		visible_on_site: 'off',
		catalog_visibility: 'hidden',
	},
	{
		...ROWS[ 0 ],
		id: 13,
		name: 'Canvas Tote Bag',
		sku: 'BAG-130',
		visible_on_site: 'partly',
		catalog_visibility: 'catalog',
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
	withInlineReceipts( apiFetch );
} );

const patches = () =>
	apiFetch.mock.calls.filter( ( [ options ] ) => options.method === 'PATCH' );

async function renderLoaded() {
	render( <Catalogue settings={ SETTINGS } /> );
	await screen.findByText( 'Canvas Tote Bag' );
}

function deferred() {
	let resolve;
	let reject;
	const promise = new Promise( ( yes, no ) => {
		resolve = yes;
		reject = no;
	} );
	return { promise, resolve, reject };
}

describe( 'Shown in shop and search switch', () => {
	it( 'shows on, off and Partly, each named with the product', async () => {
		await renderLoaded();
		const on = screen.getByRole( 'switch', {
			name: 'Shown in shop and search, Classic Cotton T-Shirt',
		} );
		expect( on.getAttribute( 'aria-checked' ) ).toBe( 'true' );
		expect( on.textContent ).toContain( 'Shown' );
		const off = screen.getByRole( 'switch', {
			name: 'Shown in shop and search, Ladies Golf Shirt',
		} );
		expect( off.getAttribute( 'aria-checked' ) ).toBe( 'false' );
		expect( off.textContent ).toContain( 'Hidden' );
		const partly = screen.getByRole( 'button', {
			name: 'Shown in shop and search, Canvas Tote Bag',
		} );
		expect( partly.textContent ).toContain( 'Partly' );
		expect(
			screen.queryByRole( 'switch', {
				name: 'Shown in shop and search, Canvas Tote Bag',
			} )
		).toBeNull();
	} );

	it( 'names the exact middle state in the Partly tooltip', async () => {
		await renderLoaded();
		const partly = screen.getByRole( 'button', {
			name: 'Shown in shop and search, Canvas Tote Bag',
		} );
		const described = document.getElementById(
			partly.getAttribute( 'aria-describedby' )
		);
		expect( described.textContent ).toBe(
			'Partly shown: Shop only. Choose on or off to change it.'
		);
	} );

	it( 'offers the two real choices on a Partly click and writes nothing until one is chosen', async () => {
		patch.mockResolvedValue( {
			item: {
				...ITEMS[ 2 ],
				visible_on_site: 'off',
				catalog_visibility: 'hidden',
			},
			batch_id: 9,
			status: 'complete',
			results: [],
		} );
		await renderLoaded();
		fireEvent.click(
			screen.getByRole( 'button', {
				name: 'Shown in shop and search, Canvas Tote Bag',
			} )
		);
		const menu = await screen.findByRole( 'group', {
			name: 'Shown in shop and search, Canvas Tote Bag',
		} );
		expect(
			within( menu ).getByRole( 'button', {
				name: 'Shop and search results',
			} )
		).toBeTruthy();
		expect( patches() ).toHaveLength( 0 );
		fireEvent.click(
			within( menu ).getByRole( 'button', { name: 'Hidden' } )
		);
		await waitFor( () => expect( patches() ).toHaveLength( 1 ) );
		expect( patch ).toHaveBeenCalledWith(
			'products/13',
			expect.objectContaining( { catalog_visibility: 'hidden' } ),
			expect.anything()
		);
		const off = await screen.findByRole( 'switch', {
			name: 'Shown in shop and search, Canvas Tote Bag',
		} );
		expect( off.getAttribute( 'aria-checked' ) ).toBe( 'false' );
	} );

	it( 'shows a grey status badge beside a draft and keeps the switch operable', async () => {
		patch.mockResolvedValue( {
			item: {
				...ITEMS[ 1 ],
				visible_on_site: 'on',
				catalog_visibility: 'visible',
			},
			batch_id: 10,
			status: 'complete',
			results: [],
		} );
		await renderLoaded();
		const off = screen.getByRole( 'switch', {
			name: 'Shown in shop and search, Ladies Golf Shirt',
		} );
		const cell = off.closest( '.bulkcat-switch' );
		const badge = cell.querySelector( '.bulkcat-switch__status' );
		expect( badge.textContent ).toBe( 'Draft' );
		expect( badge.className ).toContain( 'is-neutral' );
		fireEvent.click( off );
		await waitFor( () => expect( patches() ).toHaveLength( 1 ) );
		expect( patch.mock.calls[ 0 ][ 1 ] ).toMatchObject( {
			catalog_visibility: 'visible',
		} );
		const on = await screen.findByRole( 'switch', {
			name: 'Shown in shop and search, Ladies Golf Shirt',
		} );
		await waitFor( () =>
			expect( on.getAttribute( 'aria-checked' ) ).toBe( 'true' )
		);
		// A draft is never called visible.
		expect( on.textContent ).toContain( 'When published' );
		expect( on.textContent ).not.toContain( 'Shown' );
	} );

	it( 'issues one PATCH and keeps the row pending until it returns', async () => {
		const answer = deferred();
		patch.mockReturnValue( answer.promise );
		await renderLoaded();
		const on = screen.getByRole( 'switch', {
			name: 'Shown in shop and search, Classic Cotton T-Shirt',
		} );
		fireEvent.click( on );
		await waitFor( () => expect( patches() ).toHaveLength( 1 ) );
		const [ [ options ] ] = patches();
		expect( options.url.split( '?' )[ 0 ] ).toBe( 'products/11' );
		expect( options.data ).toMatchObject( {
			catalog_visibility: 'hidden',
		} );
		expect( getQueryArgs( options.url ).fields ).toEqual(
			expect.arrayContaining( [
				'visible_on_site',
				'catalog_visibility',
				'status',
			] )
		);
		const pending = screen.getByRole( 'switch', {
			name: 'Shown in shop and search, Classic Cotton T-Shirt',
		} );
		expect( pending.getAttribute( 'aria-busy' ) ).toBe( 'true' );
		expect( pending.getAttribute( 'aria-checked' ) ).toBe( 'false' );
		// A second click while pending writes nothing.
		fireEvent.click( pending );
		expect( patches() ).toHaveLength( 1 );
		await act( async () => {
			answer.resolve( {
				item: {
					...ITEMS[ 0 ],
					visible_on_site: 'off',
					catalog_visibility: 'hidden',
				},
				batch_id: 11,
				status: 'complete',
				results: [],
			} );
		} );
		const done = screen.getByRole( 'switch', {
			name: 'Shown in shop and search, Classic Cotton T-Shirt',
		} );
		expect( done.getAttribute( 'aria-busy' ) ).toBeNull();
		expect( done.getAttribute( 'aria-checked' ) ).toBe( 'false' );
	} );

	it( 'restores the previous position and says why when the write fails', async () => {
		patch.mockRejectedValue( {
			code: 'bulkcat_cannot_edit',
			message: 'You are not allowed to edit this product.',
			data: { status: 403, receipt_state: 'refused', replayed: false },
		} );
		await renderLoaded();
		fireEvent.click(
			screen.getByRole( 'switch', {
				name: 'Shown in shop and search, Classic Cotton T-Shirt',
			} )
		);
		expect(
			await screen.findByText(
				'We could not change whether Classic Cotton T-Shirt is shown in the shop and search, so it is as it was. You are not allowed to edit this product.'
			)
		).toBeTruthy();
		const on = screen.getByRole( 'switch', {
			name: 'Shown in shop and search, Classic Cotton T-Shirt',
		} );
		expect( on.getAttribute( 'aria-checked' ) ).toBe( 'true' );
		expect( on.getAttribute( 'aria-busy' ) ).toBeNull();
		expect(
			screen.getByRole( 'button', { name: 'Show details' } )
		).toBeTruthy();
	} );
} );
