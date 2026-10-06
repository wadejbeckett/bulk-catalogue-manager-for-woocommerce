/* global beforeEach */
/**
 * T84: every test starts with a fresh selection session, an empty tab store
 * and no held Apply or undo grant, so nothing one test holds reaches the next. The session
 * module is required here, after the test file's own mocks are in place.
 */
beforeEach( () => {
	require( '../../assets/src/panels/catalogue/selection/session' ).resetSelectionSession();
	require( '../../assets/src/panels/bulk/hooks' ).resetApplyGrant?.();
	require( '../../assets/src/panels/history/undo' ).resetUndoGrants?.();
	if ( typeof window !== 'undefined' ) {
		window.sessionStorage.clear();
	}
} );
