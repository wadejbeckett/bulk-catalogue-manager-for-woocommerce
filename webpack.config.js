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
