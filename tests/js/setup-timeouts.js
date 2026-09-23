/**
 * Testing Library waits up to 5 seconds for a findBy* or waitFor under a
 * loaded machine (see jest.config.js).
 */
const { configure } = require( '@testing-library/react' );

configure( { asyncUtilTimeout: 5000 } );
