/**
 * A stand-in for the plugin's exact-selection and durable-request routes
 * (T81–T83), for the screen's tests (T84): grants, receipts, frozen
 * snapshots with leases and retention, composition, inspection, renewal and
 * release, and durable Apply and undo, all in memory. Failures can be
 * injected before or after a route acts (a refusal, or an answer lost on the
 * way back), and answers can be held back to arrive late. Not a test file.
 */
const { getQueryArgs } = require( '@wordpress/url' );

const LEASE = 15 * 60 * 1000;
const RETAIN = 7 * 24 * 3600 * 1000;

/** The objects the fake catalogue holds. */
const OBJECTS = [
	{ id: 11, name: 'Classic Cotton T-Shirt', sku: 'TSH-100', type: 'product' },
	{ id: 12, name: 'Ladies Golf Shirt', sku: 'GLF-120', type: 'product' },
	{ id: 21, name: 'Polo Shirt', sku: 'POL-1', type: 'product' },
	{ id: 22, name: 'Rain Jacket', sku: 'RJ-2', type: 'product' },
	{ id: 31, name: 'Cap', sku: 'CAP-1', type: 'product' },
	{
		id: 2201,
		name: 'Polo Shirt - Small',
		sku: 'POL-1-S',
		type: 'product_variation',
	},
];

/**
 * A REST error as apiFetch rejects with it.
 *
 * @param {string} code    Code.
 * @param {number} status  HTTP status.
 * @param {string} message Sentence.
 * @param {Object} data    More data.
 * @return {Object} Error.
 */
function restError( code, status, message = code, data = {} ) {
	return { code, message, data: { status, ...data } };
}

/**
 * A new fake server.
 *
 * @param {Object}        options         Options.
 * @param {Array<Object>} options.objects Catalogue objects.
 * @return {Object} Server.
 */
function createSelectionServer( { objects = OBJECTS } = {} ) {
	let now = Date.parse( '2026-09-28T10:00:00Z' );
	let seq = 0;
	let snapSeq = 0;
	let batchSeq = 100;
	const byId = new Map( objects.map( ( object ) => [ object.id, object ] ) );
	const snapshots = new Map();
	const grants = new Map();
	const batches = new Map();
	const failures = [];
	const held = new Map();
	const log = [];
	let matches = ( query ) =>
		objects
			.filter(
				( object ) =>
					object.type === 'product' &&
					( ! query?.search ||
						object.name.includes( query.search ) ||
						object.sku.includes( query.search ) )
			)
			.map( ( object ) => object.id );

	const iso = ( ms ) => new Date( ms ).toISOString().replace( '.000', '' );
	const tokenOf = ( snap ) => `v2.${ snap.id }.${ snap.epoch }.sig`;
	const parse = ( token ) => {
		const parts = String( token || '' ).split( '.' );
		return parts.length === 4 && parts[ 0 ] === 'v2'
			? { id: parts[ 1 ], epoch: Number( parts[ 2 ] ) }
			: null;
	};
	const sorted = ( ids ) =>
		[ ...new Set( ids.map( Number ) ) ].sort( ( a, b ) => a - b );

	const envelope = ( snap, replayed = false ) => {
		const members = [ ...snap.members ];
		return {
			token: tokenOf( snap ),
			kind: 'snapshot',
			count: members.length,
			by_type: {
				products: members.filter(
					( id ) => byId.get( id )?.type !== 'product_variation'
				).length,
				variations: members.filter(
					( id ) => byId.get( id )?.type === 'product_variation'
				).length,
			},
			authorization_epoch: snap.epoch,
			authorization_expires_at: iso( snap.expiresAt ),
			retained_until: iso( snap.retainedUntil ),
			requires_preview: snap.expiresAt <= now,
			replayed,
		};
	};

	const create = ( members ) => {
		snapSeq += 1;
		const snap = {
			id: `s${ String( snapSeq ).padStart( 31, '0' ) }`,
			members: new Set( sorted( members ) ),
			epoch: 1,
			expiresAt: now + LEASE,
			retainedUntil: now + RETAIN,
			released: false,
		};
		snapshots.set( snap.id, snap );
		return snap;
	};

	const load = ( token, current = true ) => {
		const parsed = parse( token );
		const snap = parsed && snapshots.get( parsed.id );
		if ( ! parsed ) {
			throw restError(
				'bulkcat_invalid_selection',
				403,
				'These selected products could not be verified. Reload the catalogue and choose them again.'
			);
		}
		if ( ! snap || snap.retainedUntil <= now ) {
			throw restError(
				'bulkcat_selection_expired',
				410,
				'These selected products are no longer retained. Choose them again before starting a new change.'
			);
		}
		if ( parsed.epoch > snap.epoch ) {
			throw restError( 'bulkcat_invalid_selection', 403 );
		}
		if (
			current &&
			( parsed.epoch !== snap.epoch || snap.expiresAt <= now )
		) {
			throw restError(
				'bulkcat_selection_renew_required',
				409,
				'Your selected products are still saved. Restore your selection, then preview the change again.'
			);
		}
		return snap;
	};

	const canonical = ( operation, raw ) => {
		if ( operation === 'selection.capture' ) {
			return raw.ids
				? { version: 1, ids: sorted( raw.ids ) }
				: { version: 1, conditions: raw.query };
		}
		if ( operation === 'selection.compose' ) {
			const key = Object.keys( raw ).find( ( each ) => each !== 'token' );
			const intent = { version: 1, base: parse( raw.token )?.id };
			if ( key.endsWith( '_ids' ) ) {
				intent[ key ] = sorted( raw[ key ] );
			} else {
				intent[ key.replace( '_token', '_snapshot' ) ] = parse(
					raw[ key ]
				)?.id;
			}
			return intent;
		}
		if ( operation === 'batch.apply' ) {
			return {
				version: 1,
				source: 'bulk',
				operations: raw.operations,
				scope_hash: raw.scope_hash,
				...( raw.ids
					? { ids: sorted( raw.ids ) }
					: { snapshot: parse( raw.token )?.id } ),
			};
		}
		return {
			version: 1,
			source: 'undo',
			batch_id: raw.batch_id,
			plan_hash: raw.plan_hash,
			force: !! raw.force,
		};
	};

	const verify = ( data, operation ) => {
		const grant = grants.get( data?.uuid );
		if ( ! grant || data.request_proof !== `proof.${ data.uuid }` ) {
			throw restError(
				'bulkcat_invalid_request',
				403,
				'This request cannot be verified. Reload the catalogue before starting a new change.'
			);
		}
		if (
			grant.operation !== operation ||
			JSON.stringify( grant.intent ) !== JSON.stringify( data.intent )
		) {
			throw restError(
				'bulkcat_request_conflict',
				409,
				'This request belongs to a different change. Resolve the original change before starting another.'
			);
		}
		return grant;
	};

	const batchAnswer = ( id, replayed ) => ( {
		batch_id: id,
		status: batches.get( id ).status,
		total_items: batches.get( id ).total_items,
		replayed,
	} );

	const acts = {
		'POST requests': ( data ) => {
			seq += 1;
			const uuid = `00000000-0000-4000-8000-${ String( seq ).padStart(
				12,
				'0'
			) }`;
			const intent = canonical( data.operation, data.intent );
			grants.set( uuid, {
				operation: data.operation,
				intent,
				result: null,
			} );
			return {
				uuid,
				request_proof: `proof.${ uuid }`,
				accept_until: Math.floor( now / 1000 ) + 86400,
				operation: data.operation,
				intent,
			};
		},
		'POST requests/resolve': ( data ) => {
			const grant = verify( data, data.operation );
			if ( ! grant.result ) {
				return { status: 'not_accepted' };
			}
			return grant.result.kind === 'batch'
				? batchAnswer( grant.result.id, true )
				: envelope( snapshots.get( grant.result.id ), true );
		},
		'POST selection': ( data ) => {
			if ( ! data.uuid ) {
				// A 1.0 body: the screen must never send one (T84).
				return { count: 0, token: 'legacy.1' };
			}
			const grant = verify( data, 'selection.capture' );
			if ( grant.result ) {
				const snap = snapshots.get( grant.result.id );
				if ( ! snap || snap.retainedUntil <= now ) {
					throw restError( 'bulkcat_selection_expired', 410 );
				}
				return envelope( snap, true );
			}
			const members = grant.intent.ids
				? grant.intent.ids
				: matches( grant.intent.conditions );
			grant.intent.ids?.forEach( ( id ) => {
				if ( ! byId.has( id ) ) {
					throw restError(
						'bulkcat_invalid_value',
						400,
						'These products or filters could not be selected. Check your selection and try again.'
					);
				}
			} );
			const snap = create( members );
			grant.result = { kind: 'snapshot', id: snap.id };
			return envelope( snap );
		},
		'POST selection/compose': ( data ) => {
			const grant = verify( data, 'selection.compose' );
			if ( grant.result ) {
				return envelope( snapshots.get( grant.result.id ), true );
			}
			const base = load( data.token );
			if ( base.id !== grant.intent.base ) {
				throw restError( 'bulkcat_invalid_selection', 403 );
			}
			const members = new Set( base.members );
			const { intent } = grant;
			if ( intent.add_ids ) {
				if ( intent.add_ids.some( ( id ) => ! byId.has( id ) ) ) {
					throw restError(
						'bulkcat_invalid_value',
						400,
						'These products or filters could not be selected. Check your selection and try again.'
					);
				}
				intent.add_ids.forEach( ( id ) => members.add( id ) );
			} else if ( intent.remove_ids ) {
				intent.remove_ids.forEach( ( id ) => members.delete( id ) );
			} else {
				const operand = load( data.add_token || data.remove_token );
				operand.members.forEach( ( id ) =>
					intent.add_snapshot
						? members.add( id )
						: members.delete( id )
				);
			}
			const snap = create( [ ...members ] );
			grant.result = { kind: 'snapshot', id: snap.id };
			return envelope( snap );
		},
		'POST selection/inspect': ( data ) => {
			const snap = load( data.token );
			const members = sorted( [ ...snap.members ] );
			const page = data.page || 1;
			const perPage = data.per_page || 25;
			const items = members
				.slice( ( page - 1 ) * perPage, page * perPage )
				.map( ( id ) => {
					const object = byId.get( id );
					const item = {
						id,
						object_type: object?.type || 'product',
						state:
							object && ! object.gone
								? 'available'
								: 'unavailable',
						removal: { token: tokenOf( snap ), id },
					};
					return item.state === 'available'
						? { ...item, name: object.name, sku: object.sku }
						: item;
				} );
			return {
				...envelope( snap ),
				items,
				pagination: {
					page,
					per_page: perPage,
					total_items: members.length,
					total_pages: Math.ceil( members.length / perPage ),
				},
				selected_ids: ( data.probe_ids || [] ).filter( ( id ) =>
					snap.members.has( id )
				),
			};
		},
		'POST selection/renew': ( data ) => {
			const parsed = parse( data.token );
			const snap = load( data.token, false );
			const expired = snap.expiresAt <= now;
			if ( expired ) {
				snap.epoch += 1;
			}
			snap.expiresAt = now + LEASE;
			snap.retainedUntil = now + RETAIN;
			snap.released = false;
			return {
				...envelope( snap ),
				requires_preview: expired || parsed.epoch !== snap.epoch,
			};
		},
		'POST selection/release': ( data ) => ( {
			released: ( data.tokens || [] ).map( ( token ) => {
				const snap = snapshots.get( parse( token )?.id );
				if ( snap ) {
					snap.released = true;
				}
				return { token };
			} ),
		} ),
		'POST batches': ( data ) => {
			if ( ! data.request_proof ) {
				throw restError(
					'bulkcat_legacy_request',
					409,
					'This change was sent by an older version of this page, so it was not made. Reload the page, then preview the change and apply it again.',
					{ batch_ids: [] }
				);
			}
			const grant = verify( data, 'batch.apply' );
			if ( grant.result ) {
				return batchAnswer( grant.result.id, true );
			}
			let total = grant.intent.ids?.length || 0;
			if ( data.token ) {
				total = load( data.token ).members.size;
			}
			batchSeq += 1;
			batches.set( batchSeq, {
				status: 'queued',
				total_items: total,
				uuid: data.uuid,
			} );
			grant.result = { kind: 'batch', id: batchSeq };
			return batchAnswer( batchSeq, false );
		},
	};

	const handle = ( options ) => {
		const method = options.method || 'GET';
		const path = options.url.split( '?' )[ 0 ];
		const key = `${ method } ${ path }`;
		const data = options.data || {};
		log.push( { key, data, query: getQueryArgs( options.url ) } );
		const failure = failures.find(
			( each ) => each.key === key && each.times > 0
		);
		const act = acts[ key ];
		const run = () => {
			if ( failure ) {
				failure.times -= 1;
				if ( failure.after ) {
					act( data );
				}
				throw failure.error;
			}
			return act( data );
		};
		if ( held.has( key ) ) {
			// Act now; the answer arrives when released.
			let outcome;
			try {
				outcome = { value: run() };
			} catch ( error ) {
				outcome = { error };
			}
			return new Promise( ( resolve, reject ) =>
				held
					.get( key )
					.push( () =>
						outcome.error
							? reject( outcome.error )
							: resolve( outcome.value )
					)
			);
		}
		return run();
	};

	return {
		acts,
		handle,
		/**
		 * Whether a route is served here.
		 *
		 * @param {Object} options apiFetch options.
		 * @return {boolean} Served.
		 */
		serves: ( options ) =>
			!! acts[
				`${ options.method || 'GET' } ${ options.url.split( '?' )[ 0 ] }`
			],
		/**
		 * Route map for catalogue-fixture route(): path => handler.
		 *
		 * @return {Object} Routes.
		 */
		routes() {
			const out = {};
			Object.keys( acts ).forEach( ( key ) => {
				const path = key.split( ' ' )[ 1 ];
				out[ path ] = ( query, options ) => handle( options );
			} );
			return out;
		},
		/**
		 * Refuse the next `times` calls to a route; `after` acts first, so
		 * the change is made and only its answer is lost.
		 *
		 * @param {string}  key           "METHOD path".
		 * @param {Object}  error         What to reject with.
		 * @param {Object}  options       Options.
		 * @param {number}  options.times How many calls.
		 * @param {boolean} options.after Act before refusing (a lost answer).
		 */
		fail( key, error, { times = 1, after = false } = {} ) {
			failures.push( { key, error, times, after } );
		},
		/**
		 * Hold every answer of a route until release().
		 *
		 * @param {string} key "METHOD path".
		 */
		hold( key ) {
			held.set( key, held.get( key ) || [] );
		},
		/**
		 * Deliver the held answers of a route, in order, and stop holding.
		 *
		 * @param {string} key "METHOD path".
		 */
		release( key ) {
			const queue = held.get( key ) || [];
			held.delete( key );
			queue.forEach( ( deliver ) => deliver() );
		},
		/**
		 * Move the clock.
		 *
		 * @param {number} ms Milliseconds.
		 */
		advance( ms ) {
			now += ms;
		},
		/** Let every lease lapse (a suspended tab). */
		expireLeases() {
			snapshots.forEach( ( snap ) => {
				snap.expiresAt = now - 1;
			} );
		},
		/** Let every snapshot's retention pass (seven idle days). */
		dropAll() {
			snapshots.forEach( ( snap ) => {
				snap.retainedUntil = now - 1;
			} );
		},
		/**
		 * The members of a token's snapshot.
		 *
		 * @param {string} token Token.
		 * @return {Array<number>} Ids.
		 */
		members( token ) {
			const snap = snapshots.get( parse( token )?.id );
			return snap ? sorted( [ ...snap.members ] ) : [];
		},
		/**
		 * Whether a token's snapshot was released.
		 *
		 * @param {string} token Token.
		 * @return {boolean} Released.
		 */
		released( token ) {
			return !! snapshots.get( parse( token )?.id )?.released;
		},
		/**
		 * Make an object unavailable (deleted or unreadable).
		 *
		 * @param {number} id Object id.
		 */
		remove( id ) {
			const object = byId.get( id );
			if ( object ) {
				object.gone = true;
			}
		},
		/**
		 * Choose what a query capture matches.
		 *
		 * @param {Function} fn ( conditions ) => ids.
		 */
		setMatches( fn ) {
			matches = fn;
		},
		/**
		 * The calls made, optionally to one "METHOD path".
		 *
		 * @param {string} key Filter.
		 * @return {Array<Object>} `{ key, data, query }`.
		 */
		calls( key ) {
			return key ? log.filter( ( each ) => each.key === key ) : log;
		},
		batches,
		grants,
		snapshots,
	};
}

module.exports = { createSelectionServer, OBJECTS, restError };
