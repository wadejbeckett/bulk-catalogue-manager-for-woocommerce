/**
 * <CategoryManager> (T63, client half): creates a category and edits an
 * existing one through POST /categories and PATCH /categories/{id}, sending
 * only what changed, checks an empty name in the server's own words, shows
 * a refusal (duplicate, slug taken, cycle) in §4.8 wording with the code
 * behind Show details, refreshes the tree and announces the result.
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
		id: 1,
		name: 'Clothing',
		slug: 'clothing',
		count: 0,
		countWithDescendants: 0,
		children: [
			{
				id: 2,
				name: 'T-Shirts',
				slug: 't-shirts',
				description: 'Short sleeves',
				count: 0,
				countWithDescendants: 0,
				children: [],
			},
		],
	},
	{
		id: 5,
		name: 'Drinkware',
		slug: 'drinkware',
		count: 0,
		countWithDescendants: 0,
		children: [],
	},
];

/**
 * A tree like useCategoryTree() answers.
 *
 * @return {Object} Tree.
 */
function makeTree() {
	return {
		nodes: NODES,
		byId: indexTree( NODES ),
		reload: jest.fn(),
		recordCreated: jest.fn(),
		remember: jest.fn(),
		descriptions: new Map(),
	};
}

const field = ( name ) => screen.getByLabelText( name );

describe( 'category manager', () => {
	let speak;

	beforeEach( () => {
		speak = jest.fn();
		window.wp = { a11y: { speak } };
		apiFetch.mockReset();
	} );

	afterEach( () => {
		delete window.wp;
	} );

	it( 'hands the confirmed create receipt to its owner exactly once', async () => {
		const tree = makeTree();
		const onSaved = jest.fn();
		apiFetch.mockResolvedValue( {
			item: {
				id: 9,
				name: 'Caps',
				slug: 'caps',
				parent: 1,
				description: 'Hats with a peak',
			},
			batch_id: 3,
			status: 'complete',
		} );
		render(
			<CategoryManager tree={ tree } canManage onSaved={ onSaved } />
		);
		expect(
			screen.getByRole( 'heading', { name: 'Add a category' } )
		).toBeTruthy();
		fireEvent.change( field( 'Name' ), { target: { value: '  Caps ' } } );
		fireEvent.change( field( 'Parent category' ), {
			target: { value: '1' },
		} );
		fireEvent.change( field( 'Description' ), {
			target: { value: 'Hats with a peak' },
		} );
		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Add category' } )
			);
		} );
		expect( apiFetch ).toHaveBeenCalledWith( {
			method: 'POST',
			url: 'categories',
			data: { name: 'Caps', description: 'Hats with a peak', parent: 1 },
		} );
		expect( tree.recordCreated ).toHaveBeenCalledTimes( 1 );
		expect( tree.recordCreated ).toHaveBeenCalledWith(
			expect.objectContaining( {
				id: 9,
				description: 'Hats with a peak',
			} ),
			'Caps'
		);
		expect( tree.reload ).not.toHaveBeenCalled();
		expect( tree.remember ).not.toHaveBeenCalled();
		expect( onSaved ).toHaveBeenCalledWith(
			expect.objectContaining( { id: 9, name: 'Caps' } ),
			expect.objectContaining( { batch_id: 3 } )
		);
		// The visible receipt owns the polite announcement.
		expect( speak ).not.toHaveBeenCalled();
	} );

	it( 'keeps busy submit focusable and refuses repeated form submission until the answer', async () => {
		let resolve;
		apiFetch.mockReturnValue(
			new Promise( ( done ) => {
				resolve = done;
			} )
		);
		const tree = makeTree();
		render( <CategoryManager tree={ tree } canManage /> );
		fireEvent.change( field( 'Name' ), { target: { value: 'Caps' } } );
		const button = screen.getByRole( 'button', { name: 'Add category' } );
		button.focus();
		fireEvent.click( button );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		// Enter in an input can submit the form independently of button activation.
		fireEvent.submit( button.closest( 'form' ) );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( button.disabled ).toBe( false );
		expect( button.getAttribute( 'aria-disabled' ) ).toBe( 'true' );
		await act( async () => {
			resolve( { item: { id: 9, name: 'Caps' } } );
		} );
		expect( tree.recordCreated ).toHaveBeenCalledTimes( 1 );
		expect( button.getAttribute( 'aria-disabled' ) ).toBeNull();
	} );

	it.each( [
		undefined,
		{},
		{ id: 0 },
		{ id: -1 },
		{ id: 1.5 },
		{ id: '9' },
	] )( 'never confirms an incomplete create receipt %p', async ( item ) => {
		const tree = makeTree();
		const onSaved = jest.fn();
		apiFetch.mockResolvedValue( { item } );
		render(
			<CategoryManager tree={ tree } canManage onSaved={ onSaved } />
		);
		fireEvent.change( field( 'Name' ), { target: { value: 'Caps' } } );
		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Add category' } )
			);
		} );
		expect( tree.recordCreated ).not.toHaveBeenCalled();
		expect( tree.reload ).not.toHaveBeenCalled();
		expect( tree.remember ).not.toHaveBeenCalled();
		expect( speak ).not.toHaveBeenCalled();
		expect( onSaved ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'edits with PATCH /categories/{id}, sending only what changed', async () => {
		const tree = makeTree();
		apiFetch.mockResolvedValue( {
			item: { id: 2, name: 'Tees', slug: 'tees' },
		} );
		render( <CategoryManager tree={ tree } categoryId={ 2 } canManage /> );
		expect( field( 'Name' ).value ).toBe( 'T-Shirts' );
		expect( field( 'Slug' ).value ).toBe( 't-shirts' );
		expect( field( 'Parent category' ).value ).toBe( '1' );
		// Neither the category itself nor anything below it is offered as a parent.
		expect(
			[ ...field( 'Parent category' ).options ].map(
				( o ) => o.textContent
			)
		).toEqual( [ 'Top level', 'Clothing', 'Drinkware' ] );
		fireEvent.change( field( 'Name' ), { target: { value: 'Tees' } } );
		fireEvent.change( field( 'Slug' ), { target: { value: 'tees' } } );
		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Save category' } )
			);
		} );
		expect( apiFetch ).toHaveBeenCalledWith( {
			method: 'PATCH',
			url: 'categories/2',
			data: { name: 'Tees', slug: 'tees' },
		} );
		expect( tree.reload ).toHaveBeenCalledTimes( 1 );
		expect( tree.recordCreated ).not.toHaveBeenCalled();
		expect( speak ).toHaveBeenCalledWith(
			'Category "Tees" saved.',
			'polite'
		);
	} );

	it( 'changes the parent alone', async () => {
		apiFetch.mockResolvedValue( { item: { id: 2, name: 'T-Shirts' } } );
		render(
			<CategoryManager tree={ makeTree() } categoryId={ 2 } canManage />
		);
		fireEvent.change( field( 'Parent category' ), {
			target: { value: '0' },
		} );
		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Save category' } )
			);
		} );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toEqual( { parent: 0 } );
	} );

	it( 'refuses an empty name in the server’s own words without a request', async () => {
		render( <CategoryManager tree={ makeTree() } canManage /> );
		fireEvent.change( field( 'Name' ), { target: { value: '   ' } } );
		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Add category' } )
			);
		} );
		expect(
			screen.getByText(
				'A category needs a name. Enter a name and try again.'
			)
		).toBeTruthy();
		expect( apiFetch ).not.toHaveBeenCalled();
		expect( speak ).toHaveBeenCalledWith(
			'A category needs a name. Enter a name and try again.',
			'assertive'
		);
	} );

	it.each( [
		[
			'bulkcat_category_exists',
			'A category called "Hats" already exists in "Clothing". Choose a different name, or put it somewhere else.',
		],
		[
			'bulkcat_slug_taken',
			'The slug "hats" is already used by the category "Hats". Choose a different slug.',
		],
		[
			'bulkcat_category_cycle',
			'A category cannot be moved inside itself.',
		],
	] )(
		'shows the %s refusal in plain words with the code behind Show details',
		async ( code, message ) => {
			const tree = makeTree();
			apiFetch.mockRejectedValue( {
				code,
				message,
				data: { status: 400 },
			} );
			render(
				<CategoryManager tree={ tree } categoryId={ 1 } canManage />
			);
			fireEvent.change( field( 'Name' ), { target: { value: 'Hats' } } );
			await act( async () => {
				fireEvent.click(
					screen.getByRole( 'button', { name: 'Save category' } )
				);
			} );
			expect( screen.getByText( message ) ).toBeTruthy();
			expect( screen.queryByText( new RegExp( code ) ) ).toBeNull();
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Show details' } )
			);
			expect( screen.getByText( `${ code } · HTTP 400` ) ).toBeTruthy();
			expect( tree.reload ).not.toHaveBeenCalled();
			expect( speak ).toHaveBeenCalledWith( message, 'assertive' );
		}
	);

	it( 'falls back to a plain sentence when the failure carries none', async () => {
		apiFetch.mockRejectedValue( { code: 'fetch_error', message: '' } );
		render( <CategoryManager tree={ makeTree() } canManage /> );
		fireEvent.change( field( 'Name' ), { target: { value: 'Caps' } } );
		await act( async () => {
			fireEvent.click(
				screen.getByRole( 'button', { name: 'Add category' } )
			);
		} );
		expect(
			screen.getByText(
				'The category could not be saved. Try again in a moment.'
			)
		).toBeTruthy();
	} );

	it( 'shows no form without manage_product_terms', () => {
		render( <CategoryManager tree={ makeTree() } categoryId={ 2 } /> );
		expect( screen.queryByRole( 'textbox' ) ).toBeNull();
		expect(
			screen.getByText(
				'You can browse and filter by categories, but changing them needs permission to manage product categories.'
			)
		).toBeTruthy();
	} );

	it( 'shows the description the tree carries in the details pane', async () => {
		render(
			<CategoryManager tree={ makeTree() } categoryId={ 2 } canManage />
		);
		expect( field( 'Description' ).value ).toBe( 'Short sleeves' );
		// Ticket 22: the display type and image are read afterwards.
		await act( async () => {} );
	} );
} );
