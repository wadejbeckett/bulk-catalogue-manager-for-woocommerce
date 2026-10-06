/**
 * Ticket 19: each user's display choices (columns in order, rows per page,
 * density) are remembered through /bulkcat/v1/display-preferences, apart
 * from named saved views; the page-size control works without a sort.
 */
import {
	act,
	fireEvent,
	render,
	renderHook,
	screen,
} from '@testing-library/react';

import {
	applyDisplay,
	displayOf,
	mergeAvailableFields,
} from '../../assets/src/panels/catalogue/views/display-state';
import {
	useDisplayPreferences,
	useRememberDisplay,
} from '../../assets/src/panels/catalogue/views/use-display-preferences';
import PageSizeControl from '../../assets/src/panels/catalogue/views/page-size';

jest.mock( '@wordpress/api-fetch', () => {
	const apiFetch = jest.fn();
	apiFetch.use = jest.fn();
	apiFetch.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: apiFetch };
} );

const apiFetch = require( '@wordpress/api-fetch' ).default;

const KNOWN = [ 'sku', 'stock_status', 'regular_price' ];

beforeEach( () => {
	apiFetch.mockReset();
	jest.useRealTimers();
} );

describe( 'display state', () => {
	it( 'takes only the display keys of a view', () => {
		const view = {
			search: 'shirt',
			page: 8,
			filters: [ { field: 'sku' } ],
			fields: [ 'sku' ],
			perPage: 50,
			layout: { density: 'compact' },
		};
		expect( displayOf( view ) ).toEqual( {
			fields: [ 'sku' ],
			perPage: 50,
			density: 'compact',
		} );
		expect( displayOf( { fields: [], perPage: 25, layout: {} } ) ).toEqual(
			{
				fields: [],
				perPage: 25,
			}
		);
	} );

	it( 'applies stored choices over a view without touching its filters', () => {
		const base = {
			search: 'shirt',
			page: 3,
			fields: [ 'stock_status' ],
			perPage: 25,
			layout: { foo: 1 },
		};
		const next = applyDisplay(
			base,
			{
				fields: [ 'regular_price', 'gone', 'sku' ],
				perPage: 100,
				density: 'compact',
			},
			KNOWN
		);
		expect( next ).toEqual( {
			search: 'shirt',
			page: 1,
			fields: [ 'regular_price', 'sku' ],
			perPage: 100,
			layout: { foo: 1, density: 'compact' },
		} );
		expect( base.fields ).toEqual( [ 'stock_status' ] );
		expect( applyDisplay( base, null, KNOWN ) ).toBe( base );
		expect( applyDisplay( base, { perPage: 13 }, KNOWN ).perPage ).toBe(
			25
		);
		expect( applyDisplay( base, { fields: [] }, KNOWN ).fields ).toEqual(
			[]
		);
	} );
} );

describe( 'columns this screen does not offer just now', () => {
	it( 'keeps them in place, before the next column still offered', () => {
		// "missing" sat before stock_status; the user moves stock_status first.
		expect(
			mergeAvailableFields(
				[ 'sku', 'missing', 'stock_status' ],
				[ 'stock_status', 'sku' ],
				KNOWN
			)
		).toEqual( [ 'missing', 'stock_status', 'sku' ] );
		// A trailing run stays last; one whose anchor was hidden goes last too.
		expect(
			mergeAvailableFields(
				[ 'sku', 'gone', 'regular_price', 'later' ],
				[ 'sku' ],
				KNOWN
			)
		).toEqual( [ 'sku', 'gone', 'later' ] );
		// Nothing dormant: the visible order as it is.
		expect(
			mergeAvailableFields( [ 'sku' ], [ 'regular_price' ], KNOWN )
		).toEqual( [ 'regular_price' ] );
		expect( mergeAvailableFields( undefined, [ 'sku' ], KNOWN ) ).toEqual( [
			'sku',
		] );
	} );

	it( 'saves a display change without dropping a stored column the screen lacks', async () => {
		jest.useFakeTimers();
		apiFetch.mockResolvedValue( { version: 1, display: {} } );
		const stored = { fields: [ 'sku', 'missing', 'stock_status' ] };
		const shown = {
			fields: [ 'sku', 'stock_status' ],
			perPage: 25,
			layout: {},
		};
		const { rerender, result } = renderHook(
			( { view } ) => useRememberDisplay( view, true, stored, KNOWN ),
			{ initialProps: { view: shown } }
		);
		await act( async () => jest.advanceTimersByTime( 2000 ) );
		expect( apiFetch ).not.toHaveBeenCalled();
		result.current.touch();
		rerender( { view: { ...shown, perPage: 50 } } );
		await act( async () => jest.advanceTimersByTime( 2000 ) );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].data.display ).toEqual( {
			fields: [ 'sku', 'missing', 'stock_status' ],
			perPage: 50,
		} );
	} );
} );

describe( 'loading and remembering', () => {
	it( 'reads the stored display once and treats a failed read as none', async () => {
		apiFetch.mockResolvedValueOnce( {
			version: 1,
			display: { perPage: 50 },
		} );
		const { result } = renderHook( () => useDisplayPreferences() );
		expect( result.current.hasLoaded ).toBe( false );
		await act( async () => {} );
		expect( result.current ).toMatchObject( {
			hasLoaded: true,
			display: { perPage: 50 },
		} );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].url ).toContain(
			'display-preferences'
		);

		apiFetch.mockRejectedValueOnce( { code: 'x', data: { status: 500 } } );
		const failed = renderHook( () => useDisplayPreferences() );
		await act( async () => {} );
		expect( failed.result.current ).toMatchObject( {
			hasLoaded: true,
			display: null,
		} );
	} );

	it( 'saves a changed display once, after changes settle, never on mount', async () => {
		jest.useFakeTimers();
		apiFetch.mockResolvedValue( { version: 1, display: {} } );
		const first = { fields: [ 'sku' ], perPage: 25, layout: {} };
		const { rerender, result } = renderHook(
			( { view } ) => useRememberDisplay( view ),
			{ initialProps: { view: first } }
		);
		await act( async () => jest.advanceTimersByTime( 2000 ) );
		expect( apiFetch ).not.toHaveBeenCalled();

		// The screen's own change (no touch) is not saved.
		rerender( { view: { ...first, perPage: 100 } } );
		await act( async () => jest.advanceTimersByTime( 2000 ) );
		expect( apiFetch ).not.toHaveBeenCalled();
		rerender( { view: { ...first, search: 'x', page: 4 } } );
		result.current.touch();
		rerender( { view: { ...first, search: 'x', page: 4 } } );
		await act( async () => jest.advanceTimersByTime( 2000 ) );
		expect( apiFetch ).not.toHaveBeenCalled();

		rerender( { view: { ...first, perPage: 50 } } );
		rerender( { view: { ...first, perPage: 100 } } );
		await act( async () => jest.advanceTimersByTime( 2000 ) );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch.mock.calls[ 0 ][ 0 ] ).toMatchObject( {
			method: 'PUT',
			data: { display: { fields: [ 'sku' ], perPage: 100 } },
		} );
		expect( result.current.error ).toBe( null );
	} );

	it( 'reports a failed save and tries again with the latest display', async () => {
		jest.useFakeTimers();
		apiFetch.mockRejectedValueOnce( {
			code: 'bulkcat_display_unsaved',
			data: { status: 503 },
		} );
		const first = { fields: [ 'sku' ], perPage: 25, layout: {} };
		const { rerender, result } = renderHook(
			( { view } ) => useRememberDisplay( view ),
			{ initialProps: { view: first } }
		);
		result.current.touch();
		rerender( { view: { ...first, perPage: 50 } } );
		await act( async () => jest.advanceTimersByTime( 2000 ) );
		expect( result.current.error ).not.toBe( null );
		apiFetch.mockResolvedValueOnce( { version: 1, display: {} } );
		await act( async () => result.current.retry() );
		expect( apiFetch ).toHaveBeenCalledTimes( 2 );
		expect( apiFetch.mock.calls[ 1 ][ 0 ].data.display.perPage ).toBe( 50 );
		expect( result.current.error ).toBe( null );
	} );
} );

describe( 'density', () => {
	it( 'saves a density change even when the stored choices name no density', async () => {
		jest.useFakeTimers();
		apiFetch.mockResolvedValue( { version: 1, display: {} } );
		const stored = { fields: [ 'sku' ], perPage: 25 };
		const shown = { fields: [ 'sku' ], perPage: 25, layout: {} };
		const { rerender, result } = renderHook(
			( { view } ) => useRememberDisplay( view, true, stored, KNOWN ),
			{ initialProps: { view: shown } }
		);
		result.current.touch();
		rerender( { view: { ...shown, layout: { density: 'compact' } } } );
		await act( async () => jest.advanceTimersByTime( 2000 ) );
		expect( apiFetch ).toHaveBeenCalledTimes( 1 );
		expect( apiFetch.mock.calls[ 0 ][ 0 ].data.display.density ).toBe(
			'compact'
		);
	} );
} );

describe( 'rows per page', () => {
	it( 'offers every page size without needing a sort', () => {
		const onChangeView = jest.fn();
		const view = { page: 6, perPage: 25, fields: [] };
		render(
			<PageSizeControl view={ view } onChangeView={ onChangeView } />
		);
		const control = screen.getByRole( 'combobox', {
			name: 'Rows per page',
		} );
		expect( control.disabled ).toBe( false );
		expect(
			Array.from( control.options ).map( ( option ) => option.value )
		).toEqual( [ '25', '50', '100' ] );
		fireEvent.change( control, { target: { value: '50' } } );
		expect( onChangeView ).toHaveBeenCalledWith( {
			page: 1,
			perPage: 50,
			fields: [],
		} );
		expect( onChangeView.mock.calls[ 0 ][ 0 ] ).not.toHaveProperty(
			'sort'
		);
	} );
} );
