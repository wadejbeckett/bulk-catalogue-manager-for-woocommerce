/**
 * FRONTEND-6: the progress of a change never calls variations products. The
 * Done step's "Working through" names products and variations apart from
 * the preview's counts (items when they are not known), and the catalogue's
 * banner ends with the same sentence as the panel's Done step.
 */
import { render, screen, waitFor } from '@testing-library/react';

import { DoneStep } from '../../assets/src/panels/bulk/progress';
import { AppliedProgress } from '../../assets/src/panels/catalogue';

jest.mock( '@wordpress/api-fetch', () => {
	const apiFetch = jest.fn();
	apiFetch.use = jest.fn();
	apiFetch.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: apiFetch };
} );
jest.mock( '../../assets/src/panels/categories/announce', () => ( {
	announce: jest.fn(),
} ) );

const apiFetch = require( '@wordpress/api-fetch' ).default;
const { announce } = require( '../../assets/src/panels/categories/announce' );

let report;

beforeAll( () => {
	document.documentElement.lang = 'en-GB';
} );

afterAll( () => {
	document.documentElement.lang = '';
} );

beforeEach( () => {
	announce.mockClear();
	apiFetch.mockReset();
	apiFetch.mockImplementation( () => Promise.resolve( report ) );
} );

it.each( [
	[
		{ products: 1284, variations: 50 },
		'Working through 1,284 products and 50 variations in the background.',
	],
	[
		{ products: 1334, variations: 0 },
		'Working through 1,334 products in the background.',
	],
	[ null, 'Working through 1,334 items in the background.' ],
] )( 'says what it works through (%j)', async ( byType, sentence ) => {
	report = { status: 'running', done_items: 0, total_items: 1334 };
	render(
		<DoneStep
			batchId={ 9 }
			count={ 1334 }
			byType={ byType }
			interval={ 50 }
		/>
	);
	expect( await screen.findByText( sentence ) ).toBeTruthy();
} );

it( 'ends the banner with the Done step’s own sentence', async () => {
	report = {
		status: 'complete',
		done_items: 1284,
		total_items: 1284,
		changed_items: 1284,
	};
	render(
		<AppliedProgress
			batchId={ 9 }
			byType={ { products: 1284, variations: 0 } }
			onFinished={ jest.fn() }
		/>
	);
	await waitFor( () =>
		expect( announce ).toHaveBeenCalledWith(
			'1,284 products updated.',
			'polite'
		)
	);
} );
