/**
 * T67–T68: expandable variation rows in the grid and the inheritance
 * display — one request per opened product, at most 50 rows with chips, the
 * footer row, Select all options, collapse, Open all with variations, the
 * mode switch, derived and Mixed parent cells, and greyed parent-only cells.
 */
import {
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';

import Catalogue from '../../assets/src/panels/catalogue';
import CatalogueTable from '../../assets/src/panels/catalogue/table';
import { rowActions } from '../../assets/src/panels/catalogue/actions';
import {
	defaultView,
	descriptorsOf,
	fieldsFromRegistry,
} from '../../assets/src/panels/catalogue/fields';
import { toQuery } from '../../assets/src/panels/catalogue/query';
import {
	INITIAL,
	KINDS,
	buildRows,
	cellState,
	metaOf,
	reducer,
	variationRoles,
} from '../../assets/src/panels/catalogue/variations';

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

const SETTINGS = { settings: { rows_per_page: 25 } };
const descriptors = descriptorsOf( REGISTRY );
const byKey = ( key ) => descriptors.find( ( field ) => field.key === key );

const TEE = {
	id: 21,
	parent_id: 0,
	name: 'Classic Tee',
	type: 'variable',
	variation_count: 60,
	thumbnail: 0,
	thumbnail_url: '',
	sku: 'TEE',
	status: 'publish',
	regular_price: '',
	sale_price: '',
	price_html: '<span>R10.00</span> – <span>R15.00</span>',
	stock_status: 'instock',
	stock_quantity: null,
	manage_stock: false,
	category_ids: [ 3 ],
};

const POLO = {
	...TEE,
	id: 22,
	name: 'Polo',
	sku: 'POLO',
	variation_count: 2,
	price_html: '<span>R20.00</span>',
};

const EMPTY = {
	...TEE,
	id: 23,
	name: 'Blank Cap',
	sku: 'CAP',
	variation_count: 0,
	price_html: '',
};

const SIZES = [ 'S', 'M', 'L', 'XL' ];
const COLOURS = [ 'Navy', 'White', 'Red' ];

/**
 * A variation row.
 *
 * @param {Object} parent Parent row.
 * @param {number} n      Index.
 * @param {Object} extra  Overrides.
 * @return {Object} Row.
 */
function variation( parent, n, extra = {} ) {
	const size = SIZES[ n % SIZES.length ];
	const colour = COLOURS[ n % COLOURS.length ];
	return {
		id: parent.id * 100 + n,
		parent_id: parent.id,
		parent_name: parent.name,
		attribute_summary: `${ size } / ${ colour }`,
		name: `${ parent.name } - ${ size }, ${ colour }`,
		thumbnail: 0,
		thumbnail_url: '',
		sku: `${ parent.sku }-${ n }`,
		status: 'publish',
		regular_price: '10.00',
		sale_price: '',
		stock_status: 'instock',
		stock_quantity: 5,
		manage_stock: true,
		category_ids: [],
		...extra,
	};
}

const TEE_VARIATIONS = Array.from( { length: 60 }, ( _, n ) =>
	variation( TEE, n, { regular_price: n % 2 ? '15.00' : '10.00' } )
);
const POLO_VARIATIONS = [
	variation( POLO, 1, { regular_price: '20.00', sale_price: '' } ),
	variation( POLO, 2, {
		regular_price: '20.00',
		sale_price: '18.00',
		stock_status: 'outofstock',
		stock_quantity: 0,
	} ),
];

/**
 * A variations route answer: one page of a product's variations.
 *
 * @param {Array<Object>} all   Every variation.
 * @param {Object}        query Parsed query.
 * @return {Object} Answer.
 */
function variationsPage( all, query ) {
	const perPage = Number( query.per_page ) || 25;
	const pageNo = Number( query.page ) || 1;
	return {
		items: all.slice( ( pageNo - 1 ) * perPage, pageNo * perPage ),
		pagination: {
			page: pageNo,
			perPage,
			total: all.length,
			totalPages: Math.ceil( all.length / perPage ),
		},
	};
}

function productsPage( query ) {
	// Show all: one product's options through the route's parent filter.
	if ( query.mode === 'variations' && query.parent === '21' ) {
		return variationsPage( TEE_VARIATIONS, query );
	}
	if ( query.mode === 'variations' ) {
		return {
			items: POLO_VARIATIONS,
			pagination: { page: 1, perPage: 25, total: 1234, totalPages: 50 },
		};
	}
	return {
		items: [ ROWS[ 0 ], TEE, POLO, EMPTY ],
		pagination: { page: 1, perPage: 25, total: 4, totalPages: 1 },
	};
}

beforeEach( () => {
	apiFetch.mockReset();
	window.wp = { a11y: { speak: jest.fn() } };
	route( apiFetch, {
		fields: () => REGISTRY,
		products: productsPage,
		categories: () => CATEGORIES,
		'products/21/variations': ( query ) =>
			variationsPage( TEE_VARIATIONS, query ),
		'products/22/variations': ( query ) =>
			variationsPage( POLO_VARIATIONS, query ),
		'products/23/variations': ( query ) => variationsPage( [], query ),
	} );
} );

afterEach( () => {
	delete window.wp;
} );

async function renderLoaded() {
	const result = render( <Catalogue settings={ SETTINGS } /> );
	await screen.findByText( 'Classic Tee' );
	return result;
}

const disclosure = ( name ) =>
	screen.getByRole( 'button', {
		name: new RegExp( `(Show|Hide) the variations of ${ name }$` ),
	} );

async function open( name ) {
	fireEvent.click( disclosure( name ) );
	await waitFor( () =>
		expect( disclosure( name ).getAttribute( 'aria-busy' ) ).toBeNull()
	);
}

describe( 'expandable variation rows (T67)', () => {
	it( 'shows a disclosure and a count on variable rows only', async () => {
		await renderLoaded();
		expect(
			disclosure( 'Classic Tee' ).getAttribute( 'aria-expanded' )
		).toBe( 'false' );
		expect( screen.getByText( '60 options' ) ).toBeTruthy();
		expect( screen.getByText( '2 options' ) ).toBeTruthy();
		expect(
			screen.queryByRole( 'button', {
				name: /variations of Classic Cotton T-Shirt/,
			} )
		).toBeNull();
		const [ first ] = calls( apiFetch, 'products' );
		expect( first.fields ).toEqual(
			expect.arrayContaining( [ 'variation_count', 'type' ] )
		);
		expect( first.mode ).toBeUndefined();
	} );

	it( 'fetches once on expand and inserts at most 50 rows with attribute chips', async () => {
		await renderLoaded();
		await open( 'Classic Tee' );
		const requests = calls( apiFetch, 'products/21/variations' );
		expect( requests ).toHaveLength( 1 );
		expect( requests[ 0 ] ).toMatchObject( { per_page: '50', page: '1' } );
		expect( requests[ 0 ].fields ).toEqual(
			calls( apiFetch, 'products' )[ 0 ].fields
		);
		expect(
			disclosure( 'Classic Tee' ).getAttribute( 'aria-expanded' )
		).toBe( 'true' );
		expect(
			document.querySelectorAll( '.bcm-vrow.is-child' )
		).toHaveLength( 50 );
		const first = document.querySelector( '.bcm-vrow.is-child' );
		expect(
			[ ...first.querySelectorAll( '.bcm-chip' ) ].map(
				( chip ) => chip.textContent
			)
		).toEqual( [ 'S', 'Navy' ] );
		expect( window.wp.a11y.speak ).toHaveBeenCalledWith(
			'50 of 60 options shown.',
			'polite'
		);

		// Closing and opening again uses what was fetched.
		fireEvent.click( disclosure( 'Classic Tee' ) );
		expect(
			document.querySelectorAll( '.bcm-vrow.is-child' )
		).toHaveLength( 0 );
		fireEvent.click( disclosure( 'Classic Tee' ) );
		expect(
			document.querySelectorAll( '.bcm-vrow.is-child' )
		).toHaveLength( 50 );
		expect( calls( apiFetch, 'products/21/variations' ) ).toHaveLength( 1 );
	} );

	it( 'draws a footer row with the count, Select all options, Hide options and Show all', async () => {
		await renderLoaded();
		await open( 'Classic Tee' );
		expect(
			screen.getByText( '60 options under Classic Tee' )
		).toBeTruthy();
		expect(
			screen.getByRole( 'button', { name: 'Show all 60 options' } )
		).toBeTruthy();
		await open( 'Polo' );
		expect( screen.getByText( '2 options under Polo' ) ).toBeTruthy();
		expect(
			screen.getAllByRole( 'button', { name: 'Select all options' } )
		).toHaveLength( 2 );
		expect(
			screen.getAllByRole( 'button', { name: 'Hide options' } )
		).toHaveLength( 2 );
		// Only one "Show all": Polo's two are all shown.
		expect(
			screen.getAllByRole( 'button', { name: /^Show all \d/ } )
		).toHaveLength( 1 );
	} );

	it( 'says when a variable product has no variations yet', async () => {
		await renderLoaded();
		await open( 'Blank Cap' );
		expect(
			screen.getByText( 'This product has no variations yet.' )
		).toBeTruthy();
	} );

	it( 'collapses with Hide options', async () => {
		await renderLoaded();
		await open( 'Polo' );
		expect(
			document.querySelectorAll( '.bcm-vrow.is-child' )
		).toHaveLength( 2 );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Hide options' } )
		);
		expect(
			document.querySelectorAll( '.bcm-vrow.is-child' )
		).toHaveLength( 0 );
		expect( disclosure( 'Polo' ).getAttribute( 'aria-expanded' ) ).toBe(
			'false'
		);
		expect( screen.queryByText( '2 options under Polo' ) ).toBeNull();
	} );

	it( 'toggles the disclosure with Enter and Space', async () => {
		await renderLoaded();
		const control = disclosure( 'Polo' );
		expect( control.tagName ).toBe( 'BUTTON' );
		fireEvent.keyDown( control, { key: 'Enter' } );
		await waitFor( () =>
			expect(
				document.querySelectorAll( '.bcm-vrow.is-child' )
			).toHaveLength( 2 )
		);
		fireEvent.keyDown( disclosure( 'Polo' ), { key: ' ' } );
		expect(
			document.querySelectorAll( '.bcm-vrow.is-child' )
		).toHaveLength( 0 );
		expect( disclosure( 'Polo' ).getAttribute( 'aria-expanded' ) ).toBe(
			'false'
		);
	} );

	it( 'puts the child rows in the tab order with their own checkboxes', async () => {
		await renderLoaded();
		await open( 'Polo' );
		const rows = [
			...document.querySelectorAll( '.bcm-vrow.is-child' ),
		].map( ( cell ) => cell.closest( 'tr' ) );
		rows.forEach( ( row ) => {
			const box = row.querySelector( 'input[type="checkbox"]' );
			expect( box ).toBeTruthy();
			expect( box.disabled ).toBe( false );
			expect( box.tabIndex ).not.toBe( -1 );
		} );
	} );

	it( 'selects every option of a product with Select all options', async () => {
		await renderLoaded();
		await open( 'Polo' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Select all options' } )
		);
		await waitFor( () =>
			expect(
				[ ...document.querySelectorAll( '.bcm-vrow.is-child' ) ].every(
					( cell ) =>
						cell
							.closest( 'tr' )
							.querySelector( 'input[type="checkbox"]' ).checked
				)
			).toBe( true )
		);
		// The product itself is not selected (SPEC §8 rule 2).
		const poloRow = disclosure( 'Polo' ).closest( 'tr' );
		expect(
			poloRow.querySelector( 'input[type="checkbox"]' ).checked
		).toBe( false );
	} );

	it( 'opens every variable row with Open all with variations', async () => {
		await renderLoaded();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Open all with variations' } )
		);
		await screen.findByText( '2 options under Polo' );
		await screen.findByText( '60 options under Classic Tee' );
		expect( calls( apiFetch, 'products/21/variations' ) ).toHaveLength( 1 );
		expect( calls( apiFetch, 'products/22/variations' ) ).toHaveLength( 1 );
		expect( calls( apiFetch, 'products/23/variations' ) ).toHaveLength( 1 );
		expect(
			document.querySelectorAll( '.bcm-vrow.is-child' )
		).toHaveLength( 52 );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Close all options' } )
		);
		expect(
			document.querySelectorAll( '.bcm-vrow.is-child' )
		).toHaveLength( 0 );
	} );

	it( 'switches the route mode and the footer count with Products | Variations', async () => {
		await renderLoaded();
		expect( screen.getByText( '4 products' ) ).toBeTruthy();
		const products = screen.getByRole( 'button', { name: 'Products' } );
		const variations = screen.getByRole( 'button', { name: 'Variations' } );
		expect( products.getAttribute( 'aria-pressed' ) ).toBe( 'true' );
		fireEvent.click( variations );
		await screen.findByText( /^1\D?234 options$/ );
		const last = calls( apiFetch, 'products' ).pop();
		expect( last.mode ).toBe( 'variations' );
		expect( last.page ).toBe( '1' );
		expect(
			screen
				.getByRole( 'button', { name: 'Variations' } )
				.getAttribute( 'aria-pressed' )
		).toBe( 'true' );
		// Flat rows show their product's name; nothing opens.
		expect( document.querySelectorAll( '.bcm-vrow.is-flat' ) ).toHaveLength(
			2
		);
		expect( screen.getAllByText( 'Polo' ).length ).toBeGreaterThan( 0 );
		expect(
			screen.queryByRole( 'button', { name: 'Open all with variations' } )
		).toBeNull();
		fireEvent.click( screen.getByRole( 'button', { name: 'Products' } ) );
		await screen.findByText( '4 products' );
		expect( calls( apiFetch, 'products' ).pop().mode ).toBeUndefined();
	} );

	it( 'lists one product’s options in Variations mode with Show all', async () => {
		await renderLoaded();
		await open( 'Classic Tee' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Show all 60 options' } )
		);
		await screen.findByText( 'Showing the options of Classic Tee.' );
		await screen.findByText( '60 options' );
		// One read: the grid's own, narrowed by parent (no second request).
		const focused = calls( apiFetch, 'products' ).pop();
		expect( focused ).toMatchObject( {
			mode: 'variations',
			parent: '21',
			page: '1',
			per_page: '25',
		} );
		expect( calls( apiFetch, 'products/21/variations' ) ).toHaveLength( 1 );
		await waitFor( () =>
			expect(
				document.querySelectorAll( '.bcm-vrow.is-flat' )
			).toHaveLength( 25 )
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Show every option' } )
		);
		await screen.findByText( /^1\D?234 options$/ );
	} );

	it( 'passes the variation ids as an addition to the selection change', async () => {
		const onChangeSelection = jest.fn();
		const view = defaultView( REGISTRY, SETTINGS );
		render(
			<CatalogueTable
				fields={ fieldsFromRegistry( REGISTRY ) }
				view={ view }
				onChangeView={ () => {} }
				items={ [ ROWS[ 0 ], POLO ] }
				pagination={ { totalItems: 2, totalPages: 1 } }
				isLoading={ false }
				hasLoaded
				actions={ rowActions( { onMakeChange: () => {} } ) }
				termNames={ {} }
				pending={ new Set() }
				selection={ [ '11' ] }
				onChangeSelection={ onChangeSelection }
				empty={ null }
			/>
		);
		await open( 'Polo' );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Select all options' } )
		);
		expect( onChangeSelection ).toHaveBeenLastCalledWith(
			[ '11', '2201', '2202' ],
			{ selectedProductIds: [ 11 ], selectedVariationIds: [ 2201, 2202 ] }
		);
	} );
} );

describe( 'inheritance display (T68)', () => {
	const cellOf = ( row, column ) => {
		const headers = [ ...document.querySelectorAll( 'thead th' ) ].map(
			( th ) => th.textContent.trim()
		);
		return row.querySelectorAll( 'td' )[ headers.indexOf( column ) ];
	};

	it( 'renders a parent price as its range and stock as a summary, not editable', async () => {
		await renderLoaded();
		const teeRow = disclosure( 'Classic Tee' ).closest( 'tr' );
		const price = cellOf( teeRow, 'Regular price' );
		const locked = price.querySelector( '.bcm-locked' );
		expect( locked.getAttribute( 'data-bcm-editable' ) ).toBe( 'false' );
		expect( locked.textContent ).toContain( 'R10.00 – R15.00' );
		expect( locked.getAttribute( 'title' ) ).toBe(
			byKey( 'regular_price' ).variableParentNote
		);
		const stock = cellOf( teeRow, 'Stock status' ).querySelector(
			'.bcm-locked'
		);
		expect( stock.getAttribute( 'data-bcm-cell' ) ).toBe( 'summary' );
		expect( stock.textContent ).toContain( 'In stock' );

		// Click and Enter open no editor and send nothing.
		const before = apiFetch.mock.calls.length;
		fireEvent.click( locked );
		fireEvent.keyDown( locked, { key: 'Enter' } );
		expect( price.querySelector( 'input' ) ).toBeNull();
		expect( apiFetch.mock.calls.length ).toBe( before );

		// Once its variations are loaded the range comes from them.
		await open( 'Classic Tee' );
		expect(
			cellOf( teeRow, 'Regular price' ).querySelector( '.bcm-locked' )
				.textContent
		).toContain( '10.00 – 15.00' );
		expect(
			cellOf( teeRow, 'Stock status' ).querySelector( '.bcm-locked' )
				.textContent
		).toContain( 'In stock · 250' );
	} );

	it( 'reads Mixed where the variations disagree, with the reason', async () => {
		await renderLoaded();
		await open( 'Polo' );
		const poloRow = disclosure( 'Polo' ).closest( 'tr' );
		const sale = cellOf( poloRow, 'Sale price' ).querySelector(
			'.bcm-locked'
		);
		expect( sale.getAttribute( 'data-bcm-cell' ) ).toBe( 'mixed' );
		expect( within( sale ).getByText( 'Mixed' ) ).toBeTruthy();
		expect( sale.getAttribute( 'title' ) ).toMatch(
			/^Sale price differs between this product’s variations/
		);
		expect( sale.getAttribute( 'title' ) ).toContain( '1 of 2 variations' );
		// The reason is read out too.
		expect(
			sale.querySelector( '.screen-reader-text' ).textContent
		).toContain( 'Sale price differs' );
		const stock = cellOf( poloRow, 'Stock status' ).querySelector(
			'.bcm-locked'
		);
		expect( within( stock ).getByText( 'Mixed' ) ).toBeTruthy();
		expect( stock.getAttribute( 'title' ) ).toContain(
			'1 In stock, 1 Out of stock'
		);
		fireEvent.keyDown( sale, { key: 'Enter' } );
		expect( sale.querySelector( 'input' ) ).toBeNull();
		// The regular prices agree, so the parent shows the one value.
		expect(
			cellOf( poloRow, 'Regular price' ).querySelector( '.bcm-locked' )
				.textContent
		).toMatch( /^20\.00/ );
	} );

	it( 'greys parent-only fields on variation rows with the product’s value', async () => {
		await renderLoaded();
		await open( 'Polo' );
		await screen.findAllByText( 'T-Shirts' );
		const child = document
			.querySelector( '.bcm-vrow.is-child' )
			.closest( 'tr' );
		const categories = cellOf( child, 'Categories' ).querySelector(
			'.bcm-locked'
		);
		expect( categories.getAttribute( 'data-bcm-cell' ) ).toBe(
			'parent_only'
		);
		expect( categories.getAttribute( 'data-bcm-editable' ) ).toBe(
			'false'
		);
		expect( categories.getAttribute( 'title' ) ).toBe(
			'Categories belong to the product, not to each variation.'
		);
		expect( categories.textContent ).toContain( 'T-Shirts' );
		// A per-variation field stays the variation's own.
		expect(
			cellOf( child, 'SKU' ).querySelector( '.bcm-locked' )
		).toBeNull();
		expect( cellOf( child, 'SKU' ).textContent ).toBe( 'POLO-1' );
		// T46 composes under T67: no locked cell anywhere holds an editor
		// or the switch, and the variation's own SKU does edit.
		document.querySelectorAll( '.bcm-locked' ).forEach( ( cell ) => {
			expect(
				cell.querySelector( 'button, input, [role="switch"]' )
			).toBeNull();
		} );
		expect(
			cellOf( child, 'SKU' ).querySelector( 'button[aria-label^="Edit"]' )
		).not.toBeNull();
	} );
} );

describe( 'row tree model', () => {
	const roles = variationRoles( descriptors );

	it( 'opens, receives, caps at 50 and closes', () => {
		let state = reducer( INITIAL, { type: 'open', id: 21 } );
		state = reducer( state, { type: 'request', id: 21 } );
		let rows = buildRows( [ TEE ], state, roles, 'products' );
		expect( rows.map( ( row ) => metaOf( row ).kind ) ).toEqual( [
			KINDS.parent,
			KINDS.footer,
		] );
		state = reducer( state, {
			type: 'receive',
			id: 21,
			items: TEE_VARIATIONS,
			total: 60,
		} );
		rows = buildRows( [ TEE ], state, roles, 'products' );
		expect( rows ).toHaveLength( 52 );
		expect( metaOf( rows[ 51 ] ) ).toMatchObject( {
			kind: KINDS.footer,
			shown: 50,
			total: 60,
		} );
		state = reducer( state, { type: 'close', id: 21 } );
		expect( buildRows( [ TEE ], state, roles, 'products' ) ).toHaveLength(
			1
		);
		// Nothing opens in Variations mode.
		state = reducer( state, { type: 'open', id: 21 } );
		expect( buildRows( [ TEE ], state, roles, 'variations' ) ).toHaveLength(
			1
		);
	} );

	it( 'drops fetched entries and closed-off rows when the page is read again', () => {
		let state = reducer( INITIAL, { type: 'open_all', ids: [ 21, 22 ] } );
		state = reducer( state, {
			type: 'receive',
			id: 21,
			items: [],
			total: 0,
		} );
		state = reducer( state, { type: 'refresh', ids: [ 21 ] } );
		expect( state ).toEqual( { open: [ 21 ], entries: {} } );
		expect( reducer( state, { type: 'reset' } ) ).toBe( INITIAL );
	} );

	it( 'marks a conditional field not applicable where its condition is off', () => {
		const quantity = byKey( 'stock_quantity' );
		const managed = variation( POLO, 1, { manage_stock: true } );
		const unmanaged = variation( POLO, 2, { manage_stock: false } );
		expect( cellState( managed, quantity, descriptors ) ).toMatchObject( {
			kind: 'own',
			editable: true,
		} );
		expect( cellState( unmanaged, quantity, descriptors ) ).toMatchObject( {
			kind: 'not_applicable',
			editable: false,
			reason: quantity.variationNote,
		} );
		// The variation's name comes from its product and attributes.
		expect(
			cellState( managed, byKey( 'name' ), descriptors )
		).toMatchObject( {
			kind: 'derived',
			editable: false,
		} );
	} );

	it( 'asks for the mode and the variation fields through toQuery', () => {
		const view = defaultView( REGISTRY, SETTINGS );
		expect(
			toQuery( { ...view, mode: 'variations' }, descriptors )
		).toMatchObject( {
			mode: 'variations',
		} );
		expect(
			toQuery( { ...view, mode: 'variations' }, descriptors ).fields
		).not.toContain( 'variation_count' );
		expect(
			toQuery( { ...view, mode: 'products_and_variations' }, descriptors )
		).toMatchObject( { mode: 'products_and_variations' } );
	} );
} );

describe( 'attribute chips', () => {
	const {
		chipsOf,
	} = require( '../../assets/src/panels/catalogue/variations/cells' );

	it( 'label each chip from the attribute pairs, else split the summary', () => {
		expect(
			chipsOf( {
				attribute_summary: 'M / Navy',
				attributes: [
					{ label: 'Size', value: 'M' },
					{ label: 'Colour', value: 'Navy' },
					{ label: 'Fit', value: '' },
				],
			} )
		).toEqual( [ 'Size: M', 'Colour: Navy', 'Fit: Any' ] );
		expect( chipsOf( { attribute_summary: 'M / Navy' } ) ).toEqual( [
			'M',
			'Navy',
		] );
	} );
} );
