/**
 * History of an external change (T108, docs/EXTENSIONS.md §8.3, §11; E9):
 * the change is described with the labels its manifest kept, even when the
 * add-on is no longer registered; its values are the text the server sends;
 * and a change that needs its add-on to undo says so by name.
 */
import {
	describeBatch,
	formatValue,
	reasonSentence,
} from '../../assets/src/panels/history/describe';
import { undoneNotice } from '../../assets/src/panels/history/undo';

const BATCH = {
	batch_id: 41,
	source: 'bulk',
	operations: [
		{
			action: 'x_sample_add',
			args: {
				field: 'x_sample_labels',
				labels: [ 'red' ],
				skip_already: true,
			},
		},
	],
	actions: [ 'x_sample_add' ],
	external: {
		providers: [ { id: 'sample', label: 'Sample labels' } ],
		actions: { x_sample_add: 'Add labels' },
		fields: { x_sample_labels: 'Sample labels' },
	},
	reversibility: {
		state: 'not_reversible',
		conflicts: null,
		reason: 'provider_missing',
		provider: 'Sample labels',
	},
};

describe( 'history of an external change', () => {
	it( 'describes it from the manifest when the add-on is gone', () => {
		expect( describeBatch( BATCH, {}, {} ) ).toBe(
			'Add labels: sample labels'
		);
	} );

	it( 'prefers what the registry says while the add-on is active', () => {
		expect(
			describeBatch(
				BATCH,
				{
					x_sample_labels: {
						key: 'x_sample_labels',
						label: 'Labels',
					},
				},
				{
					x_sample_add: {
						id: 'x_sample_add',
						label: 'Add some labels',
					},
				}
			)
		).toBe(
			'Add some labels',
			'The action already names its field: said once (1.3.4).'
		);
	} );

	it( 'never repeats a field its action already names, in History or the undo notice (1.3.4)', () => {
		const groups = {
			...BATCH,
			external: {
				...BATCH.external,
				actions: { x_sample_add: 'Add customer groups' },
				fields: { x_sample_labels: 'Customer groups' },
			},
		};
		const what = describeBatch( groups, {}, {} );
		expect( what ).toBe( 'Add customer groups' );
		expect(
			undoneNotice( what, { changed_items: 1, done_items: 1 } )
		).toBe( 'Undone: Add customer groups. 1 item was put back.' );
	} );

	it( 'shows the values the server sends as text', () => {
		expect( formatValue( 'Blue, Red', { type: 'external' } ) ).toBe(
			'Blue, Red'
		);
		expect( formatValue( 'No labels', undefined ) ).toBe( 'No labels' );
	} );

	it( 'says which add-on must be active to undo it', () => {
		expect( reasonSentence( 'provider_missing', 'Sample labels' ) ).toBe(
			'Needs Sample labels to be active to undo.'
		);
		expect( reasonSentence( 'provider_missing' ) ).toBe(
			'Needs the add-on that made this change to be active to undo.'
		);
	} );

	it( 'says an add-on that is active but cannot run, with its own reason', () => {
		expect(
			reasonSentence(
				'provider_unavailable',
				'Sample labels',
				'Sample labels needs its settings saved before it can be used.'
			)
		).toBe(
			'Sample labels cannot run now, so this change cannot be undone yet. Sample labels needs its settings saved before it can be used.'
		);
		expect(
			reasonSentence( 'provider_unavailable', 'Sample labels' )
		).toBe(
			'Sample labels cannot run now, so this change cannot be undone yet.'
		);
		expect( reasonSentence( 'pruned' ) ).toContain( 'cannot be undone' );
	} );
} );

it( 'retains provider-v1 history descriptions with the new native registry', () => {
	const {
		fieldsByKey,
	} = require( '../../assets/src/panels/history/describe' );
	const registry = require( '../Unit/Fields/fields.snapshot.json' );
	const actions = Object.fromEntries(
		registry.actions.map( ( action ) => [ action.id, action ] )
	);
	expect(
		describeBatch( BATCH, fieldsByKey( registry.fields ), actions )
	).toBe( 'Add labels: sample labels' );
} );
