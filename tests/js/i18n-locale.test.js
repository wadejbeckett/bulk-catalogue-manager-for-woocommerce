/**
 * FRONTEND-11: sentences a translator can reorder, and numbers and dates in
 * the admin's language (`<html lang>`), never the browser's.
 */
import { render, screen } from '@testing-library/react';
import { resetLocaleData, setLocaleData } from '@wordpress/i18n';

import { lostText } from '../../assets/src/panels/catalogue/selection/model';
import {
	undoneNotice,
	categoriesPhrase,
} from '../../assets/src/panels/history/undo';
import { retentionLine } from '../../assets/src/panels/history/batches';
import {
	formatWhen,
	rowsLabel,
} from '../../assets/src/panels/history/describe';
import { BatchProgress } from '../../assets/src/panels/history/stalled-notice';
import { finishedSentence } from '../../assets/src/panels/categories/provider-actions/model';
import { formatDate } from '../../assets/src/panels/catalogue/cells';

const DOMAIN = 'bulk-catalogue-manager-for-woocommerce';

afterEach( () => {
	resetLocaleData( undefined, DOMAIN );
	document.documentElement.lang = '';
} );

describe( 'whole sentences', () => {
	it( 'lets a translator put what was selected before why it is gone', () => {
		setLocaleData(
			{ '%1$s You had %2$s selected.': [ 'Selected: %2$s. %1$s' ] },
			DOMAIN
		);
		expect( lostText( { count: 3, byType: null, message: 'Gone.' } ) ).toBe(
			'Selected: 3 products. Gone.'
		);
	} );

	it( 'lets a translator place the route’s line in an undo notice', () => {
		setLocaleData(
			{ 'Undone: %1$s. %2$s %3$s': [ '%3$s | %1$s | %2$s' ] },
			DOMAIN
		);
		expect(
			undoneNotice( 'Raised prices', {
				changed_items: 2,
				undo: {
					sentence: 'One was left alone.',
					by_type: { products: 2 },
				},
			} )
		).toBe(
			'One was left alone. | Raised prices | 2 products were put back.'
		);
	} );
} );

describe( 'numbers and dates in the admin language', () => {
	beforeEach( () => {
		// The browser (Node) is English; the admin is German.
		document.documentElement.lang = 'de-DE';
	} );

	it( 'groups History and Categories counts as the admin language does', () => {
		expect( rowsLabel( 1284 ) ).toContain( '1.284' );
		expect( categoriesPhrase( 1284 ) ).toContain( '1.284' );
		expect( retentionLine( 1000 ) ).toContain( '1.000' );
		expect(
			finishedSentence( {
				status: 'complete',
				changed_items: 1284,
				total_items: 2000,
			} )
		).toContain( '1.284' );
		render(
			<BatchProgress
				report={ {
					status: 'running',
					done_items: 1284,
					total_items: 2000,
				} }
			/>
		);
		expect( screen.getByText( /1\.284/ ) ).toBeTruthy();
	} );

	it( 'writes dates in the admin language', () => {
		expect( formatWhen( '2026-09-23 12:00:00' ) ).toContain( '23.09.2026' );
		expect( formatDate( '2026-09-23' ) ).toContain( '23.' );
	} );
} );
