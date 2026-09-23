/**
 * ESLint: wp-scripts' own flat config (@wordpress/eslint-plugin recommended,
 * with Prettier), plus the markup-safety rule for the screen's source (T7,
 * SPEC §13): the screen renders text as text, so dangerouslySetInnerHTML is
 * an error under assets/src.
 */
const defaultConfig = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...defaultConfig,
	{
		files: [ 'assets/src/**/*.{js,jsx}' ],
		rules: {
			'react/no-danger': 'error',
		},
	},
];
