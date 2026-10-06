/**
 * Category creation acceptance on an explicitly owned native fixture.
 * POST writes, stored terms and journals are real. Labelled simulations alter
 * category GETs or omit a returned POST receipt after the native write succeeds.
 * No lease probe or provider is needed.
 */
const { test, expect } = require( '@playwright/test' );
const { randomUUID } = require( 'crypto' );
const fs = require( 'fs' );
const path = require( 'path' );
const {
	openScreen,
	SEED,
	tabTo,
	tabToProduct,
	expectAxeClean,
	focused,
} = require( './helpers' );
const { fixtureCheck, php } = require( './lifecycle/support' );

function isCategories( url ) {
	const u = new URL( url );
	const route =
		u.searchParams.get( 'rest_route' ) ||
		u.pathname.replace( /^.*\/wp-json/, '' );
	return route.replace( /\/$/, '' ) === '/bulkcat/v1/categories';
}

function omit( nodes, id ) {
	return nodes
		.filter( ( n ) => n.id !== id )
		.map( ( n ) => ( { ...n, children: omit( n.children || [], id ) } ) );
}

function findNode( nodes, id ) {
	for ( const node of nodes ) {
		if ( node.id === id ) {
			return node;
		}
		const child = findNode( node.children || [], id );
		if ( child ) {
			return child;
		}
	}
	return null;
}

function creationResponse( page ) {
	return page
		.waitForResponse(
			( response ) =>
				response.request().method() === 'POST' &&
				isCategories( response.url() )
		)
		.then( async ( response ) => {
			expect( response.status() ).toBe( 201 );
			const body = await response.json();
			expect( Number.isInteger( body.item.id ) ).toBe( true );
			expect( body.item.id ).toBeGreaterThan( 0 );
			expect( Number( body.batch_id ) ).toBeGreaterThan( 0 );
			return body;
		} );
}

function categoryRead( page ) {
	return page.waitForResponse(
		( response ) =>
			response.request().method() === 'GET' &&
			isCategories( response.url() )
	);
}

function noticeFor( page, name ) {
	return page.getByRole( 'status' ).filter( { hasText: name } );
}

function openerFor( page ) {
	return page.getByRole( 'button', { name: '+ Add category', exact: true } );
}

async function openCategories( page, width ) {
	if ( width === 900 ) {
		const button = page.locator( 'button[aria-haspopup="dialog"]', {
			hasText: 'Categories',
		} );
		await tabTo( page, button );
		await page.keyboard.press( 'Enter' );
		await expect(
			page.getByRole( 'dialog', { name: 'Categories', exact: true } )
		).toBeVisible();
	}
}

async function evidence( page, info, label, data = {} ) {
	const filename = `${ info.title.replace( /[^a-zA-Z0-9-]+/g, '-' ) }-${ label }`;
	const output = process.env.BULKCAT_E2E_SHOTS || info.outputDir;
	fs.mkdirSync( output, { recursive: true } );
	const screenshot = path.join( output, `${ filename }.png` );
	const readback = path.join( output, `${ filename }.json` );
	await page.screenshot( { path: screenshot, fullPage: true } );
	data.focus = await focused( page );
	data.browser = await page.evaluate( () => ( {
		userAgent: window.navigator.userAgent,
		react: window.React?.version,
		reactDOM: window.ReactDOM?.version,
	} ) );
	fs.writeFileSync( readback, JSON.stringify( data, null, 2 ) + '\n' );
	await info.attach( label, {
		path: readback,
		contentType: 'application/json',
	} );
}

function nativeReadback( answer, name, parent = 0, description = '' ) {
	const result = php(
		`
		global $wpdb;
		$term = get_term($in['id'], 'product_cat');
		$terms = get_terms(['taxonomy'=>'product_cat','hide_empty'=>false,'name'=>$in['name']]);
		echo wp_json_encode([
			'term' => $term,
			'exact_ids' => array_values(array_map(fn($t) => $t->term_id, array_filter($terms, fn($t) => $t->name === $in['name']))),
			'batches' => $wpdb->get_results($wpdb->prepare("SELECT batch_id, source, status, total_items, done_items FROM {$wpdb->prefix}bulkcat_batches WHERE batch_id=%d AND source='tree'", $in['batch']), ARRAY_A),
			'changes' => $wpdb->get_results($wpdb->prepare("SELECT object_id, object_type, prop, old_value, new_value, result FROM {$wpdb->prefix}bulkcat_changes WHERE batch_id=%d", $in['batch']), ARRAY_A)
		]);
	`,
		{ id: answer.item.id, batch: answer.batch_id, name }
	);
	expect( result.exact_ids ).toEqual( [ answer.item.id ] );
	expect( result.term ).toMatchObject( {
		term_id: answer.item.id,
		name,
		parent,
		description,
	} );
	expect( result.batches ).toHaveLength( 1 );
	expect( result.batches[ 0 ] ).toMatchObject( {
		source: 'tree',
		status: 'complete',
		total_items: '1',
		done_items: '1',
	} );
	expect( result.changes.length ).toBeGreaterThan( 0 );
	const values = Object.fromEntries(
		result.changes.map( ( row ) => {
			expect( Number( row.object_id ) ).toBe( answer.item.id );
			expect( row.object_type ).toBe( 'term' );
			expect( row.result ).toBe( 'applied' );
			expect( JSON.parse( row.old_value ) ).toBeNull();
			return [ row.prop, JSON.parse( row.new_value ) ];
		} )
	);
	expect( values ).toMatchObject( {
		term_name: name,
		term_parent: parent,
		term_description: description,
	} );
	return result;
}

function cleanup( names ) {
	// Unique exact-name fallback also handles a lost POST answer. Child first.
	const remaining = php(
		`
		foreach ($in as $name) {
			$terms = get_terms(['taxonomy'=>'product_cat','hide_empty'=>false,'name'=>$name]);
			if (is_wp_error($terms)) throw new RuntimeException($terms->get_error_message());
			foreach ($terms as $term) if ($term->name === $name) wp_delete_term($term->term_id, 'product_cat');
		}
		$left = [];
		foreach ($in as $name) {
			$terms = get_terms(['taxonomy'=>'product_cat','hide_empty'=>false,'name'=>$name]);
			if (is_wp_error($terms)) throw new RuntimeException($terms->get_error_message());
			foreach ($terms as $term) if ($term->name === $name) $left[] = $term->term_id;
		}
		echo wp_json_encode($left);
	`,
		names
	);
	expect( remaining ).toEqual( [] );
}

test.beforeEach( () => {
	// Require explicit URL as well as the existing local, matching-WP_CMD guard.
	expect( process.env.BULKCAT_E2E_BASE_URL ).toBeTruthy();
	expect( fixtureCheck() ).toBe( '' );
} );

for ( const width of [ 1280, 900 ] ) {
	test( `category modal Cancel Close Escape preserve opener and single dialog at ${ width }px`, async ( {
		page,
	}, info ) => {
		let posts = 0;
		page.on( 'request', ( request ) => {
			if (
				request.method() === 'POST' &&
				isCategories( request.url() )
			) {
				posts++;
			}
		} );
		await page.setViewportSize( { width, height: 900 } );
		await openScreen( page );
		await openCategories( page, width );
		const opener = openerFor( page );
		for ( const close of [ 'Cancel', 'Close', 'Escape' ] ) {
			await tabTo( page, opener );
			await page.keyboard.press( 'Enter' );
			const modal = page.getByRole( 'dialog', {
				name: 'Add a category',
				exact: true,
			} );
			await expect( modal ).toHaveCount( 1 );
			await expect( page.locator( '.bulkcat-cat-sheet' ) ).toHaveCount(
				width === 900 ? 1 : 0
			);
			await evidence( page, info, `before-${ close }`, {
				posts,
				simulation: 'none',
				axe: await expectAxeClean(
					page,
					'native create dialog context'
				),
			} );
			if ( close === 'Escape' ) {
				await page.keyboard.press( 'Escape' );
			} else {
				await tabTo(
					page,
					modal.getByRole( 'button', { name: close, exact: true } )
				);
				await page.keyboard.press( 'Enter' );
			}
			await expect( modal ).toBeHidden();
			await expect( opener ).toBeFocused();
			await expect( page.locator( '.bulkcat-cat-sheet' ) ).toHaveCount(
				width === 900 ? 1 : 0
			);
			expect( posts ).toBe( 0 );
		}
	} );
}

for ( const submitFrom of [ 'button', 'Name input' ] ) {
	test( `pending native save preserves ${ submitFrom } focus and sends one POST`, async ( {
		page,
	}, info ) => {
		const name = `Pending ${ randomUUID() }`;
		let posts = 0;
		page.on( 'request', ( request ) => {
			if (
				request.method() === 'POST' &&
				isCategories( request.url() )
			) {
				posts++;
			}
		} );
		try {
			await page.setViewportSize( { width: 1280, height: 900 } );
			await openScreen( page );
			const opener = openerFor( page );
			await opener.press( 'Enter' );
			const modal = page.getByRole( 'dialog', {
				name: 'Add a category',
				exact: true,
			} );
			const input = modal.getByRole( 'textbox', {
				name: 'Name',
				exact: true,
			} );
			const button = modal.getByRole( 'button', {
				name: 'Add category',
				exact: true,
			} );
			await input.fill( name );
			await modal.evaluate( ( node ) => {
				window.bulkcatPendingFocus = [];
				const observer = new window.MutationObserver( () => {
					const submit = node.querySelector(
						'button[type="submit"]'
					);
					if ( submit?.classList.contains( 'is-busy' ) ) {
						const active = node.ownerDocument.activeElement;
						window.bulkcatPendingFocus.push( {
							disabled: submit.disabled,
							ariaDisabled:
								submit.getAttribute( 'aria-disabled' ),
							buttonFocused: active === submit,
							inputFocused:
								active === node.querySelector( 'input' ),
							modalContainsFocus: node.contains( active ),
							activeTag: active?.tagName,
						} );
						observer.disconnect();
					}
				} );
				observer.observe( node, {
					subtree: true,
					attributes: true,
					childList: true,
				} );
			} );
			const created = creationResponse( page );
			await ( submitFrom === 'button' ? button : input ).press( 'Enter' );
			const answer = await created;
			const notice = noticeFor( page, name );
			await expect( notice ).toContainText(
				`Category “${ name }” was created.`
			);
			await expect( notice ).not.toContainText(
				/Refreshing|missing|could not refresh/
			);
			const pending = await page.evaluate(
				() => window.bulkcatPendingFocus
			);
			await evidence( page, info, 'pending', {
				simulation:
					'none; MutationObserver records native busy transition',
				pending,
				posts,
				answer,
				native: nativeReadback( answer, name ),
			} );
			expect( posts ).toBe( 1 );
			expect( pending ).toHaveLength( 1 );
			expect.soft( pending[ 0 ].modalContainsFocus ).toBe( true );
			expect
				.soft(
					pending[ 0 ][
						submitFrom === 'button'
							? 'buttonFocused'
							: 'inputFocused'
					]
				)
				.toBe( true );
			await expect( opener ).toBeFocused();
		} finally {
			cleanup( [ name ] );
		}
	} );
}

for ( const width of [ 1280, 900 ] ) {
	test( `native root and child by keyboard preserve selection and search at ${ width }px`, async ( {
		page,
	}, info ) => {
		const names = [ `Root ${ randomUUID() }`, `Child ${ randomUUID() }` ];
		let posts = 0;
		page.on( 'request', ( request ) => {
			if (
				request.method() === 'POST' &&
				isCategories( request.url() )
			) {
				posts++;
			}
		} );
		try {
			await page.setViewportSize( { width, height: 900 } );
			await openScreen( page );
			const selected = page.getByRole( 'checkbox', {
				name: SEED.simple( 1 ),
				exact: true,
			} );
			await tabToProduct( page, SEED.simple( 1 ), selected );
			await page.keyboard.press( 'Space' );
			await expect(
				page.getByRole( 'region', { name: 'Selection' } )
			).toContainText( '1 product selected' );
			await openCategories( page, width );
			const opener = openerFor( page );
			const search = page.getByRole( 'searchbox', {
				name: 'Search categories',
				exact: true,
			} );
			const includeChildren = page.getByRole( 'checkbox', {
				name: 'Include sub-categories',
			} );
			const includeBefore = await includeChildren.isChecked();
			let parent = 0;
			for ( const name of names ) {
				await tabTo( page, opener );
				await page.keyboard.press( 'Enter' );
				const modal = page.getByRole( 'dialog', {
					name: 'Add a category',
					exact: true,
				} );
				const nameInput = modal.getByRole( 'textbox', {
					name: 'Name',
					exact: true,
				} );
				await tabTo( page, nameInput );
				await page.keyboard.type( name );
				const parentInput = modal.getByRole( 'combobox', {
					name: 'Parent category',
				} );
				await tabTo( page, parentInput );
				const options = await parentInput
					.locator( 'option' )
					.evaluateAll( ( nodes ) =>
						nodes.map( ( node ) => node.value )
					);
				const index = options.indexOf( String( parent ) );
				expect( index ).toBeGreaterThanOrEqual( 0 );
				await page.keyboard.press( 'Home' );
				for ( let i = 0; i < index; i++ ) {
					await page.keyboard.press( 'ArrowDown' );
				}
				await expect( parentInput ).toHaveValue( String( parent ) );
				const description = `Native description for ${ name }`;
				await tabTo(
					page,
					modal.getByRole( 'textbox', { name: 'Description' } )
				);
				await page.keyboard.type( description );
				const modalAxe = await expectAxeClean(
					page,
					'category create modal'
				);
				await tabTo(
					page,
					modal.getByRole( 'button', {
						name: 'Add category',
						exact: true,
					} )
				);
				const answerPending = creationResponse( page );
				const readPending = categoryRead( page );
				await page.keyboard.press( 'Enter' );
				const answer = await answerPending;
				const read = await readPending;
				expect( read.status() ).toBe( 200 );
				const tree = await read.json();
				expect( findNode( tree, answer.item.id ) ).toMatchObject( {
					name,
					parent,
				} );
				await evidence(
					page,
					info,
					parent ? 'child-focus' : 'root-focus',
					{ answer, tree }
				);
				await expect( opener ).toBeFocused();
				const notice = noticeFor( page, name );
				await expect( notice ).toContainText(
					`Category “${ name }” was created.`
				);
				await expect( notice ).not.toContainText(
					/missing|could not refresh|Refreshing/
				);
				await expect( search ).toHaveValue( parent ? 'Probe' : '' );
				await expect( includeChildren ).toBeChecked( {
					checked: includeBefore,
				} );
				const native = nativeReadback(
					answer,
					name,
					parent,
					description
				);
				const noticeAxe = await expectAxeClean(
					page,
					'category created notice'
				);
				await evidence( page, info, parent ? 'child' : 'root', {
					simulation: 'none',
					posts,
					answer,
					tree,
					native,
					modalAxe,
					noticeAxe,
				} );
				parent = answer.item.id;
				await tabTo( page, search, { back: true } );
				await page.keyboard.press( 'ControlOrMeta+A' );
				await page.keyboard.type( 'Probe' );
			}
			expect( posts ).toBe( 2 );
			await expect(
				page.getByRole( 'treeitem', {
					name: new RegExp( `^${ names[ 1 ] },` ),
				} )
			).toBeHidden();
			if ( width === 900 ) {
				await page.keyboard.press( 'Escape' );
			}
			await expect( selected ).toBeChecked();
			await expect(
				page
					.locator( '.dataviews-view-table tbody' )
					.getByRole( 'checkbox', { checked: true } )
			).toHaveCount( 1 );
			await expect(
				page.getByRole( 'region', { name: 'Selection' } )
			).toContainText( '1 product selected' );
		} finally {
			cleanup( [ ...names ].reverse() );
		}
	} );
}

for ( const width of [ 1440, 1280, 900 ] ) {
	for ( const fault of [ 'omission', 'failure' ] ) {
		test( `confirmed create survives list ${ fault } at ${ width }px`, async ( {
			page,
		}, info ) => {
			const name = `Readback ${ randomUUID() }`;
			let posts = 0;
			let inject = true;
			let createdBody;
			let injectedReads = 0;
			const countPost = ( request ) => {
				if (
					request.method() === 'POST' &&
					isCategories( request.url() )
				) {
					posts++;
				}
			};
			const getHandler = async ( route ) => {
				const request = route.request();
				if (
					! isCategories( request.url() ) ||
					request.method() !== 'GET' ||
					posts === 0 ||
					! inject
				) {
					return route.continue();
				}
				const body = await createdBody;
				injectedReads++;
				if ( fault === 'failure' ) {
					return route.fulfill( {
						status: 503,
						contentType: 'application/json',
						body: JSON.stringify( {
							code: 'read_unavailable',
							message: 'Category list unavailable.',
						} ),
					} );
				}
				const response = await route.fetch();
				return route.fulfill( {
					response,
					json: omit( await response.json(), body.item.id ),
				} );
			};
			try {
				await page.setViewportSize( { width, height: 900 } );
				await openScreen( page );
				await openCategories( page, width );
				page.on( 'request', countPost );
				await page.route( '**/*', getHandler );
				const opener = openerFor( page );
				await opener.press( 'Enter' );
				await page
					.getByRole( 'textbox', { name: 'Name', exact: true } )
					.fill( name );
				createdBody = creationResponse( page );
				await page
					.getByRole( 'button', {
						name: 'Add category',
						exact: true,
					} )
					.click();
				const answer = await createdBody;
				await expect( opener ).toBeFocused();
				const notice = noticeFor( page, name );
				const failureText =
					fault === 'omission' ? 'missing' : 'could not refresh';
				await expect( notice ).toContainText( 'was created' );
				await expect( notice ).toContainText( failureText );
				await expect(
					page.getByRole( 'treeitem', {
						name: new RegExp( `^${ name },` ),
					} )
				).toBeHidden();
				await expect(
					page.getByRole( 'treeitem', {
						name: new RegExp( `^${ SEED.root },` ),
					} )
				).toBeVisible();
				const retry = page.getByRole( 'button', {
					name: 'Refresh category list',
					exact: true,
				} );
				const axe = await expectAxeClean(
					page,
					`category ${ fault } and retry`
				);
				await evidence( page, info, 'warning', {
					simulation: `GET-only ${ fault }`,
					posts,
					answer,
					native: nativeReadback( answer, name ),
					axe,
				} );
				if ( fault === 'failure' ) {
					const details = page.getByRole( 'button', {
						name: 'Show details',
						exact: true,
					} );
					for ( const state of [ 'normal', 'hover', 'focused' ] ) {
						if ( state === 'hover' ) {
							await details.hover();
						} else if ( state === 'focused' ) {
							await page.mouse.move( 0, 0 );
							await tabTo( page, details, { back: true } );
							await expect( details ).toBeFocused();
						}
						const disclosureAxe =
							state === 'normal'
								? axe
								: await expectAxeClean(
										page,
										`category error disclosure ${ state }`
									);
						const appearance = await details.evaluate( ( node ) => {
							const view = node.ownerDocument.defaultView;
							const button = view.getComputedStyle( node );
							const error = view.getComputedStyle(
								node.closest( '.bulkcat-cat-error' )
							);
							return {
								color: button.color,
								background: error.backgroundColor,
								underline: button.textDecorationLine,
								focusRing: button.boxShadow,
								border: error.borderLeft,
							};
						} );
						// WordPress 7.0 keeps the focused underline; 7.1 removes it.
						// Both native variants must retain the visible focus ring below.
						if ( state === 'focused' ) {
							expect( [ 'none', 'underline' ] ).toContain(
								appearance.underline
							);
						} else {
							expect( appearance.underline ).toBe( 'underline' );
						}
						expect( appearance.border ).toMatch( /^4px solid / );
						if ( state === 'focused' ) {
							expect( appearance.focusRing ).not.toBe( 'none' );
						}
						await evidence( page, info, `disclosure-${ state }`, {
							appearance,
							axe: disclosureAxe,
						} );
					}
					await expect( details ).toHaveAttribute(
						'aria-expanded',
						'false'
					);
					await page.keyboard.press( 'Enter' );
					const hide = page.getByRole( 'button', {
						name: 'Hide details',
						exact: true,
					} );
					await expect( hide ).toBeFocused();
					await expect( hide ).toHaveAttribute(
						'aria-expanded',
						'true'
					);
					await expect(
						page.locator( '.bulkcat-cat-error__details' )
					).toContainText( 'read_unavailable' );
					await evidence( page, info, 'disclosure-expanded', {
						axe: await expectAxeClean(
							page,
							'expanded category error'
						),
					} );
					await page.keyboard.press( 'Enter' );
					await expect( details ).toHaveAttribute(
						'aria-expanded',
						'false'
					);
					await expect(
						page.locator( '.bulkcat-cat-error__details' )
					).toBeHidden();
				}
				const failedRetry = categoryRead( page );
				await tabTo( page, retry, { back: true } );
				await page.keyboard.press( 'Enter' );
				await failedRetry;
				await expect( notice ).toContainText( failureText );
				await expect( retry ).toBeEnabled();
				await expect( retry ).toBeFocused();
				expect( posts ).toBe( 1 );
				expect( injectedReads ).toBe( 2 );
				inject = false;
				const recovered = categoryRead( page );
				await page.keyboard.press( 'Enter' );
				const response = await recovered;
				expect( response.status() ).toBe( 200 );
				const tree = await response.json();
				expect( findNode( tree, answer.item.id ) ).toMatchObject( {
					name,
				} );
				await expect(
					page.getByRole( 'treeitem', {
						name: new RegExp( `^${ name },` ),
					} )
				).toBeVisible();
				await expect( notice ).toContainText(
					`Category “${ name }” was created.`
				);
				await expect( notice ).not.toContainText(
					/missing|could not refresh|Refreshing/
				);
				await expect( notice ).toBeFocused();
				expect( posts ).toBe( 1 );
				await evidence( page, info, 'recovered', {
					simulation: `GET-only ${ fault }; native recovery`,
					posts,
					injectedReads,
					tree,
					native: nativeReadback( answer, name ),
					axe: await expectAxeClean( page, 'recovered notice' ),
				} );
			} finally {
				await page.unroute( '**/*', getHandler );
				page.off( 'request', countPost );
				cleanup( [ name ] );
			}
		} );
	}
}

for ( const width of [ 1280, 900 ] ) {
	for ( const timing of [ 'immediate', 'deferred', 'search' ] ) {
		test( `first-category empty opener ${ timing } readback focus at ${ width }px`, async ( {
			page,
		}, info ) => {
			const name = `First ${ randomUUID() }`;
			let posts = 0;
			let release;
			const gate = new Promise( ( resolve ) => {
				release = resolve;
			} );
			const countPost = ( request ) => {
				if (
					request.method() === 'POST' &&
					isCategories( request.url() )
				) {
					posts++;
				}
			};
			const getHandler = async ( route ) => {
				if (
					route.request().method() !== 'GET' ||
					! isCategories( route.request().url() )
				) {
					return route.continue();
				}
				if ( ! posts ) {
					return route.fulfill( { status: 200, json: [] } );
				}
				if ( timing !== 'immediate' ) {
					await gate;
				}
				return route.continue();
			};
			try {
				page.on( 'request', countPost );
				await page.route( '**/*', getHandler );
				await page.setViewportSize( { width, height: 900 } );
				await openScreen( page );
				await openCategories( page, width );
				const opener = page.getByRole( 'button', {
					name: 'Create your first category',
					exact: true,
				} );
				await opener.press( 'Enter' );
				await page
					.getByRole( 'textbox', { name: 'Name', exact: true } )
					.fill( name );
				const created = creationResponse( page );
				const refreshed = categoryRead( page );
				await page
					.getByRole( 'button', {
						name: 'Add category',
						exact: true,
					} )
					.press( 'Enter' );
				const answer = await created;
				const notice = noticeFor( page, name );
				if ( timing !== 'immediate' ) {
					await expect( opener ).toBeFocused();
					await expect( notice ).toContainText( 'Refreshing' );
				}
				const search = page.getByRole( 'searchbox', {
					name: 'Search categories',
					exact: true,
				} );
				if ( timing === 'search' ) {
					await tabTo( page, search, { back: true } );
					await page.keyboard.type( 'does-not-match' );
				}
				release();
				const response = await refreshed;
				expect( response.status() ).toBe( 200 );
				const tree = await response.json();
				expect( findNode( tree, answer.item.id ) ).toMatchObject( {
					name,
				} );
				await expect( opener ).toHaveCount( 0 );
				await expect( notice ).toContainText(
					`Category “${ name }” was created.`
				);
				await expect( notice ).not.toContainText(
					/missing|could not refresh|Refreshing/
				);
				await expect(
					timing === 'search' ? search : notice
				).toBeFocused();
				await expect( search ).toHaveValue(
					timing === 'search' ? 'does-not-match' : ''
				);
				expect( posts ).toBe( 1 );
				await evidence( page, info, 'complete', {
					simulation: `empty pre-create GET only; ${ timing } native readback`,
					posts,
					answer,
					tree,
					native: nativeReadback( answer, name ),
					axe: await expectAxeClean( page, 'first category notice' ),
				} );
			} finally {
				release();
				await page.unroute( '**/*', getHandler );
				page.off( 'request', countPost );
				cleanup( [ name ] );
			}
		} );
	}
}

for ( const width of [ 1280, 900 ] ) {
	for ( const firstOutcome of [ 'confirmed', 'incomplete' ] ) {
		test( `established creation region: first ${ firstOutcome }, later read and new creation at ${ width }px`, async ( {
			page,
		}, info ) => {
			const names = [
				`First notice ${ randomUUID() }`,
				`Next notice ${ randomUUID() }`,
			];
			const incomplete =
				'The server returned an incomplete save response. Check the category list before creating it again.';
			const answers = [];
			const native = [];
			const expectedMessages = [];
			let posts = 0;
			let gate;
			let release;
			const handler = async ( route ) => {
				if ( ! isCategories( route.request().url() ) ) {
					return route.continue();
				}
				if ( route.request().method() === 'POST' ) {
					posts++;
					// Execute the actual native write; only the first incomplete
					// case changes its returned receipt, never the stored term.
					const response = await route.fetch();
					expect( response.status() ).toBe( 201 );
					const answer = await response.json();
					answers.push( answer );
					return route.fulfill( {
						response,
						json:
							firstOutcome === 'incomplete' && posts === 1
								? { ...answer, item: {} }
								: answer,
					} );
				}
				if ( route.request().method() === 'GET' && posts ) {
					await gate;
				}
				return route.continue();
			};
			try {
				await page.setViewportSize( { width, height: 900 } );
				await openScreen( page );
				await openCategories( page, width );
				const region = page.locator(
					'.bulkcat-cat-panel__body [role="status"]'
				);
				await expect( region ).toHaveCount( 1 );
				await expect( region ).toHaveText( '' );
				await expect( region ).toHaveAttribute( 'aria-live', 'polite' );
				await expect( region ).toHaveAttribute( 'aria-atomic', 'true' );
				await region.evaluate( ( node ) => {
					window.bulkcatCreationRegion = node;
					window.bulkcatCreationMessages = [];
					window.bulkcatCreationObserver =
						new window.MutationObserver( () => {
							window.bulkcatCreationMessages.push(
								node.querySelector( 'p' )?.textContent || ''
							);
						} );
					window.bulkcatCreationObserver.observe( node, {
						childList: true,
						characterData: true,
						subtree: true,
					} );
				} );
				await page.route( '**/*', handler );
				for ( const [ index, name ] of names.entries() ) {
					const unconfirmed =
						firstOutcome === 'incomplete' && index === 0;
					gate = new Promise( ( resolve ) => {
						release = resolve;
					} );
					const opener = openerFor( page );
					await opener.press( 'Enter' );
					await page
						.getByRole( 'textbox', { name: 'Name', exact: true } )
						.fill( name );
					await page
						.getByRole( 'button', {
							name: 'Add category',
							exact: true,
						} )
						.press( 'Enter' );
					const firstMessage = unconfirmed
						? incomplete
						: `Category “${ name }” was created. Refreshing the category list…`;
					expectedMessages.push( firstMessage );
					await expect( region.locator( 'p' ) ).toHaveText(
						firstMessage
					);
					await expect( opener ).toBeFocused();
					expect(
						await region.evaluate(
							( node ) => node === window.bulkcatCreationRegion
						)
					).toBe( true );
					if ( unconfirmed ) {
						const retry = region.getByRole( 'button', {
							name: 'Refresh category list',
						} );
						await retry.press( 'Enter' );
					}
					// GET-only delay makes the first outcome independently observable.
					// The eventual GET payload is unmodified native readback.
					release();
					await expect(
						page.getByRole( 'treeitem', {
							name: new RegExp( `^${ name },` ),
						} )
					).toBeVisible();
					const finalMessage = unconfirmed
						? incomplete
						: `Category “${ name }” was created.`;
					await expect( region.locator( 'p' ) ).toHaveText(
						finalMessage
					);
					if ( ! unconfirmed ) {
						expectedMessages.push( finalMessage );
					}
					expect( posts ).toBe( index + 1 );
					native.push( nativeReadback( answers[ index ], name ) );
					// An ordinary search rerender must not remount/reannounce the notice.
					const search = page.getByRole( 'searchbox', {
						name: 'Search categories',
						exact: true,
					} );
					await search.fill( 'Probe' );
					await expect( search ).toBeFocused();
					const lifecycle = await region.evaluate(
						( node, message ) => ( {
							sameRegion: node === window.bulkcatCreationRegion,
							messages: window.bulkcatCreationMessages,
							announcementSources: [
								...document.querySelectorAll( '[aria-live]' ),
							].filter( ( live ) =>
								live.textContent.includes( message )
							).length,
						} ),
						finalMessage
					);
					expect( lifecycle ).toEqual( {
						sameRegion: true,
						messages: expectedMessages,
						announcementSources: 1,
					} );
					await evidence( page, info, `outcome-${ index }`, {
						simulation:
							firstOutcome === 'incomplete'
								? 'First native POST response item replaced with {}; writes/terms/journals real. GET-only delay, payload unmodified.'
								: 'GET-only delay; native POST, GET payloads, terms and journals unmodified.',
						posts,
						answers,
						native,
						lifecycle,
						axe: await expectAxeClean(
							page,
							'established creation region'
						),
					} );
					await search.fill( '' );
					if ( ! unconfirmed && index === 0 ) {
						await region
							.getByRole( 'button', {
								name: 'Close',
								exact: true,
							} )
							.press( 'Enter' );
						await expect( region ).toHaveText( '' );
						expect(
							await region.evaluate(
								( node ) =>
									node === window.bulkcatCreationRegion
							)
						).toBe( true );
						expectedMessages.push( '' );
					}
				}
			} finally {
				release?.();
				await page.unrouteAll( { behavior: 'wait' } );
				await page.evaluate( () =>
					window.bulkcatCreationObserver?.disconnect()
				);
				cleanup( [ ...names ].reverse() );
			}
		} );
	}
}
