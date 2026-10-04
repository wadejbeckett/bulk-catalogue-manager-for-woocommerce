import { changeArguments } from '../../assets/src/provider-actions/arguments';
import { rowEntry, bulkEntry } from '../../assets/src/provider-actions/entry';
import {
	selectionArgs,
	applyKey,
	usePreview,
	useApply,
	resetApplyGrant,
} from '../../assets/src/panels/bulk/hooks';
import { renderHook, act } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
jest.mock( '@wordpress/api-fetch', () => {
	const mock = jest.fn();
	mock.use = jest.fn();
	mock.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: mock };
} );
const schema = {
	part: { type: 'choice' },
	operation: { type: 'choice', choices: { add: 'Add', clear: 'Clear' } },
	values: {
		type: 'lookup',
		multiple: true,
		depends_on: [ 'part', 'operation' ],
		active_when: { argument: 'operation', op: 'in', values: [ 'add' ] },
	},
};
const target = { kind: 'product', taxonomy: null, id: 41 };
const selection = { targets: [ target ] };
const external = {
	contract: 2,
	provider: 'sample',
	identity: 'org.example.sample',
};
afterEach( () => {
	apiFetch.mockReset();
	resetApplyGrant();
	window.sessionStorage.clear();
} );
test( 'clear removes inactive values without altering target selection', () => {
	expect(
		changeArguments(
			schema,
			{ operation: 'add', values: [ 'v1' ] },
			'operation',
			'clear'
		)
	).toEqual( { operation: 'clear', values: [] } );
	expect( selection ).toEqual( { targets: [ target ] } );
} );
test( 'dependency changes clear transitive lookups, unrelated edits retain values', () => {
	expect(
		changeArguments(
			schema,
			{ part: 'a', operation: 'add', values: [ 'v1' ] },
			'part',
			'b'
		).values
	).toEqual( [] );
	expect(
		changeArguments(
			schema,
			{ part: 'a', operation: 'add', values: [ 'v1' ] },
			'other',
			true
		).values
	).toEqual( [ 'v1' ] );
} );
test( 'entries isolate row selection and preserve typed tokens and complete target identity', () => {
	expect( rowEntry( target, 'x_sample_assign' ) ).toMatchObject( {
		location: 'product.row',
		selection,
		clearSubmitted: false,
	} );
	expect(
		bulkEntry( { typed_token: 'v3.token' }, 'key', 'category' )
	).toMatchObject( {
		selection: { typed_token: 'v3.token' },
		clearSubmitted: true,
	} );
	expect( () => bulkEntry( { targets: [] }, 'key', 'product' ) ).toThrow();
	expect( selectionArgs( selection ) ).toEqual( selection );
	expect( selectionArgs( { typed_token: 'v3.token' } ) ).toEqual( {
		typed_token: 'v3.token',
	} );
	expect( selectionArgs( { token: 'v2.token' } ) ).toEqual( {
		token: 'v2.token',
	} );
} );
test( 'flat typed preview and canonical Apply retain server identity and entry with distinct grant keys', async () => {
	const entry = {
		...rowEntry( target, 'x_sample_assign' ),
		family: 'product',
		external,
	};
	const operations = [
		{
			action: 'x_sample_assign',
			args: {
				field: 'x_sample_composite',
				part: 'a',
				operation: 'add',
				values: [ 'v1' ],
			},
		},
	];
	const preview = {
		serializer: 2,
		family: 'product',
		external,
		entry: entry.location,
		operations,
		selection,
		hash: 'hash',
		scope_hash: 'scope',
	};
	apiFetch.mockResolvedValueOnce( preview );
	const hook = renderHook( () => ( {
		preview: usePreview(),
		apply: useApply(),
	} ) );
	await act( async () => {
		await hook.result.current.preview.run( {
			operations,
			selection,
			signature: 'signature',
			entry,
		} );
	} );
	expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toEqual( {
		serializer: 2,
		family: 'product',
		external,
		entry: 'product.row',
		targets: [ target ],
		operations,
		sample_size: 25,
	} );
	apiFetch
		.mockResolvedValueOnce( {
			uuid: 'id',
			request_proof: 'proof',
			intent: { opaque: true },
		} )
		.mockResolvedValueOnce( { batch_id: 12 } );
	await act( async () => {
		await hook.result.current.apply.apply( { selection, preview, entry } );
	} );
	expect( apiFetch.mock.calls[ 1 ][ 0 ].data.intent ).toEqual( {
		serializer: 2,
		family: 'product',
		external,
		entry: 'product.row',
		source: 'bulk',
		selection,
		operations,
		scope_hash: 'scope',
	} );
	expect( applyKey( selection, preview, entry ) ).not.toBe(
		applyKey(
			selection,
			{ ...preview, entry: 'product.bulk' },
			{ ...entry, location: 'product.bulk' }
		)
	);
	expect( applyKey( selection, preview, entry ) ).not.toBe(
		applyKey(
			{ targets: [ { kind: 'term', taxonomy: 'product_cat', id: 41 } ] },
			{ ...preview, family: 'category' },
			entry
		)
	);
} );

test( 'an uncertain row grant cannot resolve as a bulk or category outcome', async () => {
	const entry = {
		...rowEntry( target, 'x_sample_assign' ),
		family: 'product',
		external,
	};
	const preview = {
		serializer: 2,
		family: 'product',
		external,
		entry: 'product.row',
		operations: [],
		scope_hash: 'scope',
		hash: 'hash',
	};
	apiFetch
		.mockResolvedValueOnce( {
			uuid: 'row-id',
			request_proof: 'proof',
			intent: { row: true },
		} )
		.mockRejectedValueOnce( { code: 'http_error', data: { status: 503 } } );
	const hook = renderHook( () => useApply() );
	await act( async () => {
		await hook.result.current.apply( { selection, preview, entry } );
	} );
	apiFetch
		.mockResolvedValueOnce( {
			uuid: 'bulk-id',
			request_proof: 'proof2',
			intent: { bulk: true },
		} )
		.mockResolvedValueOnce( { batch_id: 14 } );
	const bulk = {
		...bulkEntry( selection, 'bulk', 'product' ),
		family: 'product',
		external,
	};
	await act( async () => {
		await hook.result.current.apply( {
			selection,
			preview: { ...preview, entry: 'product.bulk' },
			entry: bulk,
		} );
	} );
	expect(
		apiFetch.mock.calls[ 2 ][ 0 ].url.endsWith( 'requests/resolve' )
	).toBe( false );
	expect( apiFetch.mock.calls[ 2 ][ 0 ].data.operation ).toBe(
		'batch.apply'
	);
} );

test( 'predicate controllers clear dependent values even without redundant depends_on', () => {
	const implicit = {
		operation: { type: 'choice' },
		values: {
			...schema.values,
			depends_on: [],
			active_when: {
				argument: 'operation',
				op: 'in',
				values: [ 'add', 'remove' ],
			},
		},
	};
	expect(
		changeArguments(
			implicit,
			{ operation: 'add', values: [ 'v1' ] },
			'operation',
			'remove'
		).values
	).toEqual( [] );
} );

test.each( [
	[ 'token', 'v2.held', 'product' ],
	[ 'typed_token', 'v3.held', 'category' ],
] )(
	'typed preview and Apply preserve %s credentials',
	async ( key, token, family ) => {
		const selectionHandle = { [ key ]: token };
		const entry = {
			...bulkEntry( selectionHandle, 'held', family ),
			family,
			external,
		};
		const preview = {
			serializer: 2,
			family,
			external,
			entry: entry.location,
			operations: [],
			scope_hash: 'scope',
			hash: 'hash',
		};
		apiFetch.mockResolvedValueOnce( preview );
		const hook = renderHook( () => ( {
			preview: usePreview(),
			applier: useApply(),
		} ) );
		await act( async () => {
			await hook.result.current.preview.run( {
				operations: [],
				selection: selectionHandle,
				signature: 's',
				entry,
			} );
		} );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].data ).toMatchObject( {
			...selectionHandle,
			serializer: 2,
			family,
			entry: entry.location,
		} );
		apiFetch
			.mockResolvedValueOnce( {
				uuid: 'token-id',
				request_proof: 'proof',
				intent: { canonical: true },
			} )
			.mockResolvedValueOnce( { batch_id: 25 } );
		await act( async () => {
			await hook.result.current.applier.apply( {
				selection: selectionHandle,
				preview,
				entry,
			} );
		} );
		expect( apiFetch.mock.calls[ 1 ][ 0 ].data.intent.selection ).toEqual(
			selectionHandle
		);
		expect( apiFetch.mock.calls[ 2 ][ 0 ].data[ key ] ).toBe( token );
	}
);
