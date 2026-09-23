/**
 * The catalogue wire-ins of 23 September 2026: the saved views row, the
 * layout switch and Export placed on the screen (T51–T53); the detail
 * panel's "Show the variations in the list" reaching the row tree (T47,
 * T67); an empty Variations list; the registry's default column and hiding
 * flags in place of client lists; and the detail panel reading its row
 * through GET /products/{id}.
 */
import {
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';

import Catalogue from '../../assets/src/panels/catalogue';
import {
	defaultColumns,
	hidingChoices,
	offSiteChoices,
} from '../../assets/src/panels/catalogue/defaults';
import { alwaysConfirmReasons } from '../../assets/src/panels/bulk';

jest.mock( '@wordpress/api-fetch', () => {
	const apiFetch = jest.fn();
	apiFetch.use = jest.fn();
	apiFetch.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: apiFetch };
} );

const apiFetch = require( '@wordpress/api-fetch' ).default;
const {
	REGISTRY,
	ROWS,
	CATEGORIES,
	route,
	calls,
} = require( './catalogue-fixture' );

// DataViews' grid measures itself; jsdom has no ResizeObserver.
global.ResizeObserver =
	global.ResizeObserver ||
	class {
		observe() {}
		unobserve() {}
		disconnect() {}
	};

const SETTINGS = { settings: { rows_per_page: 25 } };

const VARIABLE = {
	...ROWS[ 0 ],
	id: 21,
	name: 'Classic Tee',
	sku: 'TEE',
	type: 'variable',
	variation_count: 1,
	regular_price: '',
	price_html: '<span>R10.00</span>',
};

const VARIATION = {
	...ROWS[ 0 ],
	id: 2101,
	parent_id: 21,
	parent_name: 'Classic Tee',
	name: 'Classic Tee - Small',
	attribute_summary: 'Small',
	sku: 'TEE-S',
};

function routes( overrides = {} ) {
	route( apiFetch, {
		fields: () => REGISTRY,
		products: ( query ) =>
			query.mode === 'variations'
				? {
						items: [],
						pagination: { page: 1, perPage: 25, total: 0 },
					}
				: {
						items: [ ROWS[ 0 ], VARIABLE ],
						pagination: { page: 1, perPage: 25, total: 2 },
					},
		categories: () => CATEGORIES,
		views: () => ( { items: [], default: null, max: 50 } ),
		'products/21': () => ( { item: VARIABLE } ),
		'products/21/variations': () => ( {
			items: [ VARIATION ],
			pagination: { page: 1, perPage: 50, total: 1, totalPages: 1 },
		} ),
		...overrides,
	} );
}

beforeEach( () => {
	apiFetch.mockReset();
	window.wp = { a11y: { speak: jest.fn() } };
	routes();
} );

afterEach( () => {
	delete window.wp;
} );

async function renderLoaded( props = {} ) {
	render( <Catalogue settings={ SETTINGS } { ...props } /> );
	await screen.findByText( 'Classic Tee' );
}

describe( 'catalogue wire-ins', () => {
	it( 'places the saved views, the layout switch and Export, and draws cards for Grid', async () => {
		await renderLoaded();
		expect(
			screen.getByRole( 'group', { name: 'Saved views' } )
		).toBeTruthy();
		expect(
			screen.getByRole( 'button', { name: 'Export CSV' } )
		).toBeTruthy();
		const layout = screen.getByRole( 'group', { name: 'Layout' } );
		fireEvent.click(
			within( layout ).getByRole( 'button', { name: 'Grid' } )
		);
		await waitFor( () =>
			expect(
				document.querySelector( '.bcm-catalogue__cards' )
			).not.toBeNull()
		);
		expect(
			within( layout )
				.getByRole( 'button', { name: 'Grid' } )
				.getAttribute( 'aria-pressed' )
		).toBe( 'true' );
	} );

	it( 'says No variations match for an empty Variations list', async () => {
		render(
			<Catalogue
				settings={ SETTINGS }
				initialView={ { mode: 'variations' } }
			/>
		);
		expect(
			await screen.findByText( 'No variations match.' )
		).toBeTruthy();
		expect( screen.queryByText( 'There are no products yet.' ) ).toBeNull();
	} );

	it( 'reads the detail panel row through GET /products/{id} and shows the variations from its link', async () => {
		await renderLoaded();
		const row = screen
			.getByRole( 'link', { name: 'Classic Tee' } )
			.closest( 'tr' );
		fireEvent.click(
			within( row ).getByRole( 'button', { name: 'Actions' } )
		);
		fireEvent.click(
			await screen.findByRole( 'menuitem', { name: 'Edit details' } )
		);
		await screen.findByRole( 'dialog' );
		await waitFor( () =>
			expect( calls( apiFetch, 'products/21' ) ).toHaveLength( 1 )
		);
		expect( calls( apiFetch, 'products/21' )[ 0 ].fields ).toEqual(
			expect.arrayContaining( [ 'sku', 'regular_price' ] )
		);
		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Show the variations in the list',
			} )
		);
		await waitFor( () =>
			expect( screen.queryByRole( 'dialog' ) ).toBeNull()
		);
		// The row tree opens the product in place.
		expect( await screen.findByText( 'Small' ) ).toBeTruthy();
		expect(
			calls( apiFetch, 'products/21/variations' ).length
		).toBeGreaterThanOrEqual( 1 );
	} );
} );

describe( 'registry flags in place of client lists', () => {
	const fields = REGISTRY.fields;

	it( 'orders the default columns by defaultOrder', () => {
		const reversed = [ ...fields ].reverse();
		expect( defaultColumns( reversed ) ).toEqual(
			defaultColumns( fields )
		);
		expect( defaultColumns( fields )[ 0 ] ).toBe( 'sku' );
	} );

	it( 'reads the hiding choices from hidesProduct and hidesAs', () => {
		expect( offSiteChoices( fields ) ).toEqual( {
			status: [ 'draft', 'pending' ],
		} );
		const hiding = hidingChoices( fields );
		expect( Object.keys( hiding )[ 0 ] ).toBe( 'visible_on_site' );
		expect( hiding ).toEqual( {
			visible_on_site: [ 'off' ],
			catalog_visibility: [ 'hidden' ],
		} );
	} );

	it( 'confirms by the choice flags, not by a list of keys', () => {
		const flagged = {
			...REGISTRY,
			fields: fields.map( ( field ) =>
				field.key === 'status'
					? {
							...field,
							choices: field.choices.map( ( choice ) =>
								choice.value === 'private'
									? {
											...choice,
											hidesProduct: true,
											hidesAs: 'off_site',
										}
									: choice
							),
						}
					: field
			),
		};
		const operation = {
			action: 'set_value',
			args: { field: 'status', value: 'private' },
		};
		expect( alwaysConfirmReasons( REGISTRY, [ operation ], 1 ) ).toEqual(
			[]
		);
		expect(
			alwaysConfirmReasons( flagged, [ operation ], 1 )
		).toHaveLength( 1 );
	} );
} );
