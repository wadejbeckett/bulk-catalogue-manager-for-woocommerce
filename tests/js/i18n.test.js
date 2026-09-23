/**
 * Every user-facing string in assets/src goes through @wordpress/i18n with
 * the literal slug as its text domain (T9, SPEC §15 Standards, §16.4).
 *
 * The scan parses each module with @babel/parser and reports:
 * - a gettext call (__, _x, _n, _nx) whose text, context or domain is not a
 *   string literal, or whose domain is not the slug;
 * - a sprintf() whose format is not a gettext call;
 * - a gettext function used without importing it from @wordpress/i18n;
 * - JSX text holding a letter, outside a gettext call;
 * - a string literal given to a user-facing JSX attribute (label, title,
 *   placeholder, aria-label, alt, …) or object property (label, title, …).
 *
 * Technical strings (class names, keys, routes, ids, variants) are never in
 * those positions, so no allow-list is needed today; TECHNICAL holds any
 * that later need one, each with its reason.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { parse } = require( '@babel/parser' );

const DOMAIN = 'bulk-catalogue-manager-for-woocommerce';
const SOURCE = path.resolve( __dirname, '../../assets/src' );

/** Gettext functions and the argument positions of text and domain. */
const GETTEXT = {
	__: { texts: [ 0 ], domain: 1 },
	_x: { texts: [ 0, 1 ], domain: 2 },
	_n: { texts: [ 0, 1 ], domain: 3 },
	_nx: { texts: [ 0, 1, 3 ], domain: 4 },
};

/** Props and keys whose string value a person reads or hears. */
const USER_FACING = new Set( [
	'label',
	'title',
	'placeholder',
	'aria-label',
	'aria-description',
	'aria-valuetext',
	'alt',
	'help',
	'description',
	'searchLabel',
	'text',
	'message',
	'confirmButtonText',
	'cancelButtonText',
] );

/** Literal strings allowed in a user-facing position: value => reason. */
const TECHNICAL = new Map();

/**
 * Walk every node below `node`, calling visit( node, parent ).
 *
 * @param {Object}   node   AST node.
 * @param {Function} visit  Visitor.
 * @param {Object}   parent Parent node.
 */
function walk( node, visit, parent = null ) {
	if ( ! node || typeof node.type !== 'string' ) {
		return;
	}
	visit( node, parent );
	for ( const key of Object.keys( node ) ) {
		if ( [ 'loc', 'start', 'end', 'extra', 'comments' ].includes( key ) ) {
			continue;
		}
		const child = node[ key ];
		if ( Array.isArray( child ) ) {
			child.forEach( ( each ) => walk( each, visit, node ) );
		} else if ( child && typeof child === 'object' ) {
			walk( child, visit, node );
		}
	}
}

const isLiteral = ( node ) => node?.type === 'StringLiteral';

const calleeName = ( node ) =>
	node?.type === 'CallExpression' && node.callee.type === 'Identifier'
		? node.callee.name
		: null;

/**
 * A literal string sitting where a person reads it, or null.
 *
 * @param {Object} value Attribute or property value node.
 * @return {string|null} The literal text.
 */
function literalText( value ) {
	if ( ! value ) {
		return null;
	}
	if ( value.type === 'JSXExpressionContainer' ) {
		return literalText( value.expression );
	}
	if ( isLiteral( value ) ) {
		return value.value;
	}
	if ( value.type === 'TemplateLiteral' ) {
		return value.quasis.map( ( q ) => q.value.cooked ).join( '${}' );
	}
	return null;
}

const hasLetter = ( text ) => /\p{L}/u.test( text );

/**
 * Every i18n problem in one module's source.
 *
 * @param {string} code Source.
 * @param {string} file Name for messages.
 * @return {{problems: string[], calls: number}} Findings and gettext call count.
 */
function scan( code, file = 'input.js' ) {
	const ast = parse( code, { sourceType: 'module', plugins: [ 'jsx' ] } );
	const problems = [];
	const imported = new Set();
	let calls = 0;
	const at = ( node, what ) =>
		problems.push( `${ file }:${ node.loc.start.line } ${ what }` );

	walk( ast.program, ( node ) => {
		if (
			node.type === 'ImportDeclaration' &&
			node.source.value === '@wordpress/i18n'
		) {
			node.specifiers.forEach( ( s ) => imported.add( s.local.name ) );
		}
	} );

	walk( ast.program, ( node ) => {
		const name = calleeName( node );
		if ( name && GETTEXT[ name ] ) {
			calls++;
			const { texts, domain } = GETTEXT[ name ];
			if ( ! imported.has( name ) ) {
				at( node, `${ name }() is not imported from @wordpress/i18n` );
			}
			texts.forEach( ( index ) => {
				if ( ! isLiteral( node.arguments[ index ] ) ) {
					at(
						node,
						`${ name }() argument ${ index + 1 } is not a string literal`
					);
				}
			} );
			const given = node.arguments[ domain ];
			if ( ! isLiteral( given ) || given.value !== DOMAIN ) {
				at( node, `${ name }() text domain is not '${ DOMAIN }'` );
			}
			return;
		}
		if ( name === 'sprintf' ) {
			if ( ! imported.has( 'sprintf' ) ) {
				at( node, 'sprintf() is not imported from @wordpress/i18n' );
			}
			if ( ! GETTEXT[ calleeName( node.arguments[ 0 ] ) ] ) {
				at( node, 'sprintf() format is not a gettext call' );
			}
			return;
		}
		if ( node.type === 'JSXText' && hasLetter( node.value ) ) {
			at( node, `untranslated JSX text "${ node.value.trim() }"` );
			return;
		}
		if (
			node.type === 'JSXAttribute' &&
			USER_FACING.has( node.name.name )
		) {
			const text = literalText( node.value );
			if (
				text !== null &&
				hasLetter( text ) &&
				! TECHNICAL.has( text )
			) {
				at(
					node,
					`untranslated ${ node.name.name } attribute "${ text }"`
				);
			}
			return;
		}
		if ( node.type === 'ObjectProperty' && ! node.computed ) {
			const key = node.key.name ?? node.key.value;
			const text = literalText( node.value );
			if (
				USER_FACING.has( key ) &&
				text !== null &&
				hasLetter( text ) &&
				! TECHNICAL.has( text )
			) {
				at( node, `untranslated ${ key } property "${ text }"` );
			}
		}
	} );

	return { problems, calls };
}

/**
 * Every .js module under a directory.
 *
 * @param {string} dir Directory.
 * @return {string[]} Paths.
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
		} )
		.sort();
}

describe( 'translation readiness', () => {
	it( 'catches untranslated strings and passes translated ones', () => {
		const bad = scan(
			[
				"import { __, sprintf } from '@wordpress/i18n';",
				"import { _x } from 'somewhere-else';",
				'const A = () => <p title="Hello">Plain words</p>;',
				"const B = { label: 'Try again' };",
				"const C = __( 'Hi', 'other-domain' );",
				'const D = __( text, DOMAIN );',
				"const E = sprintf( 'Hi %s', name );",
				"const F = _x( 'Post', 'noun', 'bulk-catalogue-manager-for-woocommerce' );",
				'const G = <input aria-label={ `Search` } />;',
			].join( '\n' )
		).problems;
		expect( bad ).toEqual( [
			'input.js:3 untranslated title attribute "Hello"',
			'input.js:3 untranslated JSX text "Plain words"',
			'input.js:4 untranslated label property "Try again"',
			"input.js:5 __() text domain is not 'bulk-catalogue-manager-for-woocommerce'",
			'input.js:6 __() argument 1 is not a string literal',
			"input.js:6 __() text domain is not 'bulk-catalogue-manager-for-woocommerce'",
			'input.js:7 sprintf() format is not a gettext call',
			'input.js:8 _x() is not imported from @wordpress/i18n',
			'input.js:9 untranslated aria-label attribute "Search"',
		] );

		const good = scan(
			[
				"import { __, _n, sprintf } from '@wordpress/i18n';",
				"const A = () => <p className=\"x\" title={ __( 'Hello', 'bulk-catalogue-manager-for-woocommerce' ) }>{ __( 'Words', 'bulk-catalogue-manager-for-woocommerce' ) } · 3</p>;",
				"const B = { key: 'catalogue', label: __( 'Catalogue', 'bulk-catalogue-manager-for-woocommerce' ) };",
				"const C = sprintf( _n( '%d item', '%d items', n, 'bulk-catalogue-manager-for-woocommerce' ), n );",
			].join( '\n' )
		);
		expect( good.problems ).toEqual( [] );
		expect( good.calls ).toBe( 4 );
	} );

	it( 'finds every user-facing string in assets/src translated with the literal domain', () => {
		const files = modules( SOURCE );
		expect( files.length ).toBeGreaterThan( 5 );
		let calls = 0;
		const problems = files.flatMap( ( file ) => {
			const result = scan(
				fs.readFileSync( file, 'utf8' ),
				path.relative( SOURCE, file )
			);
			calls += result.calls;
			return result.problems;
		} );
		expect( problems ).toEqual( [] );
		// The scan must actually meet the screen's strings.
		expect( calls ).toBeGreaterThan( 10 );
	} );
} );
