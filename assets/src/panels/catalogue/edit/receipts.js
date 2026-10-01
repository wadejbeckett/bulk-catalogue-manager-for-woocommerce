/** Safe synchronous Save identities. Values and deduplication keys never persist. */
import { __ } from '@wordpress/i18n';
import { issueGrant, resolveGrant, isUncertain } from '../../../requests';
import { requiredTabStore, tabScope } from '../../../tab-store';
import { patchProduct } from './requests';

const OPERATION = 'product.inline';
const STATUSES = [ 'complete', 'partial', 'failed' ];
const STATES = [ 'changed', 'already', 'skipped', 'failed' ];
const positive = ( value ) => Number.isSafeInteger( value ) && value > 0;
const keysAre = ( value, keys ) =>
	value &&
	! Array.isArray( value ) &&
	Object.keys( value ).length === keys.length &&
	keys.every( ( key ) => Object.hasOwn( value, key ) );

function storageError() {
	return {
		code: 'bulkcat_inline_storage',
		message: __(
			'This tab could not safely keep the save identity. No new save can be sent. Allow session storage, then check the save outcome again.',
			'bulk-catalogue-manager-for-woocommerce'
		),
	};
}

function heldError() {
	return {
		code: 'invalid_json',
		message: __(
			'This product is already saving or its save outcome is unconfirmed. Check its outcome before saving another edit.',
			'bulk-catalogue-manager-for-woocommerce'
		),
	};
}

/**
 * Validate the payload-free signed envelope, including older opaque versions.
 *
 * @param {Object} grant Signed grant.
 * @param {number} id    Object identity.
 * @return {Object} Safe grant only.
 */
function safeGrant( grant, id ) {
	const intent = grant?.intent;
	if (
		grant?.operation !== OPERATION ||
		typeof grant?.uuid !== 'string' ||
		! /^[\da-f]{8}-(?:[\da-f]{4}-){3}[\da-f]{12}$/i.test(
			grant?.uuid || ''
		) ||
		typeof grant?.request_proof !== 'string' ||
		! grant.request_proof ||
		! positive( grant.accept_until ) ||
		! keysAre( intent, [
			'version',
			'payload_version',
			'registry_revision',
			'object_id',
			'source',
			'commitment',
		] ) ||
		! positive( intent.version ) ||
		! positive( intent.payload_version ) ||
		intent.object_id !== id ||
		! [ 'inline', 'bulk' ].includes( intent.source ) ||
		typeof intent.registry_revision !== 'string' ||
		! /^[a-f\d]{64}$/i.test( intent.registry_revision ) ||
		typeof intent.commitment !== 'string' ||
		! /^[a-f\d]{64}$/i.test( intent.commitment )
	) {
		throw storageError();
	}
	return {
		uuid: grant.uuid,
		request_proof: grant.request_proof,
		accept_until: grant.accept_until,
		operation: OPERATION,
		intent: { ...intent },
	};
}

/**
 * Only explicit, well-formed evidence can release an identity.
 *
 * @param {Object} answer Transport response.
 * @return {Object|null} Receipt or malformed response.
 */
function receiptOf( answer ) {
	if ( ! answer || typeof answer.replayed !== 'boolean' ) {
		return null;
	}
	const state = answer.receipt_state;
	if ( state === 'closed' ) {
		if (
			! positive( answer.batch_id ) ||
			! STATUSES.includes( answer.write_status ) ||
			! Array.isArray( answer.steps ) ||
			! answer.steps.every(
				( step ) =>
					keysAre( step, [ 'field', 'state', 'code' ] ) &&
					typeof step.field === 'string' &&
					/^[a-z][a-z0-9_]{0,63}$/.test( step.field ) &&
					STATES.includes( step.state ) &&
					typeof step.code === 'string'
			)
		) {
			return null;
		}
		return {
			receipt_state: state,
			batch_id: answer.batch_id,
			write_status: answer.write_status,
			steps: answer.steps,
			replayed: answer.replayed,
		};
	}
	if ( state === 'refused' || state === 'not_accepted' ) {
		return { receipt_state: state, replayed: answer.replayed };
	}
	return state === 'unclosed' && positive( answer.batch_id )
		? {
				receipt_state: state,
				batch_id: answer.batch_id,
				replayed: answer.replayed,
			}
		: null;
}
const terminal = ( receipt ) =>
	[ 'closed', 'refused' ].includes( receipt?.receipt_state );

/**
 * One editing owner's holders. Each storage update rereads and merges the
 * namespace synchronously, so standalone owners cannot erase each other.
 * Construction loads locks before any mount effect or save can run.
 *
 * @param {Object} settings Localised site/user settings.
 * @return {Object} save, resolve, restore, heldIds and isHeld.
 */
export function createInlineReceipts( settings ) {
	const store = requiredTabStore( tabScope( settings, 'inline-writes' ) );
	const held = new Map();
	let problem = null;
	let restored;
	function read() {
		const records = store.read();
		if ( records === null ) {
			return [];
		}
		const ids = new Set();
		if ( ! Array.isArray( records ) ) {
			throw storageError();
		}
		return records.map( ( record ) => {
			if (
				! keysAre( record, [ 'object_id', 'grant' ] ) ||
				! positive( record.object_id ) ||
				ids.has( record.object_id ) ||
				! keysAre( record.grant, [
					'uuid',
					'request_proof',
					'accept_until',
					'operation',
					'intent',
				] )
			) {
				throw storageError();
			}
			ids.add( record.object_id );
			return {
				object_id: record.object_id,
				grant: safeGrant( record.grant, record.object_id ),
			};
		} );
	}
	try {
		read().forEach( ( record ) =>
			held.set( record.object_id, { ...record } )
		);
	} catch {
		problem = storageError();
	}

	const restoredIds = [ ...held.keys() ];
	function persist( entry ) {
		const records = read();
		const existing = records.find(
			( record ) => record.object_id === entry.object_id
		);
		if ( existing && existing.grant.uuid !== entry.grant.uuid ) {
			entry.grant = existing.grant;
			throw heldError();
		}
		store.write( [
			...records.filter(
				( record ) => record.object_id !== entry.object_id
			),
			{ object_id: entry.object_id, grant: entry.grant },
		] );
	}
	function adopt( entry, receipt, extra = {} ) {
		if ( terminal( receipt ) ) {
			entry.closed = receipt;
		}
		const known = entry.closed || receipt;
		let clearFailed = false;
		if ( terminal( known ) ) {
			try {
				const records = read();
				const existing = records.find(
					( record ) => record.object_id === entry.object_id
				);
				if ( existing && existing.grant.uuid !== entry.grant.uuid ) {
					throw storageError();
				}
				const remaining = records.filter(
					( record ) => record.object_id !== entry.object_id
				);
				if ( remaining.length ) {
					store.write( remaining );
				} else {
					store.clear();
				}
				held.delete( entry.object_id );
			} catch {
				clearFailed = true;
			}
		}
		return {
			...known,
			...extra,
			object_id: entry.object_id,
			held: held.has( entry.object_id ),
			clearFailed,
		};
	}
	async function resolveEntry( entry ) {
		if ( entry.closed ) {
			return adopt( entry, entry.closed, { recovered: true } );
		}
		try {
			const receipt = receiptOf( await resolveGrant( entry.grant ) );
			if ( receipt ) {
				return adopt( entry, receipt, { recovered: true } );
			}
		} catch {
			/* Denied disclosure, expiry and absence are not fences. */
		}
		return adopt(
			entry,
			{ receipt_state: 'unclosed' },
			{ recovered: true, readFailed: true }
		);
	}
	function resolve( id ) {
		const entry = held.get( Number( id ) );
		if ( ! entry?.grant ) {
			return Promise.reject( problem || heldError() );
		}
		if ( ! entry.resolving ) {
			entry.resolving = resolveEntry( entry ).finally( () => {
				entry.resolving = null;
			} );
		}
		return entry.resolving;
	}
	function save( id, values, fields = [], options = {} ) {
		id = Number( id );
		if ( problem ) {
			return Promise.reject( problem );
		}
		const key = JSON.stringify( [
			Object.keys( values )
				.sort()
				.map( ( name ) => [ name, values[ name ] ] ),
			!! options.keep,
		] );
		const existing = held.get( id );
		if ( existing ) {
			return existing.promise && existing.key === key
				? existing.promise
				: Promise.reject( heldError() );
		}
		const entry = { object_id: id, key };
		held.set( id, entry ); // Reserve before even starting issuance.
		const submitted = JSON.parse( JSON.stringify( values ) );
		entry.promise = ( async () => {
			try {
				// A second owner may have persisted since this owner mounted.
				const stored = read().find(
					( record ) => record.object_id === id
				);
				if ( stored ) {
					entry.grant = stored.grant;
					throw heldError();
				}
				entry.grant = safeGrant(
					await issueGrant( OPERATION, {
						object_id: id,
						source: 'inline',
						values: submitted,
						keep_changed_status: !! options.keep,
					} ).catch( ( error ) => {
						throw error?.code && ! isUncertain( error )
							? error
							: {
									code: 'bulkcat_inline_grant',
									message: __(
										'The save did not start. Check your connection and try saving again.',
										'bulk-catalogue-manager-for-woocommerce'
									),
								};
					} ),
					id
				);
				persist( entry );
			} catch ( error ) {
				if ( ! entry.grant ) {
					held.delete( id );
				}
				throw typeof error?.code === 'string' ? error : storageError();
			}
			let answer, refusal;
			try {
				answer = await patchProduct( id, submitted, fields, {
					...options,
					grant: entry.grant,
				} );
			} catch ( error ) {
				answer = error?.data;
				refusal = error;
			}
			const receipt = receiptOf( answer );
			if ( terminal( receipt ) ) {
				const immediate =
					! refusal &&
					Number( answer?.item?.id ) === id &&
					answer.status === receipt.write_status &&
					Array.isArray( answer.results );
				return adopt(
					entry,
					receipt,
					immediate
						? {
								item: answer.item,
								status: answer.status,
								results: answer.results,
							}
						: { recovered: true, refusal }
				);
			}
			let outcome = await resolve( id );
			if ( ! terminal( outcome ) ) {
				await new Promise( ( done ) => setTimeout( done, 1000 ) );
				outcome = await resolve( id );
			}
			return outcome;
		} )().finally( () => {
			entry.promise = null;
			entry.key = null;
		} );
		return entry.promise;
	}
	return {
		save,
		resolve,
		heldIds: () => [ ...held.keys() ],
		isHeld: ( id ) => held.has( Number( id ) ),
		restore() {
			if ( problem ) {
				return Promise.reject( problem );
			}
			if ( ! restored ) {
				restored = Promise.all( restoredIds.map( resolve ) );
			}
			return restored;
		},
	};
}
