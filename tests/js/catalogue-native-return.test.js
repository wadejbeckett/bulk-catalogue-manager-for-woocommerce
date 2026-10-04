import { act, renderHook } from '@testing-library/react';
import { useNativeReturn } from '../../assets/src/panels/catalogue/use-native-return';

it( 'coalesces browser return reads and only probes the current drawn rows', async () => {
	let finish;
	const reconcile = jest.fn(
		() =>
			new Promise( ( resolve ) => {
				finish = resolve;
			} )
	);
	const session = {
		probe: jest.fn(),
		clear: jest.fn(),
		finish: jest.fn(),
		add: jest.fn(),
	};
	const view = renderHook(
		( { drawn } ) => useNativeReturn( { reconcile, session, drawn } ),
		{ initialProps: { drawn: [ 11, 12 ] } }
	);
	act( () => {
		window.dispatchEvent(
			new window.PageTransitionEvent( 'pageshow', { persisted: true } )
		);
		window.dispatchEvent( new Event( 'focus' ) );
	} );
	expect( reconcile ).toHaveBeenCalledTimes( 1 );
	view.rerender( { drawn: [ 12 ] } );
	await act( async () => {
		finish( { status: 'read' } );
	} );
	expect( session.probe ).toHaveBeenCalledWith( [ 12 ] );
	for ( const method of [ 'clear', 'finish', 'add' ] ) {
		expect( session[ method ] ).not.toHaveBeenCalled();
	}
	view.unmount();
	window.dispatchEvent( new Event( 'focus' ) );
	expect( reconcile ).toHaveBeenCalledTimes( 1 );
} );

it( 'does not probe after unmount or turn a failed read into a mutation retry', async () => {
	let fail;
	const reconcile = jest.fn(
		() =>
			new Promise( ( resolve, reject ) => {
				fail = reject;
			} )
	);
	const session = { probe: jest.fn() };
	const view = renderHook( () =>
		useNativeReturn( { reconcile, session, drawn: [ 11 ] } )
	);
	act( () => window.dispatchEvent( new Event( 'focus' ) ) );
	view.unmount();
	await act( async () => {
		fail( new Error( 'Read failed' ) );
	} );
	expect( session.probe ).not.toHaveBeenCalled();
	expect( reconcile ).toHaveBeenCalledTimes( 1 );
} );

it( 'reports a failed selection inspection and retries reads without replaying intents', async () => {
	const failure = new Error( 'Selection read failed' );
	const reconcile = jest.fn().mockResolvedValue( { status: 'read' } );
	const session = {
		probe: jest
			.fn()
			.mockRejectedValueOnce( failure )
			.mockResolvedValue( undefined ),
		retry: jest.fn(),
		clear: jest.fn(),
		add: jest.fn(),
	};
	const view = renderHook( () =>
		useNativeReturn( { reconcile, session, drawn: [ 11 ] } )
	);
	await act( async () => window.dispatchEvent( new Event( 'focus' ) ) );
	expect( view.result.current.error ).toBe( failure );
	await act( async () => view.result.current.retry() );
	expect( view.result.current.error ).toBeNull();
	expect( reconcile ).toHaveBeenCalledTimes( 2 );
	expect( session.probe ).toHaveBeenCalledTimes( 2 );
	for ( const method of [ 'retry', 'clear', 'add' ] ) {
		expect( session[ method ] ).not.toHaveBeenCalled();
	}
} );
