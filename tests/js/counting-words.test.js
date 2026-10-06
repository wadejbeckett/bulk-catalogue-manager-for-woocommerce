/**
 * Ticket 11 item 4: a count that can include variations never calls them
 * products. The Select all offer names what the grid lists in its mode
 * (products, variations, or items where both are listed); the Apply button
 * and its confirmation split products and variations from the preview's
 * `totals.by_type`; the Done step and a History row, whose counts the
 * server does not split, say "items" unless the change is known to hold
 * products only.
 */
import { fireEvent, render, screen } from '@testing-library/react';

import {
	pageWords,
	selectAllWords,
} from '../../assets/src/panels/catalogue/selection/selection-bar';
import { ConfirmApply } from '../../assets/src/panels/bulk';
import { doneSentence } from '../../assets/src/panels/bulk/progress';
import { rowsLabel } from '../../assets/src/panels/history/describe';

beforeAll( () => {
	// Numbers group in the admin's language, <html lang>.
	document.documentElement.lang = 'en-GB';
} );

afterAll( () => {
	document.documentElement.lang = '';
} );

describe( 'the Select all offer', () => {
	it( 'counts products in the Products mode', () => {
		expect( selectAllWords( 1284, true, 'products' ) ).toBe(
			'Select all 1,284 products matching these filters.'
		);
		expect( selectAllWords( 20013, false, 'products' ) ).toBe(
			'Select all 20,013 products in the catalogue.'
		);
		expect( selectAllWords( 1, true, 'products' ) ).toBe(
			'Select the 1 product matching these filters.'
		);
		expect( selectAllWords( 1284, true ) ).toBe(
			'Select all 1,284 products matching these filters.'
		);
	} );

	it( 'counts variations in the Variations mode', () => {
		expect( selectAllWords( 77784, false, 'variations' ) ).toBe(
			'Select all 77,784 variations in the catalogue.'
		);
		expect( selectAllWords( 1284, true, 'variations' ) ).toBe(
			'Select all 1,284 variations matching these filters.'
		);
		expect( selectAllWords( 1, false, 'variations' ) ).toBe(
			'Select the 1 variation in the catalogue.'
		);
	} );

	it( 'counts items where products and variations are listed together', () => {
		expect(
			selectAllWords( 90076, false, 'products_and_variations' )
		).toBe( 'Select all 90,076 items in the catalogue.' );
		expect( selectAllWords( 12, true, 'products_and_variations' ) ).toBe(
			'Select all 12 items matching these filters.'
		);
	} );

	it( 'names the one row on the page by what it is', () => {
		expect( pageWords( 1, 'products' ) ).toBe(
			'The 1 product on this page is selected.'
		);
		expect( pageWords( 1, 'variations' ) ).toBe(
			'The 1 variation on this page is selected.'
		);
		expect( pageWords( 1, 'products_and_variations' ) ).toBe(
			'The 1 item on this page is selected.'
		);
		expect( pageWords( 25, 'variations' ) ).toBe(
			'All 25 on this page are selected.'
		);
	} );
} );

describe( 'the Apply button', () => {
	const renderApply = ( props ) =>
		render(
			<ConfirmApply
				onApply={ jest.fn() }
				threshold={ 200 }
				reasons={ [] }
				{ ...props }
			/>
		);

	it( 'splits products and variations when the change reaches both', () => {
		renderApply( {
			count: 85000,
			byType: { products: 7216, variations: 77784 },
		} );
		fireEvent.click(
			screen.getByRole( 'button', {
				name: 'Apply to 7,216 products and 77,784 variations',
			} )
		);
		expect(
			screen.getByText(
				'This will change 7,216 products and 77,784 variations. Apply it?'
			)
		).toBeTruthy();
		expect(
			screen.getByRole( 'button', {
				name: 'Yes, change 7,216 products and 77,784 variations',
			} )
		).toBeTruthy();
	} );

	it( 'counts variations alone as variations', () => {
		renderApply( { count: 50, byType: { products: 0, variations: 50 } } );
		expect(
			screen.getByRole( 'button', { name: 'Apply to 50 variations' } )
		).toBeTruthy();
	} );

	it( 'keeps products for a change to products only', () => {
		renderApply( { count: 250, byType: { products: 250, variations: 0 } } );
		fireEvent.click(
			screen.getByRole( 'button', { name: 'Apply to 250 products' } )
		);
		expect(
			screen.getByText( 'This will change 250 products. Apply it?' )
		).toBeTruthy();
	} );
} );

describe( 'the Done step', () => {
	const complete = { status: 'complete', total_items: 1284 };
	const partial = {
		status: 'partial',
		total_items: 1284,
		changed_items: 1280,
		failed_items: 4,
	};

	it( 'says items when the change may have reached variations', () => {
		expect( doneSentence( complete ) ).toBe( '1,284 items updated.' );
		expect(
			doneSentence( complete, { products: 1200, variations: 84 } )
		).toBe( '1,284 items updated.' );
		expect( doneSentence( partial ) ).toBe(
			'1,280 of 1,284 items updated. The rest are listed below.'
		);
		expect( doneSentence( { status: 'complete', total_items: 1 } ) ).toBe(
			'1 item updated.'
		);
	} );

	it( 'says products when the change held products only', () => {
		const products = { products: 1284, variations: 0 };
		expect( doneSentence( complete, products ) ).toBe(
			'1,284 products updated.'
		);
		expect( doneSentence( partial, products ) ).toBe(
			'1,280 of 1,284 products updated. The rest are listed below.'
		);
	} );
} );

describe( 'a History row', () => {
	it( 'counts items: the server does not split the row’s count', () => {
		expect( rowsLabel( 1248 ) ).toMatch( /^1\D?248 items$/ );
		expect( rowsLabel( 1 ) ).toBe( '1 item' );
	} );
} );
