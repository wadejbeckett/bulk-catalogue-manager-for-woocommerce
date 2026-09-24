/**
 * T47: the detail panel. Edit details opens a DataForm drawer holding every
 * editable registry field for the product (the four-way catalogue visibility
 * and the full status included, the switch left to the row), with no
 * hard-coded list; per-field validation messages and nothing written; the
 * unsaved-changes prompt; Escape and focus return; and Save writing only the
 * changed fields in one PATCH, the row updating from the answer.
 */
import {
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';

import Catalogue from '../../assets/src/panels/catalogue';
import { panelDescriptors } from '../../assets/src/panels/catalogue/edit/drawer';
import { descriptorsOf } from '../../assets/src/panels/catalogue/fields';

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

/** The full row the panel reads: every field of the registry. */
const FULL = {
	...ROWS[ 0 ],
	catalog_visibility: 'visible',
	visible_on_site: 'on',
	featured: false,
	tag_ids: [],
	manage_stock: true,
	weight: '0.3',
	length: '',
	width: '',
	height: '',
	shipping_class_id: 0,
	tax_status: 'taxable',
	tax_class: '',
	sold_individually: false,
	reviews_allowed: true,
	type: 'simple',
};

const VARIATIONS = [
	{
		id: 31,
		parent_id: 11,
		name: 'Classic Cotton T-Shirt - Small',
		attribute_summary: 'Small',
		price_html: '<span>R 12.50</span>',
	},
];

let patch;

beforeEach( () => {
	apiFetch.mockReset();
	patch = jest.fn();
	apiFetch.mockImplementation( ( { url, method, data } ) => {
		const path = url.split( '?' )[ 0 ];
		const query = getQueryArgs( url );
		if ( method === 'PATCH' ) {
			return patch( path, data, query );
		}
		switch ( path ) {
			case 'fields':
				return Promise.resolve( REGISTRY );
			case 'products':
				return Promise.resolve( page( ROWS ) );
			// T47 reads the full row through GET /products/{id}.
			case 'products/11':
				return Promise.resolve( { item: FULL } );
			case 'products/11/variations':
				return Promise.resolve( page( VARIATIONS, 1 ) );
			case 'audit':
				return Promise.resolve( {
					items: [
						{
							field: 'regular_price',
							field_label: 'Regular price',
							applied_gmt: '2026-09-20 10:00:00',
						},
					],
					pagination: { total: 1 },
				} );
			case 'categories':
				return Promise.resolve( CATEGORIES );
			default:
				return new Promise( () => {} );
		}
	} );
} );

const patches = () =>
	apiFetch.mock.calls.filter( ( [ options ] ) => options.method === 'PATCH' );

async function openDrawer() {
	render( <Catalogue settings={ SETTINGS } /> );
	await screen.findByText( 'Classic Cotton T-Shirt' );
	const row = screen
		.getByRole( 'link', { name: 'Classic Cotton T-Shirt' } )
		.closest( 'tr' );
	const menu = within( row ).getByRole( 'button', { name: 'Actions' } );
	menu.focus();
	fireEvent.click( menu );
	fireEvent.click(
		await screen.findByRole( 'menuitem', { name: 'Edit details' } )
	);
	const dialog = await screen.findByRole( 'dialog', { name: 'Details' } );
	await within( dialog ).findByRole( 'button', { name: 'Save' } );
	// Let the panel's other reads settle.
	await within( dialog ).findByText( 'Small' );
	await within( dialog ).findByText( /Regular price was changed on/ );
	return { dialog, menu };
}

describe( 'detail panel', () => {
	it( 'holds every editable registry field and no hard-coded list', async () => {
		const { dialog } = await openDrawer();
		const expected = panelDescriptors( descriptorsOf( REGISTRY ) );
		expect( expected.length ).toBeGreaterThan( 15 );
		for ( const field of expected ) {
			expect(
				within( dialog ).getAllByText( field.label ).length
			).toBeGreaterThan( 0 );
		}
		// The switch is the row's; the panel states the exact four-way value.
		expect( within( dialog ).queryByText( 'Visible on site' ) ).toBeNull();
		const visibility = within( dialog ).getByRole( 'combobox', {
			name: 'Catalogue visibility',
		} );
		expect(
			within( visibility )
				.getAllByRole( 'option' )
				.map( ( option ) => option.textContent )
		).toEqual( [
			'Shop and search results',
			'Shop only',
			'Search results only',
			'Hidden',
		] );
		const status = within( dialog ).getByRole( 'combobox', {
			name: 'Status',
		} );
		expect( within( status ).getAllByRole( 'option' ) ).toHaveLength( 4 );
		// Variations read-only, and the latest change with its history link.
		expect( await within( dialog ).findByText( 'Small' ) ).toBeTruthy();
		expect( within( dialog ).getByText( 'R 12.50' ) ).toBeTruthy();
		expect(
			await within( dialog ).findByText( /Regular price was changed on/ )
		).toBeTruthy();
		const history = within( dialog ).getByRole( 'link', {
			name: 'See history',
		} );
		const url = new URL( history.getAttribute( 'href' ) );
		expect( url.searchParams.get( 'panel' ) ).toBe( 'history' );
		expect( url.searchParams.get( 'product' ) ).toBe( '11' );
	} );

	it( 'saves only the changed fields in one PATCH and updates the row', async () => {
		patch.mockResolvedValue( {
			item: { ...FULL, regular_price: '15.00', sku: 'TSH-900' },
			batch_id: 40,
			status: 'complete',
			results: [],
		} );
		const { dialog } = await openDrawer();
		fireEvent.change(
			within( dialog ).getByRole( 'spinbutton', {
				name: 'Regular price',
			} ),
			{ target: { value: '15' } }
		);
		fireEvent.change(
			within( dialog ).getByRole( 'textbox', { name: 'SKU' } ),
			{ target: { value: 'TSH-900' } }
		);
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Save' } )
		);
		await waitFor( () => expect( patches() ).toHaveLength( 1 ) );
		expect( patch.mock.calls[ 0 ][ 0 ] ).toBe( 'products/11' );
		expect( patch.mock.calls[ 0 ][ 1 ] ).toEqual( {
			regular_price: '15',
			sku: 'TSH-900',
		} );
		expect(
			await within( dialog ).findByText( 'Saved: Regular price, SKU.' )
		).toBeTruthy();
		// The grid row now shows the answer.
		expect(
			screen.getByRole( 'button', {
				name: 'Edit SKU, Classic Cotton T-Shirt',
			} ).textContent
		).toBe( 'TSH-900' );
	} );

	it( 'shows a refused value beside its own field and writes nothing', async () => {
		const { dialog } = await openDrawer();
		const price = within( dialog ).getByRole( 'spinbutton', {
			name: 'Regular price',
		} );
		fireEvent.change( price, { target: { value: '-3' } } );
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Save' } )
		);
		expect(
			await within( dialog ).findByText(
				'Regular price has to be a price of 0 or more, like 12.50, or left empty.'
			)
		).toBeTruthy();
		expect( patches() ).toHaveLength( 0 );
	} );

	it( 'shows the server refusal beside the field it names', async () => {
		patch.mockRejectedValue( {
			code: 'bulkcat_invalid_value',
			message:
				'A product with the SKU GLF-120 already exists, so this SKU was not applied.',
			data: { status: 400, field: 'sku' },
		} );
		const { dialog } = await openDrawer();
		fireEvent.change(
			within( dialog ).getByRole( 'textbox', { name: 'SKU' } ),
			{ target: { value: 'GLF-120' } }
		);
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Save' } )
		);
		expect(
			await within( dialog ).findByText(
				'A product with the SKU GLF-120 already exists, so this SKU was not applied.'
			)
		).toBeTruthy();
		expect(
			within( dialog ).getByRole( 'textbox', { name: 'SKU' } ).value
		).toBe( 'GLF-120' );
	} );

	it( 'asks before closing with unsaved changes', async () => {
		const { dialog } = await openDrawer();
		fireEvent.change(
			within( dialog ).getByRole( 'textbox', { name: 'SKU' } ),
			{ target: { value: 'CHANGED' } }
		);
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Close details' } )
		);
		expect(
			within( dialog ).getByText(
				'You have changes that are not saved. Close without saving them?'
			)
		).toBeTruthy();
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Keep editing' } )
		);
		expect(
			screen.getByRole( 'dialog', { name: 'Details' } )
		).toBeTruthy();
		fireEvent.keyDown( dialog, { key: 'Escape' } );
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Discard changes' } )
		);
		await waitFor( () =>
			expect(
				screen.queryByRole( 'dialog', { name: 'Details' } )
			).toBeNull()
		);
		expect( patches() ).toHaveLength( 0 );
	} );

	it( 'takes focus, closes on Escape and returns focus to the row menu', async () => {
		const { dialog, menu } = await openDrawer();
		expect( dialog.contains( document.activeElement ) ).toBe( true );
		fireEvent.keyDown( dialog, { key: 'Escape' } );
		await waitFor( () =>
			expect(
				screen.queryByRole( 'dialog', { name: 'Details' } )
			).toBeNull()
		);
		expect( document.activeElement ).toBe( menu );
	} );
} );
