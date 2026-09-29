/**
 * T85 scale through the screen (SPEC §12.5; the 25 September "all products
 * Off" report): a whole-catalogue selection reaches an enabled Apply, the
 * change runs through the queue and is undone, and the timings are written
 * down. Only on a scale test site (tests/probe/seed.sh 20000) with the
 * measurement must-use plugin of tests/probe/README.md (Performance), and
 * only when asked:
 *
 *   BULKCAT_E2E_SCALE=1          the products and products-and-variations previews;
 *   BULKCAT_E2E_SCALE_APPLY=1    also apply Hide from site to every product, run
 *                                the queue and undo it (about an hour);
 *   BULKCAT_E2E_SCALE_100K=1     the 100,000-member selection, preview and Apply
 *                                (needs seed.sh 22206: 100,002 objects);
 *   BULKCAT_E2E_SCALE_UNDO=<id>  undo that finished batch from History and run it;
 *   BULKCAT_E2E_METRICS=<file>   where the numbers are written as JSON.
 *
 * Every change it makes is undone before it ends.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { execSync } = require( 'child_process' );
const { test, expect } = require( '@playwright/test' );
const { openScreen } = require( '../helpers' );
const {
	WP,
	php,
	rows,
	screenParts,
	lastBatchId,
	undoLeftovers,
} = require( '../lifecycle/support' );

test.skip(
	! WP || ! process.env.BULKCAT_E2E_SCALE,
	'Scale runs only on a scale site, with BULKCAT_E2E_SCALE=1.'
);

// No click waits for ever on a run that is hours long.
test.use( { actionTimeout: 120_000 } );

const METRICS = process.env.BULKCAT_E2E_METRICS || '';
const SHOTS = process.env.BULKCAT_E2E_SHOTS || '';

/**
 * Add numbers to the metrics file.
 *
 * @param {string} key   Group.
 * @param {Object} value Numbers.
 */
function record( key, value ) {
	// eslint-disable-next-line no-console
	console.log( key, JSON.stringify( value ) );
	if ( ! METRICS ) {
		return;
	}
	let all = {};
	try {
		all = JSON.parse( fs.readFileSync( METRICS, 'utf8' ) );
	} catch {}
	all[ key ] = { ...( all[ key ] || {} ), ...value };
	fs.writeFileSync( METRICS, JSON.stringify( all, null, 2 ) );
}

/**
 * Keep every plugin POST's timing and the measurement plugin's numbers.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {Array<Object>} `{ path, status, ms, perf, bytes }` in order.
 */
function timeWrites( page ) {
	const started = new Map();
	const list = [];
	page.on( 'request', ( request ) => {
		if ( /bulkcat(\/|%2F)v1/.test( request.url() ) ) {
			started.set( request, Date.now() );
		}
	} );
	page.on( 'response', async ( response ) => {
		const request = response.request();
		const match = /bulkcat\/v1\/([a-z/0-9]+)/.exec(
			request.url().replace( /%2F/g, '/' )
		);
		if ( ! match || request.method() !== 'POST' ) {
			return;
		}
		const header = response.headers()[ 'x-bulkcat-perf' ];
		list.push( {
			path: match[ 1 ],
			status: response.status(),
			ms: Date.now() - ( started.get( request ) || Date.now() ),
			perf: header ? JSON.parse( header ) : null,
			bytes: ( request.postData() || '' ).length,
		} );
	} );
	return list;
}

/**
 * Wait for a locator and return how long it took from `from`.
 *
 * @param {import('@playwright/test').Locator} target  What shows.
 * @param {number}                             from    Start, ms.
 * @param {number}                             timeout Most to wait.
 * @return {Promise<number>} Milliseconds.
 */
async function timed( target, from, timeout = 300_000 ) {
	await expect( target ).toBeVisible( { timeout } );
	return Date.now() - from;
}

/**
 * Run the queue until a batch has finished, as `wp action-scheduler run`
 * does on a site without WP-Cron — one WP-CLI process working the chained
 * chunks one after another, as T76 measured — or, for a sample, exactly
 * `chunks` chunk actions; and read what each chunk used from the
 * measurement plugin's log.
 *
 * @param {number} batchId  Batch.
 * @param {number} chunks   Chunk actions to run, 0 for the whole batch.
 * @param {number} basePeak A light REST request's peak memory, MB.
 * @return {Object} Numbers.
 */
function runBatch( batchId, chunks = 0, basePeak = 0 ) {
	php(
		`$f = WP_CONTENT_DIR . '/bulkcat-perf.log'; if ( file_exists( $f ) ) { unlink( $f ); } echo 'true';`
	);
	const started = Date.now();
	let header = null;
	for ( let round = 0; round < 20; round++ ) {
		execSync(
			`${ WP } action-scheduler run --hooks=bulkcat_run_chunk ${
				chunks
					? `--batch-size=1 --batches=${ chunks }`
					: '--batch-size=10'
			}`,
			{ stdio: 'ignore', timeout: 4 * 3600 * 1000 }
		);
		header = rows(
			'SELECT status, total_items, done_items, changed_items, failed_items FROM {p}bulkcat_batches WHERE batch_id = %d',
			[ batchId ]
		)[ 0 ];
		if (
			chunks ||
			! [ 'queued', 'running', 'undoing' ].includes( header.status )
		) {
			break;
		}
	}
	const wall = ( Date.now() - started ) / 1000;
	const logged = php(
		`$f = WP_CONTENT_DIR . '/bulkcat-perf.log';
		$out = array();
		foreach ( file_exists( $f ) ? file( $f, FILE_IGNORE_NEW_LINES ) : array() as $line ) {
			$row = json_decode( $line, true );
			if ( 'bulkcat_run_chunk' === ( $row['kind'] ?? '' ) ) { $out[] = $row; }
		}
		echo wp_json_encode( $out );`
	);
	const own = Math.max( 0, ...logged.map( ( c ) => c.peak_mb - c.start_mb ) );
	const done = Number( header.done_items );
	return {
		batch_id: batchId,
		status: header.status,
		total_items: Number( header.total_items ),
		done_items: done,
		changed_items: Number( header.changed_items ),
		failed_items: Number( header.failed_items ),
		wall_s: Math.round( wall ),
		per_minute: Math.round( done / ( wall / 60 ) ),
		chunks: logged.length,
		chunk_ms_median: median( logged.map( ( c ) => c.ms ) ),
		chunk_ms_max: Math.max( 0, ...logged.map( ( c ) => c.ms ) ),
		chunk_queries_median: median( logged.map( ( c ) => c.queries ) ),
		chunk_own_mb_max: Number( own.toFixed( 2 ) ),
		peak_per_chunk_mb: Number( ( basePeak + own ).toFixed( 2 ) ),
		memory_kept_mb: logged.length
			? Number(
					(
						logged[ logged.length - 1 ].end_mb -
						logged[ 0 ].start_mb
					).toFixed( 2 )
				)
			: 0,
	};
}

/**
 * The median.
 *
 * @param {Array<number>} values Values.
 * @return {number} Median.
 */
function median( values ) {
	if ( ! values.length ) {
		return 0;
	}
	const sorted = [ ...values ].sort( ( a, b ) => a - b );
	return sorted[ Math.floor( sorted.length / 2 ) ];
}

/**
 * A light request's peak memory (GET /fields), MB.
 *
 * @param {import('@playwright/test').Page} page Page on the screen.
 * @return {Promise<number>} MB.
 */
async function lightPeak( page ) {
	return page.evaluate( async () => {
		const s = window.bulkcatSettings;
		const response = await window.fetch( s.root + 'fields', {
			headers: { 'X-WP-Nonce': s.nonce },
		} );
		return JSON.parse( response.headers.get( 'x-bulkcat-perf' ) ).peak_mb;
	} );
}

/**
 * Select every product (or, in Variations mode, every option) the view
 * shows: tick the page, then the bar's Select all offer.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {Promise<number>} Milliseconds from the Select all click to the count.
 */
async function selectEverything( page ) {
	const { bar } = screenParts( page );
	const header = page
		.locator( '.dataviews-view-table thead' )
		.getByRole( 'checkbox' )
		.first();
	await header.check();
	await expect( bar ).toBeVisible();
	const offer = bar.getByRole( 'button', { name: /^Select all [\d,]+ / } );
	const words = await offer.innerText();
	const total = words.match( /([\d,]+)/ )[ 1 ];
	const before = await bar.innerText();
	const started = Date.now();
	await offer.click();
	await expect( bar ).not.toHaveText( before, { timeout: 120_000 } );
	await expect( bar.getByText( /Saving/ ) ).toHaveCount( 0, {
		timeout: 120_000,
	} );
	return { ms: Date.now() - started, total };
}

/**
 * A screenshot when BULKCAT_E2E_SHOTS names a folder.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string}                          name File name.
 */
async function shot( page, name ) {
	if ( SHOTS ) {
		await page.screenshot( { path: path.join( SHOTS, name ) } );
	}
}

/**
 * Products hidden from the catalogue (the product_visibility term Hide from
 * site sets).
 *
 * @return {number} Count.
 */
function hiddenCount() {
	return Number(
		rows(
			"SELECT COUNT(DISTINCT tr.object_id) AS n FROM {p}term_relationships tr JOIN {p}term_taxonomy tt ON tt.term_taxonomy_id = tr.term_taxonomy_id JOIN {p}terms t ON t.term_id = tt.term_id WHERE tt.taxonomy = 'product_visibility' AND t.slug = 'exclude-from-catalog'"
		)[ 0 ].n
	);
}

let mark = 0;
test.beforeEach( () => {
	mark = lastBatchId();
} );
test.afterEach( () => {
	undoLeftovers( mark );
} );

test( 'every product: Select all, Hide from site, an enabled Apply in a clear time; applied through the queue and undone', async ( {
	page,
} ) => {
	test.setTimeout( 4 * 3600 * 1000 );
	const writes = timeWrites( page );
	await openScreen( page );
	const products = Number(
		rows(
			"SELECT COUNT(*) AS n FROM {p}posts WHERE post_type = 'product' AND post_status IN ('publish','draft','pending','private')"
		)[ 0 ].n
	);
	const load = execSync( 'cat /proc/loadavg', { encoding: 'utf8' } ).trim();
	const selected = await selectEverything( page );
	const { bar } = screenParts( page );
	const capture = writes.filter( ( w ) => w.path === 'selection' ).pop();
	const compose = writes
		.filter( ( w ) => w.path === 'selection/compose' )
		.pop();

	// the client's case: Hide from site on the whole catalogue.
	const opened = Date.now();
	await bar.getByRole( 'button', { name: 'Hide from site' } ).click();
	const panel = page.getByRole( 'dialog', { name: 'Bulk change' } );
	const check = panel.getByRole( 'button', { name: 'Check the change' } );
	await expect( check ).toBeEnabled();
	const checking = Date.now();
	await check.click();
	await expect(
		panel.getByText( 'Working out what would change. Nothing is saved.' )
	)
		.toBeVisible()
		.catch( () => {} );
	await shot( page, 'scale-products-computing.png' );
	const apply = panel.getByRole( 'button', {
		name: /^Apply to [\d,]+ products?$/,
	} );
	const previewMs = await timed( apply, checking );
	await expect( apply ).toBeEnabled();
	const toApply = Date.now() - opened;
	const preview = writes.filter( ( w ) => w.path === 'preview' ).pop();
	const applyName = await apply.innerText();
	await shot( page, 'scale-products-ready-1440x900.png' );
	for ( const [ width, height ] of [
		[ 1024, 800 ],
		[ 1440, 680 ],
	] ) {
		await page.setViewportSize( { width, height } );
		await expect( apply ).toBeInViewport();
		await expect( apply ).toBeEnabled();
		await shot( page, `scale-products-ready-${ width }x${ height }.png` );
	}
	await page.setViewportSize( { width: 1440, height: 900 } );
	record( 'products_preview', {
		products,
		load_before: load,
		select_all_ms: selected.ms,
		select_all_offer: selected.total,
		capture_server_ms: capture?.perf?.server_ms,
		capture_peak_mb: capture?.perf?.peak_mb,
		compose_server_ms: compose?.perf?.server_ms,
		compose_peak_mb: compose?.perf?.peak_mb,
		check_to_enabled_apply_ms: previewMs,
		open_panel_to_enabled_apply_ms: toApply,
		preview_http_ms: preview?.ms,
		preview_server_ms: preview?.perf?.server_ms,
		preview_queries: preview?.perf?.dispatch_queries,
		preview_peak_mb: preview?.perf?.peak_mb,
		apply_button: applyName,
	} );
	if ( ! process.env.BULKCAT_E2E_SCALE_APPLY ) {
		return;
	}

	// Apply: the confirmation, then the durable request.
	const hiddenBefore = hiddenCount();
	await apply.click();
	const yes = panel.getByRole( 'button', { name: /^Yes, change / } );
	await expect( yes ).toBeVisible();
	await shot( page, 'scale-products-confirm.png' );
	const confirmed = Date.now();
	await yes.click();
	await expect( panel ).toContainText( 'Applying the change', {
		timeout: 300_000,
	} );
	const accepted = writes.filter( ( w ) => w.path === 'batches' ).pop();
	const batchId = Number(
		rows(
			"SELECT MAX(batch_id) AS id FROM {p}bulkcat_batches WHERE source = 'bulk'"
		)[ 0 ].id
	);
	const base = await lightPeak( page );
	record( 'products_apply_accept', {
		batch_id: batchId,
		click_to_accepted_ms: Date.now() - confirmed,
		batches_http_ms: accepted?.ms,
		batches_server_ms: accepted?.perf?.server_ms,
		batches_queries: accepted?.perf?.dispatch_queries,
		batches_peak_mb: accepted?.perf?.peak_mb,
		request_bytes: accepted?.bytes,
		light_request_peak_mb: base,
	} );
	const run = runBatch( batchId, 0, base );
	record( 'products_apply_queue', run );
	expect( run.status ).toBe( 'complete' );
	await expect( panel ).toContainText( 'products updated', {
		timeout: 120_000,
	} );
	await expect( page.locator( '.bulkcat-selection' ) ).toContainText(
		'The change finished, so your selection was cleared.',
		{ timeout: 120_000 }
	);
	await shot( page, 'scale-products-done.png' );
	const hidden = hiddenCount();
	record( 'products_apply_result', { hidden_from_catalogue: hidden } );

	// Undo from History, through the screen.
	await panel.getByRole( 'button', { name: 'Undo this change' } ).click();
	const confirm = page.getByRole( 'dialog', { name: 'Undo this change?' } );
	const planStarted = Date.now();
	await expect( confirm ).toBeVisible( { timeout: 600_000 } );
	const planMs = Date.now() - planStarted;
	await confirm.getByRole( 'button', { name: 'Undo', exact: true } ).click();
	await expect( confirm ).toBeHidden( { timeout: 300_000 } );
	const undoId = Number(
		rows(
			'SELECT batch_id FROM {p}bulkcat_batches WHERE undo_of = %d ORDER BY batch_id DESC LIMIT 1',
			[ batchId ]
		)[ 0 ].batch_id
	);
	const undoRun = runBatch( undoId, 0, base );
	record( 'products_undo_queue', { undo_preview_ms: planMs, ...undoRun } );
	expect( undoRun.status ).toBe( 'complete' );
	const after = hiddenCount();
	record( 'products_undo_result', {
		hidden_before: hiddenBefore,
		hidden_after_undo: after,
	} );
	expect( after ).toBe( hiddenBefore );
} );

test( 'products and variations: every product and every option selected, then an enabled Apply', async ( {
	page,
} ) => {
	test.setTimeout( 3600 * 1000 );
	const writes = timeWrites( page );
	await openScreen( page );
	const first = await selectEverything( page );
	await page
		.getByRole( 'button', { name: 'Variations', exact: true } )
		.click();
	await expect(
		page.locator( '.dataviews-view-table tbody tr' ).first()
	).toBeVisible();
	const second = await selectEverything( page );
	const { bar } = screenParts( page );
	const count = await bar
		.locator( '.bulkcat-selection__count, strong' )
		.first()
		.innerText()
		.catch( () => '' );
	const composes = writes.filter( ( w ) => w.path === 'selection/compose' );
	const opened = Date.now();
	await bar.getByRole( 'button', { name: 'Make a change to these' } ).click();
	const panel = page.getByRole( 'dialog', { name: 'Bulk change' } );
	await panel
		.getByRole( 'radio', { name: /^Increase or decrease/ } )
		.first()
		.check();
	await panel
		.getByLabel( 'By' )
		.selectOption( 'percent' )
		.catch( () => {} );
	await panel.getByRole( 'textbox', { name: 'How much' } ).fill( '10' );
	const checking = Date.now();
	await panel.getByRole( 'button', { name: 'Check the change' } ).click();
	const apply = panel.getByRole( 'button', { name: /^Apply to / } );
	const previewMs = await timed( apply, checking );
	await expect( apply ).toBeEnabled();
	await shot( page, 'scale-mixed-ready.png' );
	const preview = writes.filter( ( w ) => w.path === 'preview' ).pop();
	record( 'mixed_preview', {
		selected_words: count || ( await bar.innerText() ).split( '\n' )[ 0 ],
		products_select_all_ms: first.ms,
		variations_select_all_ms: second.ms,
		compose_server_ms: composes.map( ( w ) => w.perf?.server_ms ),
		compose_peak_mb: composes.map( ( w ) => w.perf?.peak_mb ),
		check_to_enabled_apply_ms: previewMs,
		open_panel_to_enabled_apply_ms: Date.now() - opened,
		preview_server_ms: preview?.perf?.server_ms,
		preview_queries: preview?.perf?.dispatch_queries,
		preview_peak_mb: preview?.perf?.peak_mb,
		apply_button: await apply.innerText(),
	} );
} );

/**
 * A checksum of every regular price (the change the 100,000 run makes).
 *
 * @return {Object} `{ n, total }`.
 */
function priceChecksum() {
	return rows(
		"SELECT COUNT(*) AS n, ROUND(SUM(CAST(m.meta_value AS DECIMAL(14,4))), 4) AS total FROM {p}postmeta m JOIN {p}posts p ON p.ID = m.post_id WHERE m.meta_key = '_regular_price' AND m.meta_value <> '' AND p.post_type IN ('product','product_variation')"
	)[ 0 ];
}

test( '100,000 members: composed exactly through the screen, 100,002 refused, previewed, accepted; a sample of the queue, cancelled and undone', async ( {
	page,
} ) => {
	test.skip(
		! process.env.BULKCAT_E2E_SCALE_100K,
		'Needs seed.sh 22206 and BULKCAT_E2E_SCALE_100K=1.'
	);
	test.setTimeout( 3 * 3600 * 1000 );
	const writes = timeWrites( page );
	const sampleChunks = Number(
		process.env.BULKCAT_E2E_SCALE_SAMPLE_CHUNKS || 60
	);
	await openScreen( page );
	const { bar, search } = screenParts( page );
	const products = await selectEverything( page );

	// Every option too would be 100,002: refused, the selection kept.
	await page
		.getByRole( 'button', { name: 'Variations', exact: true } )
		.click();
	await expect(
		page.locator( '.dataviews-view-table tbody tr' ).first()
	).toBeVisible();
	const offer = bar.getByRole( 'button', { name: /^Select all [\d,]+ / } );
	const allOptions = ( await offer.innerText() ).match( /([\d,]+)/ )[ 1 ];
	await offer.click();
	await expect( page.locator( '.bulkcat-selection' ) ).toContainText(
		'This selection is too large. Add a filter to narrow it and try again.',
		{ timeout: 120_000 }
	);
	const refusedWords = ( await bar.innerText() ).split( '\n' )[ 0 ];
	await shot( page, 'scale-100k-refused.png' );

	// Exactly 100,000: the scale options, the sixty, and one more option.
	await search.fill( 'Scale Product' );
	await expect(
		page.locator( '.dataviews-view-table tbody tr' ).first()
	).toBeVisible();
	await expect( bar.getByText( /Saving/ ) ).toHaveCount( 0, {
		timeout: 120_000,
	} );
	const scaleOptions = await selectOffer( page );
	await search.fill( 'Probe Sixty' );
	await expect(
		page.locator( '.dataviews-view-table tbody tr' ).first()
	).toBeVisible();
	const sixty = await selectOffer( page );
	// Then options of Probe Variable, one at a time, up to exactly 100,000.
	const selectedCount = async () =>
		Number(
			( ( await bar.innerText() ).match( /^([\d,]+) products?/ ) || [
				'',
				'0',
			] )[ 1 ].replace( /,/g, '' )
		) +
		Number(
			( ( await bar.innerText() ).match(
				/([\d,]+) variations? selected/
			) || [ '', '0' ] )[ 1 ].replace( /,/g, '' )
		);
	await search.fill( 'Probe Variable' );
	const options = page
		.locator( '.dataviews-view-table tbody tr' )
		.getByRole( 'checkbox' );
	await expect( options.first() ).toBeVisible();
	for ( let i = 0; ( await selectedCount() ) < 100000 && i < 3; i++ ) {
		await options.nth( i ).check();
		await expect( bar.getByText( /Saving/ ) ).toHaveCount( 0, {
			timeout: 120_000,
		} );
	}
	expect( await selectedCount() ).toBe( 100000 );
	const words = ( await bar.innerText() ).split( '\n' )[ 0 ];
	await shot( page, 'scale-100k-selected.png' );
	const composes = writes.filter( ( w ) => w.path === 'selection/compose' );
	record( 'hundred_thousand_selection', {
		products_select_all_ms: products.ms,
		all_options_offer: allOptions,
		refused_left_selection: refusedWords,
		scale_options_select_all_ms: scaleOptions,
		sixty_select_all_ms: sixty,
		selected_words: words,
		compose_server_ms_max: Math.max(
			...composes.map( ( w ) => w.perf?.server_ms || 0 )
		),
		compose_peak_mb_max: Math.max(
			...composes.map( ( w ) => w.perf?.peak_mb || 0 )
		),
	} );

	// Preview an increase of 10 per cent.
	const before = priceChecksum();
	const opened = Date.now();
	await bar.getByRole( 'button', { name: 'Make a change to these' } ).click();
	const panel = page.getByRole( 'dialog', { name: 'Bulk change' } );
	await panel
		.getByRole( 'radio', { name: /^Increase or decrease/ } )
		.first()
		.check();
	await panel
		.getByLabel( 'By' )
		.selectOption( 'percent' )
		.catch( () => {} );
	await panel.getByRole( 'textbox', { name: 'How much' } ).fill( '10' );
	const checking = Date.now();
	await panel.getByRole( 'button', { name: 'Check the change' } ).click();
	const apply = panel.getByRole( 'button', { name: /^Apply to / } );
	const previewMs = await timed( apply, checking );
	await expect( apply ).toBeEnabled();
	await shot( page, 'scale-100k-ready.png' );
	const preview = writes.filter( ( w ) => w.path === 'preview' ).pop();
	record( 'hundred_thousand_preview', {
		check_to_enabled_apply_ms: previewMs,
		open_panel_to_enabled_apply_ms: Date.now() - opened,
		preview_server_ms: preview?.perf?.server_ms,
		preview_queries: preview?.perf?.dispatch_queries,
		preview_peak_mb: preview?.perf?.peak_mb,
		apply_button: await apply.innerText(),
	} );

	// Apply: acceptance materialises every item in one transaction.
	await apply.click();
	const confirmed = Date.now();
	await panel.getByRole( 'button', { name: /^Yes, change / } ).click();
	await expect( panel ).toContainText( 'Applying the change', {
		timeout: 600_000,
	} );
	const accepted = writes.filter( ( w ) => w.path === 'batches' ).pop();
	const batchId = Number(
		rows(
			"SELECT MAX(batch_id) AS id FROM {p}bulkcat_batches WHERE source = 'bulk'"
		)[ 0 ].id
	);
	const base = await lightPeak( page );
	record( 'hundred_thousand_accept', {
		batch_id: batchId,
		click_to_accepted_ms: Date.now() - confirmed,
		batches_http_ms: accepted?.ms,
		batches_server_ms: accepted?.perf?.server_ms,
		batches_queries: accepted?.perf?.dispatch_queries,
		batches_peak_mb: accepted?.perf?.peak_mb,
		request_bytes: accepted?.bytes,
		items: Number(
			rows(
				'SELECT COUNT(*) AS n FROM {p}bulkcat_batch_items WHERE batch_id = %d',
				[ batchId ]
			)[ 0 ].n
		),
	} );

	// A sample of the queue, then Cancel from the screen.
	const sample = runBatch( batchId, sampleChunks, base );
	record( 'hundred_thousand_queue_sample', sample );
	await panel.getByRole( 'button', { name: 'Back to products' } ).click();
	const banner = page.getByRole( 'group', { name: 'Change in progress' } );
	if ( 'running' === sample.status || 'queued' === sample.status ) {
		await banner.getByRole( 'button', { name: 'Cancel' } ).click();
		await expect( banner ).toBeHidden( { timeout: 120_000 } );
	}
	execSync(
		`${ WP } action-scheduler run --hooks=bulkcat_run_chunk --batch-size=5`,
		{
			stdio: 'ignore',
			timeout: 900_000,
		}
	);
	const header = rows(
		'SELECT status, done_items, changed_items FROM {p}bulkcat_batches WHERE batch_id = %d',
		[ batchId ]
	)[ 0 ];
	record( 'hundred_thousand_cancelled', header );
	await shot( page, 'scale-100k-cancelled.png' );

	// Undo what was applied, from History.
	await page.goto(
		`/wp-admin/edit.php?post_type=product&page=bulk-catalogue-manager-for-woocommerce&panel=history&batch=${ batchId }&undo=1`
	);
	const confirm = page.getByRole( 'dialog', { name: 'Undo this change?' } );
	const planned = Date.now();
	await expect( confirm ).toBeVisible( { timeout: 900_000 } );
	const planMs = Date.now() - planned;
	await confirm.getByRole( 'button', { name: 'Undo', exact: true } ).click();
	await expect( confirm ).toBeHidden( { timeout: 600_000 } );
	const undoId = Number(
		rows(
			'SELECT batch_id FROM {p}bulkcat_batches WHERE undo_of = %d ORDER BY batch_id DESC LIMIT 1',
			[ batchId ]
		)[ 0 ].batch_id
	);
	const undone = runBatch( undoId, 0, base );
	record( 'hundred_thousand_undo', { undo_preview_ms: planMs, ...undone } );
	const after = priceChecksum();
	record( 'hundred_thousand_prices', { before, after } );
	expect( after ).toEqual( before );
} );

/**
 * Take the bar's Select all offer for the view; returns how long it took.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {Promise<number>} Milliseconds.
 */
async function selectOffer( page ) {
	const { bar } = screenParts( page );
	const offer = bar.getByRole( 'button', { name: /^Select all [\d,]+ / } );
	const before = ( await bar.innerText() ).split( '\n' )[ 0 ];
	const started = Date.now();
	await offer.click();
	await expect( bar ).not.toContainText( before, { timeout: 120_000 } );
	await expect( bar.getByText( /Saving/ ) ).toHaveCount( 0, {
		timeout: 120_000,
	} );
	return Date.now() - started;
}

test( 'undo a finished whole-catalogue change from History and run it (BULKCAT_E2E_SCALE_UNDO=<batch id>)', async ( {
	page,
} ) => {
	const batchId = Number( process.env.BULKCAT_E2E_SCALE_UNDO || 0 );
	test.skip( ! batchId, 'Names the finished batch to undo.' );
	test.setTimeout( 4 * 3600 * 1000 );
	const writes = timeWrites( page );
	const hiddenBefore = hiddenCount();
	await openScreen( page );
	const base = await lightPeak( page );
	await page.goto(
		`/wp-admin/edit.php?post_type=product&page=bulk-catalogue-manager-for-woocommerce&panel=history&batch=${ batchId }&undo=1`
	);
	const confirm = page.getByRole( 'dialog', { name: 'Undo this change?' } );
	const planned = Date.now();
	await expect( confirm ).toBeVisible( { timeout: 900_000 } );
	const planMs = Date.now() - planned;
	const planRequest = writes
		.filter( ( w ) => /\/undo$/.test( w.path ) )
		.pop();
	await shot( page, 'scale-undo-confirm.png' );
	const clicked = Date.now();
	await confirm.getByRole( 'button', { name: 'Undo', exact: true } ).click();
	await expect( confirm ).toBeHidden( { timeout: 900_000 } );
	// The dialog closes as the request leaves; the undo batch follows.
	const undoOf = () =>
		Number(
			rows(
				'SELECT batch_id FROM {p}bulkcat_batches WHERE undo_of = %d ORDER BY batch_id DESC LIMIT 1',
				[ batchId ]
			)[ 0 ]?.batch_id || 0
		);
	await expect.poll( undoOf, { timeout: 900_000 } ).toBeGreaterThan( 0 );
	const undoId = undoOf();
	await expect
		.poll(
			() => writes.filter( ( w ) => /\/undo$/.test( w.path ) ).length,
			{
				timeout: 900_000,
			}
		)
		.toBeGreaterThan( 1 );
	const accepted = writes.filter( ( w ) => /\/undo$/.test( w.path ) ).pop();
	record( `undo_${ batchId }_accept`, {
		undo_batch_id: undoId,
		open_to_confirm_ms: planMs,
		plan_server_ms: planRequest?.perf?.server_ms,
		plan_peak_mb: planRequest?.perf?.peak_mb,
		click_to_accepted_ms: Date.now() - clicked,
		accept_server_ms: accepted?.perf?.server_ms,
		accept_peak_mb: accepted?.perf?.peak_mb,
		hidden_before: hiddenBefore,
	} );
	const run = runBatch( undoId, 0, base );
	record( `undo_${ batchId }_queue`, {
		...run,
		hidden_after: hiddenCount(),
	} );
	expect( run.status ).toBe( 'complete' );
} );
