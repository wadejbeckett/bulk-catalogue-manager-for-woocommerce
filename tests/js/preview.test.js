/**
 * The preview screen (T50, SPEC §4.4): every block in order from the
 * /preview answer, struck-through and bold values, skipped rows and Replace
 * noted, each warning kind, the partially computable list, the sample-size
 * control's effect on the request, the changed-only filter, the download
 * control, and the computing and nothing-to-do states.
 */
import {
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';

import apiFetch from '@wordpress/api-fetch';
import BulkChangePanel, { PreviewResult } from '../../assets/src/panels/bulk';

const {
	CATEGORIES,
	IDS,
	REASON,
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

const OPERATIONS = [
	{
		action: 'adjust',
		args: {
			field: 'regular_price',
			direction: 'increase',
			by: 'percent',
			amount: '10',
		},
	},
	{
		action: 'replace_terms',
		args: { field: 'category_ids', terms: [ 2, 3 ] },
	},
];

/**
 * Render the preview alone.
 *
 * @param {Object} props Overrides.
 * @return {Object} Render result and callbacks.
 */
function renderPreview( props = {} ) {
	const onSampleSize = jest.fn();
	const onChangedOnly = jest.fn();
	const result = render(
		<PreviewResult
			response={ previewResponse() }
			registry={ REGISTRY }
			operations={ OPERATIONS }
			terms={ { categories: CATEGORIES } }
			sampleSize={ 25 }
			onSampleSize={ onSampleSize }
			changedOnly={ false }
			onChangedOnly={ onChangedOnly }
			{ ...props }
		/>
	);
	return { ...result, onSampleSize, onChangedOnly };
}

const rows = () => screen.getAllByRole( 'row' ).slice( 1 );

describe( 'preview', () => {
	it( 'shows the server summary and the products and variations apart (T69)', () => {
		const response = previewResponse();
		renderPreview( {
			response: {
				...response,
				summary:
					'Regular price will change on 12 products and 8 variations.',
				totals: {
					...response.totals,
					by_type: {
						product: { matched: 14, would_change: 12 },
						variation: { matched: 10, would_change: 8 },
					},
				},
			},
		} );
		expect(
			screen.getByText(
				'Regular price will change on 12 products and 8 variations.'
			)
		).toBeVisible();
		const count = ( label ) =>
			screen.getByText( label ).closest( 'div' ).querySelector( 'dd' )
				.textContent;
		expect( count( 'Products that will change' ) ).toBe( '12' );
		expect( count( 'Variations that will change' ) ).toBe( '8' );
	} );

	it( 'renders summary, counts, sample and warnings in that order', () => {
		const { container } = renderPreview();
		const summary = screen.getByText(
			'This will change Regular price and Categories on 20 products.'
		);
		const counts = screen.getByText( 'Will change' );
		const table = screen.getByRole( 'table' );
		const warnings = container.querySelector( '[data-block="warnings"]' );
		const order = [ summary, counts, table, warnings ];
		order.slice( 1 ).forEach( ( node, index ) => {
			expect(
				// eslint-disable-next-line no-bitwise
				order[ index ].compareDocumentPosition( node ) &
					window.Node.DOCUMENT_POSITION_FOLLOWING
			).toBeTruthy();
		} );
	} );

	it( 'names variations in the summary when the answer counts them', () => {
		renderPreview( {
			response: previewResponse( {
				totals: {
					...previewResponse().totals,
					would_change: 1284,
					variations: 3915,
				},
			} ),
			operations: [ OPERATIONS[ 0 ] ],
		} );
		expect(
			screen.getByText(
				'This will change Regular price on 1,284 products and 3,915 variations.'
			)
		).toBeVisible();
	} );

	it( 'counts will change, already at the target value and cannot change with each reason', () => {
		renderPreview();
		const value = ( label ) =>
			screen.getByText( label ).closest( 'div' ).querySelector( 'dd' )
				.textContent;
		expect( value( 'Will change' ) ).toBe( '20' );
		expect( value( 'Already at the target value' ) ).toBe( '2' );
		const failure = screen
			.getAllByText( REASON )
			.find( ( node ) => node.closest( 'dt' ) );
		expect(
			within( failure.closest( 'div' ) ).getByText( 'Cannot change' )
		).toBeVisible();
		expect(
			failure.closest( 'div' ).querySelector( 'dd' ).textContent
		).toBe( '2' );
	} );

	it( 'strikes through the current value, bolds the new one, notes skipped rows and states Replace in bold', () => {
		renderPreview();
		const [ changed, variation, already, skipped, replaced ] = rows();
		expect( changed.querySelector( 'del' ).textContent ).toBe( '100.00' );
		expect( changed.querySelector( 'strong' ).textContent ).toBe(
			'110.00'
		);
		expect( within( changed ).getByText( 'Regular price' ) ).toBeVisible();
		expect( variation.className ).toContain( 'is-variation' );
		expect(
			within( already ).getByText( 'Already correct, left alone' )
		).toBeVisible();
		expect( already.querySelector( 'del' ) ).toBeNull();
		expect(
			within( skipped ).getByText( 'Will be skipped: ' + REASON )
		).toBeVisible();
		expect( skipped.className ).toContain( 'is-skip' );
		const bold = Array.from( replaced.querySelectorAll( 'strong' ) ).map(
			( node ) => node.textContent.trim()
		);
		expect( bold ).toEqual( [
			'Replaces the whole list with',
			'Clothing › Shirts, Clothing › Hats',
		] );
		expect( replaced.querySelector( 'del' ).textContent ).toBe(
			'Clothing'
		);
		expect(
			screen.getByText(
				'The first 5 of 24 rows are shown. The same change applies to the rest.'
			)
		).toBeVisible();
	} );

	it.each( [
		[
			'sale_above_regular',
			'1 sale price would not be below its regular price.',
		],
		[ 'price_not_positive', '2 prices would fall to zero or below.' ],
		[ 'stock_negative', 'Stock would go below zero on 3 products.' ],
		[
			'sku_duplicate',
			'4 SKUs would duplicate another product’s, so they would not be applied.',
		],
	] )( 'warns about %s', ( kind, sentence ) => {
		const { container } = renderPreview( {
			response: previewResponse( {
				warnings: {
					sale_above_regular: 0,
					price_not_positive: 0,
					stock_negative: 0,
					sku_duplicate: 0,
					[ kind ]: previewResponse().warnings[ kind ],
				},
			} ),
		} );
		const items = container.querySelectorAll( '[data-warning]' );
		expect( items ).toHaveLength( 1 );
		expect( items[ 0 ].getAttribute( 'data-warning' ) ).toBe( kind );
		expect( items[ 0 ].textContent ).toBe( sentence );
	} );

	it( 'has no warning block when nothing warns', () => {
		const { container } = renderPreview( {
			response: previewResponse( {
				warnings: {
					sale_above_regular: 0,
					price_not_positive: 0,
					stock_negative: 0,
					sku_duplicate: 0,
				},
			} ),
		} );
		expect(
			container.querySelector( '[data-block="warnings"]' )
		).toBeNull();
	} );

	it( 'lists the chosen products that cannot be changed, kept in the change (T83)', () => {
		const { container } = renderPreview();
		const block = container.querySelector( '[data-block="unreadable"]' );
		expect( block.textContent ).toContain(
			'1 chosen product cannot be changed now. It stays in this change and will be recorded as not changed.'
		);
		expect( block.textContent ).toContain( '#999' );
	} );

	it( 'counts every unavailable product, beyond those listed', () => {
		const { container } = renderPreview( {
			response: previewResponse( { unavailable_count: 1200 } ),
		} );
		expect(
			container.querySelector( '[data-block="unreadable"]' ).textContent
		).toContain( '1,200 chosen products cannot be changed now.' );
	} );

	it( 'says when the counts are estimated', () => {
		renderPreview( {
			response: previewResponse( {
				totals: {
					...previewResponse().totals,
					estimated: true,
					computed: 10000,
				},
			} ),
		} );
		expect(
			screen.getByText(
				'These counts are worked out from the first 10,000 products and scaled to the whole selection.'
			)
		).toBeVisible();
	} );

	it( 'filters to changed rows only', () => {
		const { rerender, onChangedOnly } = renderPreview();
		fireEvent.click( screen.getByLabelText( 'Only rows that change' ) );
		expect( onChangedOnly ).toHaveBeenCalledWith( true );
		rerender(
			<PreviewResult
				response={ previewResponse() }
				registry={ REGISTRY }
				operations={ OPERATIONS }
				terms={ { categories: CATEGORIES } }
				sampleSize={ 25 }
				onSampleSize={ jest.fn() }
				changedOnly
				onChangedOnly={ jest.fn() }
			/>
		);
		expect( rows() ).toHaveLength( 3 );
		rows().forEach( ( row ) =>
			expect( row.getAttribute( 'data-state' ) ).toBe( 'changed' )
		);
	} );

	it( 'offers 25, 100 or every row up to 1,000', () => {
		const { onSampleSize } = renderPreview();
		expect(
			screen
				.getByRole( 'button', { name: '25' } )
				.getAttribute( 'aria-pressed' )
		).toBe( 'true' );
		fireEvent.click( screen.getByRole( 'button', { name: '100' } ) );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Every row up to 1,000' } )
		);
		expect( onSampleSize.mock.calls ).toEqual( [ [ 100 ], [ 'all' ] ] );
	} );

	it( 'disables Download sample CSV with its reason until the export is wired, and calls it when given', () => {
		const { unmount } = renderPreview();
		const button = screen.getByRole( 'button', {
			name: 'Download sample CSV',
		} );
		expect( button ).toBeDisabled();
		expect(
			screen.getByText( 'The download arrives with the export.' )
		).toBeVisible();
		unmount();
		const onDownload = jest.fn();
		renderPreview( { onDownload } );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Download sample CSV' } )
		);
		expect( onDownload ).toHaveBeenCalled();
	} );
} );

describe( 'preview inside the panel', () => {
	beforeEach( () => apiFetch.mockReset() );

	/** Choose "increase regular price by 10 per cent" and check it. */
	function checkIncrease() {
		const heading = screen.getByRole( 'heading', { name: 'Pricing' } );
		fireEvent.click(
			within( heading.parentElement ).getByRole( 'radio', {
				name: /^Increase or decrease/,
			} )
		);
		fireEvent.change( screen.getByLabelText( 'How much' ), {
			target: { value: '10' },
		} );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Check the change' } )
		);
	}

	it( 'sends the sample size chosen, and the download gets the preview request', async () => {
		routeApi( apiFetch, { 'POST preview': previewResponse() } );
		const onDownloadSample = jest.fn();
		render(
			<BulkChangePanel
				selection={ { kind: 'ids', ids: IDS } }
				registry={ REGISTRY }
				settings={ SETTINGS }
				onDownloadSample={ onDownloadSample }
			/>
		);
		checkIncrease();
		await screen.findByRole( 'table' );
		fireEvent.click( screen.getByRole( 'button', { name: '100' } ) );
		await waitFor( () =>
			expect( callsTo( apiFetch, 'POST preview' ) ).toHaveLength( 2 )
		);
		await waitFor( () =>
			expect(
				screen
					.getByRole( 'button', { name: '100' } )
					.getAttribute( 'aria-pressed' )
			).toBe( 'true' )
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Every row up to 1,000' } )
		);
		await waitFor( () =>
			expect( callsTo( apiFetch, 'POST preview' ) ).toHaveLength( 3 )
		);
		const sizes = callsTo( apiFetch, 'POST preview' ).map(
			( call ) => call.data.sample_size
		);
		expect( sizes ).toEqual( [ 25, 100, 'all' ] );
		await waitFor( () =>
			expect(
				screen.getByRole( 'button', { name: 'Download sample CSV' } )
					.disabled
			).toBe( false )
		);
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Download sample CSV' } )
		);
		expect( onDownloadSample ).toHaveBeenCalledWith( {
			operations: callsTo( apiFetch, 'POST preview' )[ 0 ].data
				.operations,
			ids: IDS,
			sample_size: 'all',
		} );
	} );

	it( 'shows the computing state, then nothing to do with Apply disabled', async () => {
		let answer;
		routeApi( apiFetch, {
			'POST preview': () =>
				new Promise( ( resolve ) => {
					answer = resolve;
				} ),
		} );
		render(
			<BulkChangePanel
				selection={ { kind: 'ids', ids: IDS } }
				registry={ REGISTRY }
				settings={ SETTINGS }
			/>
		);
		checkIncrease();
		expect(
			screen.getByText(
				'Working out what would change. Nothing is saved.'
			)
		).toBeVisible();
		answer(
			previewResponse( {
				totals: { ...previewResponse().totals, would_change: 0 },
				sample: [],
			} )
		);
		expect(
			await screen.findByText( 'Nothing would change' )
		).toBeVisible();
		expect(
			screen.getByRole( 'button', { name: 'Apply to 0 products' } )
		).toBeDisabled();
	} );

	it( 'never lets a preview of an older version of the change enable Apply (T85)', async () => {
		const answers = [];
		routeApi( apiFetch, {
			'POST preview': ( options ) =>
				new Promise( ( resolve ) => {
					answers.push( { resolve, options } );
				} ),
		} );
		render(
			<BulkChangePanel
				selection={ { kind: 'ids', ids: IDS } }
				registry={ REGISTRY }
				settings={ SETTINGS }
			/>
		);
		checkIncrease();
		expect( answers ).toHaveLength( 1 );
		// Check the change waits while it is worked out.
		expect(
			screen.getByRole( 'button', { name: 'Check the change' } )
		).toBeDisabled();
		// The change is edited while the first answer is still out.
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Edit step 1' } )
		);
		fireEvent.change( screen.getByLabelText( 'How much' ), {
			target: { value: '12' },
		} );
		// The answer for 10 arrives: it is not the change on screen.
		answers[ 0 ].resolve(
			previewResponse( {
				operations: answers[ 0 ].options.data.operations,
			} )
		);
		const again = await screen.findByRole( 'button', {
			name: /^(Check the change|Preview again)$/,
		} );
		expect(
			screen.queryByRole( 'button', { name: /^Apply to / } )
		).toBeNull();
		fireEvent.click( again );
		await waitFor( () => expect( answers ).toHaveLength( 2 ) );
		expect( answers[ 1 ].options.data.operations[ 0 ].args.amount ).toBe(
			'12'
		);
		answers[ 1 ].resolve(
			previewResponse( {
				operations: answers[ 1 ].options.data.operations,
			} )
		);
		expect(
			await screen.findByRole( 'button', {
				name: 'Apply to 20 products',
			} )
		).toBeEnabled();
	} );

	it( 'shows a failed preview in plain words with the code behind Show details', async () => {
		routeApi( apiFetch, {
			'POST preview': {
				reject: {
					code: 'bulkcat_selection_too_large',
					message: 'This selection holds 100,001 products.',
				},
			},
		} );
		render(
			<BulkChangePanel
				selection={ { kind: 'ids', ids: IDS } }
				registry={ REGISTRY }
				settings={ SETTINGS }
			/>
		);
		checkIncrease();
		expect(
			await screen.findByText( 'This selection holds 100,001 products.' )
		).toBeVisible();
		expect(
			screen.queryByText( 'bulkcat_selection_too_large' )
		).toBeNull();
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Show details' } )
		);
		expect(
			screen.getByText( 'bulkcat_selection_too_large' )
		).toBeVisible();
	} );
} );

describe( 'the Off and On block (T91, SPEC §9.1)', () => {
	const statusRow = (
		id,
		name,
		note,
		context,
		state = 'changed',
		extra = {}
	) => ( {
		object_id: id,
		object_type: 'product',
		name,
		field: 'status',
		current: context.current || 'draft',
		new: 'publish',
		state,
		reason: '',
		code: '',
		replaces: false,
		note,
		context,
		...extra,
	} );
	const answer = () =>
		previewResponse( {
			sample: [
				statusRow( 11, 'Blue Tee', 'on', {
					saved: 'publish',
					restores: 'publish',
				} ),
				statusRow(
					12,
					'Red Cap',
					'on_restores',
					{ saved: 'private', restores: 'private' },
					'changed',
					{ new: 'private' }
				),
				statusRow( 13, 'Old Hat', 'on_scheduled', {
					saved: 'future',
					restores: 'future',
					scheduled_gmt: '2026-10-04 09:30:00',
				} ),
				statusRow( 14, 'Wool Sock', 'publishes_not_off', {
					current: 'future',
					scheduled_gmt: '2026-10-05 08:00:00',
				} ),
				statusRow(
					15,
					'Grey Scarf',
					'conflict',
					{ saved: 'publish', current: 'private' },
					'skipped',
					{
						reason: 'This product was changed by someone else while it was Off, so it was left alone.',
					}
				),
			],
			availability: {
				counts: {
					off: 0,
					off_recapture: 0,
					already_off: 0,
					already_draft: 0,
					on: 1,
					on_restores: 1,
					on_scheduled: 1,
					on_schedule_passed: 2,
					publishes_not_off: 1,
					conflict: 1,
					conflict_kept: 0,
					record_dropped: 0,
				},
				restores: { private: 1, pending: 0 },
				variations_kept: 6,
				conflicts: [
					{
						object_id: 15,
						name: 'Grey Scarf',
						current: 'private',
						saved: 'publish',
					},
				],
			},
		} );
	const STATUS_OPS = [
		{ action: 'set_value', args: { field: 'status', value: 'publish' } },
	];

	it( 'counts every outcome, conflicts first, then restores, passed schedules and publications', () => {
		const { container } = renderPreview( {
			response: answer(),
			operations: STATUS_OPS,
		} );
		const lines = Array.from(
			container.querySelectorAll(
				'[data-block="availability"] .bulkcat-bulk__lines li'
			)
		).map( ( item ) => item.textContent );
		expect( lines ).toEqual( [
			'1 product was changed by someone else while Off and will be left alone. Choose Keep current status to clear its Off record instead.',
			'1 product comes back with the status it had before Off, not Published: 1 Private.',
			'2 products were scheduled and their time has passed: this publishes them now.',
			'1 product was not switched Off: this publishes it.',
			'1 product will be switched back On.',
			'1 product comes back scheduled.',
			'6 variations are left as they are: switching On restores only what switching Off saved.',
		] );
		expect(
			within(
				container.querySelector( '[data-block="availability"]' )
			).getByText( /Grey Scarf/ )
		).toBeVisible();
		// The block sits above the sample.
		expect(
			// eslint-disable-next-line no-bitwise
			container
				.querySelector( '[data-block="availability"]' )
				.compareDocumentPosition( screen.getByRole( 'table' ) ) &
				window.Node.DOCUMENT_POSITION_FOLLOWING
		).toBeTruthy();
	} );

	it( 'words each Status row by its outcome', () => {
		renderPreview( { response: answer(), operations: STATUS_OPS } );
		expect( screen.getByText( 'Switched back On' ) ).toBeVisible();
		expect(
			screen.getByText( 'Was Private before Off: comes back Private' )
		).toBeVisible();
		expect(
			screen.getByText(
				/^Was scheduled before Off: comes back scheduled for /
			)
		).toBeVisible();
		expect(
			screen.getByText( /^Not switched Off: stays scheduled for / )
		).toBeVisible();
		expect(
			screen.getByText( 'Changed by someone else while Off: left alone' )
		).toBeVisible();
	} );

	it( 'shows no block for a change that does not set Status', () => {
		const { container } = renderPreview();
		expect(
			container.querySelector( '[data-block="availability"]' )
		).toBeNull();
	} );
} );
