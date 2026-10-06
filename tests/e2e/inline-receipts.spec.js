/**
 * Native receipt qualification. Actual HTTP and instrumented native REST processes
 * are recorded separately; no installed helper, reseed or global queue drain.
 */
const { randomUUID, createHash } = require( 'crypto' );
const { readFileSync, writeFileSync, mkdirSync } = require( 'fs' );
const { spawn, execFileSync } = require( 'child_process' );
const { join } = require( 'path' );
const { test, expect } = require( '@playwright/test' );
const { fixtureCheck, members } = require( './lifecycle/support' );
const { openScreen, rest, tabTo, expectAxeClean } = require( './helpers' );

const run = randomUUID();
const password = randomUUID();
const siteurl = process.env.BULKCAT_E2E_BASE_URL;
const evidence = process.env.BULKCAT_E2E_OWNED_EVIDENCE;
const state = { run, products: [], processes: [], journeys: [] };
const active = new Set();

// A qualification run of its own (tests/probe/README.md): with an owned, private
// evidence directory and its own Playwright configuration. The ordinary suite
// (`npm run e2e`) has neither, so it skips this file rather than fail its setup.
test.skip(
	! evidence,
	'Needs BULKCAT_E2E_OWNED_EVIDENCE and its own configuration (tests/probe/README.md).'
);

function record() {
	if ( ! evidence ) {
		throw new Error( 'Explicit private evidence directory required.' );
	}
	mkdirSync( evidence, { recursive: true } );
	writeFileSync(
		join( evidence, `${ run }.json` ),
		JSON.stringify( state, null, 2 ),
		{
			mode: 0o600,
		}
	);
}

function identity() {
	const paths = execFileSync(
		'git',
		[
			'ls-files',
			'--cached',
			'--others',
			'--exclude-standard',
			'src',
			'assets/src',
			'assets/build',
			'tests/e2e/inline-receipts.spec.js',
			'tests/probe/inline-receipts.php',
			'tests/probe/inline-receipts-http.php',
			'tests/probe/inline-receipts-http.py',
			'tests/probe/native-status.php',
			'tests/probe/fixture.php',
		],
		{ encoding: 'utf8' }
	)
		.trim()
		.split( '\n' );
	return {
		head: execFileSync( 'git', [ 'rev-parse', 'HEAD' ], {
			encoding: 'utf8',
		} ).trim(),
		files: Object.fromEntries(
			[ ...new Set( paths ) ].map( ( path ) => [
				path,
				createHash( 'sha256' )
					.update( readFileSync( path ) )
					.digest( 'hex' ),
			] )
		),
	};
}

function start( phase, input = {} ) {
	const args = {
		run,
		siteurl,
		phase,
		user: state.user,
		ids: state.products.map( ( p ) => p.id ),
		...input,
	};
	const encoded = Buffer.from( JSON.stringify( args ) ).toString( 'base64' );
	const code = `$bulkcat_receipt_args=json_decode(base64_decode('${ encoded }'),true); require 'wp-content/plugins/bulk-catalogue-manager-for-woocommerce/tests/probe/inline-receipts.php';`;
	const child = spawn( process.env.WP_CMD, [ 'eval', code ], {
		stdio: [ 'pipe', 'pipe', 'pipe' ],
	} );
	const entry = {
		phase,
		action: input.action,
		fault: input.fault,
		events: [],
		stderr: '',
		started: Date.now(),
	};
	const handle = { child, entry, done: false };
	state.processes.push( entry );
	active.add( handle );
	let buffer = '';
	child.stdout.on( 'data', ( chunk ) => {
		buffer += chunk;
		let end;
		while ( ( end = buffer.indexOf( '\n' ) ) >= 0 ) {
			const line = buffer.slice( 0, end );
			buffer = buffer.slice( end + 1 );
			if ( ! line.startsWith( '{' ) ) {
				continue;
			}
			const event = JSON.parse( line );
			entry.events.push( event );
			if ( event.event === 'owned_user' ) {
				state.user = event.id;
			}
			if ( event.event === 'owned_product' ) {
				state.products.push( { id: event.id, name: event.name } );
			}
			if ( event.event === 'baseline' ) {
				state.baseline = event.data;
			}
			record();
		}
	} );
	child.stderr.on( 'data', ( chunk ) => {
		entry.stderr += chunk;
	} );
	handle.finished = new Promise( ( finish, reject ) => {
		child.on( 'error', reject );
		child.on( 'close', ( codeValue, signal ) => {
			handle.done = true;
			entry.exit = codeValue;
			entry.signal = signal;
			entry.elapsedMs = Date.now() - entry.started;
			active.delete( handle );
			record();
			finish( entry );
		} );
	} );
	return handle;
}

async function result( handle ) {
	await expect.poll( () => handle.done, { timeout: 90_000 } ).toBe( true );
	const entry = await handle.finished;
	expect( entry.exit, entry.stderr ).toBe( 0 );
	const last = entry.events.findLast( ( event ) => event.event === 'result' );
	expect( last, JSON.stringify( entry ) ).toBeTruthy();
	return last;
}

async function probe( phase, input ) {
	return ( await result( start( phase, input ) ) ).data;
}
async function barrier( handle, name ) {
	await expect
		.poll(
			() =>
				handle.entry.events.some(
					( event ) =>
						event.event === 'barrier' && event.boundary === name
				),
			{ timeout: 30_000 }
		)
		.toBe( true );
}

function releaseOriginal( handle ) {
	handle.entry.releaseAtMs = Date.now();
	record();
	handle.child.stdin.write( 'release\n' );
}

async function killOwned( handle ) {
	if ( handle.done ) {
		return;
	}
	const process = handle.entry.events.find(
		( event ) => event.event === 'process'
	);
	expect( process?.container ).toMatch( /^[a-f0-9]{12}$/ );
	const inspected = JSON.parse(
		execFileSync( 'docker', [ 'inspect', process.container ], {
			encoding: 'utf8',
		} )
	)[ 0 ];
	if ( ! inspected.State.Running ) {
		await handle.finished;
		return;
	}
	expect( inspected.Config.Image ).toBe( 'wordpress:cli-php8.3' );
	expect( inspected.HostConfig.AutoRemove ).toBe( true );
	expect( inspected.Config.Cmd.join( ' ' ) ).toContain(
		'inline-receipts.php'
	);
	// Only the exact ephemeral wrapper process; never shared Apache/database.
	execFileSync( 'docker', [ 'kill', '--signal=KILL', inspected.Id ], {
		encoding: 'utf8',
	} );
	handle.entry.termination = { signal: 'SIGKILL', container: inspected.Id };
	await expect.poll( () => handle.done ).toBe( true );
	expect( handle.entry.exit ).toBe( 137 );
}

const native = ( method, route, data = {}, extra = {} ) =>
	start( 'assert', { action: 'rest', method, route, data, ...extra } );
async function grant( product, values ) {
	const response = await result(
		native( 'POST', 'requests', {
			operation: 'product.inline',
			intent: { object_id: product.id, source: 'inline', values },
		} )
	);
	expect( response.data.status ).toBe( 200 );
	return response.data.json;
}
function body( held, values ) {
	return {
		...values,
		source: 'inline',
		uuid: held.uuid,
		request_proof: held.request_proof,
		intent: held.intent,
	};
}
function resolve( held ) {
	return native( 'POST', 'requests/resolve', {
		operation: 'product.inline',
		uuid: held.uuid,
		request_proof: held.request_proof,
		intent: held.intent,
	} );
}
async function observe() {
	return probe( 'assert', { action: 'observe' } );
}
async function singleIdentity( held, id, receiptState ) {
	const view = await observe();
	const batches = view.batches.filter(
		( batch ) => batch.uuid === held.uuid
	);
	expect( batches ).toHaveLength( 1 );
	const batch = Number( batches[ 0 ].batch_id );
	expect( view.items[ batch ] ).toHaveLength( 1 );
	expect( Number( view.items[ batch ][ 0 ].object_id ) ).toBe( id );
	expect( view.queue[ batch ] ).toEqual( [] );
	expect(
		view.receipts.filter( ( r ) => r.uuid === held.uuid )
	).toHaveLength( 1 );
	const answer = await result( resolve( held ) );
	expect( answer.data.json.receipt_state ).toBe( receiptState );
	expect( answer.data.json.batch_id ).toBe( batch );
	return { batch, view, receipt: answer.data.json };
}

async function blocked( held, product, values, price ) {
	const before = await singleIdentity( held, product.id, 'unclosed' );
	const duplicate = await result(
		native( 'PATCH', `products/${ product.id }`, body( held, values ) )
	);
	expect( duplicate.hooks ).toBe( 0 );
	expect( duplicate.data.json ).toMatchObject( {
		receipt_state: 'unclosed',
		batch_id: before.batch,
	} );
	const delivery = await result(
		start( 'assert', { action: 'deliver', batch: before.batch } )
	);
	expect( delivery.hooks ).toBe( 0 );
	expect( delivery.data.queued ).toBe( false );
	expect( delivery.data.cancel ).toBe( 'unclosed' );
	expect( delivery.data.undo.status ).toBeGreaterThanOrEqual( 400 );
	expect( delivery.data.history.status ).toBe( 200 );
	const after = await singleIdentity( held, product.id, 'unclosed' );
	expect( after.view ).toEqual( before.view );
	expect(
		after.view.products.find( ( p ) => p.id === product.id ).price
	).toBe( price );
	return before;
}

test.beforeAll( async () => {
	expect( fixtureCheck() ).toBe( '' );
	state.identityBefore = identity();
	record();
	state.inspect = await probe( 'inspect' );
	await probe( 'before', { password } );
	expect( state.baseline ).toEqual( state.inspect.baseline );
	record();
} );

test.afterAll( async () => {
	for ( const handle of active ) {
		await killOwned( handle );
	}
	if ( state.user ) {
		state.cleanup = await probe( 'cleanup' );
		record();
		expect( state.cleanup.remaining ).toEqual( [] );
		expect( state.cleanup.user_removed ).toBe( true );
		expect( state.cleanup.baseline ).toEqual( state.baseline );
	}
	const retained = execFileSync( 'docker', [ 'ps', '-aq', '--no-trunc' ], {
		encoding: 'utf8',
	} )
		.trim()
		.split( '\n' );
	state.remainingOwnedContainers = state.processes
		.flatMap( ( entry ) =>
			entry.events
				.filter( ( event ) => event.event === 'process' )
				.map( ( event ) => event.container )
		)
		.filter( ( id ) => retained.some( ( full ) => full.startsWith( id ) ) );
	expect( state.remainingOwnedContainers ).toEqual( [] );
	state.identityAfter = identity();
	record();
	expect( state.identityAfter ).toEqual( state.identityBefore );
} );

test( 'native pipe barrier bounds unreleased and partial tokens and accepts ordinary release', async ( {}, info ) => {
	test.skip(
		info.project.name !== 'desktop',
		'Native pipe checks run once.'
	);
	test.setTimeout( 180_000 );
	for ( const token of [ '', 'rel' ] ) {
		const held = start( 'assert', { action: 'barrier_check' } );
		await barrier( held, 'deadline_qualification' );
		const entered = held.entry.events.find(
			( e ) => e.event === 'barrier'
		).at;
		if ( token ) {
			held.child.stdin.write( token );
		}
		await expect.poll( () => held.done, { timeout: 50_000 } ).toBe( true );
		const entry = await held.finished;
		expect( entry.exit ).toBe( 255 );
		expect( entry.stderr ).toContain(
			'Owned barrier not released within 45 seconds.'
		);
		expect(
			entry.started + entry.elapsedMs - entered * 1000
		).toBeGreaterThanOrEqual( 44_000 );
		expect( entry.started + entry.elapsedMs - entered * 1000 ).toBeLessThan(
			50_000
		);
		expect( entry.events.some( ( e ) => e.event === 'result' ) ).toBe(
			false
		);
	}
	const released = start( 'assert', { action: 'barrier_check' } );
	await barrier( released, 'deadline_qualification' );
	releaseOriginal( released );
	expect( ( await result( released ) ).data ).toBe( true );
	const invalid = start( 'assert', { action: 'barrier_check' } );
	await barrier( invalid, 'deadline_qualification' );
	invalid.child.stdin.write( 'release\nextra' );
	await expect.poll( () => invalid.done ).toBe( true );
	expect( ( await invalid.finished ).exit ).toBe( 255 );
	expect( invalid.entry.stderr ).toContain(
		'Invalid owned barrier release token.'
	);
	state.journeys.push( {
		label: info.title,
		deadlines: [ 'unreleased', 'partial' ],
		release: true,
		oversizedRefused: true,
	} );
	record();
} );

test( 'real SQL unique-claim contention executes one native save', async ( {}, info ) => {
	test.skip(
		info.project.name !== 'desktop',
		'Process gates run once; browser gates run both widths.'
	);
	test.setTimeout( 180_000 );
	const product = state.products[ 0 ];
	const values = { regular_price: '21' };
	const held = await grant( product, values );
	const original = native(
		'PATCH',
		`products/${ product.id }`,
		body( held, values ),
		{ fault: 'claim' }
	);
	await barrier( original, 'uncommitted_unique_claim' );
	const peer = native(
		'PATCH',
		`products/${ product.id }`,
		body( held, values )
	);
	try {
		await expect
			.poll(
				() =>
					peer.entry.events.find( ( e ) => e.event === 'process' )
						?.connection
			)
			.toBeTruthy();
		await expect
			.poll( () =>
				peer.entry.events.some(
					( e ) => e.event === 'claim_statement_entered'
				)
			)
			.toBe( true );
		const connection = peer.entry.events.find(
			( e ) => e.event === 'process'
		).connection;
		const server = await probe( 'assert', {
			action: 'lock',
			connection,
			original: original.entry.events.find(
				( e ) => e.event === 'process'
			).connection,
		} );
		state.journeys.push( {
			label: 'same-user server connection observation while claim is held',
			server,
		} );
		record();
		expect( server.map( ( c ) => c.id ).sort( ( a, b ) => a - b ) ).toEqual(
			[
				connection,
				original.entry.events.find( ( e ) => e.event === 'process' )
					.connection,
			].sort( ( a, b ) => a - b )
		);
		expect( server.find( ( c ) => c.id === connection ).command ).toBe(
			'Query'
		);
		await new Promise( ( done ) => setTimeout( done, 1000 ) );
		expect( peer.done ).toBe( false );
	} finally {
		releaseOriginal( original );
	}
	const first = await result( original );
	const second = await result( peer );
	expect( first.hooks ).toBe( 1 );
	expect( second.hooks ).toBe( 0 );
	expect( first.data.json ).toMatchObject( {
		receipt_state: 'closed',
		write_status: 'complete',
	} );
	expect( second.data.json.batch_id ).toBe( first.data.json.batch_id );
	await singleIdentity( held, product.id, 'closed' );
	state.journeys.push( {
		label: info.title,
		batch: first.data.json.batch_id,
		hooks: first.hooks + second.hooks,
		boundary:
			'actual independent SQL connections, same-user PROCESSLIST, committed unique-claim serialization; State is retained verbatim',
	} );
	record();
} );

for ( const fault of [ 'accepted', 'mid_write', 'delayed' ] ) {
	test( `owned original ${ fault }: no queue, duplicate, History or resolve takeover`, async ( {}, info ) => {
		test.skip( info.project.name !== 'desktop', 'Process gate runs once.' );
		test.setTimeout( 180_000 );
		const product =
			state.products[
				{ accepted: 1, mid_write: 2, delayed: 3 }[ fault ]
			];
		const values = { regular_price: '22' };
		const held = await grant( product, values );
		const original = native(
			'PATCH',
			`products/${ product.id }`,
			body( held, values ),
			{ fault: fault === 'delayed' ? 'accepted' : fault }
		);
		await barrier(
			original,
			fault === 'mid_write'
				? 'after_native_save_before_journal_settlement'
				: 'confirmed_acceptance_before_execution'
		);
		const gate = await blocked(
			held,
			product,
			values,
			fault === 'mid_write' ? '22.00' : '17.25'
		);
		if ( fault === 'delayed' ) {
			releaseOriginal( original );
			const first = await result( original );
			expect( first.hooks ).toBe( 1 );
			const closure = await singleIdentity( held, product.id, 'closed' );
			expect( closure.batch ).toBe( gate.batch );
		} else {
			await killOwned( original );
			await blocked(
				held,
				product,
				values,
				fault === 'mid_write' ? '22.00' : '17.25'
			);
			expect(
				original.entry.events.filter( ( e ) => e.event === 'save_hook' )
			).toHaveLength( fault === 'mid_write' ? 1 : 0 );
		}
		state.journeys.push( {
			label: info.title,
			batch: gate.batch,
			boundary:
				fault === 'delayed'
					? 'real paused process released through STDIN'
					: 'actual SIGKILL of exact ephemeral PHP container',
		} );
		record();
	} );
}

test( 'pre-effect refusal, changed payload, revoked disclosure and absent receipt stay fenced', async ( {}, info ) => {
	test.skip(
		info.project.name !== 'desktop',
		'Targeted native cases run once.'
	);
	test.setTimeout( 180_000 );
	const product = state.products[ 4 ];
	const badValues = { sale_price: '30' };
	const refused = await grant( product, badValues );
	const refusal = await result(
		native(
			'PATCH',
			`products/${ product.id }`,
			body( refused, badValues )
		)
	);
	expect( refusal.hooks ).toBe( 0 );
	expect( refusal.data.json.data.receipt_state ).toBe( 'refused' );
	expect(
		( await result( resolve( refused ) ) ).data.json.receipt_state
	).toBe( 'refused' );
	const invalidValues = { stock_quantity: '1.5' };
	const invalidGrant = await grant( product, invalidValues );
	const invalid = await result(
		native(
			'PATCH',
			`products/${ product.id }`,
			body( invalidGrant, invalidValues )
		)
	);
	expect( invalid.hooks ).toBe( 0 );
	expect( invalid.data.status ).toBe( 400 );
	expect( invalid.data.json.code ).toBe( 'rest_invalid_param' );
	expect( invalid.data.json.data ).toEqual( {
		status: 400,
		params: { stock_quantity: invalid.data.json.message },
		field: 'stock_quantity',
		receipt_state: 'refused',
		replayed: false,
	} );
	expect( invalid.data.json.message ).toBe(
		'Stock quantity has to be a whole number. Enter a number like 12.'
	);
	expect( ( await result( resolve( invalidGrant ) ) ).data.json ).toEqual( {
		receipt_state: 'refused',
		replayed: true,
	} );
	const values = { regular_price: '23' };
	const held = await grant( product, values );
	const first = await result(
		native( 'PATCH', `products/${ product.id }`, body( held, values ) )
	);
	expect( first.hooks ).toBe( 1 );
	const changed = await result(
		native(
			'PATCH',
			`products/${ product.id }`,
			body( held, { regular_price: '24' } )
		)
	);
	expect( changed.data.json.code ).toBe( 'bulkcat_request_conflict' );
	expect( changed.hooks ).toBe( 0 );
	await probe( 'assert', { action: 'rights', deny: true } );
	try {
		const denied = await result( resolve( held ) );
		expect( denied.data.status ).toBe( 403 );
		expect( denied.hooks ).toBe( 0 );
	} finally {
		await probe( 'assert', { action: 'rights', deny: false } );
	}
	const closure = await singleIdentity( held, product.id, 'closed' );
	expect( await probe( 'assert', { action: 'prune', grant: held } ) ).toBe(
		1
	);
	const missing = await result( resolve( held ) );
	expect( missing.data.json.receipt_state ).toBe( 'not_accepted' );
	const retry = await result(
		native( 'PATCH', `products/${ product.id }`, body( held, values ) )
	);
	expect( retry.hooks ).toBe( 0 );
	expect( retry.data.status ).toBe( 409 );
	const view = await observe();
	expect( view.batches.filter( ( b ) => b.uuid === held.uuid ) ).toHaveLength(
		1
	);
	expect( view.products.find( ( p ) => p.id === product.id ).price ).toBe(
		'23.00'
	);
	state.journeys.push( {
		label: info.title,
		batch: closure.batch,
		pruned: 'exact owned receipt deletion simulates retention; global cleanup not run',
	} );
	record();
} );

test( 'native partial result closes truthfully after current state changes between acceptance and execution', async ( {}, info ) => {
	test.skip(
		info.project.name !== 'desktop',
		'Targeted native case runs once.'
	);
	const product = state.products[ 9 ];
	const values = { name: 'Receipt partial sentinel', sale_price: '16' };
	const held = await grant( product, values );
	const original = native(
		'PATCH',
		`products/${ product.id }`,
		body( held, values ),
		{ fault: 'accepted' }
	);
	await barrier( original, 'confirmed_acceptance_before_execution' );
	const competing = await result(
		start( 'assert', { action: 'compete', object: product.id } )
	);
	expect( competing.hooks ).toBe( 1 );
	releaseOriginal( original );
	const answer = await result( original );
	expect( answer.hooks ).toBe( 1 );
	expect( answer.data.json ).toMatchObject( {
		receipt_state: 'closed',
		write_status: 'partial',
	} );
	const closed = await singleIdentity( held, product.id, 'closed' );
	expect( closed.receipt.write_status ).toBe( 'partial' );
	const current = await result( native( 'GET', `products/${ product.id }` ) );
	expect( current.data.json.item ).toMatchObject( {
		name: 'Receipt partial sentinel',
		regular_price: '15',
		sale_price: '',
	} );
	const again = await result(
		native( 'PATCH', `products/${ product.id }`, body( held, values ) )
	);
	expect( again.hooks ).toBe( 0 );
	state.journeys.push( {
		label: info.title,
		batch: closed.batch,
		boundary:
			'separate owned native CRUD lowers price after committed acceptance; original independently validates its steps',
	} );
	record();
} );

test( 'accepted variation reloads its native parent metadata before Writer', async ( {}, info ) => {
	test.skip(
		info.project.name !== 'desktop',
		'Native parent read gate runs once.'
	);
	const parent = state.products[ 12 ];
	const product = state.products[ 13 ];
	const values = { regular_price: '21' };
	const held = await grant( product, values );
	const original = native(
		'PATCH',
		`products/${ product.id }`,
		body( held, values ),
		{ fault: 'accepted' }
	);
	await barrier( original, 'confirmed_acceptance_before_execution' );
	await result(
		start( 'assert', {
			action: 'compete',
			object: parent.id,
			change: 'parent-tax',
		} )
	);
	releaseOriginal( original );
	const answer = await result( original );
	const childHooks = original.entry.events.filter(
		( e ) => e.event === 'save_hook' && e.id === product.id
	);
	expect( childHooks ).toHaveLength( 1 );
	expect( childHooks[ 0 ].tax_status ).toBe( 'none' );
	expect( answer.data.json ).toMatchObject( {
		receipt_state: 'closed',
		write_status: 'complete',
	} );
	const closed = await singleIdentity( held, product.id, 'closed' );
	state.journeys.push( {
		label: info.title,
		batch: closed.batch,
		childHooks,
		boundary:
			'native variation read inherits parent tax metadata; separate parent CRUD updates it during the acceptance barrier',
	} );
	record();
} );

test( 'real COMMIT acknowledgement is dropped before the original mysqli caller receives it', async ( {}, info ) => {
	test.skip( info.project.name !== 'desktop', 'Process gate runs once.' );
	test.setTimeout( 180_000 );
	const product = state.products[ 8 ];
	const values = { regular_price: '26' };
	const held = await grant( product, values );
	const original = native(
		'PATCH',
		`products/${ product.id }`,
		body( held, values ),
		{ fault: 'commit_ack' }
	);
	await expect.poll( () => original.done, { timeout: 90_000 } ).toBe( true );
	await original.finished;
	expect( original.entry.exit ).toBe( 1 );
	expect(
		original.entry.events.some( ( e ) => e.event === 'commit_ok_discarded' )
	).toBe( true );
	// Native wpdb exits before the parent can reap/report its proxy child.
	// The auto-removed owned container bounds and removes both processes.
	expect(
		original.entry.events.filter( ( e ) => e.event === 'save_hook' )
	).toHaveLength( 0 );
	const gate = await blocked( held, product, values, '17.25' );
	state.journeys.push( {
		label: info.title,
		batch: gate.batch,
		boundary:
			'actual MySQL OK packet consumed by owned loopback proxy and socket closed before client acknowledgement',
	} );
	record();
} );

async function openOwned( page ) {
	await page.goto( '/wp-login.php' );
	await page
		.getByLabel( 'Username or Email Address' )
		.fill( `receipt-${ run }` );
	await page.getByLabel( 'Password', { exact: true } ).fill( password );
	await page.getByRole( 'button', { name: 'Log In', exact: true } ).click();
	await expect( page ).toHaveURL( /\/wp-admin\// );
	await openScreen( page );
	const viewName = `${ run } ${ state.journeys.length }`;
	const saved = await rest( page, 'views', {
		method: 'POST',
		data: {
			name: viewName,
			view: {
				type: 'table',
				perPage: 100,
				search: run,
				fields: [ 'status', 'visible_on_site', 'regular_price' ],
				filters: [],
			},
		},
	} );
	expect( saved.status ).toBe( 201 );
	await page.reload();
	await page
		.getByRole( 'group', { name: 'Saved views' } )
		.getByRole( 'button', { name: viewName, exact: true } )
		.click();
	return viewName;
}
function pathOf( request ) {
	return (
		decodeURIComponent( request.url() ).split( 'bulkcat/v1/' )[ 1 ] || ''
	).split( /[?&]/ )[ 0 ];
}
function methodOf( request ) {
	return request.headers()[ 'x-http-method-override' ] || request.method();
}
async function heldStorage( page ) {
	return page.evaluate( () => {
		const s = window.bulkcatSettings;
		return JSON.parse(
			sessionStorage.getItem(
				`bulkcat-selection:${ s.root }:${ s.user.id }:inline-writes`
			) || '[]'
		);
	} );
}

function apacheObservations( target, file = 'observations.jsonl' ) {
	const output = execFileSync(
		'docker',
		[
			'exec',
			'--user',
			'33:33',
			process.env.BULKCAT_RECEIPT_OBSERVER_CONTAINER,
			'php',
			'-r',
			'echo file_get_contents($argv[1]);',
			`${ process.env.BULKCAT_RECEIPT_OBSERVER_DIR }/${ file }`,
		],
		{ encoding: 'utf8', timeout: 10_000 }
	);
	return output
		.trim()
		.split( '\n' )
		.filter( Boolean )
		.map( JSON.parse )
		.filter( ( row ) => row.run === run && row.target === target );
}

test( 'native Apache observer includes shutdown hook before final count and lease release', async ( {
	page,
}, info ) => {
	expect( process.env.BULKCAT_RECEIPT_OBSERVER_CONTAINER ).toMatch(
		/^[a-f0-9]{64}$/
	);
	await openOwned( page );
	const product = state.products[ 0 ];
	const request = randomUUID();
	const response = await page.evaluate(
		async ( args ) => {
			const settings = window.bulkcatSettings;
			const reply = await window.fetch(
				settings.root +
					'products?search=' +
					encodeURIComponent( args.name ),
				{
					credentials: 'same-origin',
					headers: {
						'X-WP-Nonce': settings.nonce,
						'X-Bulkcat-Observer': args.command,
						'X-Bulkcat-Receipt-Run': args.run,
						'X-Bulkcat-Receipt-Target': String( args.target ),
						'X-Bulkcat-Receipt-Request': args.request,
						'X-Bulkcat-Receipt-Lifecycle': 'shutdown',
					},
				}
			);
			return { status: reply.status, json: await reply.json() };
		},
		{
			run,
			request,
			target: product.id,
			name: product.name,
			command: process.env.BULKCAT_RECEIPT_OBSERVER_COMMAND,
		}
	);
	expect( response.status ).toBe( 200 );
	expect( response.json.items.some( ( row ) => row.id === product.id ) ).toBe(
		true
	);
	let witness;
	await expect
		.poll( () => {
			witness = apacheObservations( product.id, 'lifecycle.jsonl' );
			return witness.length;
		} )
		.toBe( 1 );
	const apache = apacheObservations( product.id );
	state.journeys.push( {
		label: info.title,
		request,
		witness,
		apache,
		boundary:
			'synthetic native save-hook event during actual WordPress shutdown; no CRUD save',
	} );
	record();
	expect( witness[ 0 ] ).toMatchObject( {
		run,
		request,
		target: product.id,
		saves: 1,
		shutdown_actions: 1,
		shutdown_running: 1,
	} );
	expect( apache ).toHaveLength( 1 );
	expect( apache[ 0 ] ).toMatchObject( {
		run,
		request,
		target: product.id,
		observer_loaded: 1,
		target_verified: 1,
		saves: 1,
		shutdown_actions: 1,
		shutdown_running: 0,
	} );
	expect( witness[ 0 ].lease_held ).toBe( 1 );
} );

for ( const recovery of [ 'automatic', 'reload-denied' ] ) {
	test( `real HTTP completed response dropped: ${ recovery } preserves exact selection`, async ( {
		page,
	}, info ) => {
		test.setTimeout( 180_000 );
		expect(
			process.env.BULKCAT_RECEIPT_OBSERVER_CONTAINER,
			'Use the bounded Apache observer wrapper for HTTP hook qualification.'
		).toMatch( /^[a-f0-9]{64}$/ );
		expect( process.env.BULKCAT_RECEIPT_OBSERVER_DIR ).toMatch(
			/^\/tmp\/bulkcat-receipt-observer-[a-f0-9-]{36}$/
		);
		const viewName = await openOwned( page );
		const product = state.products[ recovery === 'automatic' ? 5 : 6 ];
		const other = state.products[ 11 ];
		await page
			.getByRole( 'checkbox', { name: other.name, exact: true } )
			.check();
		await expect
			.poll( () => members( page ).catch( () => [] ) )
			.toEqual( [ other.id ] );
		const requests = [];
		const observedRequests = [];
		const started = new Map();
		let click = 0;
		let completed;
		let held;
		let denyRecovery = recovery === 'reload-denied';
		page.on( 'request', ( request ) => {
			if ( pathOf( request ) ) {
				started.set( request, performance.now() );
				requests.push( {
					route: pathOf( request ),
					method: methodOf( request ),
					startMs: performance.now() - click,
				} );
			}
		} );
		page.on( 'response', ( response ) => {
			const request = response.request();
			if ( started.has( request ) ) {
				const row = requests.findLast(
					( r ) =>
						r.route === pathOf( request ) &&
						r.method === methodOf( request ) &&
						r.responseMs === undefined
				);
				if ( row ) {
					row.responseMs = performance.now() - click;
					row.status = response.status();
				}
			}
		} );
		await page.route(
			( url ) =>
				decodeURIComponent( url.toString() ).includes( 'bulkcat/v1/' ),
			async ( route ) => {
				const request = route.request();
				const observed = {
					request: randomUUID(),
					method: methodOf( request ),
					route: pathOf( request ),
				};
				const headers = {
					...request.headers(),
					'X-Bulkcat-Observer':
						process.env.BULKCAT_RECEIPT_OBSERVER_COMMAND,
					'X-Bulkcat-Receipt-Run': run,
					'X-Bulkcat-Receipt-Target': String( product.id ),
					'X-Bulkcat-Receipt-Request': observed.request,
				};
				if (
					methodOf( request ) === 'PATCH' &&
					pathOf( request ) === `products/${ product.id }`
				) {
					held = request.postDataJSON();
					observedRequests.push( observed );
					const response = await route.fetch( { headers } );
					completed = {
						status: response.status(),
						json: await response.json(),
						elapsedMs: performance.now() - click,
					};
					await route.abort( 'connectionreset' );
					return;
				}
				if (
					pathOf( request ) === 'requests/resolve' &&
					denyRecovery
				) {
					await route.abort( 'connectionreset' );
					return;
				}
				observedRequests.push( observed );
				await route.continue( { headers } );
			}
		);
		const control = page.getByRole( 'switch', {
			name: `Shown in shop and search, ${ product.name }`,
		} );
		await tabTo( page, control, { max: 500 } );
		click = performance.now();
		await page.keyboard.press( 'Space' );
		await expect.poll( () => completed?.status ).toBe( 200 );
		expect( completed.json ).toMatchObject( {
			receipt_state: 'closed',
			write_status: 'complete',
		} );
		if ( denyRecovery ) {
			await expect(
				page.getByRole( 'button', {
					name: 'Check save outcome',
					exact: true,
				} )
			).toBeVisible();
			await page.waitForTimeout( 1300 );
			const stored = await heldStorage( page );
			expect( stored ).toHaveLength( 1 );
			expect( Object.keys( stored[ 0 ] ).sort() ).toEqual( [
				'grant',
				'object_id',
			] );
			expect( JSON.stringify( stored ) ).not.toContain(
				'visible_on_site'
			);
			await probe( 'assert', { action: 'rights', deny: true } );
			denyRecovery = false;
			try {
				await page.reload();
				await expect(
					page.getByRole( 'button', {
						name: 'Check save outcome',
						exact: true,
					} )
				).toBeVisible();
				expect( await heldStorage( page ) ).toEqual( stored );
				expect(
					(
						await rest( page, 'requests/resolve', {
							method: 'POST',
							data: {
								operation: 'product.inline',
								uuid: held.uuid,
								request_proof: held.request_proof,
								intent: held.intent,
							},
						} )
					).status
				).toBe( 403 );
			} finally {
				await probe( 'assert', { action: 'rights', deny: false } );
			}
			const check = page.getByRole( 'button', {
				name: 'Check save outcome',
				exact: true,
			} );
			await tabTo( page, check, { max: 500 } );
			await page.keyboard.press( 'Enter' );
		}
		await expect.poll( () => heldStorage( page ) ).toEqual( [] );
		await expect( page.locator( '#bulkcat-app' ) ).toContainText(
			`Saved changes to ${ recovery === 'reload-denied' ? '#' + product.id : product.name }`
		);
		if ( recovery === 'reload-denied' ) {
			await page
				.getByRole( 'group', { name: 'Saved views' } )
				.getByRole( 'button', { name: viewName, exact: true } )
				.click();
		}
		await expect( control ).toHaveAttribute( 'aria-checked', 'true' );
		const displayedMs = performance.now() - click;
		await page.waitForTimeout( 1100 );
		expect( requests.filter( ( r ) => r.method === 'PATCH' ) ).toHaveLength(
			1
		);
		expect(
			requests.filter(
				( r ) => r.route === 'requests' && r.method === 'POST'
			)
		).toHaveLength( 1 );
		expect( await members( page ) ).toEqual( [ other.id ] );
		const nativeIdentity = await singleIdentity(
			held,
			product.id,
			'closed'
		);
		expect( nativeIdentity.batch ).toBe( completed.json.batch_id );
		const axe = await expectAxeClean( page, 'receipt recovery' );
		await page.screenshot( {
			path: info.outputPath( 'receipt-recovery.png' ),
			fullPage: true,
		} );
		await page.unrouteAll( { behavior: 'wait' } );
		let apache;
		await expect
			.poll( () => {
				apache = apacheObservations( product.id );
				return apache.map( ( row ) => row.request ).sort();
			} )
			.toEqual( observedRequests.map( ( row ) => row.request ).sort() );
		const patch = observedRequests.filter(
			( row ) => row.method === 'PATCH'
		);
		expect( patch ).toHaveLength( 1 );
		for ( const row of apache ) {
			expect( row.observer_loaded ).toBe( 1 );
			expect( row.target_verified ).toBe( 1 );
			expect( row.shutdown_actions ).toBe( 1 );
			expect( row.shutdown_running ).toBe( 0 );
			expect( row.saves ).toBe(
				row.request === patch[ 0 ].request ? 1 : 0
			);
		}
		expect( apache.reduce( ( sum, row ) => sum + row.saves, 0 ) ).toBe( 1 );
		state.journeys.push( {
			label: info.title,
			boundary:
				'actual Apache HTTP PATCH completed; Playwright drops only reply; temporary native Apache hook observer verifies one target save and zero recovery saves',
			requests,
			observedRequests,
			apache,
			displayedMs,
			completed,
			batch: nativeIdentity.batch,
			selection: [ other.id ],
			axe,
		} );
		record();
	} );
}

test( 'browser-loaded api-fetch opts inline out while ordinary invalid-nonce request recovers', async ( {
	page,
}, info ) => {
	await openOwned( page );
	const calls = [];
	page.on( 'request', ( r ) => {
		if ( pathOf( r ) || r.url().includes( 'rest-nonce' ) ) {
			calls.push( {
				route: pathOf( r ),
				method: methodOf( r ),
				nonceRefresh: r.url().includes( 'rest-nonce' ),
			} );
		}
	} );
	const product = state.products[ 7 ];
	const values = { regular_price: '25' };
	const held = await grant( product, values );
	const answers = await page.evaluate(
		async ( args ) => {
			const api = window.wp.apiFetch;
			api.nonceMiddleware.nonce = 'receipt-invalid-nonce';
			const settle = ( promise ) =>
				promise.then(
					() => ( { ok: true } ),
					( error ) => ( { code: error.code } )
				);
			return Promise.all( [
				settle(
					api( {
						url:
							window.bulkcatSettings.root +
							`products/${ args.id }`,
						method: 'PATCH',
						bulkcatNoRetry: true,
						data: args.body,
					} )
				),
				settle(
					api( {
						url:
							window.bulkcatSettings.root + 'products?per_page=1',
					} )
				),
			] );
		},
		{ id: product.id, body: body( held, values ) }
	);
	expect( answers[ 0 ].code ).toBe( 'bulkcat_nonce_expired' );
	expect( answers[ 1 ].ok ).toBe( true );
	expect( calls.filter( ( r ) => r.method === 'PATCH' ) ).toHaveLength( 1 );
	expect( calls.filter( ( r ) => r.route === 'products' ) ).toHaveLength( 2 );
	expect( ( await result( resolve( held ) ) ).data.json.receipt_state ).toBe(
		'not_accepted'
	);
	state.journeys.push( {
		label: info.title,
		answers,
		calls,
		scripts: await page
			.locator( 'script[src*="api-fetch"]' )
			.evaluateAll( ( nodes ) => nodes.map( ( node ) => node.src ) ),
	} );
	record();
} );
