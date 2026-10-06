/**
 * Build configuration: wp-scripts' own, with the entry at assets/src/index.js
 * and the output in assets/build (SPEC §15 Layout).
 *
 * DataViews is bundled at the tag pinned for the supported WordPress major
 * (README, SPEC §15 DataViews), and so are the components package, the private
 * API registry and every other WordPress package it builds on, so the private
 * component APIs DataViews unlocks always come from the copy it was released
 * with, never the host's. Only the packages that must be the host's single
 * shared instance stay external (the script dependencies in index.asset.php):
 * React; element (one React); i18n (the translations
 * wp_set_script_translations() loads); api-fetch (its nonce middleware); url;
 * and the host singletons data, date (the site's time zone and formats),
 * hooks and a11y (the admin's live regions).
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );

const HOST = new Set( [
	'@wordpress/a11y',
	'@wordpress/api-fetch',
	'@wordpress/data',
	'@wordpress/date',
	'@wordpress/element',
	'@wordpress/hooks',
	'@wordpress/i18n',
	'@wordpress/url',
] );

/** The plugin's text domain (SPEC §15). */
const TEXT_DOMAIN = 'bulk-catalogue-manager-for-woocommerce';

/** Gettext functions and how many arguments they take before the domain. */
const GETTEXT = { __: 1, _x: 2, _n: 3, _nx: 4 };

/**
 * STANDALONE-WPORG-4: the bundled WordPress packages (DataViews, components
 * and what they build on) call the host's wp.i18n with no text domain, so
 * their strings sit in WordPress's 'default' domain, which WordPress fills
 * only for its own registered scripts (it registers no DataViews script).
 * This Babel plugin gives each such call the plugin's domain, so the strings
 * reach the plugin's .pot (composer make-pot reads assets/build), its
 * translate.wordpress.org project and the JSON wp_set_script_translations()
 * loads. A call that names a domain already is left alone.
 *
 * @param {Object} babel       Babel.
 * @param {Object} babel.types AST builders.
 * @return {Object} Plugin.
 */
function textDomainPlugin( { types } ) {
	return {
		name: 'bulkcat-text-domain',
		visitor: {
			CallExpression( call ) {
				const callee = call.get( 'callee' );
				if ( ! callee.isIdentifier() ) {
					return;
				}
				// The function as imported from @wordpress/i18n, whatever its local name.
				const binding = call.scope.getBinding( callee.node.name );
				if (
					binding?.kind !== 'module' ||
					binding.path.parent.source?.value !== '@wordpress/i18n'
				) {
					return;
				}
				const before = GETTEXT[ binding.path.node.imported?.name ];
				if ( ! before || call.node.arguments.length !== before ) {
					return;
				}
				call.node.arguments.push( types.stringLiteral( TEXT_DOMAIN ) );
			},
		},
	};
}

/** The modules of a bundled (not host) WordPress package. */
const BUNDLED_PACKAGE = new RegExp(
	'[\\\\/]node_modules[\\\\/]@wordpress[\\\\/](?!(?:' +
		[ ...HOST ]
			.map( ( name ) => name.replace( '@wordpress/', '' ) )
			.join( '|' ) +
		')[\\\\/])[^\\\\/]+[\\\\/]build-module[\\\\/].*\\.m?js$'
);

module.exports = {
	...defaultConfig,
	entry: { index: path.resolve( __dirname, 'assets/src/index.js' ) },
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'assets/build' ),
	},
	module: {
		...defaultConfig.module,
		rules: [
			...defaultConfig.module.rules,
			// STANDALONE-WPORG-4: bundled package strings in the plugin's domain.
			{
				test: BUNDLED_PACKAGE,
				use: {
					loader: require.resolve( 'babel-loader' ),
					options: {
						babelrc: false,
						configFile: false,
						compact: false,
						plugins: [ textDomainPlugin ],
					},
				},
			},
			// @wordpress/dataviews declares `sideEffects: false`, so webpack
			// would drop index.js's bare import of its stylesheet (the
			// filters, chips, grid and table rules) from the build.
			{
				test: /[\\/]@wordpress[\\/]dataviews[\\/]build-style[\\/]style\.css$/,
				sideEffects: true,
			},
		],
	},
	plugins: [
		...defaultConfig.plugins.filter(
			( plugin ) =>
				! ( plugin instanceof DependencyExtractionWebpackPlugin )
		),
		new DependencyExtractionWebpackPlugin( {
			requestToExternal( request ) {
				if (
					request.startsWith( '@wordpress/' ) &&
					! HOST.has( request )
				) {
					return false;
				}
				return undefined;
			},
		} ),
	],
};
