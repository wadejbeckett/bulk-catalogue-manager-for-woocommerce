/* global beforeEach */
/**
 * T84: every test starts with a fresh selection session and an empty tab
 * store, so a selection made by one test never reaches the next. The session
 * module is required here, after the test file's own mocks are in place.
 */
beforeEach( () => {
	require( '../../assets/src/panels/catalogue/selection/session' ).resetSelectionSession();
	if ( typeof window !== 'undefined' ) {
		window.sessionStorage.clear();
	}
} );
