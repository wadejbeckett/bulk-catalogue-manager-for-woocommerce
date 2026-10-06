/**
 * STANDALONE-WPORG-4: the WordPress packages bundled with the screen
 * (DataViews, components and what they build on) call the host's
 * wp.i18n with no text domain, and WordPress loads translations into the
 * 'default' domain only for its own registered scripts (DataViews has none).
 * The build gives those calls the plugin's text domain, so the strings reach
 * the plugin's .pot and its JSON (wp_set_script_translations) and translate.
 */
const fs = require( 'fs' );
const path = require( 'path' );
// The Babel that @wordpress/scripts builds with.
// eslint-disable-next-line import/no-extraneous-dependencies
const { transformSync } = require( '@babel/core' );
const { parse } = require( '@babel/parser' );

const DOMAIN = 'bulk-catalogue-manager-for-woocommerce';
const ROOT = path.resolve( __dirname, '../..' );
const BUILD = path.join( ROOT, 'assets/build/index.js' );

/** Argument position of the domain for each gettext function. */
const DOMAIN_AT = { __: 1, _x: 2, _n: 3, _nx: 4 };

/**
 * The build's rule for bundled WordPress package modules.
 *
 * @param {string} file A module path.
 * @return {Object|undefined} The rule.
 */
function ruleFor( file ) {
	const config = require( '../../webpack.config.js' );
	return config.module.rules.find(
		( rule ) =>
			rule.test instanceof RegExp &&
			rule.test.test( file ) &&
			rule.use?.options?.plugins
	);
}

describe( 'bundled package strings (STANDALONE-WPORG-4)', () => {
	const DATAVIEWS = path.join(
		ROOT,
		'node_modules/@wordpress/dataviews/build-module/components/x.js'
	);

	it( 'gives a bundled package’s gettext calls the plugin’s domain', () => {
		const rule = ruleFor( DATAVIEWS );
		expect( rule ).toBeTruthy();
		const out = transformSync(
			[
				"import { __, _x, _n, _nx, sprintf } from '@wordpress/i18n';",
				"const a = __( 'Add filter' );",
				"const b = _x( 'Is', 'filter operator' );",
				"const c = _n( '%d item', '%d items', 2 );",
				"const d = _nx( '%d row', '%d rows', 2, 'table' );",
				"const e = __( 'Kept', 'other-domain' );",
				'const f = sprintf( __( "Page %1$s of %2$s" ), 1, 2 );',
			].join( '\n' ),
			{
				filename: DATAVIEWS,
				babelrc: false,
				configFile: false,
				plugins: rule.use.options.plugins,
			}
		).code;
		expect( out ).toContain( `__('Add filter', "${ DOMAIN }")` );
		expect( out ).toContain( `_x('Is', 'filter operator', "${ DOMAIN }")` );
		expect( out ).toContain(
			`_n('%d item', '%d items', 2, "${ DOMAIN }")`
		);
		expect( out ).toContain(
			`_nx('%d row', '%d rows', 2, 'table', "${ DOMAIN }")`
		);
		expect( out ).toContain( "__('Kept', 'other-domain')" );
		expect( out ).toContain( `__("Page %1$s of %2$s", "${ DOMAIN }")` );
	} );

	it( 'leaves the host packages and the plugin’s own sources alone', () => {
		[
			path.join( ROOT, 'node_modules/@wordpress/i18n/build-module/i.js' ),
			path.join(
				ROOT,
				'node_modules/@wordpress/element/build-module/e.js'
			),
			path.join( ROOT, 'assets/src/app.js' ),
		].forEach( ( file ) => expect( ruleFor( file ) ).toBeUndefined() );
	} );

	// Read from the local build, as AssetsTest does: run npm run build first.
	it( 'leaves no gettext call in the build without the plugin’s domain', () => {
		const ast = parse( fs.readFileSync( BUILD, 'utf8' ), {
			sourceType: 'script',
		} );
		const missing = new Set();
		const walk = ( node ) => {
			if ( ! node || typeof node.type !== 'string' ) {
				return;
			}
			if ( node.type === 'CallExpression' ) {
				let callee = node.callee;
				if ( callee.type === 'SequenceExpression' ) {
					callee =
						callee.expressions[ callee.expressions.length - 1 ];
				}
				const name =
					callee.type === 'MemberExpression'
						? callee.property.name
						: callee.name;
				if (
					Object.hasOwn( DOMAIN_AT, name ) &&
					node.arguments[ 0 ]?.type === 'StringLiteral'
				) {
					const domain = node.arguments[ DOMAIN_AT[ name ] ];
					if ( domain?.value !== DOMAIN ) {
						missing.add( node.arguments[ 0 ].value );
					}
				}
			}
			Object.keys( node ).forEach( ( key ) => {
				const value = node[ key ];
				if ( Array.isArray( value ) ) {
					value.forEach( walk );
				} else if ( value && typeof value === 'object' ) {
					walk( value );
				}
			} );
		};
		walk( ast.program );
		expect( [ ...missing ].slice( 0, 10 ) ).toEqual( [] );
	} );
} );
