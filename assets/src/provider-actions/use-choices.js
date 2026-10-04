import { useEffect, useRef, useState } from '@wordpress/element';
import { request } from '../api';

/**
 * Generation-bound pages and separate selected-value resolution. No opaque validation state is kept.
 * @param {Object}        root0              Root0.
 * @param {string}        root0.provider     Provider.
 * @param {string}        root0.action       Action.
 * @param {string}        root0.argument     Argument.
 * @param {Object}        root0.selection    Selection.
 * @param {Object}        root0.dependencies Dependencies.
 * @param {Array<string>} root0.selected     Selected.
 */
export function useChoices( {
	provider,
	action,
	argument,
	selection,
	dependencies,
	selected = [],
} ) {
	const scopeKey = JSON.stringify( {
		provider,
		action,
		argument,
		selection,
		dependencies,
	} );
	const [ query, setQuery ] = useState( { scopeKey, value: '' } );
	const [ retryCount, setRetry ] = useState( 0 );
	const text = query.scopeKey === scopeKey ? query.value : '';
	const key = JSON.stringify( [ scopeKey, text, retryCount ] );
	const generation = useRef( { key, value: 0 } );
	if ( generation.current.key !== key ) {
		generation.current = { key, value: generation.current.value + 1 };
	}
	const [ page, setPage ] = useState( {
		key: '',
		items: [],
		next: null,
		loading: true,
		error: null,
	} );
	const [ resolved, setResolved ] = useState( {
		key: '',
		items: [],
		loading: false,
		error: null,
	} );
	const pending = useRef( false );
	const load = useRef( null );
	const selectedKey = JSON.stringify( selected );
	useEffect( () => {
		const version = generation.current.value;
		let stopped = false;
		const body = JSON.parse( scopeKey );
		const fetchPage = async ( cursor = null ) => {
			pending.current = true;
			setPage( ( previous ) => ( {
				key,
				items: cursor && previous.key === key ? previous.items : [],
				next: null,
				loading: true,
				error: null,
			} ) );
			try {
				const response = await request( 'provider-choices/search', {
					method: 'POST',
					data: { ...body, query: text, cursor, limit: 50 },
				} );
				if ( stopped || version !== generation.current.value ) {
					return;
				}
				setPage( ( previous ) => ( {
					key,
					items: [
						...new Map(
							[
								...( cursor ? previous.items : [] ),
								...response.items,
							].map( ( item ) => [ item.value, item ] )
						).values(),
					],
					next: response.next_cursor,
					loading: false,
					error: null,
				} ) );
			} catch ( error ) {
				if ( ! stopped && version === generation.current.value ) {
					setPage( ( previous ) => ( {
						...previous,
						key,
						loading: false,
						error,
					} ) );
				}
			} finally {
				if ( ! stopped && version === generation.current.value ) {
					pending.current = false;
				}
			}
		};
		load.current = fetchPage;
		fetchPage();
		return () => {
			stopped = true;
		};
	}, [ key, scopeKey, text ] );
	useEffect( () => {
		let stopped = false;
		const version = generation.current.value;
		const values = JSON.parse( selectedKey );
		const resolvedKey = key + selectedKey;
		setResolved( {
			key: resolvedKey,
			items: [],
			loading: !! values.length,
			error: null,
		} );
		if ( values.length ) {
			request( 'provider-choices/resolve', {
				method: 'POST',
				data: { ...JSON.parse( scopeKey ), values },
			} )
				.then( ( response ) => {
					if ( ! stopped && version === generation.current.value ) {
						setResolved( {
							key: resolvedKey,
							items: response.items,
							loading: false,
							error: null,
						} );
					}
				} )
				.catch( ( error ) => {
					if ( ! stopped && version === generation.current.value ) {
						setResolved( {
							key: resolvedKey,
							items: [],
							loading: false,
							error,
						} );
					}
				} );
		}
		return () => {
			stopped = true;
		};
	}, [ key, scopeKey, selectedKey ] );
	const current = page.key === key;
	const resolvedCurrent = resolved.key === key + selectedKey;
	return {
		items: current ? page.items : [],
		selected: resolvedCurrent ? resolved.items : [],
		loading:
			! current || page.loading || ! resolvedCurrent || resolved.loading,
		error:
			( current && page.error ) ||
			( resolvedCurrent && resolved.error ) ||
			null,
		hasMore: current && !! page.next,
		search: ( value ) => setQuery( { scopeKey, value } ),
		more: () => {
			if ( current && page.next && ! pending.current ) {
				load.current( page.next );
			}
		},
		retry: () => setRetry( ( value ) => value + 1 ),
	};
}
