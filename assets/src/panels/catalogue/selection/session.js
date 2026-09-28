/**
 * The exact selection's client (T84; SPEC §4.1, docs/EXACT-SELECTION.md
 * "Client sequencing"). One session per screen holds the current snapshot
 * handle the server gave, and changes it only through a serialized queue of
 * intents: ticking rows adds ids, unticking removes them, Select all
 * matching captures the view's query and adds that set. Nothing here depends
 * on the view, so search, filters, sorting, paging and modes never change
 * the selection; only Clear (selection or search), a removal, or a change
 * that completed in full does.
 *
 * Every capture and composition carries a server grant (requests.js), kept
 * with its intent until the answer is definite. A failure whose outcome is
 * unknown pauses the queue with the last confirmed snapshot still current,
 * and Retry sends the very same grants again; a later intent waits behind it,
 * so no answer can overtake another. Clear moves to a new generation, drops
 * what is queued and ignores late answers, releasing the snapshots nothing
 * refers to any more.
 *
 * The session renews every snapshot it holds (the current one, the one a
 * running change was applied to, the additions made since, and any a paused
 * intent holds) a third of the way through its lease, and again when the tab
 * becomes visible. A lease that had lapsed comes back with a new token and
 * `requires_preview`: the same products, which must be previewed again
 * (`drift`). A snapshot past its retention is reported as lost work with the
 * count it had, never as an empty selection.
 *
 * The tab's sessionStorage (never localStorage) keeps the opaque handles
 * with their counts and, while an intent is not yet confirmed, that intent:
 * its own arguments (the ids ticked or unticked, or the view's query for
 * Select all matching) and its grants. A snapshot's membership is never
 * kept there. So a reload or a tab the browser discarded while it was
 * suspended comes back to the same selection, and an unconfirmed intent can
 * be resent with its own grant; closing the tab ends it (corrected in T85:
 * T84 said "never ids", which was not so for a pending intent).
 *
 * The session keeps no whole id set: the browser holds the rows on screen
 * (`visible`, from inspection) and the ids of pending intents.
 */
import { issueGrant, isUncertain } from '../../../requests';
import { TAB_PREFIX, tabScope, tabStore } from '../../../tab-store';
import * as routes from './client';

/** Most ids in one capture or composition (the contract's mutation limit). */
export const MAX_IDS = 1000;

/** Most ids in one inspection probe. */
export const PROBE_MAX = 100;

/** The shortest wait between two renewals. */
export const MIN_RENEW_MS = 15000;

/** How often the session reads a running change it applied (T85). */
export const WATCH_MS = 5000;

/** How long a snapshot released by Clear search can still be restored. */
export const RESTORE_MS = 3600 * 1000;

/** Batch statuses after which nothing more happens (Data\Schema). */
const FINAL = [
	'complete',
	'partial',
	'failed',
	'cancelled',
	'undoing',
	'undone',
];

/** What the tab keeps (tab-store.js), and its format version. */
export const STORAGE_KEY = TAB_PREFIX;
const VERSION = 1;

/** The refusal asking for a renewal before a lapsed lease is used. */
export const RENEW_REQUIRED = 'bulkcat_selection_renew_required';

/** Refusals that mean a snapshot is gone for good. */
const LOST = [ 'bulkcat_selection_expired', 'bulkcat_invalid_selection' ];

/** Refusals that mean the user must sign in again or lacks the right now. */
const AUTH = [
	'rest_forbidden',
	'rest_not_logged_in',
	'rest_cookie_invalid_nonce',
	'bulkcat_nonce_expired',
];

/** A job abandoned because Clear started a new generation. */
const DISCARDED = { code: 'bulkcat_discarded' };

let sequence = 0;

/**
 * A key for a handle or a job, unique in this tab.
 *
 * @param {string} prefix Prefix.
 * @return {string} Key.
 */
function newKey( prefix ) {
	sequence += 1;
	return `${ prefix }${ Date.now().toString( 36 ) }${ sequence }`;
}

/**
 * Sorted, unique, positive whole ids.
 *
 * @param {Array<*>} ids Ids as strings or numbers.
 * @return {Array<number>} Ids.
 */
export function cleanIds( ids ) {
	return [
		...new Set(
			( ids || [] )
				.map( Number )
				.filter( ( id ) => Number.isInteger( id ) && id > 0 )
		),
	].sort( ( a, b ) => a - b );
}

/**
 * A list in parts of at most `size`.
 *
 * @param {Array<*>} list List.
 * @param {number}   size Part size.
 * @return {Array<Array<*>>} Parts.
 */
export function chunk( list, size ) {
	const parts = [];
	for ( let i = 0; i < list.length; i += size ) {
		parts.push( list.slice( i, i + size ) );
	}
	return parts;
}

/**
 * A handle from a snapshot envelope.
 *
 * @param {Object} envelope The route's envelope.
 * @param {string} key      The handle's key on this screen.
 * @return {Object} `{ key, token, count, byType, epoch, expiresAt, retainedUntil }`.
 */
export function toHandle( envelope, key ) {
	return {
		key,
		token: String( envelope?.token || '' ),
		count: Number( envelope?.count ) || 0,
		byType: {
			products: Number( envelope?.by_type?.products ) || 0,
			variations: Number( envelope?.by_type?.variations ) || 0,
		},
		epoch: Number( envelope?.authorization_epoch ) || 0,
		expiresAt: Date.parse( envelope?.authorization_expires_at || '' ) || 0,
		retainedUntil: Date.parse( envelope?.retained_until || '' ) || 0,
	};
}

export { tabStore };

/**
 * An error as the session reports it.
 *
 * @param {*}      error What a request rejected with.
 * @param {string} kind  What failed: save, renew or cleanup.
 * @return {Object} `{ kind, code, message, retry, uncertain }`.
 */
function report( error, kind ) {
	const code = typeof error?.code === 'string' ? error.code : '';
	const uncertain = isUncertain( error );
	return {
		kind,
		code,
		// Only the plugin's own refusals are written for people (§4.8).
		message:
			/^bulkcat_/.test( code ) && typeof error?.message === 'string'
				? error.message
				: '',
		uncertain,
		retry: uncertain || AUTH.includes( code ),
		status: Number( error?.data?.status ) || 0,
	};
}

/**
 * The session.
 */
class SelectionSession {
	/**
	 * @param {Object}   options          Options.
	 * @param {Object}   options.api      The selection routes (client.js).
	 * @param {Function} options.issue    Grant issuance (requests.js).
	 * @param {Object}   options.store    tabStore(), or null.
	 * @param {Function} options.now      Clock in milliseconds.
	 * @param {Function} options.schedule ( fn, ms ) => cancel.
	 */
	constructor( {
		api = routes,
		issue = issueGrant,
		store = null,
		now = () => Date.now(),
		schedule = ( fn, ms ) => {
			const timer = setTimeout( fn, ms );
			return () => clearTimeout( timer );
		},
	} = {} ) {
		this.api = api;
		this.issueGrant = issue;
		this.store = store;
		this.now = now;
		this.schedule = schedule;
		this.listeners = new Set();
		this.handles = new Map();
		this.grants = new Map();
		this.visible = new Map();
		this.pending = new Map();
		this.queue = [];
		this.running = null;
		this.paused = null;
		this.generation = 0;
		this.currentKey = null;
		this.additionsKey = null;
		this.submitted = null;
		this.lastMatching = '';
		this.error = null;
		this.notice = null;
		this.drift = false;
		this.lost = null;
		this.blocked = null;
		this.renewing = null;
		this.cancelRenew = null;
		this.watching = 0;
		this.cancelWatch = null;
		this.finished = new Set();
		this.restorable = null;
		this.revision = 0;
		this.disposed = false;
		this.snapshot = null;
		this.emit( false );
	}

	/* ---- reading ------------------------------------------------------ */

	/**
	 * Listen for changes.
	 *
	 * @param {Function} listener Receives the state.
	 * @return {Function} Unsubscribe.
	 */
	subscribe( listener ) {
		this.listeners.add( listener );
		return () => this.listeners.delete( listener );
	}

	/**
	 * The current state (a new object after every change).
	 *
	 * @return {Object} State.
	 */
	getState() {
		return this.snapshot;
	}

	/**
	 * Whether a row is selected, as far as the screen knows: a pending intent
	 * first, then the last inspection of the rows on screen.
	 *
	 * @param {string|number} id Row id.
	 * @return {boolean} Selected.
	 */
	isSelected( id ) {
		const key = String( id );
		const pending = this.pending.get( key );
		if ( pending ) {
			return pending === 'add';
		}
		return this.visible.get( key ) === true;
	}

	/**
	 * The token of a handle.
	 *
	 * @param {string} key Handle key.
	 * @return {string} Token.
	 */
	tokenOf( key ) {
		return this.handles.get( key )?.token || '';
	}

	/**
	 * Every handle the screen still refers to.
	 *
	 * @return {Array<string>} Keys.
	 */
	heldKeys() {
		const keys = [
			this.currentKey,
			this.additionsKey,
			this.submitted?.key,
		];
		[ this.running, this.paused, ...this.queue ].forEach( ( job ) => {
			if ( job ) {
				keys.push( job.captured, job.stage, job.final );
			}
		} );
		return [ ...new Set( keys.filter( Boolean ) ) ].filter( ( key ) =>
			this.handles.has( key )
		);
	}

	/* ---- changing ----------------------------------------------------- */

	/**
	 * Add rows to the selection.
	 *
	 * @param {Array<*>} ids Ids.
	 * @return {Promise<boolean>} Whether it was saved.
	 */
	add( ids ) {
		const clean = cleanIds( ids );
		return clean.length
			? this.enqueue( 'add', { ids: clean } )
			: Promise.resolve( true );
	}

	/**
	 * Remove rows from the selection, on this page or not.
	 *
	 * @param {Array<*>} ids Ids.
	 * @return {Promise<boolean>} Whether it was saved.
	 */
	remove( ids ) {
		const clean = cleanIds( ids );
		return clean.length
			? this.enqueue( 'remove', { ids: clean } )
			: Promise.resolve( true );
	}

	/**
	 * Add every product matching a query, captured once, now.
	 *
	 * @param {Object} query The view's conditions (selectionQuery()).
	 * @return {Promise<boolean>} Whether it was saved.
	 */
	addMatching( query ) {
		return this.enqueue( 'matching', {
			query,
			key: JSON.stringify( query || {} ),
		} );
	}

	/**
	 * Clear the selection: a new generation, nothing queued survives, late
	 * answers are ignored, and unreferenced snapshots are released. After
	 * Clear search the released snapshot can be restored (restore()) for as
	 * long as the server still keeps it: an hour after its release at most.
	 *
	 * @param {string} reason 'selection' or 'search' (Clear search).
	 */
	clear( reason = 'selection' ) {
		const was = this.currentKey
			? this.handles.get( this.currentKey )
			: null;
		this.restorable =
			reason === 'search' && was
				? {
						...was,
						until: Math.min(
							was.retainedUntil || Infinity,
							this.now() + RESTORE_MS
						),
					}
				: null;
		this.generation += 1;
		const dropped = [ this.paused, ...this.queue ].filter( Boolean );
		this.queue = [];
		this.paused = null;
		const released = [ this.currentKey, this.additionsKey ];
		dropped.forEach( ( job ) => {
			released.push( job.captured, job.stage, job.final );
			this.forget( job );
			job.settle( false );
		} );
		this.pending.clear();
		this.visible.clear();
		this.currentKey = null;
		this.additionsKey = null;
		this.lastMatching = '';
		this.error = null;
		this.drift = false;
		this.lost = null;
		this.notice =
			reason === 'search'
				? { kind: 'searchCleared', restorable: !! this.restorable }
				: null;
		this.release( released );
		this.changed();
	}

	/**
	 * Bring back the selection Clear search released, while the server still
	 * keeps it and nothing new has been selected since: the same snapshot,
	 * kept again (a renewal), never a new capture of the search.
	 *
	 * @return {Promise<boolean>} Whether it came back.
	 */
	restore() {
		const was = this.restorable;
		if ( ! was || this.currentKey || this.queue.length || this.running ) {
			return Promise.resolve( false );
		}
		return this.enqueue( 'restore', {} );
	}

	/**
	 * Send the paused intent again, with the very same grants.
	 */
	retry() {
		if ( ! this.paused ) {
			return;
		}
		const job = this.paused;
		this.paused = null;
		this.error = null;
		this.queue.unshift( job );
		this.changed();
		this.pump();
	}

	/**
	 * Forget a notice or the drift line once read.
	 *
	 * @param {string} what 'notice', 'drift', 'lost' or 'error'.
	 */
	dismiss( what ) {
		if ( what === 'drift' ) {
			this.drift = false;
		} else if ( what === 'lost' ) {
			this.lost = null;
		} else if ( what === 'error' && ! this.paused ) {
			this.error = null;
		} else {
			this.notice = null;
		}
		this.changed();
	}

	/**
	 * An Apply was accepted for the current selection: keep that snapshot
	 * until the change has finished and track what is added after it.
	 *
	 * @param {number} batchId The batch.
	 * @param {string} key     The handle it was applied to (the current one).
	 */
	submit( batchId, key = this.currentKey ) {
		if ( ! batchId || ! key || ! this.handles.has( key ) ) {
			return;
		}
		const previous = [ this.submitted?.key, this.additionsKey ];
		this.submitted = { key, batchId: Number( batchId ) };
		this.additionsKey = null;
		this.release( previous );
		this.changed();
		this.watch();
	}

	/**
	 * Follow the change applied to the selection until it has finished, and
	 * then finish() it, whichever panel is open and whether or not its
	 * progress is on screen (T85): after a reload or a visit to History the
	 * catalogue's banner is gone, and without this a change that finished in
	 * full would leave its products selected. finish() acts once per change.
	 */
	watch() {
		const id = this.submitted?.batchId || 0;
		if ( ! id || this.watching === id || this.disposed ) {
			return;
		}
		if ( this.cancelWatch ) {
			this.cancelWatch();
			this.cancelWatch = null;
		}
		this.watching = id;
		const following = () =>
			! this.disposed &&
			this.watching === id &&
			this.submitted?.batchId === id;
		const poll = async () => {
			this.cancelWatch = null;
			if ( ! following() ) {
				return;
			}
			let answer = null;
			try {
				answer = await this.api.batch( id );
			} catch {
				answer = null;
			}
			if ( ! following() ) {
				return;
			}
			if ( answer && FINAL.includes( answer.status ) ) {
				this.watching = 0;
				this.finish( id, answer );
				return;
			}
			this.cancelWatch = this.schedule(
				poll,
				answer ? WATCH_MS : WATCH_MS * 2
			);
		};
		poll();
	}

	/**
	 * A change has finished. Full success — `complete`, nothing failed and
	 * nothing pending — takes the submitted products out of the selection,
	 * keeping anything selected since; anything else keeps the selection.
	 *
	 * @param {number} batchId The batch.
	 * @param {Object} outcome Its final report.
	 * @return {Promise<boolean>} Whether the selection changed.
	 */
	finish( batchId, outcome = {} ) {
		if (
			! this.submitted ||
			this.submitted.batchId !== Number( batchId ) ||
			this.finished.has( Number( batchId ) )
		) {
			return Promise.resolve( false );
		}
		// Once per change, whoever sees it end first (the banner, the panel,
		// or the session's own watch).
		this.finished.add( Number( batchId ) );
		const success =
			outcome?.status === 'complete' &&
			( Number( outcome.failed_items ) || 0 ) === 0 &&
			( Number( outcome.pending_items ) || 0 ) === 0;
		if ( success ) {
			return this.enqueue( 'complete', { batchId: Number( batchId ) } );
		}
		const released = [ this.submitted.key, this.additionsKey ];
		this.submitted = null;
		this.additionsKey = null;
		this.notice = {
			kind: 'kept',
			batchId: Number( batchId ),
			status: String( outcome?.status || '' ),
		};
		this.release( released );
		this.changed();
		return Promise.resolve( false );
	}

	/**
	 * The tab is visible again: resolve a paused intent with its own grants,
	 * then renew everything held.
	 *
	 * @return {Promise} Done.
	 */
	wake() {
		if ( this.paused ) {
			this.retry();
		}
		return this.renewAll();
	}

	/* ---- inspection --------------------------------------------------- */

	/**
	 * Which of the rows on screen are in the selection (`selected_ids`),
	 * refreshed from the server; stale answers are ignored.
	 *
	 * @param {Array<*>} ids Rows drawn on screen.
	 * @return {Promise} Done.
	 */
	async probe( ids ) {
		const key = this.currentKey;
		if ( ! key ) {
			// Nothing is selected: nothing to ask, and nothing to redraw
			// unless a row was shown as selected.
			const shown = [ ...this.visible.values() ].some( Boolean );
			this.visible.clear();
			if ( shown ) {
				this.changed( false );
			}
			return;
		}
		const wanted = cleanIds( ids );
		if ( ! wanted.length ) {
			return;
		}
		const answers = await Promise.all(
			chunk( wanted, PROBE_MAX ).map( ( part ) =>
				this.inspectWith( key, {
					page: 1,
					per_page: 1,
					probe_ids: part,
				} )
			)
		);
		if ( this.currentKey !== key || this.disposed ) {
			return;
		}
		const selected = new Set(
			answers.flatMap( ( answer ) =>
				( answer?.selected_ids || [] ).map( String )
			)
		);
		this.visible = new Map(
			wanted.map( ( id ) => [
				String( id ),
				selected.has( String( id ) ),
			] )
		);
		if ( answers[ 0 ]?.token ) {
			this.refresh( key, answers[ 0 ] );
		}
		this.changed( false );
	}

	/**
	 * One page of the selection for the review, under current permissions.
	 *
	 * @param {number} page    Page, from 1.
	 * @param {number} perPage Rows per page, at most 100.
	 * @return {Promise<Object>} The inspection answer.
	 */
	inspectPage( page = 1, perPage = 25 ) {
		if ( ! this.currentKey ) {
			return Promise.resolve( null );
		}
		return this.inspectWith( this.currentKey, {
			page,
			per_page: perPage,
		} );
	}

	/**
	 * Inspect, renewing once when the lease had lapsed.
	 *
	 * @param {string} key     Handle.
	 * @param {Object} options Inspection arguments.
	 * @return {Promise<Object>} Answer.
	 */
	async inspectWith( key, options ) {
		try {
			return await this.api.inspect( this.tokenOf( key ), options );
		} catch ( error ) {
			if ( error?.code !== RENEW_REQUIRED ) {
				throw error;
			}
			await this.renewAll();
			if ( ! this.handles.has( key ) ) {
				throw error;
			}
			return this.api.inspect( this.tokenOf( key ), options );
		}
	}

	/* ---- renewal ------------------------------------------------------ */

	/**
	 * Renew every held snapshot, one after another, then plan the next.
	 *
	 * @return {Promise} Done.
	 */
	renewAll() {
		if ( this.renewing ) {
			return this.renewing;
		}
		const run = async () => {
			for ( const key of this.heldKeys() ) {
				const handle = this.handles.get( key );
				if ( ! handle ) {
					continue;
				}
				try {
					const envelope = await this.api.renew( handle.token );
					if ( this.disposed ) {
						return;
					}
					this.refresh( key, envelope );
					this.blocked = null;
				} catch ( error ) {
					if ( this.disposed ) {
						return;
					}
					this.renewFailed( key, error );
				}
			}
		};
		this.renewing = run().finally( () => {
			this.renewing = null;
			if ( ! this.disposed ) {
				this.planRenewal();
				this.changed();
			}
		} );
		return this.renewing;
	}

	/**
	 * Take a newer envelope for a handle. A different token means the lease
	 * had lapsed and a new epoch began: the same products, previewed again.
	 *
	 * @param {string} key      Handle.
	 * @param {Object} envelope Envelope.
	 */
	refresh( key, envelope ) {
		const old = this.handles.get( key );
		if ( ! old || ! envelope?.token ) {
			return;
		}
		const next = toHandle( envelope, key );
		this.handles.set( key, next );
		if (
			( envelope.requires_preview || next.token !== old.token ) &&
			( key === this.currentKey || key === this.submitted?.key )
		) {
			this.drift = true;
		}
	}

	/**
	 * A renewal was refused. A snapshot past its retention (or no longer
	 * this user's) is lost work; anything else keeps it for the next try.
	 *
	 * @param {string} key   Handle.
	 * @param {*}      error Refusal.
	 */
	renewFailed( key, error ) {
		if ( LOST.includes( error?.code ) ) {
			this.lose( key, error );
			return;
		}
		// Signed out or without the right now: nothing can be done until
		// that is fixed, and the retained selection is kept for then. Any
		// other failure is tried again at the next, sooner, renewal.
		if ( AUTH.includes( error?.code ) ) {
			this.blocked = report( error, 'renew' );
		}
	}

	/**
	 * Forget a snapshot the server no longer keeps, saying so when it was
	 * the selection: the count it had stays on screen, never as nothing.
	 *
	 * @param {string} key   Handle.
	 * @param {*}      error The refusal.
	 */
	lose( key, error ) {
		const handle = this.handles.get( key );
		if ( key === this.currentKey && handle ) {
			this.lost = {
				count: handle.count,
				byType: handle.byType,
				message: report( error, 'renew' ).message,
			};
			this.currentKey = null;
			this.visible.clear();
			this.drift = false;
		}
		if ( key === this.additionsKey ) {
			this.additionsKey = null;
		}
		if ( this.submitted?.key === key ) {
			this.submitted = { ...this.submitted, key: null };
		}
		this.handles.delete( key );
	}

	/**
	 * Plan the next renewal a third of the way through the shortest lease.
	 */
	planRenewal() {
		if ( this.cancelRenew ) {
			this.cancelRenew();
			this.cancelRenew = null;
		}
		const keys = this.heldKeys();
		if ( ! keys.length || this.disposed ) {
			return;
		}
		const now = this.now();
		let wait = Infinity;
		keys.forEach( ( key ) => {
			const left = ( this.handles.get( key )?.expiresAt || 0 ) - now;
			wait = Math.min( wait, left > 0 ? left / 3 : 0 );
		} );
		this.cancelRenew = this.schedule(
			() => this.renewAll(),
			Math.max( MIN_RENEW_MS, wait )
		);
	}

	/* ---- the queue ---------------------------------------------------- */

	/**
	 * Queue an intent behind any other.
	 *
	 * @param {string} type Job type.
	 * @param {Object} args Arguments.
	 * @return {Promise<boolean>} Settles when the job does.
	 */
	enqueue( type, args ) {
		if ( type !== 'restore' && type !== 'complete' && this.restorable ) {
			// Something new is selected: Restore would replace it.
			this.restorable = null;
			if ( this.notice?.kind === 'searchCleared' ) {
				this.notice = { kind: 'searchCleared', restorable: false };
			}
		}
		const job = this.makeJob( {
			id: newKey( 'j' ),
			type,
			args,
			results: {},
		} );
		job.gen = this.generation;
		this.queue.push( job );
		this.mark( job, true );
		this.changed();
		this.pump();
		return job.done;
	}

	/**
	 * A job with its settle callback.
	 *
	 * @param {Object} fields Stored fields.
	 * @return {Object} Job.
	 */
	makeJob( fields ) {
		const job = { captured: null, stage: null, final: null, ...fields };
		job.done = new Promise( ( resolve ) => {
			job.settle = resolve;
		} );
		return job;
	}

	/**
	 * Show a job's ids as selected or not while it is pending.
	 *
	 * @param {Object}  job Job.
	 * @param {boolean} on  Pending or no longer.
	 */
	mark( job, on ) {
		if ( job.type !== 'add' && job.type !== 'remove' ) {
			return;
		}
		( job.args.ids || [] ).forEach( ( id ) => {
			const key = String( id );
			if ( on ) {
				this.pending.set( key, job.type );
			} else if ( this.pending.get( key ) === job.type ) {
				this.pending.delete( key );
			}
		} );
	}

	/**
	 * Forget a job's grants: its answer is definite.
	 *
	 * @param {Object} job Job.
	 */
	forget( job ) {
		[ ...this.grants.keys() ]
			.filter( ( key ) => key.startsWith( job.id + ':' ) )
			.forEach( ( key ) => this.grants.delete( key ) );
		this.mark( job, false );
	}

	/**
	 * Run the next job, unless one runs or waits for Retry.
	 */
	async pump() {
		if ( this.running || this.paused || this.disposed ) {
			return;
		}
		const job = this.queue.shift();
		if ( ! job ) {
			this.changed();
			return;
		}
		if ( job.gen !== this.generation ) {
			this.forget( job );
			job.settle( false );
			this.pump();
			return;
		}
		this.running = job;
		this.changed();
		let ok = false;
		try {
			await this.execute( job );
			ok = true;
		} catch ( error ) {
			this.running = null;
			if ( error === DISCARDED || job.gen !== this.generation ) {
				this.release( [ job.captured, job.stage, job.final ] );
				this.forget( job );
				job.settle( false );
			} else {
				const failure = report( error, 'save' );
				if ( failure.retry ) {
					// Unknown outcome (or signed out): keep the grants, wait for Retry.
					this.paused = job;
					this.error = failure;
					this.changed();
					return;
				}
				this.failed( job, error, failure );
			}
		}
		if ( ok ) {
			this.running = null;
			this.forget( job );
			this.error = null;
			job.settle( true );
		}
		this.changed();
		this.pump();
	}

	/**
	 * A definite refusal: the job is dropped and the last confirmed
	 * selection stays.
	 *
	 * @param {Object} job     Job.
	 * @param {*}      error   Refusal.
	 * @param {Object} failure report().
	 */
	failed( job, error, failure ) {
		this.release( [ job.captured, job.stage, job.final ] );
		this.forget( job );
		if ( LOST.includes( error?.code ) && job.type !== 'complete' ) {
			// A snapshot it used is no longer retained: renewal finds which.
			this.renewAll();
		}
		if ( job.type === 'complete' ) {
			// The change finished, but its products could not be taken out.
			const released = [ this.submitted?.key, this.additionsKey ];
			this.submitted = null;
			this.additionsKey = null;
			this.notice = { kind: 'cleanup', batchId: job.args.batchId };
			this.release( released );
		} else {
			this.error = failure;
		}
		job.settle( false );
	}

	/**
	 * Throw DISCARDED when Clear started a new generation.
	 *
	 * @param {Object} job Job.
	 */
	check( job ) {
		if ( job.gen !== this.generation || this.disposed ) {
			throw DISCARDED;
		}
	}

	/**
	 * One durable step of a job: its grant (issued once, kept across
	 * retries), then the route. A lapsed lease is renewed and the same grant
	 * sent once more.
	 *
	 * @param {Object}   job       Job.
	 * @param {string}   name      Step name, unique in the job.
	 * @param {string}   operation Grant operation.
	 * @param {Function} intent    () => raw intent, read when issuing.
	 * @param {Function} send      ( grant ) => Promise: the route.
	 * @return {Promise<Object>} The envelope.
	 */
	async step( job, name, operation, intent, send ) {
		const key = `${ job.id }:${ name }`;
		let grant = this.grants.get( key );
		if ( ! grant ) {
			grant = await this.issueGrant( operation, intent() );
			this.check( job );
			this.grants.set( key, grant );
			this.persist();
		}
		let answer;
		try {
			answer = await send( grant );
		} catch ( error ) {
			if ( error?.code !== RENEW_REQUIRED ) {
				throw error;
			}
			await this.renewAll();
			this.check( job );
			answer = await send( grant );
		}
		if ( job.gen !== this.generation || this.disposed ) {
			// Accepted after Clear: nothing refers to the result.
			if ( answer?.token ) {
				this.api.release( [ answer.token ] ).catch( () => {} );
			}
			throw DISCARDED;
		}
		return answer;
	}

	/**
	 * Keep an envelope as a new handle.
	 *
	 * @param {Object} envelope Envelope.
	 * @return {string} Its key.
	 */
	keep( envelope ) {
		const key = newKey( 'h' );
		this.handles.set( key, toHandle( envelope, key ) );
		return key;
	}

	/**
	 * Make a handle the selection, releasing the one it replaces. An empty
	 * snapshot is no selection.
	 *
	 * @param {string|null} key Handle.
	 */
	setCurrent( key ) {
		const previous = this.currentKey;
		this.currentKey = key && this.handles.get( key )?.count ? key : null;
		if ( this.currentKey ) {
			this.lost = null;
		}
		this.release( [ previous, key !== this.currentKey ? key : null ] );
		this.persist();
	}

	/**
	 * Make a handle the post-submission additions, releasing the old one.
	 *
	 * @param {string|null} key Handle.
	 */
	setAdditions( key ) {
		const previous = this.additionsKey;
		this.additionsKey = key && this.handles.get( key )?.count ? key : null;
		this.release( [ previous, key !== this.additionsKey ? key : null ] );
		this.persist();
	}

	/**
	 * Release snapshots nothing on this screen refers to any more: forget
	 * their handles and tell the server, best effort.
	 *
	 * @param {Array<string|null>} keys Candidate handles.
	 */
	release( keys ) {
		const held = new Set( this.heldKeys() );
		const tokens = [];
		[ ...new Set( keys ) ].forEach( ( key ) => {
			if ( key && ! held.has( key ) && this.handles.has( key ) ) {
				tokens.push( this.handles.get( key ).token );
				this.handles.delete( key );
			}
		} );
		chunk( tokens.filter( Boolean ), 4 ).forEach( ( part ) =>
			this.api.release( part ).catch( () => {} )
		);
	}

	/**
	 * Run one job.
	 *
	 * @param {Object} job Job.
	 * @return {Promise} Done.
	 */
	execute( job ) {
		switch ( job.type ) {
			case 'add':
				return this.runAdd( job );
			case 'remove':
				return this.runRemove( job );
			case 'matching':
				return this.runMatching( job );
			case 'complete':
				return this.runComplete( job );
			case 'restore':
				return this.runRestore( job );
			default:
				return Promise.resolve();
		}
	}

	/**
	 * Compose a handle with ids or a snapshot, by key at send time.
	 *
	 * @param {Object} job      Job.
	 * @param {string} name     Step.
	 * @param {string} baseKey  Base handle.
	 * @param {string} mutation add_ids, remove_ids, add_token or remove_token.
	 * @param {*}      value    Ids, or the operand's handle key.
	 * @return {Promise<Object>} Envelope.
	 */
	compose( job, name, baseKey, mutation, value ) {
		const operand = mutation.endsWith( '_token' );
		const credentials = () => ( {
			token: this.tokenOf( baseKey ),
			...( operand ? { [ mutation ]: this.tokenOf( value ) } : {} ),
		} );
		return this.step(
			job,
			name,
			'selection.compose',
			() => ( {
				token: this.tokenOf( baseKey ),
				[ mutation ]: operand ? this.tokenOf( value ) : value,
			} ),
			( grant ) => this.api.compose( grant, credentials() )
		);
	}

	/**
	 * Capture ids or a query.
	 *
	 * @param {Object} job    Job.
	 * @param {string} name   Step.
	 * @param {Object} intent `{ ids }` or `{ query }`.
	 * @return {Promise<Object>} Envelope.
	 */
	capture( job, name, intent ) {
		return this.step(
			job,
			name,
			'selection.capture',
			() => intent,
			( grant ) => this.api.capture( grant )
		);
	}

	/**
	 * Tick: add ids, a thousand at a time, and track them as additions while
	 * a change runs.
	 *
	 * @param {Object} job Job.
	 */
	async runAdd( job ) {
		const parts = chunk( job.args.ids, MAX_IDS );
		for ( let i = 0; i < parts.length; i++ ) {
			const name = `add${ i }`;
			if ( job.results[ name ] ) {
				continue;
			}
			const base = this.currentKey;
			const envelope = base
				? await this.compose( job, name, base, 'add_ids', parts[ i ] )
				: await this.capture( job, name, { ids: parts[ i ] } );
			job.results[ name ] = true;
			this.setCurrent( this.keep( envelope ) );
			parts[ i ].forEach( ( id ) =>
				this.visible.set( String( id ), true )
			);
		}
		if ( ! this.submitted ) {
			return;
		}
		for ( let i = 0; i < parts.length; i++ ) {
			const name = `more${ i }`;
			if ( job.results[ name ] ) {
				continue;
			}
			const base = this.additionsKey;
			const envelope = base
				? await this.compose( job, name, base, 'add_ids', parts[ i ] )
				: await this.capture( job, name, { ids: parts[ i ] } );
			job.results[ name ] = true;
			this.setAdditions( this.keep( envelope ) );
		}
	}

	/**
	 * Untick: remove ids from the selection and from the additions.
	 *
	 * @param {Object} job Job.
	 */
	async runRemove( job ) {
		const parts = chunk( job.args.ids, MAX_IDS );
		for ( let i = 0; i < parts.length; i++ ) {
			const name = `remove${ i }`;
			const base = this.currentKey;
			if ( job.results[ name ] || ! base ) {
				continue;
			}
			const envelope = await this.compose(
				job,
				name,
				base,
				'remove_ids',
				parts[ i ]
			);
			job.results[ name ] = true;
			this.setCurrent( this.keep( envelope ) );
			parts[ i ].forEach( ( id ) =>
				this.visible.set( String( id ), false )
			);
		}
		for ( let i = 0; i < parts.length; i++ ) {
			const name = `less${ i }`;
			const base = this.additionsKey;
			if ( job.results[ name ] || ! base ) {
				continue;
			}
			const envelope = await this.compose(
				job,
				name,
				base,
				'remove_ids',
				parts[ i ]
			);
			job.results[ name ] = true;
			this.setAdditions( this.keep( envelope ) );
		}
	}

	/**
	 * Select all matching: capture the query once, then union it into the
	 * selection (and the additions while a change runs). A retry sends the
	 * same two grants and uses the same captured set.
	 *
	 * @param {Object} job Job.
	 */
	async runMatching( job ) {
		if ( ! job.results.capture ) {
			const envelope = await this.capture( job, 'capture', {
				query: job.args.query,
			} );
			job.captured = this.keep( envelope );
			job.results.capture = true;
			this.persist();
		}
		const captured = job.captured;
		if ( ! job.results.union ) {
			const base = this.currentKey;
			if ( base ) {
				const envelope = await this.compose(
					job,
					'union',
					base,
					'add_token',
					captured
				);
				this.setCurrent( this.keep( envelope ) );
			} else {
				this.currentKey = null;
				this.setCurrent( captured );
			}
			job.results.union = true;
		}
		if ( this.submitted && ! job.results.more ) {
			const base = this.additionsKey;
			if ( base ) {
				const envelope = await this.compose(
					job,
					'more',
					base,
					'add_token',
					captured
				);
				this.setAdditions( this.keep( envelope ) );
			} else {
				this.additionsKey = this.handles.get( captured )?.count
					? captured
					: null;
			}
			job.results.more = true;
		}
		job.captured = null;
		this.release( [ captured ] );
		this.lastMatching = job.args.key;
	}

	/**
	 * Restore: keep the snapshot Clear search released again (a renewal,
	 * which the server allows while it still holds the payload) and make it
	 * the selection. A snapshot no longer kept is lost work, said so with
	 * the count it had; an unknown answer pauses for Try again.
	 *
	 * @param {Object} job Job.
	 */
	async runRestore( job ) {
		const was = this.restorable;
		if ( ! was || this.currentKey ) {
			return;
		}
		if ( this.now() >= was.until ) {
			this.restorable = null;
			this.notice = null;
			this.lost = { count: was.count, byType: was.byType, message: '' };
			return;
		}
		let envelope;
		try {
			envelope = await this.api.renew( was.token );
		} catch ( error ) {
			if ( LOST.includes( error?.code ) ) {
				this.restorable = null;
				this.notice = null;
				this.lost = {
					count: was.count,
					byType: was.byType,
					message: report( error, 'renew' ).message,
				};
				return;
			}
			throw error;
		}
		this.check( job );
		const key = this.keep( envelope );
		if ( envelope.requires_preview || envelope.token !== was.token ) {
			this.drift = true;
		}
		this.restorable = null;
		this.setCurrent( key );
		this.notice = this.currentKey ? { kind: 'restored' } : null;
	}

	/**
	 * A change finished in full: (current minus submitted) union additions,
	 * committed only once both compositions have succeeded.
	 *
	 * @param {Object} job Job.
	 */
	async runComplete( job ) {
		const submitted = this.submitted;
		if ( ! submitted || submitted.batchId !== job.args.batchId ) {
			return;
		}
		if ( ! submitted.key && this.currentKey ) {
			// The submitted set was no longer retained: nothing can be subtracted.
			throw { code: 'bulkcat_selection_expired' };
		}
		const current = this.currentKey;
		const additions = this.additionsKey;
		let result = null;
		if ( current && current !== submitted.key ) {
			if ( ! job.stage ) {
				const envelope = await this.compose(
					job,
					'subtract',
					current,
					'remove_token',
					submitted.key
				);
				job.stage = this.keep( envelope );
				this.persist();
			}
			result = job.stage;
			if ( additions ) {
				if ( ! job.final ) {
					const envelope = await this.compose(
						job,
						'union',
						job.stage,
						'add_token',
						additions
					);
					job.final = this.keep( envelope );
					this.persist();
				}
				result = job.final;
			}
		} else if ( current ) {
			// The selection is exactly what was applied, plus nothing since.
			result = additions;
		}
		const kept = !! ( result && this.handles.get( result )?.count );
		const released = [
			current,
			submitted.key,
			additions,
			job.stage,
			job.final,
		];
		this.currentKey = kept ? result : null;
		this.submitted = null;
		this.additionsKey = null;
		job.stage = null;
		job.final = null;
		this.visible.clear();
		this.drift = false;
		this.notice = {
			kind: 'done',
			batchId: job.args.batchId,
			kept: kept ? this.handles.get( result ).count : 0,
		};
		this.release( released );
	}

	/* ---- keeping the tab's copy -------------------------------------- */

	/**
	 * Write what a reload needs: the held handles, the pending intents and
	 * their grants. Nothing when there is nothing to keep.
	 */
	persist() {
		if ( ! this.store || this.disposed ) {
			return;
		}
		const jobs = [ this.running, this.paused, ...this.queue ].filter(
			( job ) => job && job.gen === this.generation
		);
		const held = this.heldKeys();
		if ( ! held.length && ! jobs.length && ! this.lost ) {
			this.store.clear();
			return;
		}
		this.store.write( {
			v: VERSION,
			handles: held.map( ( key ) => this.handles.get( key ) ),
			current: this.currentKey,
			additions: this.additionsKey,
			submitted: this.submitted,
			lastMatching: this.lastMatching,
			lost: this.lost,
			drift: this.drift,
			jobs: jobs.map( ( job ) => ( {
				id: job.id,
				type: job.type,
				args: job.args,
				results: job.results,
				captured: job.captured,
				stage: job.stage,
				final: job.final,
				grants: Object.fromEntries(
					[ ...this.grants.entries() ].filter( ( [ key ] ) =>
						key.startsWith( job.id + ':' )
					)
				),
			} ) ),
		} );
	}

	/**
	 * Come back to the tab's selection after a reload: the same handles,
	 * renewed under the current sign-in (a lapsed lease gives the same
	 * products a new epoch), and any intent that was in flight paused for
	 * Retry with its own grants.
	 *
	 * @return {boolean} Whether anything was restored.
	 */
	recover() {
		const data = this.store?.read();
		if ( ! data || data.v !== VERSION ) {
			return false;
		}
		( data.handles || [] ).forEach( ( handle ) => {
			if ( handle?.key && handle.token ) {
				this.handles.set( handle.key, handle );
			}
		} );
		const known = ( key ) =>
			key && this.handles.has( key ) ? key : null;
		this.currentKey = known( data.current );
		this.additionsKey = known( data.additions );
		this.submitted =
			data.submitted?.batchId > 0
				? {
						batchId: Number( data.submitted.batchId ),
						key: known( data.submitted.key ),
					}
				: null;
		this.lastMatching = String( data.lastMatching || '' );
		this.lost = data.lost || null;
		this.drift = !! data.drift;
		const jobs = ( data.jobs || [] ).map( ( stored ) => {
			const job = this.makeJob( {
				id: stored.id,
				type: stored.type,
				args: stored.args || {},
				results: stored.results || {},
				captured: known( stored.captured ),
				stage: known( stored.stage ),
				final: known( stored.final ),
			} );
			job.gen = this.generation;
			if ( stored.type === 'complete' ) {
				// Its completion is already under way: never a second one.
				this.finished.add( Number( stored.args?.batchId ) || 0 );
			}
			Object.entries( stored.grants || {} ).forEach( ( [ key, grant ] ) =>
				this.grants.set( key, grant )
			);
			this.mark( job, true );
			return job;
		} );
		if ( jobs.length ) {
			this.paused = jobs[ 0 ];
			this.queue = jobs.slice( 1 );
			this.error = {
				kind: 'unconfirmed',
				code: '',
				message: '',
				uncertain: true,
				retry: true,
				status: 0,
			};
		}
		const restored = !! ( this.currentKey || jobs.length || this.lost );
		if ( this.currentKey ) {
			this.notice = { kind: 'restored' };
		}
		this.changed();
		this.renewAll();
		// A change applied before the reload is followed to its end here,
		// since no banner on this page knows about it (T85).
		this.watch();
		return restored;
	}

	/**
	 * Stop: no timer, no listener, late answers ignored.
	 */
	dispose() {
		this.disposed = true;
		this.generation += 1;
		if ( this.cancelRenew ) {
			this.cancelRenew();
		}
		if ( this.cancelWatch ) {
			this.cancelWatch();
		}
		this.listeners.clear();
	}

	/* ---- notifying ---------------------------------------------------- */

	/**
	 * Keep the tab's copy, plan the renewal and tell the listeners.
	 *
	 * @param {boolean} keep Whether membership or handles may have changed.
	 */
	changed( keep = true ) {
		if ( keep ) {
			this.persist();
			if ( ! this.renewing ) {
				this.planRenewal();
			}
		}
		this.emit();
	}

	/**
	 * Build the state and tell the listeners.
	 *
	 * @param {boolean} notify Whether to call them.
	 */
	emit( notify = true ) {
		const current = this.currentKey
			? this.handles.get( this.currentKey ) || null
			: null;
		let status = 'idle';
		if ( this.paused ) {
			status = 'uncertain';
		} else if ( this.running || this.queue.length ) {
			status = 'saving';
		} else if ( this.blocked ) {
			status = 'blocked';
		}
		this.revision += 1;
		this.snapshot = {
			current,
			count: current?.count || 0,
			byType: current?.byType || { products: 0, variations: 0 },
			status,
			error: this.error,
			blocked: this.blocked,
			notice: this.notice,
			drift: this.drift,
			lost: this.lost,
			submitted: this.submitted
				? { batchId: this.submitted.batchId }
				: null,
			lastMatching: this.lastMatching,
			revision: this.revision,
		};
		if ( notify ) {
			this.listeners.forEach( ( listener ) => listener( this.snapshot ) );
		}
	}
}

/**
 * A new session.
 *
 * @param {Object} options See SelectionSession.
 * @return {SelectionSession} Session.
 */
export function createSelectionSession( options = {} ) {
	return new SelectionSession( options );
}

let shared = null;

/**
 * The screen's one session, created on first use: it outlives moving
 * between panels, keeps the tab's copy under the site and user, and wakes
 * when the tab becomes visible again.
 *
 * @param {Object} settings Localised settings (`root`, `user.id`).
 * @return {SelectionSession} Session.
 */
export function getSelectionSession( settings = {} ) {
	if ( shared ) {
		return shared;
	}
	shared = createSelectionSession( {
		store: tabStore( tabScope( settings ) ),
	} );
	const session = shared;
	if ( typeof document !== 'undefined' ) {
		const onVisible = () => {
			if (
				document.visibilityState === 'visible' &&
				! session.disposed
			) {
				session.wake();
			}
		};
		document.addEventListener( 'visibilitychange', onVisible );
		const dispose = session.dispose.bind( session );
		session.dispose = () => {
			document.removeEventListener( 'visibilitychange', onVisible );
			dispose();
		};
	}
	session.recover();
	return session;
}

/**
 * Forget the screen's session (tests).
 */
export function resetSelectionSession() {
	if ( shared ) {
		shared.dispose();
	}
	shared = null;
}
