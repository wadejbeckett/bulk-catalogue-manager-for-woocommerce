/**
 * An add-on's actions on categories in the Categories workspace (contract
 * version 2, category target): offered for one category and for several
 * only when `/fields` advertises an action with `target: product_cat`;
 * never in the product bulk chooser; Include subcategories offered when a
 * chosen category has any; Preview lists category records by path with the
 * add-on's words; Apply sends exactly the captured categories through a
 * durable grant and follows the job; a stale preview asks again; an action
 * the user may not use shows its reason.
 */
import { act, fireEvent, render, screen, within } from '@testing-library/react';

import CategoriesTab from '../../assets/src/panels/categories/index';
import { groupedActions } from '../../assets/src/panels/bulk/descriptors';
import {
	applyIntent,
	categoryActionsOf,
	previewBody,
	rowText,
	scopeSentence,
} from '../../assets/src/panels/categories/provider-actions/model';
import { resetCategoryApplyGrant } from '../../assets/src/panels/categories/provider-actions/dialog';
import { categoryActions } from '../../assets/src/panels/categories/workspace-actions';
import { ShellContext } from '../../assets/src/shell';

jest.mock( '@wordpress/api-fetch', () => {
	const apiFetch = jest.fn();
	apiFetch.use = jest.fn();
	apiFetch.createNonceMiddleware = jest.fn();
	return { __esModule: true, default: apiFetch };
} );

const apiFetch = require( '@wordpress/api-fetch' ).default;
const { REGISTRY, ROWS, route } = require( './catalogue-fixture' );

const SHELVES = {
	id: 'x_shelves_add',
	label: 'Put on shelves',
	description: 'Puts each chosen category on the chosen shelves.',
	group: 'x_shelves_items',
	groups: [ 'x_shelves_items' ],
	editKinds: [ 'add' ],
	fields: [ 'x_shelves_items' ],
	provider: 'shelves',
	variations: 'parent_only',
	available: true,
	unavailableReason: '',
	alwaysConfirm: false,
	confirmReason: '',
	target: 'product_cat',
	args: {
		type: 'object',
		properties: {
			field: {
				title: 'Field',
				type: 'string',
				enum: [ 'x_shelves_items' ],
				required: true,
			},
			shelves: {
				title: 'Shelves',
				description: 'Choose at least one shelf from the list.',
				type: 'array',
				items: { type: 'string', enum: [ 'north', 'south' ] },
				uniqueItems: true,
				minItems: 1,
				maxItems: 4,
				required: true,
				choices: [
					{ value: 'north', label: 'North wall' },
					{ value: 'south', label: 'South wall' },
				],
			},
			skip_already: { title: 'Skip', type: 'boolean', default: true },
		},
		additionalProperties: false,
	},
};

const WITH_SHELVES = () => ( {
	...REGISTRY,
	fields: [
		...REGISTRY.fields,
		{
			key: 'x_shelves_items',
			label: 'Shelves',
			type: 'external',
			source: 'external',
			provider: 'shelves',
			group: 'x_shelves_items',
		},
	],
	actions: [ ...REGISTRY.actions, SHELVES ],
	groups: [
		...REGISTRY.groups,
		{ id: 'x_shelves_items', label: 'Sample shelves' },
	],
} );

const node = ( id, name, children = [] ) => ( {
	id,
	name,
	slug: name.toLowerCase(),
	description: '',
	count: 1,
	countWithDescendants: 1,
	children,
} );

const TREE = () => [
	node( 41, 'Kitchen', [ node( 42, 'Pots' ), node( 43, 'Pans' ) ] ),
	node( 50, 'Garden' ),
];

const MANAGER = {
	root: 'http://example.test/wp-json/',
	user: {
		id: 7,
		name: 'Mia',
		capabilities: { manage_product_terms: true },
	},
	settings: { confirm_threshold: 200 },
};

const PREVIEW = ( data ) => ( {
	totals: { matched: 3, would_change: 2, already: 1, would_fail: {} },
	summary: 'Shelves will change on 2 categories.',
	records: [
		{
			id: 41,
			ref: 'term:product_cat:41',
			path: 'Kitchen',
			outcome: 'change',
			rows: [
				{
					field: 'x_shelves_items',
					state: 'changed',
					current: 'No shelves',
					new: 'North wall',
					change: 'Puts it on North wall',
					reason: '',
					notes: [
						'Products in this category show the new shelves at their next page load.',
					],
				},
			],
		},
		{
			id: 42,
			ref: 'term:product_cat:42',
			path: 'Kitchen › Pots',
			outcome: 'already',
			rows: [
				{
					field: 'x_shelves_items',
					state: 'already',
					current: 'North wall',
					new: 'North wall',
					change: '',
					reason: '',
					notes: [],
				},
			],
		},
		{
			id: 43,
			ref: 'term:product_cat:43',
			path: 'Kitchen › Pans',
			outcome: 'skip',
			rows: [
				{
					field: 'x_shelves_items',
					state: 'skipped',
					current: '',
					new: '',
					change: '',
					reason: 'Sample shelves does not allow changes to this category.',
					notes: [],
				},
			],
		},
	],
	categories: {
		chosen: data.categories,
		subcategories: data.include_subcategories,
		ids: [ 41, 42, 43 ],
		count: 3,
		added: 2,
	},
	hash: 'h'.repeat( 64 ),
	scope_hash: 's'.repeat( 64 ),
	operations: data.operations,
	external: {
		provider: 'shelves',
		label: 'Sample shelves',
		target: 'product_cat',
		warnings: [],
		confirm: false,
		reasons: [],
	},
} );

let registry;
let posted;
let batchAnswer;

function routes() {
	route( apiFetch, {
		fields: () => registry,
		categories: () => TREE(),
		products: () => ( {
			items: ROWS,
			pagination: { page: 1, perPage: 10, total: 2, totalPages: 1 },
		} ),
		'categories/actions/preview': ( query, options ) => {
			posted.push( [ 'preview', options.data ] );
			return PREVIEW( options.data );
		},
		requests: ( query, options ) => {
			posted.push( [ 'requests', options.data ] );
			return {
				uuid: 'u-1',
				request_proof: 'p-1',
				operation: options.data.operation,
				intent: { version: 1, ...options.data.intent },
			};
		},
		batches: ( query, options ) => {
			posted.push( [ 'batches', options.data ] );
			return batchAnswer();
		},
		'batches/9': () => ( {
			batch_id: 9,
			status: 'complete',
			total_items: 3,
			done_items: 3,
			changed_items: 1,
		} ),
	} );
}

async function renderTab() {
	render(
		<ShellContext.Provider
			value={ {
				exportSlot: null,
				bulkRequested: false,
				consumeBulk: () => {},
				navigate: jest.fn(),
			} }
		>
			<CategoriesTab settings={ MANAGER } />
		</ShellContext.Provider>
	);
	await screen.findByRole( 'tree' );
	fireEvent.click( screen.getByRole( 'button', { name: 'Expand all' } ) );
}

const item = ( name ) =>
	screen
		.getAllByRole( 'treeitem' )
		.find( ( el ) =>
			el.getAttribute( 'aria-label' ).startsWith( name + ',' )
		);
const rowOf = ( name ) => item( name ).querySelector( '.bulkcat-cat-row' );

/**
 * Choose an add-on action from its group's menu in a pane (1.4.1).
 *
 * @param {Element} pane   The details or selection pane.
 * @param {string}  menu   The group's menu button, such as "Sample shelves…".
 * @param {string}  action The action, such as "Put on shelves".
 */
async function chooseFrom( pane, menu, action ) {
	fireEvent.click( within( pane ).getByRole( 'button', { name: menu } ) );
	fireEvent.click( await screen.findByRole( 'menuitem', { name: action } ) );
}

beforeEach( () => {
	apiFetch.mockReset();
	window.localStorage.clear();
	window.sessionStorage.clear();
	window.wp = { a11y: { speak: jest.fn() } };
	resetCategoryApplyGrant();
	registry = WITH_SHELVES();
	posted = [];
	batchAnswer = () => ( {
		batch_id: 9,
		status: 'queued',
		total_items: 3,
		replayed: false,
	} );
	routes();
} );

afterEach( () => {
	delete window.wp;
} );

describe( 'category actions: model', () => {
	it( 'finds only category actions, and the product chooser never shows them', () => {
		expect( categoryActionsOf( registry ).map( ( a ) => a.id ) ).toEqual( [
			'x_shelves_add',
		] );
		expect( categoryActionsOf( REGISTRY ) ).toEqual( [] );
		const offered = groupedActions( registry ).flatMap( ( group ) =>
			group.actions.map( ( action ) => action.id )
		);
		expect( offered ).not.toContain( 'x_shelves_add' );
	} );

	it( 'sends sorted distinct ids, and applies exactly what the preview captured', () => {
		expect( previewBody( [], [ 42, 41, 42 ], true ) ).toEqual( {
			operations: [],
			categories: [ 41, 42 ],
			include_subcategories: true,
		} );
		const preview = PREVIEW( {
			categories: [ 41 ],
			include_subcategories: true,
			operations: [ { action: 'x', args: {} } ],
		} );
		expect( applyIntent( preview ) ).toEqual( {
			source: 'bulk',
			operations: [ { action: 'x', args: {} } ],
			scope_hash: 's'.repeat( 64 ),
			categories: {
				chosen: [ 41 ],
				subcategories: true,
				ids: [ 41, 42, 43 ],
			},
		} );
		expect( scopeSentence( preview ) ).toBe(
			'This change covers 3 categories. That includes 2 subcategories.'
		);
		expect( rowText( preview.records[ 2 ].rows[ 0 ] ) ).toBe(
			'Sample shelves does not allow changes to this category.'
		);
	} );
} );

describe( 'category actions: the workspace', () => {
	it( 'offers nothing extra without an add-on action on categories', async () => {
		registry = REGISTRY;
		await renderTab();
		fireEvent.click( rowOf( 'Garden' ) );
		const pane = await screen.findByRole( 'region', { name: 'Garden' } );
		expect(
			within( pane ).queryByRole( 'button', { name: 'Put on shelves' } )
		).toBeNull();
		expect(
			within( pane ).queryByRole( 'button', { name: 'Sample shelves…' } )
		).toBeNull();
		expect(
			categoryActions( 1 ).filter( ( a ) =>
				String( a.key ).startsWith( 'provider:' )
			)
		).toEqual( [] );
	} );

	it( 'previews one category with its subcategories and applies the captured set', async () => {
		await renderTab();
		fireEvent.click( rowOf( 'Kitchen' ) );
		const pane = await screen.findByRole( 'region', { name: 'Kitchen' } );
		await chooseFrom( pane, 'Sample shelves…', 'Put on shelves' );
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Put on shelves',
		} );
		const preview = within( dialog ).getByRole( 'button', {
			name: 'Preview',
		} );
		expect( preview.getAttribute( 'aria-disabled' ) ).toBe( 'true' );
		fireEvent.click(
			within( dialog ).getByRole( 'checkbox', { name: 'North wall' } )
		);
		fireEvent.click(
			within( dialog ).getByRole( 'checkbox', {
				name: 'Include subcategories',
			} )
		);
		await act( async () => {
			fireEvent.click(
				within( dialog ).getByRole( 'button', { name: 'Preview' } )
			);
		} );
		expect( posted[ 0 ] ).toEqual( [
			'preview',
			{
				operations: [
					{
						action: 'x_shelves_add',
						args: {
							field: 'x_shelves_items',
							shelves: [ 'north' ],
							skip_already: true,
						},
					},
				],
				categories: [ 41 ],
				include_subcategories: true,
			},
		] );
		const list = within( dialog ).getByRole( 'list', {
			name: 'Categories in this change',
		} );
		expect( within( list ).getByText( 'Kitchen › Pots' ) ).toBeTruthy();
		expect(
			within( list ).getByText( 'Puts it on North wall' )
		).toBeTruthy();
		expect(
			within( list ).getByText(
				'Products in this category show the new shelves at their next page load.'
			)
		).toBeTruthy();
		expect(
			within( list ).getByText(
				'Sample shelves does not allow changes to this category.'
			)
		).toBeTruthy();
		expect(
			within( dialog ).getByText( 'Shelves will change on 2 categories.' )
		).toBeTruthy();

		await act( async () => {
			fireEvent.click(
				within( dialog ).getByRole( 'button', {
					name: 'Apply to 3 categories',
				} )
			);
		} );
		expect( posted[ 1 ] ).toEqual( [
			'requests',
			{
				operation: 'batch.apply',
				intent: {
					source: 'bulk',
					operations: posted[ 0 ][ 1 ].operations,
					scope_hash: 's'.repeat( 64 ),
					categories: {
						chosen: [ 41 ],
						subcategories: true,
						ids: [ 41, 42, 43 ],
					},
				},
			},
		] );
		expect( posted[ 2 ][ 0 ] ).toBe( 'batches' );
		expect( posted[ 2 ][ 1 ] ).toMatchObject( {
			uuid: 'u-1',
			request_proof: 'p-1',
			preview_hash: 'h'.repeat( 64 ),
		} );
		expect(
			await within( dialog ).findByText(
				"1 of 3 categories changed. Each category's result is in History, where you can undo it."
			)
		).toBeTruthy();
	} );

	it( 'previews several chosen categories and asks again when the preview went stale', async () => {
		batchAnswer = () => {
			const error = new Error( 'stale' );
			error.code = 'bulkcat_preview_stale';
			error.data = { status: 409, reason: 'scope_changed' };
			throw error;
		};
		await renderTab();
		fireEvent.click( rowOf( 'Pots' ) );
		fireEvent.click( rowOf( 'Garden' ), { ctrlKey: true } );
		const pane = await screen.findByRole( 'region', {
			name: '2 categories selected',
		} );
		await chooseFrom( pane, 'Sample shelves…', 'Put on shelves' );
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Put on shelves',
		} );
		expect(
			within( dialog ).queryByRole( 'checkbox', {
				name: 'Include subcategories',
			} )
		).toBeNull();
		fireEvent.click(
			within( dialog ).getByRole( 'checkbox', { name: 'South wall' } )
		);
		await act( async () => {
			fireEvent.click(
				within( dialog ).getByRole( 'button', { name: 'Preview' } )
			);
		} );
		expect( posted[ 0 ][ 1 ].categories ).toEqual( [ 42, 50 ] );
		expect( posted[ 0 ][ 1 ].include_subcategories ).toBe( false );
		await act( async () => {
			fireEvent.click(
				within( dialog ).getByRole( 'button', {
					name: 'Apply to 3 categories',
				} )
			);
		} );
		expect(
			within( dialog ).getByText(
				'The categories or the add-on changed since this preview. Preview again to see what the change will do now.'
			)
		).toBeTruthy();
		expect(
			within( dialog ).queryByRole( 'button', {
				name: 'Apply to 3 categories',
			} )
		).toBeNull();
	} );

	it( 'shows why an action cannot be used, and offers no preview', async () => {
		registry = WITH_SHELVES();
		registry.actions[ registry.actions.length - 1 ] = {
			...SHELVES,
			available: false,
			unavailableReason: 'You do not have permission to use this action.',
			args: { type: 'object', properties: {} },
		};
		await renderTab();
		fireEvent.click( rowOf( 'Garden' ) );
		const pane = await screen.findByRole( 'region', { name: 'Garden' } );
		await chooseFrom( pane, 'Sample shelves…', 'Put on shelves' );
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Put on shelves',
		} );
		expect(
			within( dialog ).getByText(
				'You do not have permission to use this action.'
			)
		).toBeTruthy();
		expect(
			within( dialog ).queryByRole( 'button', { name: 'Preview' } )
		).toBeNull();
	} );
} );

describe( 'category actions: grouped by add-on (1.4.1)', () => {
	const action = ( id, label, group ) => ( {
		...SHELVES,
		id,
		label,
		group,
		groups: [ group ],
	} );
	const WITH_GROUPS = () => {
		const base = WITH_SHELVES();
		return {
			...base,
			actions: [
				...base.actions,
				action(
					'x_shelves_remove',
					'Take off shelves',
					'x_shelves_items'
				),
				// An add-on action in a native group is grouped by its add-on.
				action( 'x_shelves_note', 'Set a shelf note', 'publishing' ),
			],
			providers: [
				{
					id: 'shelves',
					label: 'Shelving add-on',
					version: '1.0.0',
					available: true,
					reason: '',
				},
			],
		};
	};
	const NATIVE = [ 'Rename', 'Move to…', 'Add subcategory', 'Edit details' ];

	beforeEach( () => {
		registry = WITH_GROUPS();
	} );

	it( 'offers one menu per group in the details pane, beside the native buttons', async () => {
		await renderTab();
		fireEvent.click( rowOf( 'Garden' ) );
		const pane = await screen.findByRole( 'region', { name: 'Garden' } );
		NATIVE.forEach( ( name ) =>
			expect(
				within( pane ).getByRole( 'button', { name } )
			).toBeTruthy()
		);
		[ 'Put on shelves', 'Take off shelves', 'Set a shelf note' ].forEach(
			( name ) =>
				expect(
					within( pane ).queryByRole( 'button', { name } )
				).toBeNull()
		);
		const menu = within( pane ).getByRole( 'button', {
			name: 'Sample shelves…',
		} );
		expect( menu.getAttribute( 'aria-haspopup' ) ).toBeTruthy();
		expect( menu.getAttribute( 'aria-expanded' ) ).toBe( 'false' );
		expect(
			within( pane ).getByRole( 'button', { name: 'Shelving add-on…' } )
		).toBeTruthy();
		fireEvent.click( menu );
		const list = await screen.findByRole( 'menu', {
			name: 'Sample shelves…',
		} );
		expect(
			within( list )
				.getAllByRole( 'menuitem' )
				.map( ( each ) => each.textContent )
		).toEqual( [ 'Put on shelves', 'Take off shelves' ] );
		fireEvent.click(
			within( list ).getByRole( 'menuitem', { name: 'Take off shelves' } )
		);
		expect(
			await screen.findByRole( 'dialog', { name: 'Take off shelves' } )
		).toBeTruthy();
	} );

	it( 'groups them the same way in the selection pane', async () => {
		await renderTab();
		fireEvent.click( rowOf( 'Pots' ) );
		fireEvent.click( rowOf( 'Garden' ), { ctrlKey: true } );
		const pane = await screen.findByRole( 'region', {
			name: '2 categories selected',
		} );
		expect(
			within( pane ).queryByRole( 'button', { name: 'Put on shelves' } )
		).toBeNull();
		await chooseFrom( pane, 'Shelving add-on…', 'Set a shelf note' );
		expect(
			await screen.findByRole( 'dialog', { name: 'Set a shelf note' } )
		).toBeTruthy();
	} );

	it( 'groups them in the tree row menu, each group opening its own list', async () => {
		await renderTab();
		act( () => item( 'Garden' ).focus() );
		fireEvent.keyDown( item( 'Garden' ), { key: 'F10', shiftKey: true } );
		const menu = await screen.findByRole( 'menu', {
			name: 'Actions for Garden',
		} );
		const names = within( menu )
			.getAllByRole( 'menuitem' )
			.map( ( each ) => each.textContent );
		expect( names ).toEqual(
			expect.arrayContaining( [
				'Rename',
				'Move to…',
				'Sample shelves…',
				'Shelving add-on…',
			] )
		);
		expect( names ).not.toContain( 'Put on shelves' );
		const group = within( menu ).getByRole( 'menuitem', {
			name: 'Sample shelves…',
		} );
		expect( group.getAttribute( 'aria-haspopup' ) ).toBe( 'menu' );
		fireEvent.click( group );
		const sub = await screen.findByRole( 'menu', {
			name: 'Sample shelves',
		} );
		const subItems = within( sub ).getAllByRole( 'menuitem' );
		expect( subItems.map( ( each ) => each.textContent ) ).toEqual( [
			'Back to all actions',
			'Put on shelves',
			'Take off shelves',
		] );
		expect( document.activeElement ).toBe( subItems[ 1 ] );
		// Escape goes back one level, to the group's own item.
		fireEvent.keyDown( document.activeElement, { key: 'Escape' } );
		const again = await screen.findByRole( 'menu', {
			name: 'Actions for Garden',
		} );
		expect( document.activeElement ).toBe(
			within( again ).getByRole( 'menuitem', { name: 'Sample shelves…' } )
		);
		fireEvent.keyDown( document.activeElement, { key: 'ArrowRight' } );
		fireEvent.click(
			within(
				await screen.findByRole( 'menu', { name: 'Sample shelves' } )
			).getByRole( 'menuitem', { name: 'Take off shelves' } )
		);
		expect(
			await screen.findByRole( 'dialog', { name: 'Take off shelves' } )
		).toBeTruthy();
	} );
} );
