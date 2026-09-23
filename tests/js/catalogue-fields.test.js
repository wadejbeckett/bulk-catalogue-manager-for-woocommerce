/**
 * T40: the grid's fields, columns and sort options come from the /fields
 * registry alone, and no field or storage key is written in the client
 * outside the presentation preset (panels/catalogue/defaults.js).
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { parse } = require( '@babel/parser' );
import { render } from '@testing-library/react';

import {
	defaultView,
	descriptorsOf,
	fieldsFromRegistry,
	queryFields,
	rolesOf,
} from '../../assets/src/panels/catalogue/fields';
import { toQuery } from '../../assets/src/panels/catalogue/query';
import { CellContext, textOf } from '../../assets/src/panels/catalogue/cells';
import { DEFAULT_COLUMNS } from '../../assets/src/panels/catalogue/defaults';

const { REGISTRY, ROWS } = require( './catalogue-fixture' );

const SOURCE = path.resolve( __dirname, '../../assets/src' );
const PRESET = path.join( SOURCE, 'panels/catalogue/defaults.js' );

const without = ( key ) => ( {
	...REGISTRY,
	fields: REGISTRY.fields.filter( ( field ) => field.key !== key ),
} );
const ids = ( fields ) => fields.map( ( field ) => field.id );
const sortable = ( fields ) =>
	fields
		.filter( ( field ) => field.enableSorting )
		.map( ( field ) => field.id );

describe( 'fields from the registry', () => {
	it( 'builds one DataViews field per registry field, in its order', () => {
		const fields = fieldsFromRegistry( REGISTRY );
		expect( ids( fields ) ).toEqual(
			REGISTRY.fields.map( ( field ) => field.key )
		);
		fields.forEach( ( field, index ) => {
			expect( field.label ).toBe( REGISTRY.fields[ index ].label );
			expect( field.filterBy ).toBe( false );
			expect( typeof field.render ).toBe( 'function' );
		} );
	} );

	it( 'offers sorting exactly where the registry marks a field sortable', () => {
		expect( sortable( fieldsFromRegistry( REGISTRY ) ) ).toEqual(
			REGISTRY.fields
				.filter( ( field ) => field.sortable )
				.map( ( field ) => field.key )
		);
	} );

	it( 'finds the title, image and identity fields by what they are', () => {
		const roles = rolesOf( descriptorsOf( REGISTRY ) );
		const byKey = ( key ) =>
			REGISTRY.fields.find( ( field ) => field.key === key );
		expect( byKey( roles.title ).dataviews.enableHiding ).toBe( false );
		expect( byKey( roles.media ).type ).toBe( 'image' );
		expect( byKey( roles.id ).type ).toBe( 'id' );
	} );

	it( 'drops exactly the column and sort option of a field the registry drops', () => {
		const full = fieldsFromRegistry( REGISTRY );
		for ( const dropped of REGISTRY.fields
			.map( ( field ) => field.key )
			.filter( ( key ) => DEFAULT_COLUMNS.includes( key ) ) ) {
			const fields = fieldsFromRegistry( without( dropped ) );
			expect( ids( fields ) ).toEqual(
				ids( full ).filter( ( id ) => id !== dropped )
			);
			expect( sortable( fields ) ).toEqual(
				sortable( full ).filter( ( id ) => id !== dropped )
			);
			const view = defaultView( without( dropped ), {} );
			expect( view.fields ).toEqual(
				defaultView( REGISTRY, {} ).fields.filter(
					( id ) => id !== dropped
				)
			);
			expect(
				toQuery( view, descriptorsOf( without( dropped ) ) ).fields
			).not.toContain( dropped );
		}
	} );

	it( 'opens on the default columns with the product cell carrying image and name', () => {
		const view = defaultView( REGISTRY, {
			settings: { rows_per_page: 50 },
		} );
		const roles = rolesOf( descriptorsOf( REGISTRY ) );
		expect( view.titleField ).toBe( roles.title );
		expect( view.mediaField ).toBe( roles.media );
		expect( view.fields ).toEqual( DEFAULT_COLUMNS );
		expect( view.perPage ).toBe( 50 );
		expect( view.sort ).toBeUndefined();
		expect( defaultView( REGISTRY, {} ).perPage ).toBe( 25 );
		expect(
			defaultView( REGISTRY, { settings: { rows_per_page: 30 } } ).perPage
		).toBe( 25 );
	} );

	it( 'asks for the identity, product cell, visible columns and companions', () => {
		const descriptors = descriptorsOf( REGISTRY );
		const view = defaultView( REGISTRY, {} );
		const keys = queryFields( view, descriptors );
		const roles = rolesOf( descriptors );
		expect( keys ).toEqual(
			expect.arrayContaining( [
				roles.id,
				roles.title,
				roles.media,
				...DEFAULT_COLUMNS,
			] )
		);
		// Stock status carries its quantity.
		expect( keys ).toContain( 'stock_quantity' );
		expect( new Set( keys ).size ).toBe( keys.length );
	} );
} );

describe( 'cells', () => {
	const fields = fieldsFromRegistry( REGISTRY );
	const cell = ( key, item, context ) => {
		const field = fields.find( ( each ) => each.id === key );
		const { container } = render(
			<CellContext.Provider
				value={ context || { termNames: {}, pending: new Set() } }
			>
				{ field.render( { item } ) }
			</CellContext.Provider>
		);
		return container;
	};

	it( 'shows a choice by its label, as a badge with its tone', () => {
		const badge = cell( 'status', ROWS[ 0 ] ).querySelector( '.bcm-badge' );
		expect( badge.textContent ).toBe( 'Published' );
		expect( badge.className ).toContain( 'is-good' );
	} );

	it( 'shows stock status with its quantity, and without one when unmanaged', () => {
		expect( cell( 'stock_status', ROWS[ 0 ] ).textContent ).toBe(
			'In stock · 659'
		);
		expect( cell( 'stock_status', ROWS[ 1 ] ).textContent ).toBe(
			'Out of stock'
		);
	} );

	it( 'shows an empty price as nothing, never 0', () => {
		expect( cell( 'sale_price', ROWS[ 0 ] ).textContent ).toBe( '' );
		expect( cell( 'sale_price', ROWS[ 1 ] ).textContent ).toBe( '22.10' );
	} );

	it( 'links the name to the classic product editor, a variation to its product', () => {
		const link = cell( 'name', ROWS[ 0 ] ).querySelector( 'a' );
		expect( link.textContent ).toBe( 'Classic Cotton T-Shirt' );
		expect( link.getAttribute( 'href' ) ).toBe(
			'post.php?post=11&action=edit'
		);
		const variation = cell( 'name', {
			id: 31,
			parent_id: 11,
			name: 'Classic Cotton T-Shirt',
			attribute_summary: 'Blue / Large',
		} );
		expect( variation.querySelector( 'a' ).getAttribute( 'href' ) ).toBe(
			'post.php?post=11&action=edit'
		);
		expect( variation.textContent ).toContain( 'Blue / Large' );
	} );

	it( 'names categories when their names are known, and counts them otherwise', () => {
		const names = { categories: new Map( [ [ 3, 'T-Shirts' ] ] ) };
		expect(
			cell( 'category_ids', ROWS[ 0 ], {
				termNames: names,
				pending: new Set(),
			} ).textContent
		).toBe( 'T-Shirts' );
		expect( cell( 'category_ids', ROWS[ 0 ] ).textContent ).toBe(
			'1 assigned'
		);
	} );

	it( 'renders a product name holding markup as text', () => {
		const container = cell( 'name', {
			id: 9,
			name: '<img src=x onerror=alert(1)>',
		} );
		expect( container.querySelector( 'img' ) ).toBeNull();
		expect( container.textContent ).toBe( '<img src=x onerror=alert(1)>' );
		expect( textOf( '<span>R<b>12.50</b></span> <img src=x>' ) ).toBe(
			'R12.50'
		);
	} );

	it( 'marks a row with a change in flight as pending (T46 placeholder)', () => {
		const container = cell( 'sku', ROWS[ 0 ], {
			termNames: {},
			pending: new Set( [ 11 ] ),
		} );
		const span = container.querySelector( '.bcm-cell' );
		expect( span.className ).toContain( 'is-pending' );
		expect( span.getAttribute( 'aria-busy' ) ).toBe( 'true' );
		expect(
			cell( 'sku', ROWS[ 1 ] ).querySelector( '.is-pending' )
		).toBeNull();
	} );
} );

/**
 * Every module under a directory.
 *
 * @param {string} dir Directory.
 * @return {Array<string>} Paths.
 */
function modules( dir ) {
	return fs
		.readdirSync( dir, { withFileTypes: true } )
		.flatMap( ( entry ) => {
			const full = path.join( dir, entry.name );
			if ( entry.isDirectory() ) {
				return modules( full );
			}
			return /\.jsx?$/.test( entry.name ) ? [ full ] : [];
		} );
}

/** Storage names of SPEC §5.2 that must never appear in the client. */
const STORAGE = [
	'post_title',
	'post_status',
	'comment_status',
	'product_visibility',
	'product_cat',
	'product_tag',
	'product_shipping_class',
	'exclude-from-catalog',
	'exclude-from-search',
	'_regular_price',
	'_sale_price',
	'_stock_status',
	'_manage_stock',
	'_stock',
	'_sku',
	'_weight',
	'_length',
	'_width',
	'_height',
	'_tax_status',
	'_tax_class',
	'_sold_individually',
];

/**
 * A literal that equals a field key only because it is something else,
 * keyed "file:value", each with its reason.
 */
const ALLOWED = new Map( [
	[
		'panels/catalogue/fields.js:id',
		'the registry value type of the identity field, compared with descriptor.type',
	],
] );

describe( 'no field or storage key in the client', () => {
	it( 'names field keys only in the presentation preset', () => {
		const keys = new Set( REGISTRY.fields.map( ( field ) => field.key ) );
		const found = [];
		for ( const file of modules( SOURCE ) ) {
			const relative = path.relative( SOURCE, file );
			const ast = parse( fs.readFileSync( file, 'utf8' ), {
				sourceType: 'module',
				plugins: [ 'jsx' ],
			} );
			const visit = ( node, parent ) => {
				if ( ! node || typeof node.type !== 'string' ) {
					return;
				}
				if (
					node.type === 'StringLiteral' &&
					parent?.type !== 'ImportDeclaration' &&
					parent?.type !== 'JSXAttribute'
				) {
					const value = node.value;
					if ( STORAGE.includes( value ) ) {
						found.push( `${ relative }: storage "${ value }"` );
					} else if (
						keys.has( value ) &&
						file !== PRESET &&
						! ALLOWED.has( `${ relative }:${ value }` )
					) {
						found.push( `${ relative }: field key "${ value }"` );
					}
				}
				for ( const key of Object.keys( node ) ) {
					if ( [ 'loc', 'start', 'end', 'extra' ].includes( key ) ) {
						continue;
					}
					const child = node[ key ];
					( Array.isArray( child ) ? child : [ child ] ).forEach(
						( each ) =>
							each &&
							typeof each === 'object' &&
							visit( each, node )
					);
				}
			};
			visit( ast.program, null );
		}
		expect( found ).toEqual( [] );
	} );
} );
