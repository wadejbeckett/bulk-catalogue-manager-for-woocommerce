/**
 * The confirmation policy (T48, SPEC §4.5, §11): one click below the
 * threshold, a restated count and a second click at or above it, always a
 * confirmation naming what a draft, hide or clear will do, never a typed
 * word, dismissible by keyboard with focus returned, and never for preview.
 */
import {
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';

import apiFetch from '@wordpress/api-fetch';
import BulkChangePanel, {
	ConfirmApply,
	alwaysConfirmReasons,
	confirmPolicy,
} from '../../assets/src/panels/bulk';

const {
	REGISTRY,
	SETTINGS,
	callsTo,
	previewResponse,
	routeApi,
} = require( './bulk-fixtures' );

jest.mock( '@wordpress/api-fetch', () => {
	const mock = jest.fn( () => new Promise( () => {} ) );
	mock.use = jest.fn();
	mock.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: mock };
} );

/**
 * Render the Apply control.
 *
 * @param {Object} props Props.
 * @return {Function} The onApply mock.
 */
function renderApply( props ) {
	const onApply = jest.fn();
	render( <ConfirmApply onApply={ onApply } { ...props } /> );
	return onApply;
}

const applyButton = ( count ) =>
	screen.getByRole( 'button', {
		name:
			count === 1
				? 'Apply to 1 product'
				: `Apply to ${ count.toLocaleString( 'en-US' ) } products`,
	} );

describe( 'confirmation policy', () => {
	it.each( [
		[ 199, 200 ],
		[ 1, 200 ],
		[ 49, 50 ],
	] )(
		'applies %i rows in one click with the threshold at %i',
		( count, threshold ) => {
			expect( confirmPolicy( { count, threshold } ).required ).toBe(
				false
			);
			const onApply = renderApply( { count, threshold } );
			fireEvent.click( applyButton( count ) );
			expect( onApply ).toHaveBeenCalledTimes( 1 );
			expect( screen.queryByRole( 'group' ) ).toBeNull();
		}
	);

	it.each( [
		[ 200, 200, 'This will change 200 products. Apply it?' ],
		[ 201, 200, 'This will change 201 products. Apply it?' ],
		[ 1284, 200, 'This will change 1,284 products. Apply it?' ],
		[ 50, 50, 'This will change 50 products. Apply it?' ],
		[ 51, 50, 'This will change 51 products. Apply it?' ],
	] )(
		'restates %i rows and needs a second click with the threshold at %i',
		( count, threshold, sentence ) => {
			expect( confirmPolicy( { count, threshold } ).required ).toBe(
				true
			);
			const onApply = renderApply( { count, threshold } );
			fireEvent.click( applyButton( count ) );
			expect( onApply ).not.toHaveBeenCalled();
			const ask = screen.getByRole( 'group', { name: sentence } );
			fireEvent.click(
				within( ask ).getByRole( 'button', { name: /^Yes, change/ } )
			);
			expect( onApply ).toHaveBeenCalledTimes( 1 );
		}
	);

	it( 'uses the default threshold of 200 when none is set', () => {
		expect( confirmPolicy( { count: 199 } ).required ).toBe( false );
		expect( confirmPolicy( { count: 200 } ).required ).toBe( true );
	} );

	const op = ( action, args ) => ( {
		action,
		args: { skip_already: true, ...args },
	} );
	it.each( [
		[
			'status to draft',
			op( 'set_value', { field: 'status', value: 'draft' } ),
			"This switches 1 product Off: they leave the site for every buyer, and each one's status is saved for switching On.",
		],
		[
			'status to pending',
			op( 'set_value', { field: 'status', value: 'pending' } ),
			'This sets Status to Pending review on 1 product, which takes them off the site.',
		],
		// T93: a listing control hides a product from the shop and search; it stays on sale.
		[
			'Shown in shop and search off',
			op( 'set_value', { field: 'visible_on_site', value: 'off' } ),
			'This sets Shown in shop and search to Hidden on 1 product: they leave the shop and search results, but can still be opened by link and bought. To take them off sale, set Status to Draft.',
		],
		[
			'catalogue visibility hidden',
			op( 'set_value', { field: 'catalog_visibility', value: 'hidden' } ),
			'This sets Catalogue visibility to Hidden on 1 product: they leave the shop and search results, but can still be opened by link and bought. To take them off sale, set Status to Draft.',
		],
		[
			'clearing a field',
			op( 'clear', { field: 'sale_price' } ),
			'This empties Sale price on 1 product.',
		],
		[
			'setting a field to empty',
			op( 'set_value', { field: 'weight', value: '' } ),
			'This empties Weight on 1 product.',
		],
	] )(
		'always confirms %s, naming it, even for one row',
		( name, operation, sentence ) => {
			const reasons = alwaysConfirmReasons( REGISTRY, [ operation ], 1 );
			expect( reasons ).toEqual( [ sentence ] );
			const onApply = renderApply( {
				count: 1,
				threshold: 200,
				reasons,
			} );
			fireEvent.click( applyButton( 1 ) );
			expect( onApply ).not.toHaveBeenCalled();
			expect( screen.getByText( reasons[ 0 ] ) ).toBeVisible();
		}
	);

	it( 'does not confirm a publish, a visible switch or a value for one row', () => {
		expect(
			alwaysConfirmReasons(
				REGISTRY,
				[
					op( 'set_value', { field: 'status', value: 'publish' } ),
					op( 'set_value', {
						field: 'visible_on_site',
						value: 'on',
					} ),
					op( 'set_value', { field: 'weight', value: '2' } ),
					op( 'adjust', {
						field: 'regular_price',
						direction: 'increase',
						by: 'percent',
						amount: '5',
					} ),
				],
				1
			)
		).toEqual( [] );
	} );

	it( 'never asks the owner to type anything', () => {
		const reasons = alwaysConfirmReasons(
			REGISTRY,
			[ op( 'clear', { field: 'sale_price' } ) ],
			5000
		);
		const { container } = render(
			<ConfirmApply
				count={ 5000 }
				threshold={ 200 }
				reasons={ reasons }
				onApply={ jest.fn() }
			/>
		);
		fireEvent.click( applyButton( 5000 ) );
		expect( screen.getByRole( 'group' ) ).toBeVisible();
		expect(
			container.querySelectorAll( 'input, textarea, [contenteditable]' )
		).toHaveLength( 0 );
	} );

	it( 'moves focus to the second click, and Escape dismisses it with focus returned to Apply', () => {
		const onApply = renderApply( { count: 500, threshold: 200 } );
		const apply = applyButton( 500 );
		apply.focus();
		fireEvent.click( apply );
		const yes = screen.getByRole( 'button', {
			name: 'Yes, change 500 products',
		} );
		expect( document.activeElement ).toBe( yes );
		fireEvent.keyDown( yes, { key: 'Escape' } );
		expect( screen.queryByRole( 'group' ) ).toBeNull();
		expect( document.activeElement ).toBe( apply );
		expect( onApply ).not.toHaveBeenCalled();

		fireEvent.click( apply );
		fireEvent.click( screen.getByRole( 'button', { name: 'Not yet' } ) );
		expect( document.activeElement ).toBe( apply );
		fireEvent.click( apply );
		fireEvent.keyDown(
			screen.getByRole( 'button', { name: 'Yes, change 500 products' } ),
			{ key: 'Enter' }
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Yes, change 500 products' } )
		);
		expect( onApply ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'never confirms a preview, even for a large always-confirm change', async () => {
		apiFetch.mockReset();
		routeApi( apiFetch, {
			'POST preview': previewResponse( {
				totals: { ...previewResponse().totals, would_change: 5000 },
			} ),
		} );
		render(
			<BulkChangePanel
				selection={ { kind: 'filter', token: 'tok', count: 5000 } }
				registry={ REGISTRY }
				settings={ SETTINGS }
			/>
		);
		const heading = screen.getByRole( 'heading', { name: 'Publishing' } );
		fireEvent.click(
			within( heading.parentElement ).getByRole( 'radio', {
				name: /^Set a value/,
			} )
		);
		fireEvent.change( screen.getByLabelText( 'Field' ), {
			target: { value: 'status' },
		} );
		fireEvent.change( screen.getByLabelText( 'New value' ), {
			target: { value: 'draft' },
		} );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Check the change' } )
		);
		expect( callsTo( apiFetch, 'POST preview' ) ).toHaveLength( 1 );
		expect(
			screen.queryByRole( 'group', { name: /Apply it\?/ } )
		).toBeNull();

		// Apply, on the other hand, asks, naming the draft: Status is the Off/On switch (T91).
		fireEvent.click(
			await screen.findByRole( 'button', {
				name: 'Apply to 5,000 products',
			} )
		);
		expect(
			screen.getByText(
				"This switches 5,000 products Off: they leave the site for every buyer, and each one's status is saved for switching On."
			)
		).toBeVisible();
		expect( callsTo( apiFetch, 'POST batches' ) ).toHaveLength( 0 );
		await waitFor( () =>
			expect(
				screen.getByRole( 'group', { name: /Apply it\?/ } )
			).toBeVisible()
		);
	} );
} );

describe( 'Off/On confirmation (T91, SPEC §4.5)', () => {
	const FIXTURE = require( '../fixtures/status-switch.json' );
	const {
		CONFIRMS,
		availabilityReasons,
	} = require( '../../assets/src/panels/bulk/availability' );
	const step = ( value ) => ( {
		action: 'set_value',
		args: { field: 'status', value, skip_already: true },
	} );
	const block = ( counts, extra = {} ) => ( {
		counts: Object.fromEntries(
			FIXTURE.codes.map( ( code ) => [ code, counts[ code ] || 0 ] )
		),
		restores: { private: 0, pending: 0 },
		variations_kept: 0,
		conflicts: [],
		...extra,
	} );

	it( 'confirms exactly the shared list the server publishes', () => {
		expect( CONFIRMS ).toEqual( FIXTURE.confirms );
	} );

	it( 'says a Draft step switches the products Off, counted from the preview', () => {
		expect(
			alwaysConfirmReasons(
				REGISTRY,
				[ step( 'draft' ) ],
				5,
				block( { off: 2, off_recapture: 1, already_off: 2 } )
			)
		).toEqual( [
			"This switches 3 products Off: they leave the site for every buyer, and each one's status is saved for switching On.",
			'1 product was changed by someone else while Off and will be switched Off again.',
		] );
	} );

	it.each( FIXTURE.confirms.filter( ( code ) => code !== 'off' ) )(
		'confirms a Status step whose preview counts %s, naming the count',
		( code ) => {
			const reasons = alwaysConfirmReasons(
				REGISTRY,
				[ step( 'publish' ) ],
				4,
				block(
					{ [ code ]: 2 },
					code === 'on_restores'
						? { restores: { private: 1, pending: 1 } }
						: {}
				)
			);
			expect( reasons ).toHaveLength( 1 );
			expect( reasons[ 0 ] ).toMatch( /^2 products / );
		}
	);

	it.each( [ 'on', 'conflict_kept', 'already_off', 'already_draft' ] )(
		'adds no reason for %s',
		( code ) => {
			expect(
				alwaysConfirmReasons(
					REGISTRY,
					[ step( 'publish' ) ],
					4,
					block( { [ code ]: 3 } )
				)
			).toEqual( [] );
		}
	);

	it( 'names the statuses a restore gives back', () => {
		expect(
			availabilityReasons(
				REGISTRY,
				block(
					{ on_restores: 3 },
					{ restores: { private: 2, pending: 1 } }
				)
			)
		).toEqual( [
			'3 products come back with the status they had before Off, not Published: 2 Private, 1 Pending review.',
		] );
	} );

	it( 'asks for the second click whatever the count, with every reason listed', () => {
		const reasons = alwaysConfirmReasons(
			REGISTRY,
			[ step( 'publish' ) ],
			1,
			block( { conflict: 1, publishes_not_off: 1 } )
		);
		const onApply = renderApply( { count: 1, threshold: 200, reasons } );
		fireEvent.click( applyButton( 1 ) );
		expect( onApply ).not.toHaveBeenCalled();
		reasons.forEach( ( reason ) =>
			expect( screen.getByText( reason ) ).toBeVisible()
		);
	} );
} );
