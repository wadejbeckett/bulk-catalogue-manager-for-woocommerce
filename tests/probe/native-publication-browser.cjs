/** Native publication history redaction, reusing the exact run-owned session. */
const { chromium, expect } = require( '@playwright/test' );

let stage = 'input';
async function main() {
	let input = '';
	for await ( const chunk of process.stdin ) {
		input += chunk;
	}
	const { base, cookies, batches } = JSON.parse( input );
	process.stdout.write(
		JSON.stringify( {
			cookieDomains: cookies.map( ( c ) => c.domain ),
			target: new URL( base ).hostname,
		} ) + '\n'
	);
	stage = 'launch';
	const browser = await chromium.launch( { headless: true } );
	try {
		const context = await browser.newContext();
		// Python CookieJar uses the synthetic ".local" suffix for a single-label
		// host. Chromium needs the actual host, while values/paths stay exact.
		const host = new URL( base ).hostname;
		await context.addCookies(
			cookies.map( ( cookie ) => ( {
				...cookie,
				domain:
					cookie.domain === `${ host }.local` ? host : cookie.domain,
			} ) )
		);
		const page = await context.newPage();
		for ( const batch of batches ) {
			stage = 'navigate';
			await page.goto(
				`${ base }/wp-admin/edit.php?post_type=product&page=bulk-catalogue-manager-for-woocommerce&panel=history&batch=${ batch }`
			);
			const app = page.locator( '#bulkcat-app .bulkcat-app' );
			stage = 'app';
			await expect( app ).toBeVisible();
			const card = page.locator(
				`article[aria-labelledby="bulkcat-batch-${ batch }"]`
			);
			stage = 'card';
			await expect( card ).toBeVisible();
			stage = 'expand';
			await card
				.getByRole( 'button', { name: 'See every change' } )
				.click();
			stage = 'rows';
			await expect(
				card.locator( '.bulkcat-changes__table' )
			).toBeVisible();
			if ( batch === batches[ 0 ] ) {
				stage = 'protected-label';
				await expect( app ).toContainText( 'Password protected' );
			}
			stage = 'text';
			const text = await app.innerText();
			if ( text.includes( 'opaque' ) || text.includes( 'path' ) ) {
				throw new Error(
					'Protected publication value reached browser text'
				);
			}
			process.stdout.write( `PASS history batch ${ batch } redacted\n` );
		}
	} finally {
		await browser.close();
	}
}

main().catch( () => {
	// Do not serialize browser state or cookies when a check fails.
	process.stderr.write(
		`Native history rendering/redaction check failed at ${ stage }\n`
	);
	process.exitCode = 1;
} );
