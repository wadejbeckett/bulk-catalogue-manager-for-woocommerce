/**
 * What a buyer sees when a product in their cart is switched Off (SPEC §5.5;
 * T94, proof test B6): in a fresh browser context with no admin cookies, a
 * simple product and a variation are added to the cart and the checkout
 * shows them with its Place order button while On; the manager switches
 * both Off through its own REST route; after a reload the checkout names
 * each removed item and offers no Place order button, and the product page
 * is 404. A logged-in customer repeats it. Both products are switched back
 * On afterwards.
 *
 * Needs WP_CMD (tests/probe/README.md): the spec enables Check payments and
 * turns shipping off for a billing-only checkout, creates its customer with
 * the probe seed's password, and puts every option back at the end.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { test, expect } = require( '@playwright/test' );
const {
	SEED,
	openScreen,
	patchProduct,
	productByName,
} = require( './helpers' );

const CUSTOMER = 'bulkcat_e2e_customer';
const OPTIONS = {
	woocommerce_ship_to_countries: 'disabled',
	woocommerce_cheque_settings: { enabled: 'yes', title: 'Cheque' },
};

/**
 * One WP-CLI command through WP_CMD, its arguments passed as a list (no
 * shell): WP_CMD itself is split on spaces, as seed.sh splits it.
 *
 * @param {...string} args Arguments.
 * @return {string} Its output.
 */
function wp( ...args ) {
	const [ command, ...prefix ] = process.env.WP_CMD.trim().split( /\s+/ );
	return execFileSync( command, [ ...prefix, ...args ], {
		encoding: 'utf8',
		timeout: 120_000,
		stdio: [ 'ignore', 'pipe', 'pipe' ],
	} ).trim();
}

/**
 * An option's value as JSON, or null when it does not exist.
 *
 * @param {string} name Option name.
 * @return {string|null} JSON text.
 */
function optionJson( name ) {
	try {
		return wp( 'option', 'get', name, '--format=json' );
	} catch {
		return null;
	}
}

/**
 * The probe seed's throwaway password (tests/probe/seed.sh).
 *
 * @return {string} Password.
 */
function seedPassword() {
	const file =
		process.env.SEED_JSON ||
		path.join( __dirname, '..', 'probe', 'seed.json' );
	return JSON.parse( fs.readFileSync( file, 'utf8' ) ).password;
}

/**
 * Switch products Off (draft) or On (publish) through the manager's route.
 *
 * @param {import('@playwright/test').Page} page  An admin page on the screen.
 * @param {Array<number>}                   ids   Product ids.
 * @param {string}                          value draft or publish.
 */
async function setStatus( page, ids, value ) {
	for ( const id of ids ) {
		const { status, json } = await patchProduct( page, id, {
			status: value,
		} );
		expect( status, JSON.stringify( json ) ).toBe( 200 );
		expect( json.status ).toBe( 'complete' );
	}
}

test.describe( 'a buyer after Off (B6)', () => {
	test.skip(
		! process.env.WP_CMD,
		'Needs WP_CMD to set up a checkout and a customer (tests/probe/README.md).'
	);

	const was = {};
	let password = '';

	test.beforeAll( () => {
		for ( const [ name, value ] of Object.entries( OPTIONS ) ) {
			was[ name ] = optionJson( name );
			wp(
				'option',
				'update',
				name,
				JSON.stringify( value ),
				'--format=json'
			);
		}
		password = seedPassword();
		try {
			wp( 'user', 'get', CUSTOMER, '--field=ID' );
		} catch {
			wp(
				'user',
				'create',
				CUSTOMER,
				`${ CUSTOMER }@example.test`,
				'--role=customer',
				`--user_pass=${ password }`
			);
		}
	} );

	test.afterAll( () => {
		for ( const [ name, json ] of Object.entries( was ) ) {
			if ( json === null ) {
				wp( 'option', 'delete', name );
			} else {
				wp( 'option', 'update', name, json, '--format=json' );
			}
		}
	} );

	for ( const who of [ 'anonymous', 'customer' ] ) {
		test( `${ who }: the checkout drops Off items and offers no Place order`, async ( {
			page,
			browser,
		}, testInfo ) => {
			await openScreen( page );
			const simple = await productByName( page, SEED.simple( 3 ), [
				'status',
			] );
			const variable = await productByName( page, SEED.variable, [
				'status',
			] );
			const ids = [ simple.id, variable.id ];
			await setStatus( page, ids, 'publish' );

			const baseURL = testInfo.project.use.baseURL;
			const buyer = await browser.newContext( {
				baseURL,
				storageState: { cookies: [], origins: [] },
			} );
			const shop = await buyer.newPage();
			try {
				if ( who === 'customer' ) {
					await shop.goto( '/wp-login.php' );
					await shop.fill( '#user_login', CUSTOMER );
					await shop.fill( '#user_pass', password );
					await Promise.all( [
						shop.waitForURL(
							( url ) =>
								! url.toString().includes( 'wp-login.php' )
						),
						shop.click( '#wp-submit' ),
					] );
				}

				// While On: both in the cart, and the checkout offers Place order.
				await shop.goto( `/?post_type=product&p=${ simple.id }` );
				const permalink = shop.url();
				await shop.click( 'button.single_add_to_cart_button' );
				await expect(
					shop.getByText( /has been added to your cart/ )
				).toBeVisible();
				await shop.goto( `/?post_type=product&p=${ variable.id }` );
				await shop.selectOption(
					'select[name="attribute_size"]',
					'Small'
				);
				await shop.click(
					'button.single_add_to_cart_button:not(.disabled)'
				);
				await expect(
					shop.getByText( /has been added to your cart/ )
				).toBeVisible();
				await shop.goto( '/checkout/' );
				const items = shop.locator(
					'.wc-block-components-order-summary-item .wc-block-components-product-name'
				);
				await expect( items ).toHaveCount( 2, { timeout: 30_000 } );
				const place = shop.locator(
					'button.wc-block-components-checkout-place-order-button'
				);
				await expect( place ).toHaveCount( 1 );

				// Off, through the manager.
				await setStatus( page, ids, 'draft' );
				await shop.reload();
				await shop.waitForLoadState( 'networkidle' );
				await expect( shop.locator( 'body' ) ).toContainText(
					`${ SEED.simple( 3 ) } has been removed from your cart`,
					{ timeout: 30_000 }
				);
				await expect( shop.locator( 'body' ) ).toContainText(
					`${ SEED.variable } – Small has been removed from your cart`
				);
				await expect( place ).toHaveCount( 0 );
				const product = await shop.goto( permalink );
				expect( product.status() ).toBe( 404 );
			} finally {
				await buyer.close();
				await setStatus( page, ids, 'publish' );
			}
			for ( const id of ids ) {
				const row = await productByName(
					page,
					id === simple.id ? SEED.simple( 3 ) : SEED.variable,
					[ 'status' ]
				);
				expect( [ row.status, row.off_state ] ).toEqual( [
					'publish',
					null,
				] );
			}
		} );
	}
} );
