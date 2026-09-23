/**
 * The category panel's pure rules (T61, T62, T64): the index, search,
 * visible rows, the chip sentence, click selection, drop zones, the move
 * payloads the server expects, and the per-user panel state.
 */
import {
	categorySentence,
	dropZone,
	indexTree,
	isInside,
	joinPath,
	movePayload,
	nextValue,
	parentOptions,
	pathNames,
	searchMatches,
	visibleRows,
} from '../../assets/src/panels/categories/tree-model';
import {
	DEFAULT_WIDTH,
	MAX_WIDTH,
	MIN_WIDTH,
	readPanelState,
	storageKey,
	writePanelState,
} from '../../assets/src/panels/categories/storage';

const node = ( id, name, children = [], count = 1, all = count ) => ( {
	id,
	name,
	slug: name.toLowerCase(),
	count,
	countWithDescendants: all,
	children,
} );

const TREE = [
	node(
		1,
		'Clothing',
		[
			node( 2, 'T-Shirts', [ node( 4, 'Short sleeve' ) ] ),
			node( 3, 'Hats' ),
		],
		5,
		20
	),
	node( 5, 'Drinkware' ),
	node( 6, 'Bags' ),
];

describe( 'category tree model', () => {
	const byId = indexTree( TREE );

	it( 'indexes parents, depth, lineage and paths', () => {
		expect( byId.get( 4 ) ).toMatchObject( { parent: 2, depth: 2 } );
		expect( pathNames( byId, 4 ) ).toEqual( [
			'Clothing',
			'T-Shirts',
			'Short sleeve',
		] );
		expect( joinPath( pathNames( byId, 4 ) ) ).toBe(
			'Clothing › T-Shirts › Short sleeve'
		);
		expect( joinPath( [] ) ).toBe( '' );
		expect( isInside( byId, 1, 4 ) ).toBe( true );
		expect( isInside( byId, 1, 1 ) ).toBe( true );
		expect( isInside( byId, 4, 1 ) ).toBe( false );
	} );

	it( 'shows only open branches, and every ancestor of a search match', () => {
		expect(
			visibleRows( TREE, new Set() ).map( ( row ) => row.id )
		).toEqual( [ 1, 5, 6 ] );
		const open = visibleRows( TREE, new Set( [ 1 ] ) );
		expect( open.map( ( row ) => row.id ) ).toEqual( [ 1, 2, 3, 5, 6 ] );
		expect( open[ 1 ] ).toMatchObject( {
			depth: 1,
			parent: 1,
			setSize: 2,
			posInSet: 1,
			hasChildren: true,
			open: false,
		} );

		const kept = searchMatches( byId, 'sleeve' );
		expect( [ ...kept ].sort() ).toEqual( [ 1, 2, 4 ] );
		expect(
			visibleRows( TREE, new Set(), kept ).map( ( row ) => row.id )
		).toEqual( [ 1, 2, 4 ] );
		expect( searchMatches( byId, '  ' ) ).toBeNull();
	} );

	it( 'reads the filter value as a sentence', () => {
		expect( categorySentence( null, byId ) ).toBe( '' );
		expect(
			categorySentence( { id: 5, includeChildren: false }, byId )
		).toBe( 'Category is Drinkware' );
		expect(
			categorySentence( { id: 1, includeChildren: true }, byId )
		).toBe( 'Category is Clothing or its sub-categories' );
		expect(
			categorySentence(
				{ id: 5, ids: [ 5, 6, 3 ], includeChildren: false },
				byId
			)
		).toBe( 'Category is Drinkware, Bags or Hats' );
		expect(
			categorySentence(
				{ id: 5, ids: [ 5, 1 ], includeChildren: true },
				byId
			)
		).toBe( 'Category is Drinkware or Clothing, or their sub-categories' );
	} );

	it( 'chooses one category on a click and several with Ctrl or Cmd', () => {
		expect( nextValue( null, 5, false, true ) ).toEqual( {
			id: 5,
			includeChildren: true,
		} );
		const two = nextValue( { id: 5 }, 6, true, false );
		expect( two ).toEqual( {
			id: 5,
			ids: [ 5, 6 ],
			includeChildren: false,
		} );
		expect( nextValue( two, 5, true, false ) ).toEqual( {
			id: 6,
			includeChildren: false,
		} );
		expect( nextValue( { id: 6 }, 6, true, false ) ).toBeNull();
		expect( nextValue( two, 1, false, true ) ).toEqual( {
			id: 1,
			includeChildren: true,
		} );
	} );

	it( 'places a drop before, inside or after a row', () => {
		expect( dropZone( 2, 28 ) ).toBe( 'before' );
		expect( dropZone( 14, 28 ) ).toBe( 'inside' );
		expect( dropZone( 26, 28 ) ).toBe( 'after' );
		expect( dropZone( 5, 0 ) ).toBe( 'inside' );
	} );

	it( 'builds the move payload, counting positions without the category itself', () => {
		// Into another category: last among its children.
		expect( movePayload( TREE, byId, 5, 2, 'inside' ) ).toEqual( {
			parent: 2,
			position: undefined,
			blocked: false,
		} );
		// Reorder at the top level: Bags before Drinkware.
		expect( movePayload( TREE, byId, 6, 5, 'before' ) ).toEqual( {
			parent: 0,
			position: 1,
			blocked: false,
		} );
		// Out of Clothing to after Drinkware at the top level.
		expect( movePayload( TREE, byId, 3, 5, 'after' ) ).toEqual( {
			parent: 0,
			position: 2,
			blocked: false,
		} );
		// Onto the All products row: the top level.
		expect( movePayload( TREE, byId, 4, 0, 'inside' ) ).toEqual( {
			parent: 0,
			position: undefined,
			blocked: false,
		} );
		// Inside itself or a descendant is blocked.
		expect( movePayload( TREE, byId, 1, 4, 'inside' ) ).toEqual( {
			blocked: true,
		} );
		expect( movePayload( TREE, byId, 1, 4, 'before' ) ).toEqual( {
			blocked: true,
		} );
		// Onto itself, or to where it already is, changes nothing.
		expect( movePayload( TREE, byId, 5, 5, 'inside' ) ).toBeNull();
		expect( movePayload( TREE, byId, 5, 6, 'before' ) ).toBeNull();
		expect( movePayload( TREE, byId, 3, 1, 'inside' ) ).toBeNull();
	} );

	it( 'offers every parent but the category itself and those below it', () => {
		const options = parentOptions( byId, 2 ).map( ( o ) => o.label );
		expect( options ).toEqual( [
			'Top level',
			'Clothing',
			'Clothing › Hats',
			'Drinkware',
			'Bags',
		] );
	} );
} );

describe( 'panel state per user', () => {
	const memory = () => {
		const data = new Map();
		return {
			getItem: ( key ) => ( data.has( key ) ? data.get( key ) : null ),
			setItem: ( key, value ) => data.set( key, String( value ) ),
			data,
		};
	};

	it( 'starts from the category panel default setting', () => {
		expect( readPanelState( 7, true, memory() ) ).toEqual( {
			width: DEFAULT_WIDTH,
			collapsed: false,
		} );
		expect( readPanelState( 7, false, memory() ) ).toEqual( {
			width: DEFAULT_WIDTH,
			collapsed: true,
		} );
	} );

	it( 'round-trips width and collapsed state per user, clamped', () => {
		const store = memory();
		writePanelState( 7, { width: 300, collapsed: true }, store );
		writePanelState( 8, { width: 9999, collapsed: false }, store );
		expect( readPanelState( 7, true, store ) ).toEqual( {
			width: 300,
			collapsed: true,
		} );
		expect( readPanelState( 8, true, store ) ).toEqual( {
			width: MAX_WIDTH,
			collapsed: false,
		} );
		store.setItem( storageKey( 9 ), '{"width":10}' );
		expect( readPanelState( 9, false, store ) ).toEqual( {
			width: MIN_WIDTH,
			collapsed: true,
		} );
		store.setItem( storageKey( 10 ), 'not json' );
		expect( readPanelState( 10, true, store ).width ).toBe( DEFAULT_WIDTH );
	} );

	it( 'survives storage that throws', () => {
		const broken = {
			getItem: () => {
				throw new Error( 'denied' );
			},
			setItem: () => {
				throw new Error( 'full' );
			},
		};
		expect( readPanelState( 1, true, broken ).collapsed ).toBe( false );
		expect( () =>
			writePanelState( 1, { width: 200, collapsed: false }, broken )
		).not.toThrow();
	} );
} );
