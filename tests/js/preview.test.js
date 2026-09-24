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

	it( 'lists the products that could not be read when partially computable', () => {
		const { container } = renderPreview();
		const block = container.querySelector( '[data-block="unreadable"]' );
		expect( block.textContent ).toContain(
			'1 product could not be read and is left out of this change.'
		);
		expect( block.textContent ).toContain( '#999' );
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
