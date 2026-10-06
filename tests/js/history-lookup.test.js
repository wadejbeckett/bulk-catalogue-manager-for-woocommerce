/**
 * History names an add-on's chosen lookup items by the labels its manifest
 * kept (ticket 25; docs/EXTENSIONS-v2.md §4.5), never by raw ids, and only
 * what the batch report disclosed to this reader.
 */
import { describeBatch } from '../../assets/src/panels/history/describe';

const BATCH = {
	source: 'bulk',
	operations: [
		{
			action: 'x_picks_add',
			args: { field: 'x_picks_items', recipients: [ 'r0001', 'r0042' ] },
		},
	],
	external: {
		providers: [ { id: 'picks', label: 'Sample picks' } ],
		actions: { x_picks_add: 'Add recipients' },
		fields: { x_picks_items: 'Sample picks' },
		choices: {
			x_picks_add: {
				recipients: {
					r0001: 'Recipient 0001',
					r0042: 'Recipient 0042',
				},
			},
		},
	},
};

test( 'the chosen items are named by their kept labels', () => {
	expect( describeBatch( BATCH ) ).toBe(
		'Add recipients: Recipient 0001, Recipient 0042'
	);
} );

test( 'without disclosed choices the action and its value are named as before', () => {
	const withheld = {
		...BATCH,
		operations: [
			{ action: 'x_picks_add', args: { field: 'x_picks_items' } },
		],
		external: { ...BATCH.external, choices: {} },
	};
	expect( describeBatch( withheld ) ).toBe( 'Add recipients: sample picks' );
	const version1 = { ...BATCH, external: { ...BATCH.external } };
	delete version1.external.choices;
	expect( describeBatch( version1 ) ).not.toContain( 'r0001' );
} );
