/**
 * Creation receipts follow the latest completed tree read. Only the HTTP
 * transport is mocked: request construction, the hook and tree index are real.
 */
import {
	act,
	render,
	renderHook,
	screen,
	waitFor,
} from '@testing-library/react';

import CategoryPanel from '../../assets/src/panels/categories/category-panel';
import useCategoryTree from '../../assets/src/panels/categories/use-category-tree';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );
const apiFetch = require( '@wordpress/api-fetch' ).default;

const deferred = () => {
	let resolve, reject;
	const promise = new Promise( ( ok, no ) => {
		resolve = ok;
		reject = no;
	} );
	return { promise, resolve, reject };
};
const node = ( id, name, children = [] ) => ( {
	id,
	name,
	slug: name.toLowerCase(),
	parent: 0,
	count: 0,
	countWithDescendants: 0,
	children,
} );

beforeEach( () => {
	apiFetch.mockReset();
	window.localStorage.clear();
} );

test( 'confirmed identity survives omission and a read-only retry resolves it', async () => {
	const initial = deferred(),
		missing = deferred(),
		recovered = deferred();
	apiFetch.mockReset();
	apiFetch
		.mockReturnValueOnce( initial.promise )
		.mockReturnValueOnce( missing.promise )
		.mockReturnValueOnce( recovered.promise );
	const { result } = renderHook( () => useCategoryTree() );
	await act( async () => initial.resolve( [ node( 1, 'Clothing' ) ] ) );
	act( () => result.current.recordCreated( { id: 9 }, 'Caps' ) );
	expect( result.current.creation ).toMatchObject( {
		id: 9,
		name: 'Caps',
		phase: 'refreshing',
	} );
	await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 2 ) );
	await act( async () => missing.resolve( [ node( 1, 'Clothing' ) ] ) );
	expect( result.current.creation ).toMatchObject( {
		id: 9,
		name: 'Caps',
		phase: 'missing',
	} );
	expect( result.current.byId.has( 9 ) ).toBe( false );
	act( () => result.current.reload() );
	await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 3 ) );
	await act( async () =>
		recovered.resolve( [ node( 1, 'Clothing' ), node( 9, 'Caps' ) ] )
	);
	expect( result.current.creation.phase ).toBe( 'visible' );
	expect(
		apiFetch.mock.calls.every(
			( [ o ] ) => ( o.method || 'GET' ) === 'GET'
		)
	).toBe( true );
} );

test( 'remembers the confirmed name and description without inserting a node', async () => {
	const initial = deferred(),
		read = deferred();
	apiFetch
		.mockReturnValueOnce( initial.promise )
		.mockReturnValueOnce( read.promise );
	const { result } = renderHook( () => useCategoryTree() );
	const existing = [ node( 1, 'Clothing' ) ];
	await act( async () => initial.resolve( existing ) );
	act( () =>
		result.current.recordCreated(
			{
				id: 9,
				name: 'Summer caps',
				description: 'Cotton caps',
			},
			'  Caps  '
		)
	);
	expect( result.current.creation ).toMatchObject( {
		id: 9,
		name: 'Summer caps',
		phase: 'refreshing',
	} );
	expect( result.current.creation.revision ).toBeGreaterThan( 0 );
	expect( result.current.descriptions.get( 9 ) ).toBe( 'Cotton caps' );
	expect( result.current.nodes ).toBe( existing );
	expect( result.current.byId.has( 9 ) ).toBe( false );
	// A descendant counts as visible even when its parent is collapsed in a panel.
	await act( async () =>
		read.resolve( [ node( 1, 'Clothing', [ node( 9, 'Summer caps' ) ] ) ] )
	);
	expect( result.current.creation.phase ).toBe( 'visible' );
	expect( result.current.byId.has( 9 ) ).toBe( true );
} );

test( 'failed refresh retains the tree and identity until a retry actually finds it', async () => {
	const initial = deferred(),
		failed = deferred(),
		missing = deferred(),
		recovered = deferred();
	apiFetch
		.mockReturnValueOnce( initial.promise )
		.mockReturnValueOnce( failed.promise )
		.mockReturnValueOnce( missing.promise )
		.mockReturnValueOnce( recovered.promise );
	const { result } = renderHook( () => useCategoryTree() );
	const existing = [ node( 1, 'Clothing' ) ];
	await act( async () => initial.resolve( existing ) );
	act( () =>
		result.current.recordCreated( { id: 9, name: '' }, '  Caps  ' )
	);
	const revision = result.current.creation.revision;
	const failure = new Error( 'Read unavailable' );
	await act( async () => failed.reject( failure ) );
	expect( result.current ).toMatchObject( {
		loaded: true,
		loading: false,
		error: failure,
	} );
	expect( result.current.nodes ).toBe( existing );
	expect( result.current.byId.has( 1 ) ).toBe( true );
	expect( result.current.creation ).toEqual( {
		revision,
		id: 9,
		name: 'Caps',
		phase: 'refresh_failed',
	} );
	act( () => expect( result.current.reload() ).toBeUndefined() );
	expect( result.current.creation.phase ).toBe( 'refreshing' );
	await act( async () => missing.resolve( existing ) );
	expect( result.current.error ).toBeNull();
	expect( result.current.creation.phase ).toBe( 'missing' );
	act( () => result.current.reload() );
	await act( async () =>
		recovered.resolve( [ ...existing, node( 9, 'Caps' ) ] )
	);
	expect( result.current.creation ).toEqual( {
		revision,
		id: 9,
		name: 'Caps',
		phase: 'visible',
	} );
	expect( apiFetch ).toHaveBeenCalledTimes( 4 );
} );

test( 'two overlapping reloads resolved in reverse order keep the newest tree and receipt', async () => {
	const initial = deferred(),
		created = deferred(),
		older = deferred(),
		newer = deferred();
	apiFetch
		.mockReturnValueOnce( initial.promise )
		.mockReturnValueOnce( created.promise )
		.mockReturnValueOnce( older.promise )
		.mockReturnValueOnce( newer.promise );
	const { result } = renderHook( () => useCategoryTree() );
	await act( async () => initial.resolve( [] ) );
	act( () => result.current.recordCreated( { id: 10 }, 'Hats' ) );
	await act( async () => created.resolve( [] ) );
	act( () => result.current.reload() );
	act( () => result.current.reload() );
	await waitFor( () => expect( apiFetch ).toHaveBeenCalledTimes( 4 ) );
	await act( async () => newer.resolve( [ node( 10, 'Hats' ) ] ) );
	await act( async () => older.resolve( [] ) );
	expect( result.current.creation.id ).toBe( 10 );
	expect( result.current.creation.phase ).toBe( 'visible' );
	expect( result.current.byId.has( 10 ) ).toBe( true );
	expect( result.current.nodes ).toEqual( [ node( 10, 'Hats' ) ] );
} );

test.each( [ 'resolve', 'reject' ] )(
	'reload invalidates a read synchronously before its %s handler runs',
	async ( settle ) => {
		const initial = deferred(),
			older = deferred(),
			newer = deferred();
		apiFetch
			.mockReturnValueOnce( initial.promise )
			.mockReturnValueOnce( older.promise )
			.mockReturnValueOnce( newer.promise );
		const { result } = renderHook( () => useCategoryTree() );
		const existing = [ node( 1, 'Clothing' ) ];
		await act( async () => initial.resolve( existing ) );
		act( () => result.current.reload() );
		// React batches the reload render until after this old promise settles.
		await act( async () => {
			result.current.reload();
			older[ settle ](
				settle === 'resolve'
					? [ node( 2, 'Stale' ) ]
					: new Error( 'Stale failure' )
			);
			await older.promise.catch( () => {} );
		} );
		expect( result.current.nodes ).toBe( existing );
		expect( result.current.error ).toBeNull();
		expect( result.current.loading ).toBe( true );
		await act( async () => newer.resolve( [ node( 3, 'Current' ) ] ) );
		expect( result.current.byId.has( 3 ) ).toBe( true );
		expect( result.current.loading ).toBe( false );
	}
);

test( 'recordCreated invalidates a pre-save read before the refresh effect starts', async () => {
	const initial = deferred(),
		read = deferred();
	apiFetch
		.mockReturnValueOnce( initial.promise )
		.mockReturnValueOnce( read.promise );
	const { result } = renderHook( () => useCategoryTree() );
	await act( async () => {
		result.current.recordCreated( { id: 9 }, 'Caps' );
		initial.resolve( [ node( 1, 'Stale' ) ] );
		await initial.promise;
	} );
	expect( result.current.nodes ).toEqual( [] );
	expect( result.current.loaded ).toBe( false );
	expect( result.current.creation.phase ).toBe( 'refreshing' );
	await act( async () => read.resolve( [ node( 9, 'Caps' ) ] ) );
	expect( result.current.creation.phase ).toBe( 'visible' );
} );

test( 'a query change supersedes a pending read and equivalent queries do not loop', async () => {
	const initial = deferred(),
		older = deferred(),
		newer = deferred();
	apiFetch
		.mockReturnValueOnce( initial.promise )
		.mockReturnValueOnce( older.promise )
		.mockReturnValueOnce( newer.promise );
	const { result, rerender } = renderHook(
		( query ) => useCategoryTree( query ),
		{
			initialProps: { search: 'shirt' },
		}
	);
	await act( async () => initial.resolve( [] ) );
	act( () => result.current.recordCreated( { id: 9 }, 'Caps' ) );
	rerender( { search: 'cap' } );
	expect( apiFetch.mock.calls[ 2 ][ 0 ].url ).toBe( 'categories?search=cap' );
	await act( async () => newer.resolve( [ node( 9, 'Caps' ) ] ) );
	await act( async () => older.resolve( [ node( 1, 'Shirts' ) ] ) );
	rerender( { search: 'cap' } );
	expect( result.current.creation.phase ).toBe( 'visible' );
	expect( result.current.byId.has( 9 ) ).toBe( true );
	expect( result.current.byId.has( 1 ) ).toBe( false );
	expect( apiFetch ).toHaveBeenCalledTimes( 3 );
} );

test.each( [ 'resolve', 'reject' ] )(
	'a second creation owns the receipt after an older read %s',
	async ( settle ) => {
		const initial = deferred(),
			older = deferred(),
			newer = deferred();
		apiFetch
			.mockReturnValueOnce( initial.promise )
			.mockReturnValueOnce( older.promise )
			.mockReturnValueOnce( newer.promise );
		const { result } = renderHook( () => useCategoryTree() );
		await act( async () => initial.resolve( [] ) );
		act( () =>
			result.current.recordCreated(
				{ id: 9, description: 'First' },
				'Caps'
			)
		);
		const firstRevision = result.current.creation.revision;
		act( () =>
			result.current.recordCreated(
				{ id: 10, description: 'Second' },
				'Hats'
			)
		);
		expect( result.current.creation.revision ).toBeGreaterThan(
			firstRevision
		);
		await act( async () => newer.resolve( [ node( 10, 'Hats' ) ] ) );
		await act( async () =>
			older[ settle ](
				settle === 'resolve'
					? [ node( 9, 'Caps' ) ]
					: new Error( 'Older failure' )
			)
		);
		expect( result.current.creation ).toMatchObject( {
			id: 10,
			name: 'Hats',
			phase: 'visible',
		} );
		expect( result.current.byId.has( 10 ) ).toBe( true );
		expect( result.current.byId.has( 9 ) ).toBe( false );
		expect( result.current.error ).toBeNull();
		expect( result.current.descriptions.get( 9 ) ).toBe( 'First' );
		expect( result.current.descriptions.get( 10 ) ).toBe( 'Second' );
	}
);

test.each( [ 'resolve', 'reject' ] )(
	'dismissed receipt stays null when its pending read %s',
	async ( settle ) => {
		const initial = deferred(),
			read = deferred(),
			retry = deferred();
		apiFetch
			.mockReturnValueOnce( initial.promise )
			.mockReturnValueOnce( read.promise )
			.mockReturnValueOnce( retry.promise );
		const { result } = renderHook( () => useCategoryTree() );
		await act( async () => initial.resolve( [ node( 1, 'Clothing' ) ] ) );
		act( () => result.current.recordCreated( { id: 9 }, 'Caps' ) );
		const before = result.current.nodes;
		act( () => result.current.dismissCreation() );
		expect( result.current.creation ).toBeNull();
		expect( result.current.nodes ).toBe( before );
		await act( async () =>
			read[ settle ](
				settle === 'resolve'
					? [ node( 9, 'Caps' ) ]
					: new Error( 'Read failed' )
			)
		);
		expect( result.current.creation ).toBeNull();
		expect( result.current.byId.has( 9 ) ).toBe( settle === 'resolve' );
		act( () => result.current.reload() );
		await act( async () => retry.resolve( [ node( 9, 'Caps' ) ] ) );
		expect( result.current.creation ).toBeNull();
		expect( result.current.byId.has( 9 ) ).toBe( true );
	}
);

test.each( [ 'resolve', 'reject' ] )(
	'unmounted owner ignores pending read %s',
	async ( settle ) => {
		const initial = deferred(),
			read = deferred();
		apiFetch
			.mockReturnValueOnce( initial.promise )
			.mockReturnValueOnce( read.promise );
		const renders = [];
		const { result, unmount } = renderHook( () => {
			const tree = useCategoryTree();
			renders.push( tree );
			return tree;
		} );
		await act( async () => initial.resolve( [] ) );
		act( () => result.current.recordCreated( { id: 9 }, 'Caps' ) );
		const before = result.current;
		const renderCount = renders.length;
		unmount();
		await act( async () =>
			read[ settle ](
				settle === 'resolve'
					? [ node( 9, 'Caps' ) ]
					: new Error( 'Late failure' )
			)
		);
		expect( result.current ).toBe( before );
		expect( renders ).toHaveLength( renderCount );
		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
	}
);

test.each( [
	undefined,
	null,
	{},
	{ id: 0 },
	{ id: -1 },
	{ id: 1.5 },
	{ id: '9' },
	{ id: NaN },
] )(
	'incomplete identity %p cannot become a confirmed creation',
	async ( item ) => {
		const initial = deferred();
		apiFetch.mockReturnValueOnce( initial.promise );
		const { result } = renderHook( () => useCategoryTree() );
		await act( async () => initial.resolve( [] ) );
		act( () => result.current.recordCreated( item, 'Caps' ) );
		expect( result.current.creation ).toBeNull();
		expect( result.current.nodes ).toEqual( [] );
		expect( result.current.descriptions.size ).toBe( 0 );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
	}
);

test( 'initial failure and read-only retry preserve existing loaded and error meanings', async () => {
	const initial = deferred(),
		retry = deferred();
	apiFetch
		.mockReturnValueOnce( initial.promise )
		.mockReturnValueOnce( retry.promise );
	const { result } = renderHook( () => useCategoryTree() );
	const failure = new Error( 'Unavailable' );
	await act( async () => initial.reject( failure ) );
	expect( result.current ).toMatchObject( {
		loaded: false,
		loading: false,
		error: failure,
	} );
	act( () => expect( result.current.reload() ).toBeUndefined() );
	await act( async () => retry.resolve( [ node( 1, 'Clothing' ) ] ) );
	expect( result.current ).toMatchObject( {
		loaded: true,
		loading: false,
		error: null,
	} );
	expect( result.current.byId.has( 1 ) ).toBe( true );
} );

test( 'shared owner reconciles once for two panels whose own hooks are disabled', async () => {
	const initial = deferred(),
		read = deferred();
	apiFetch
		.mockReturnValueOnce( initial.promise )
		.mockReturnValueOnce( read.promise );
	let owner;
	function SharedOwner() {
		owner = useCategoryTree();
		return (
			<>
				<CategoryPanel tree={ owner } />
				<CategoryPanel tree={ owner } />
			</>
		);
	}
	render( <SharedOwner /> );
	await act( async () => initial.resolve( [ node( 1, 'Clothing' ) ] ) );
	expect( screen.getAllByRole( 'tree' ) ).toHaveLength( 2 );
	act( () => owner.recordCreated( { id: 9 }, 'Caps' ) );
	await act( async () =>
		read.resolve( [ node( 1, 'Clothing', [ node( 9, 'Caps' ) ] ) ] )
	);
	expect( owner.creation.phase ).toBe( 'visible' );
	expect( owner.byId.has( 9 ) ).toBe( true );
	expect( screen.queryByRole( 'treeitem', { name: /^Caps,/ } ) ).toBeNull();
	expect( apiFetch ).toHaveBeenCalledTimes( 2 );
} );
