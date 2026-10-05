/**
 * Ticket 22 (individual details): the category editor reads a category's
 * native display type and image through GET /categories/{id}, offers
 * Default/Products/Subcategories/Both and the media library's image chooser
 * with Remove, and sends only what changed.
 */
import { act, fireEvent, render, screen } from '@testing-library/react';

import {
	CategoryManager,
	indexTree,
} from '../../assets/src/panels/categories/index';

jest.mock( '@wordpress/api-fetch', () => {
	const apiFetch = jest.fn();
	apiFetch.use = jest.fn();
	apiFetch.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: apiFetch };
} );

const apiFetch = require( '@wordpress/api-fetch' ).default;

const NODES = [
	{
		id: 2,
		name: 'T-Shirts',
		slug: 't-shirts',
		description: '',
		count: 0,
		countWithDescendants: 0,
		children: [],
	},
];

const tree = () => ( {
	nodes: NODES,
	byId: indexTree( NODES ),
	reload: jest.fn(),
	recordCreated: jest.fn(),
	remember: jest.fn(),
	descriptions: new Map(),
} );

let selected;
function mediaFrame() {
	const handlers = {};
	return {
		on: ( event, fn ) => {
			handlers[ event ] = fn;
		},
		open: jest.fn( () => handlers.select?.() ),
		state: () => ( {
			get: () => ( { first: () => ( { toJSON: () => selected } ) } ),
		} ),
	};
}

beforeEach( () => {
	apiFetch.mockReset();
	selected = {
		id: 77,
		type: 'image',
		sizes: { thumbnail: { url: 'https://example.test/77-150.jpg' } },
		url: 'https://example.test/77.jpg',
	};
	window.wp = { a11y: { speak: jest.fn() }, media: jest.fn( mediaFrame ) };
} );

afterEach( () => {
	delete window.wp;
} );

function serve( details ) {
	apiFetch.mockImplementation( ( options ) => {
		if ( ! options.method || options.method === 'GET' ) {
			return Promise.resolve( { item: details } );
		}
		return Promise.resolve( {
			item: { ...details, ...options.data },
			status: 'complete',
		} );
	} );
}

describe( 'category display type and image', () => {
	it( 'reads the details, then sends only a changed display type', async () => {
		serve( {
			id: 2,
			display_type: 'products',
			thumbnail_id: 0,
			thumbnail_url: null,
		} );
		render(
			<CategoryManager tree={ tree() } categoryId={ 2 } canManage />
		);
		await act( async () => {} );
		expect( apiFetch.mock.calls[ 0 ][ 0 ] ).toMatchObject( {
			url: 'categories/2',
		} );
		const select = screen.getByLabelText( 'Display type' );
		expect( select.value ).toBe( 'products' );
		fireEvent.change( select, { target: { value: 'both' } } );
		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Save category' } )
			);
		} );
		const patch = apiFetch.mock.calls.find(
			( [ options ] ) => options.method === 'PATCH'
		)[ 0 ];
		expect( patch.data ).toEqual( { display_type: 'both' } );
	} );

	it( 'chooses an image from the media library and removes one', async () => {
		serve( {
			id: 2,
			display_type: '',
			thumbnail_id: 5,
			thumbnail_url: 'https://example.test/5-150.jpg',
		} );
		render(
			<CategoryManager tree={ tree() } categoryId={ 2 } canManage />
		);
		await act( async () => {} );
		expect( screen.getByRole( 'img' ).getAttribute( 'src' ) ).toBe(
			'https://example.test/5-150.jpg'
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Replace image' } )
		);
		expect( window.wp.media ).toHaveBeenCalledWith(
			expect.objectContaining( {
				library: { type: 'image' },
				multiple: false,
			} )
		);
		expect( screen.getByRole( 'img' ).getAttribute( 'src' ) ).toBe(
			'https://example.test/77-150.jpg'
		);
		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Save category' } )
			);
		} );
		let patch = apiFetch.mock.calls.filter(
			( [ options ] ) => options.method === 'PATCH'
		);
		expect( patch.at( -1 )[ 0 ].data ).toEqual( { thumbnail_id: 77 } );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Remove image' } )
		);
		expect( screen.queryByRole( 'img' ) ).toBeNull();
		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Save category' } )
			);
		} );
		patch = apiFetch.mock.calls.filter(
			( [ options ] ) => options.method === 'PATCH'
		);
		expect( patch.at( -1 )[ 0 ].data ).toEqual( { thumbnail_id: 0 } );
	} );

	it( 'leaves the details alone when they could not be read', async () => {
		apiFetch.mockImplementation( ( options ) =>
			! options.method || options.method === 'GET'
				? Promise.reject( { code: 'x', data: { status: 500 } } )
				: Promise.resolve( { item: { id: 2, name: 'Tees' } } )
		);
		render(
			<CategoryManager tree={ tree() } categoryId={ 2 } canManage />
		);
		await act( async () => {} );
		expect( screen.queryByLabelText( 'Display type' ) ).toBeNull();
		expect(
			screen.getByText(
				'The display type and image could not be loaded, so they are left as they are.'
			)
		).toBeTruthy();
		fireEvent.change( screen.getByLabelText( 'Name' ), {
			target: { value: 'Tees' },
		} );
		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Save category' } )
			);
		} );
		const patch = apiFetch.mock.calls.find(
			( [ options ] ) => options.method === 'PATCH'
		)[ 0 ];
		expect( patch.data ).toEqual( { name: 'Tees' } );
	} );

	it( 'creates with a chosen display type, and without the media library offers no chooser', async () => {
		delete window.wp.media;
		apiFetch.mockResolvedValue( { item: { id: 9, name: 'Caps' } } );
		render( <CategoryManager tree={ tree() } canManage /> );
		expect( apiFetch ).not.toHaveBeenCalled();
		expect(
			screen.queryByRole( 'button', { name: 'Choose image' } )
		).toBeNull();
		fireEvent.change( screen.getByLabelText( 'Name' ), {
			target: { value: 'Caps' },
		} );
		fireEvent.change( screen.getByLabelText( 'Display type' ), {
			target: { value: 'subcategories' },
		} );
		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Add category' } )
			);
		} );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toEqual( {
			name: 'Caps',
			parent: 0,
			display_type: 'subcategories',
		} );
	} );
} );
