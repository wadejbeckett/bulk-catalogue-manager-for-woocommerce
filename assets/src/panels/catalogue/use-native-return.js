/** Re-read after native navigation without changing the exact selection. */
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';

export function useNativeReturn( { reconcile, session, drawn } ) {
	const latest = useRef( { reconcile, session, drawn } );
	const refreshRef = useRef( null );
	const [ error, setError ] = useState( null );
	latest.current = { reconcile, session, drawn };
	useEffect( () => {
		let live = true;
		let pending = false;
		const refresh = async () => {
			if ( ! live || pending || document.visibilityState === 'hidden' ) {
				return;
			}
			pending = true;
			try {
				const result = await latest.current.reconcile();
				if ( live && result?.status === 'read' ) {
					await latest.current.session.probe( latest.current.drawn );
					if ( live ) {
						setError( null );
					}
				}
			} catch ( failure ) {
				if ( live ) {
					setError( failure );
				}
			} finally {
				pending = false;
			}
		};
		refreshRef.current = refresh;
		const pageshow = ( event ) => {
			if ( event.persisted ) {
				refresh();
			}
		};
		window.addEventListener( 'pageshow', pageshow );
		window.addEventListener( 'focus', refresh );
		return () => {
			live = false;
			refreshRef.current = null;
			window.removeEventListener( 'pageshow', pageshow );
			window.removeEventListener( 'focus', refresh );
		};
	}, [] );
	const retry = useCallback( () => refreshRef.current?.(), [] );
	return { error, retry };
}
