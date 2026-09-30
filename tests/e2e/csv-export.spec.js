/**
 * T103: the export dialog on a real site (ticket 10; SPEC §10, §14). Ticked
 * rows export as the selection with the identifiers first, in ascending ID
 * order, and the file goes back through WooCommerce's own Products → Import
 * with Update existing products: nothing changes, then only an edited price
 * changes (and is put back). A selection built across two searches, with an
 * off-page item removed in Review selected products, exports exactly its
 * remaining members. By keyboard alone, with nothing selected, the whole
 * catalogue needs its confirmation before Download. Axe finds nothing
 * serious in the open dialog at 1280 and 320 pixels, and the page does not
 * scroll sideways at 320. No request carries the selection's token in its
 * address.
 */
const fs = require( 'fs' );
const { test, expect } = require( '@playwright/test' );
const {
	SCREEN,
	SEED,
	openScreen,
	tabFromContent,
	tabTo,
	rest,
	productByName,
	expectAxeClean,
} = require( './helpers' );

const EXPORT = /bulkcat(\/|%2F)v1(\/|%2F)export/;
const IMPORTER = '/wp-admin/edit.php?post_type=product&page=product_importer';
const FIELDS = [ 'sku', 'regular_price', 'sale_price', 'stock_status' ];

/**
 * Every POST to the export route the page sends: its address and body.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {Array<Object>} `{ url, body }`, filled as requests go.
 */
function exportPosts( page ) {
	const seen = [];
	page.on( 'request', ( request ) => {
		if ( request.method() === 'POST' && EXPORT.test( request.url() ) ) {
			seen.push( { url: request.url(), body: request.postData() || '' } );
		}
	} );
	return seen;
}

/**
 * The rows of a CSV file (RFC 4180: quoted fields, doubled quotes, CRLF),
 * its byte-order mark removed.
 *
 * @param {string} text File.
 * @return {Array<Array<string>>} Rows.
 */
function csvRows( text ) {
	const rows = [];
	let row = [];
	let cell = '';
	let quoted = false;
	const body = text.replace( /^\uFEFF/, '' );
	for ( let i = 0; i < body.length; i++ ) {
		const char = body[ i ];
		if ( quoted ) {
			if ( char === '"' && body[ i + 1 ] === '"' ) {
				cell += '"';
				i++;
			} else if ( char === '"' ) {
				quoted = false;
			} else {
				cell += char;
			}
		} else if ( char === '"' ) {
			quoted = true;
		} else if ( char === ',' ) {
			row.push( cell );
			cell = '';
		} else if ( char === '\r' && body[ i + 1 ] === '\n' ) {
			row.push( cell );
			rows.push( row );
			row = [];
			cell = '';
			i++;
		} else {
			cell += char;
		}
	}
	if ( cell !== '' || row.length ) {
		row.push( cell );
		rows.push( row );
	}
	return rows;
}

/**
 * One CSV line with every field quoted, as the export writes data rows.
 *
 * @param {Array<string>} cells Cells.
 * @return {string} Line.
 */
function csvLine( cells ) {
	return cells
		.map( ( cell ) => `"${ String( cell ).replace( /"/g, '""' ) }"` )
		.join( ',' );
}

/**
 * Clear the selection when there is one (set-up, not the journey).
 *
 * @param {import('@playwright/test').Page} page Page.
 */
async function clearSelection( page ) {
	const bar = page.getByRole( 'region', { name: 'Selection' } );
	if ( await bar.count() ) {
		await bar.getByRole( 'button', { name: 'Clear selection' } ).click();
		await expect( bar ).toHaveCount( 0 );
	}
}

/**
 * Open the export dialog with the pointer.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @return {Promise<import('@playwright/test').Locator>} Dialog.
 */
async function openExport( page ) {
	await page.getByRole( 'button', { name: 'Export CSV' } ).click();
	const dialog = page.getByRole( 'dialog', { name: 'Export CSV' } );
	await expect( dialog ).toBeVisible();
	return dialog;
}

/**
 * Start a download and read the file.
 *
 * @param {import('@playwright/test').Page} page  Page.
 * @param {Function}                        start What starts it.
 * @return {Promise<Object>} `{ name, text, path }`.
 */
async function downloaded( page, start ) {
	const [ download ] = await Promise.all( [
		page.waitForEvent( 'download' ),
		start(),
	] );
	const file = await download.path();
	return {
		name: download.suggestedFilename(),
		text: fs.readFileSync( file, 'utf8' ),
		path: file,
	};
}

/**
 * Products, variations and terms: what an import must not add to.
 *
 * @param {import('@playwright/test').Page} page Page on the screen.
 * @return {Promise<Object>} Counts.
 */
async function counts( page ) {
	const items = await rest(
		page,
		'products?mode=products_and_variations&per_page=1&fields[]=name'
	);
	const tags = await rest( page, 'terms/product_tag' );
	const classes = await rest( page, 'terms/product_shipping_class' );
	const tree = await rest( page, 'categories' );
	const walk = ( nodes ) =>
		( nodes || [] ).reduce(
			( sum, node ) => sum + 1 + walk( node.children ),
			0
		);
	return {
		items: items.json.pagination.total,
		tags: tags.json.length,
		classes: classes.json.length,
		categories: walk( tree.json.items || tree.json ),
	};
}

/**
 * The values of some products, by name.
 *
 * @param {import('@playwright/test').Page} page  Page on the screen.
 * @param {Array<string>}                   names Product names.
 * @return {Promise<Object>} Rows by name.
 */
async function values( page, names ) {
	const out = {};
	for ( const name of names ) {
		out[ name ] = await productByName( page, name, FIELDS );
	}
	return out;
}

/**
 * Import a file through WooCommerce's own importer with Update existing
 * products and the automatic mapping.
 *
 * @param {import('@playwright/test').Page} page Page.
 * @param {string}                          file Path.
 * @return {Promise<Object>} `{ mapping: { heading: field }, done }`.
 */
async function importFile( page, file ) {
	await page.goto( IMPORTER );
	await page.locator( '#upload' ).setInputFiles( file );
	await page.locator( '#woocommerce-importer-update-existing' ).check();
	await expect(
		page.locator( '#woocommerce-importer-map-preferences' )
	).not.toBeChecked();
	await page.getByRole( 'button', { name: 'Continue' } ).click();
	const table = page.locator( '.wc-importer-mapping-table' );
	await expect( table ).toBeVisible();
	const mapping = Object.fromEntries(
		await table
			.locator( 'tbody tr' )
			.evaluateAll( ( rows ) =>
				rows.map( ( row ) => [
					row.querySelector( 'input[name^="map_from"]' ).value,
					row.querySelector( 'select' ).value,
				] )
			)
	);
	await page.getByRole( 'button', { name: 'Run the importer' } ).click();
	const done = page.locator( '.woocommerce-importer-done' );
	await expect( done ).toBeVisible( { timeout: 120_000 } );
	return { mapping, done: ( await done.innerText() ).trim() };
}

test.describe( 'the export dialog', () => {
	test( 'ticked rows export as the selection, identifiers first, and go back through Products → Import unchanged', async ( {
		page,
	} ) => {
		test.setTimeout( 300_000 );
		const posts = exportPosts( page );
		await openScreen( page );
		await clearSelection( page );
		const names = [ 3, 1, 5 ].map( SEED.simple );
		const ids = [];
		for ( const name of names ) {
			ids.push( ( await productByName( page, name ) ).id );
		}
		await page
			.getByRole( 'searchbox', { name: 'Search by name or SKU' } )
			.fill( 'Probe Simple 0' );
		for ( const name of names ) {
			await page.getByRole( 'checkbox', { name, exact: true } ).check();
		}
		const bar = page.getByRole( 'region', { name: 'Selection' } );
		await expect( bar ).toContainText( '3 products selected' );

		const dialog = await openExport( page );
		await expect(
			dialog.getByRole( 'radio', { name: 'Selected: 3 products' } )
		).toBeChecked();
		const identifiers = dialog.getByRole( 'checkbox', {
			name: 'Include identifiers for re-import',
		} );
		await expect( identifiers ).not.toBeChecked();
		await identifiers.check();
		await expect( dialog ).toContainText(
			'The file will have 3 rows. 0 selected items will be left out.'
		);
		const file = await downloaded( page, () =>
			dialog.getByRole( 'button', { name: 'Download' } ).click()
		);
		await expect( dialog ).toBeHidden();
		expect( file.name ).toMatch(
			/^catalogue-selected-with-ids-\d{4}-\d{2}-\d{2}-\d{4}\.csv$/
		);
		expect(
			file.text.startsWith( '\uFEFFID,"SKU","Parent","Type",' )
		).toBe( true );
		const rows = csvRows( file.text );
		expect( rows[ 0 ].slice( 0, 5 ) ).toEqual( [
			'ID',
			'SKU',
			'Parent',
			'Type',
			'Name',
		] );
		expect( rows.slice( 1 ).map( ( row ) => Number( row[ 0 ] ) ) ).toEqual(
			[ ...ids ].sort( ( a, b ) => a - b )
		);
		// The token went in the bodies only, never in an address.
		expect( posts.length ).toBeGreaterThanOrEqual( 2 );
		posts.forEach( ( post ) => {
			expect( post.url ).not.toMatch( /v2\.|selection=/ );
		} );
		expect(
			posts.some( ( post ) => /(^|&)selection=v2\./.test( post.body ) )
		).toBe( true );
		await clearSelection( page );

		// Products → Import, Update existing products, automatic mapping.
		const original = test.info().outputPath( 'selected.csv' );
		fs.writeFileSync( original, file.text );
		const before = await values( page, names );
		const counted = await counts( page );
		const first = await importFile( page, original );
		expect( first.mapping.ID ).toBe( 'id' );
		expect( first.mapping.SKU ).toBe( 'sku' );
		expect( first.mapping.Parent ).toBe( 'parent_id' );
		expect( first.mapping.Type ).toBe( 'type' );
		const reference = Object.keys( first.mapping ).filter( ( heading ) =>
			heading.endsWith( '(for reference)' )
		);
		expect( reference.length ).toBeGreaterThan( 0 );
		reference.forEach( ( heading ) =>
			expect( first.mapping[ heading ], heading ).toBe( '' )
		);
		expect( first.done ).toContain( '3 products updated' );
		expect( first.done ).not.toMatch( /imported|skipped|Failed/ );
		await openScreen( page );
		expect( await values( page, names ) ).toEqual( before );
		expect( await counts( page ) ).toEqual( counted );

		// Edit one regular price in the file: only that value changes.
		const priceAt = rows[ 0 ].indexOf( 'Regular price' );
		expect( priceAt ).toBeGreaterThan( 0 );
		const edited = test.info().outputPath( 'selected-edited.csv' );
		const target = rows.findIndex( ( row ) => row[ 4 ] === names[ 1 ] );
		const price = (
			Number( before[ names[ 1 ] ].regular_price || 0 ) + 1.25
		).toFixed( 2 );
		const lines = file.text.split( '\r\n' );
		const cells = [ ...rows[ target ] ];
		cells[ priceAt ] = price;
		lines[ target ] = csvLine( cells );
		fs.writeFileSync( edited, lines.join( '\r\n' ) );
		const second = await importFile( page, edited );
		expect( second.done ).toContain( '3 products updated' );
		await openScreen( page );
		const after = await values( page, names );
		expect( Number( after[ names[ 1 ] ].regular_price ) ).toBeCloseTo(
			Number( price ),
			2
		);
		expect( {
			...after,
			[ names[ 1 ] ]: before[ names[ 1 ] ],
		} ).toEqual( before );
		expect( await counts( page ) ).toEqual( counted );

		// Put the price back with the unedited file.
		await importFile( page, original );
		await openScreen( page );
		expect( await values( page, names ) ).toEqual( before );
	} );

	test( 'a selection built across two searches, with an off-page item removed in Review, exports exactly its members', async ( {
		page,
	} ) => {
		const posts = exportPosts( page );
		await openScreen( page );
		await clearSelection( page );
		const search = page.getByRole( 'searchbox', {
			name: 'Search by name or SKU',
		} );
		const bar = page.getByRole( 'region', { name: 'Selection' } );
		const picked = [ 4, 6, 7 ].map( SEED.simple );
		const ids = {};
		for ( const name of picked ) {
			ids[ name ] = ( await productByName( page, name ) ).id;
			await search.fill( name );
			await page.getByRole( 'checkbox', { name, exact: true } ).check();
		}
		await expect( bar ).toContainText( '3 products selected' );
		// Only the last search's row is on screen; remove one that is not.
		await expect(
			page.getByRole( 'checkbox', { name: picked[ 0 ], exact: true } )
		).toHaveCount( 0 );
		await bar
			.getByRole( 'button', { name: 'Review selected products' } )
			.click();
		const drawer = page.getByRole( 'dialog', {
			name: 'Selected products',
		} );
		await expect( drawer ).toContainText( picked[ 0 ] );
		await drawer
			.getByRole( 'button', {
				name: `Remove ${ picked[ 0 ] } from the selection`,
			} )
			.click();
		await expect( drawer ).toContainText( '2 products selected' );
		await page.keyboard.press( 'Escape' );
		await expect( drawer ).toBeHidden();

		const dialog = await openExport( page );
		await expect(
			dialog.getByRole( 'radio', { name: 'Selected: 2 products' } )
		).toBeChecked();
		await dialog
			.getByRole( 'checkbox', {
				name: 'Include identifiers for re-import',
			} )
			.check();
		await expect( dialog ).toContainText( 'The file will have 2 rows.' );
		const file = await downloaded( page, () =>
			dialog.getByRole( 'button', { name: 'Download' } ).click()
		);
		const rows = csvRows( file.text );
		expect( rows.slice( 1 ).map( ( row ) => Number( row[ 0 ] ) ) ).toEqual(
			[ ids[ picked[ 1 ] ], ids[ picked[ 2 ] ] ].sort( ( a, b ) => a - b )
		);
		posts.forEach( ( post ) =>
			expect( post.url ).not.toMatch( /v2\.|selection=/ )
		);
		await clearSelection( page );
	} );

	test( 'by keyboard alone, the whole catalogue needs its confirmation before Download', async ( {
		page,
	} ) => {
		await openScreen( page );
		await clearSelection( page );
		const clear = page.getByRole( 'button', { name: 'Clear filters' } );
		if ( await clear.count() ) {
			await tabFromContent( page, clear.first() );
			await page.keyboard.press( 'Enter' );
		}
		await openScreen( page );
		const exportButton = page.getByRole( 'button', { name: 'Export CSV' } );
		await tabFromContent( page, exportButton );
		await page.keyboard.press( 'Enter' );
		const dialog = page.getByRole( 'dialog', { name: 'Export CSV' } );
		await expect( dialog ).toBeVisible();
		await expect(
			dialog.getByRole( 'radio', { name: 'Selected' } )
		).toBeDisabled();
		await expect( dialog ).toContainText( 'Nothing selected' );
		const confirm = dialog.getByRole( 'checkbox', {
			name: 'Yes, export the whole catalogue',
		} );
		await expect( confirm ).toBeVisible();
		await expect(
			dialog.getByRole( 'radio', { name: /^The whole catalogue \(/ } )
		).toBeChecked();
		const download = dialog.getByRole( 'button', { name: 'Download' } );
		await expect( download ).toHaveAttribute( 'aria-disabled', 'true' );
		await tabTo( page, confirm );
		await page.keyboard.press( 'Space' );
		await expect( confirm ).toBeChecked();
		await expect( download ).not.toHaveAttribute( 'aria-disabled', 'true' );
		await tabTo( page, download );
		const file = await downloaded( page, () =>
			page.keyboard.press( 'Enter' )
		);
		expect( file.name ).toMatch(
			/^catalogue-all-\d{4}-\d{2}-\d{2}-\d{4}\.csv$/
		);
		await expect( dialog ).toBeHidden();
		await expect( page.locator( '.bulkcat-export__button' ) ).toBeFocused();
		// Every product, as the grid counts them in its Products mode.
		const listed = await rest( page, 'products?per_page=1&fields[]=name' );
		expect( csvRows( file.text ).length - 1 ).toBe(
			listed.json.pagination.total
		);
	} );

	test( 'a download refused in the hidden frame says so in plain words, and Try again gives focus back to Export', async ( {
		page,
	} ) => {
		await openScreen( page );
		await clearSelection( page );
		// The server's answer to the form post, replaced by a refusal as the
		// plugin sends one (JSON, before any CSV byte); the summary is real.
		await page.route( EXPORT, ( route ) => {
			const request = route.request();
			const form = (
				request.headers()[ 'content-type' ] || ''
			).startsWith( 'application/x-www-form-urlencoded' );
			if ( request.method() !== 'POST' || ! form ) {
				return route.fallback();
			}
			return route.fulfill( {
				status: 409,
				contentType: 'application/json; charset=UTF-8',
				body: JSON.stringify( {
					code: 'bulkcat_selection_renew_required',
					message:
						'Your selection has been paused. Restore it, then try again.',
					data: { status: 409 },
				} ),
			} );
		} );
		const dialog = await openExport( page );
		const confirm = dialog.getByRole( 'checkbox', {
			name: 'Yes, export the whole catalogue',
		} );
		await confirm.check();
		await dialog.getByRole( 'button', { name: 'Download' } ).click();
		await expect( dialog ).toBeHidden();
		const notice = page.locator( '.bulkcat-export .bulkcat-error' );
		await expect( notice ).toContainText(
			'Your selection has been paused. Restore it, then try again.'
		);
		await expect( page.locator( '.bulkcat-export__button' ) ).toHaveText(
			'Export CSV'
		);
		// Try again by keyboard, then Escape: focus is back on Export.
		const again = notice.getByRole( 'button', { name: 'Try again' } );
		await again.focus();
		await page.keyboard.press( 'Enter' );
		await expect( dialog ).toBeVisible();
		await expect( dialog ).toContainText( /The file will have/ );
		await page.keyboard.press( 'Escape' );
		await expect( dialog ).toBeHidden();
		await expect( page.locator( '.bulkcat-export__button' ) ).toBeFocused();
		await page.unroute( EXPORT );
	} );

	for ( const [ label, size ] of [
		[ '1280 pixels wide', { width: 1280, height: 900 } ],
		[ '320 pixels wide', { width: 320, height: 720 } ],
	] ) {
		test( `the open dialog passes axe at ${ label }`, async ( {
			page,
		} ) => {
			await page.setViewportSize( size );
			await page.goto( SCREEN );
			await expect(
				page.locator( '#bulkcat-app .bulkcat-app' )
			).toBeVisible();
			await expect(
				page.getByRole( 'button', { name: 'Export CSV' } )
			).toBeVisible();
			await clearSelection( page );
			const dialog = await openExport( page );
			await expect( dialog ).toContainText( /The file will have/ );
			await expectAxeClean( page, `the export dialog at ${ label }`, [
				'.components-modal__screen-overlay',
			] );
			const sideways = await page.evaluate(
				() =>
					document.documentElement.scrollWidth >
					document.documentElement.clientWidth + 1
			);
			expect( sideways ).toBe( false );
			const box = await dialog.boundingBox();
			expect( box.x ).toBeGreaterThanOrEqual( 0 );
			expect( box.x + box.width ).toBeLessThanOrEqual( size.width + 1 );
			await page.keyboard.press( 'Escape' );
			await expect( dialog ).toBeHidden();
		} );
	}
} );
