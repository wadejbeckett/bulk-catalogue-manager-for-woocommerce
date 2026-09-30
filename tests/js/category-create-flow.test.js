/**
 * Creation/readback proof promoted from the archived red proof. The real
 * panel and hook cross POST and deferred GET responses; only transport is mocked.
 */
import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';
import {
	CategoryPanel,
	useCategoryTree,
} from '../../assets/src/panels/categories/index';
import { createSelectionSession } from '../../assets/src/panels/catalogue/selection/session';
import useSelection from '../../assets/src/panels/catalogue/selection/use-selection';
import { createSelectionServer } from './selection-server';
import { html500, expectNoRawText } from './error-fixtures';

jest.mock( '@wordpress/api-fetch', () => {
	const apiFetch = jest.fn();
	apiFetch.use = jest.fn();
	apiFetch.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: apiFetch };
} );

const apiFetch = require( '@wordpress/api-fetch' ).default;
const node = ( id, name, children = [] ) => ( {
	id,
	name,
	slug: name.toLowerCase(),
	parent: 0,
	count: 0,
	countWithDescendants: 0,
	children,
} );
const INITIAL = [
	node( 1, 'Clothing', [ node( 2, 'Shirts' ) ] ),
	node( 3, 'Bags' ),
];
const CREATED = node( 9, 'Caps' );
const SETTINGS = {
	user: { id: 7, name: 'Mia', capabilities: { manage_product_terms: true } },
	settings: { category_panel: true },
};
const PRODUCTS = [ { id: 11 }, { id: 12 } ];
const INCOMPLETE =
	'The server returned an incomplete save response. Check the category list before creating it again.';
let reads;
let receipt;
let postFailure;
let selectionServer;
let immediateReadback;

const deferred = () => {
	let resolve;
	let reject;
	const promise = new Promise( ( yes, no ) => {
		resolve = yes;
		reject = no;
	} );
	return { promise, resolve, reject };
};

beforeEach( () => {
	window.localStorage.clear();
	window.wp = { a11y: { speak: jest.fn() } };
	reads = [];
	receipt = CREATED;
	postFailure = null;
	selectionServer = null;
	immediateReadback = false;
	apiFetch.mockReset();
	apiFetch.mockImplementation( ( options ) => {
		const route = options.url.split( '?' )[ 0 ];
		const method = options.method || 'GET';
		if ( route === 'categories' && method === 'GET' ) {
			const read = deferred();
			reads.push( read );
			if ( immediateReadback && reads.length > 1 ) {
				read.resolve( [ CREATED ] );
			}
			return read.promise;
		}
		if ( route === 'categories' && method === 'POST' ) {
			return postFailure
				? Promise.reject( postFailure )
				: Promise.resolve( { item: receipt, status: 'complete' } );
		}
		return Promise.resolve().then( () => {
			if ( selectionServer ) {
				return selectionServer.handle( options );
			}
			throw new Error( `Unexpected ${ method } ${ route }` );
		} );
	} );
} );
afterEach( () => {
	delete window.wp;
} );

const settle = async ( index, nodes = INITIAL, failure = null ) => {
	await waitFor( () => expect( reads.length ).toBeGreaterThan( index ) );
	await act( async () => {
		if ( failure ) {
			reads[ index ].reject( failure );
		} else {
			reads[ index ].resolve( nodes );
		}
		await reads[ index ].promise.catch( () => {} );
	} );
};

/**
 * Catalogue ownership: one real shared tree and the real product-selection hook.
 *
 * @param {Object} props         Props.
 * @param {Object} props.session Optional selection session.
 * @return {Element} Panel and the catalogue's selected rows.
 */
function SharedPanel( { session } ) {
	const tree = useCategoryTree();
	const selection = useSelection( {
		conditions: {},
		items: PRODUCTS,
		session,
	} );
	return (
		<>
			<div aria-label="Selected products">
				{ selection.tableSelection.join( ',' ) }
			</div>
			<CategoryPanel tree={ tree } settings={ SETTINGS } />
		</>
	);
}

const start = async (
	element = <CategoryPanel settings={ SETTINGS } />,
	nodes = INITIAL
) => {
	const result = render( element );
	await settle( 0, nodes );
	return result;
};

const submit = async ( parent = '0' ) => {
	const opener = screen.getByRole( 'button', {
		name: /^(\+ Add category|Create your first category)$/,
	} );
	fireEvent.click( opener );
	const dialog = screen.getByRole( 'dialog', { name: 'Add a category' } );
	fireEvent.change(
		within( dialog ).getByRole( 'textbox', { name: 'Name' } ),
		{
			target: { value: '  Caps  ' },
		}
	);
	fireEvent.change( within( dialog ).getByLabelText( 'Parent category' ), {
		target: { value: parent },
	} );
	await act( async () => {
		fireEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Add category' } )
		);
	} );
	return opener;
};

const posts = () =>
	apiFetch.mock.calls.filter( ( [ o ] ) => o.method === 'POST' );
const status = () => screen.getByRole( 'status' );
const retry = () =>
	screen.getByRole( 'button', { name: 'Refresh category list' } );
const retryRead = () => {
	act( () => retry().focus() );
	fireEvent.click( retry() );
};

const expectVisibleNotice = ( message ) => {
	expect( status().textContent ).toContain( 'Caps' );
	expect( status().textContent ).toMatch( /created|saved/ );
	expect( status().textContent ).toContain( message );
	expect( status().getAttribute( 'aria-live' ) ).toBe( 'polite' );
	expect(
		status().closest( '[hidden], [aria-hidden="true"], .bulkcat-sr-only' )
	).toBeNull();
	expect( status().querySelector( '.components-notice' ) ).toBeTruthy();
	expect(
		screen.queryByRole( 'dialog', { name: 'Add a category' } )
	).toBeNull();
};

test( 'successful create shows a visible receipt while GET waits, then a confirmed tree member', async () => {
	await start();
	const opener = await submit();
	expectVisibleNotice(
		'Category “Caps” was created. Refreshing the category list…'
	);
	expect( document.activeElement ).toBe( opener );
	expect( screen.queryByRole( 'treeitem', { name: /^Caps,/ } ) ).toBeNull();
	await settle( 1, [ ...INITIAL, CREATED ] );
	expectVisibleNotice( 'Category “Caps” was created.' );
	expect( screen.getByRole( 'treeitem', { name: /^Caps,/ } ) ).toBeTruthy();
	expect( document.activeElement ).toBe( opener );
	expect( window.wp.a11y.speak ).not.toHaveBeenCalledWith(
		expect.stringMatching( /created/ ),
		expect.anything()
	);
	expect( posts() ).toHaveLength( 1 );
} );

// The native Modal restores its own focus, but the first successful GET
// replaces the empty-state opener after that restoration has already happened.
test.each( [ 'immediate', 'deferred' ] )(
	'first-category %s readback preserves useful focus when the empty opener disappears',
	async ( timing ) => {
		const { rerender } = await start(
			<CategoryPanel settings={ SETTINGS } />,
			[]
		);
		immediateReadback = timing === 'immediate';
		const opener = await submit();
		const returnedFocus = immediateReadback ? status() : opener;
		expect( document.activeElement ).toBe( returnedFocus );
		expect( opener.isConnected ).toBe( ! immediateReadback );
		// An ordinary rerender must not detach an unstable callback ref
		// and move focus before the empty opener actually disappears.
		rerender( <CategoryPanel settings={ SETTINGS } total={ 1 } /> );
		expect( document.activeElement ).toBe( returnedFocus );
		expectVisibleNotice(
			immediateReadback
				? 'Category “Caps” was created.'
				: 'Category “Caps” was created. Refreshing the category list…'
		);
		if ( timing === 'deferred' ) {
			await settle( 1, [ CREATED ] );
		}
		expectVisibleNotice( 'Category “Caps” was created.' );
		expect( opener.isConnected ).toBe( false );
		expect(
			screen.getByRole( 'treeitem', { name: /^Caps,/ } )
		).toBeTruthy();
		expect( posts() ).toHaveLength( 1 );
		expect( reads ).toHaveLength( 2 );
		expect( document.activeElement ).toBe( status() );
	}
);

test( 'first-category completion leaves focus in search when the user moved away from the empty opener', async () => {
	await start( <CategoryPanel settings={ SETTINGS } />, [] );
	const opener = await submit();
	expect( document.activeElement ).toBe( opener );
	const search = screen.getByRole( 'searchbox' );
	act( () => search.focus() );
	fireEvent.change( search, { target: { value: 'Bags' } } );
	// A render while the opener still exists must not trigger a fallback either.
	expect( document.activeElement ).toBe( search );
	await settle( 1, [ CREATED ] );
	expectVisibleNotice( 'Category “Caps” was created.' );
	expect( opener.isConnected ).toBe( false );
	expect( screen.queryByRole( 'treeitem', { name: /^Caps,/ } ) ).toBeNull();
	expect( search.value ).toBe( 'Bags' );
	expect( document.activeElement ).toBe( search );
	expect( posts() ).toHaveLength( 1 );
} );

test( 'omitted readback explains the saved result; two retries issue one POST and keep useful focus', async () => {
	await start();
	await submit();
	await settle( 1 );
	expectVisibleNotice(
		'Category “Caps” was created, but is missing from the refreshed category list. You do not need to create it again.'
	);
	expect( screen.queryByRole( 'treeitem', { name: /^Caps,/ } ) ).toBeNull();
	retryRead();
	expect( document.activeElement ).toBe( retry() );
	await settle( 2, INITIAL, html500() );
	expectVisibleNotice(
		'Category “Caps” was created, but the category list could not refresh.'
	);
	expect( document.activeElement ).toBe( retry() );
	expect(
		screen.getByText( 'The categories could not be loaded.' )
	).toBeTruthy();
	expect(
		screen.getByRole( 'treeitem', { name: /^Clothing,/ } )
	).toBeTruthy();
	fireEvent.click( screen.getByRole( 'button', { name: 'Show details' } ) );
	expectNoRawText();
	retryRead();
	await settle( 3, [ ...INITIAL, CREATED ] );
	expectVisibleNotice( 'Category “Caps” was created.' );
	expect(
		screen.queryByRole( 'button', { name: 'Refresh category list' } )
	).toBeNull();
	expect( document.activeElement ).toBe( status() );
	expect(
		screen.queryByText( 'The categories could not be loaded.' )
	).toBeNull();
	expect( posts() ).toHaveLength( 1 );
	expect( reads ).toHaveLength( 4 );
} );

test( 'failed first readback retains the existing tree and shows the refresh failure', async () => {
	await start();
	await submit();
	await settle( 1, INITIAL, html500() );
	expectVisibleNotice(
		'Category “Caps” was created, but the category list could not refresh.'
	);
	expect(
		screen.getByRole( 'treeitem', { name: /^Clothing,/ } )
	).toBeTruthy();
	expect(
		screen.getByText( 'The categories could not be loaded.' )
	).toBeTruthy();
	expectNoRawText();
} );

test( 'failed POST keeps the form open with no confirmed creation or extra read', async () => {
	postFailure = html500();
	await start();
	await submit();
	expect( screen.getByRole( 'dialog' ) ).toBeTruthy();
	expect( screen.getByRole( 'textbox', { name: 'Name' } ).value ).toBe(
		'  Caps  '
	);
	expect(
		screen.getByText(
			'The category could not be saved. Try again in a moment.'
		)
	).toBeTruthy();
	expect( screen.queryByRole( 'status' ) ).toBeNull();
	expect( reads ).toHaveLength( 1 );
	expect( posts() ).toHaveLength( 1 );
	expectNoRawText();
} );

test( 'a sparse confirmed receipt falls back to the trimmed submitted name', async () => {
	receipt = { id: 9 };
	await start();
	await submit();
	await settle( 1, [ ...INITIAL, CREATED ] );
	expectVisibleNotice( 'Category “Caps” was created.' );
	expect( screen.getByRole( 'treeitem', { name: /^Caps,/ } ) ).toBeTruthy();
} );

test.each( [ undefined, {}, { id: 0 }, { id: '9' } ] )(
	'an incomplete response %p closes the form and offers only read-only refresh',
	async ( item ) => {
		receipt = item;
		await start();
		await submit();
		expect( screen.queryByRole( 'dialog' ) ).toBeNull();
		expect( status().textContent ).toContain( INCOMPLETE );
		expect( status().textContent ).not.toMatch( /was created/ );
		expect(
			within( status() )
				.getAllByRole( 'button' )
				.map( ( button ) => button.textContent )
		).toEqual( [ 'Refresh category list' ] );
		retryRead();
		await settle( 1, [ ...INITIAL, CREATED ] );
		expect( document.activeElement ).toBe( retry() );
		expect( status().textContent ).toContain( INCOMPLETE );
		expect( posts() ).toHaveLength( 1 );
	}
);

test( 'shared owner preserves product selection and hidden-by-search creation succeeds without changing expansion', async () => {
	selectionServer = createSelectionServer();
	const session = createSelectionSession();
	try {
		await session.add( [ 11, 12 ] );
		await start( <SharedPanel session={ session } /> );
		await waitFor( () =>
			expect(
				screen.getByLabelText( 'Selected products' ).textContent
			).toBe( '11,12' )
		);
		const before = session.getState().current;
		const previousPosts = posts().length;
		fireEvent.change( screen.getByRole( 'searchbox' ), {
			target: { value: 'Bags' },
		} );
		await submit( '1' );
		await settle( 1 );
		retryRead();
		await settle( 2, [
			node( 1, 'Clothing', [ node( 2, 'Shirts' ), CREATED ] ),
			INITIAL[ 1 ],
		] );
		expectVisibleNotice( 'Category “Caps” was created.' );
		expect( status().textContent ).not.toMatch( /missing/ );
		expect( screen.getByRole( 'searchbox' ).value ).toBe( 'Bags' );
		expect(
			screen.queryByRole( 'treeitem', { name: /^Caps,/ } )
		).toBeNull();
		expect( document.activeElement ).toBe( status() );
		fireEvent.change( screen.getByRole( 'searchbox' ), {
			target: { value: '' },
		} );
		expect(
			screen
				.getByRole( 'treeitem', { name: /^Clothing,/ } )
				.getAttribute( 'aria-expanded' )
		).toBe( 'false' );
		expect(
			screen.queryByRole( 'treeitem', { name: /^Caps,/ } )
		).toBeNull();
		expect( session.getState().current ).toBe( before );
		expect( screen.getByLabelText( 'Selected products' ).textContent ).toBe(
			'11,12'
		);
		expect( selectionServer.members( before.token ) ).toEqual( [ 11, 12 ] );
		expect( posts().length - previousPosts ).toBe( 1 );
		expect( reads ).toHaveLength( 3 );
	} finally {
		session.dispose();
	}
} );

test( 'a retry completing in the background does not take focus from search', async () => {
	await start();
	await submit();
	await settle( 1 );
	retryRead();
	const search = screen.getByRole( 'searchbox' );
	act( () => search.focus() );
	await settle( 2, [ ...INITIAL, CREATED ] );
	expectVisibleNotice( 'Category “Caps” was created.' );
	expect( document.activeElement ).toBe( search );
} );

test( 'dismissing while refresh waits prevents the completed read from restoring the notice', async () => {
	await start();
	await submit();
	fireEvent.click(
		within( status() ).getByRole( 'button', { name: 'Close' } )
	);
	await settle( 1, [ ...INITIAL, CREATED ] );
	expect( screen.queryByRole( 'status' ) ).toBeNull();
	expect( screen.getByRole( 'treeitem', { name: /^Caps,/ } ) ).toBeTruthy();
} );
