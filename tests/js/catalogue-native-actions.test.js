import { rowActions } from '../../assets/src/panels/catalogue/actions';

const product = () => ( {
	id: 11,
	native_actions: {
		details: true,
		editor: { url: '/wp-admin/post.php?post=11&action=edit' },
		view: { url: '/product/item/' },
		duplicate: { url: '/native-duplicate-11' },
		trash: { url: '/native-trash-11', confirm: '' },
		unavailable: '',
	},
} );

it( 'keeps native order and navigates exactly one row without replay', () => {
	const navigate = jest.fn();
	const openNewTab = jest.fn();
	const row = product();
	const actions = rowActions( { navigate, openNewTab } );
	expect(
		actions
			.filter( ( a ) => ! a.isEligible || a.isEligible( row ) )
			.map( ( a ) => a.id )
	).toEqual( [
		'edit-details',
		'open-in-editor',
		'view-product',
		'duplicate-product',
		'trash-product',
	] );
	const duplicate = actions.find( ( a ) => a.id === 'duplicate-product' );
	duplicate.callback( [ row, { ...row, id: 12 } ] );
	expect( navigate ).not.toHaveBeenCalled();
	duplicate.callback( [ row ] );
	duplicate.callback( [ row ] );
	expect( navigate ).toHaveBeenCalledTimes( 1 );
	expect( navigate ).toHaveBeenCalledWith( '/native-duplicate-11' );
	actions.find( ( a ) => a.id === 'view-product' ).callback( [ row ] );
	expect( openNewTab ).toHaveBeenCalledWith( '/product/item/' );
	expect( actions.every( ( a ) => a.supportsBulk === false ) ).toBe( true );
} );

it( 'keeps bulk selection and details separate; variations offer no parent navigation', () => {
	const onMakeChange = jest.fn();
	const onEditDetails = jest.fn();
	const navigate = jest.fn();
	const actions = rowActions( { onMakeChange, onEditDetails, navigate } );
	const variation = {
		id: 21,
		native_actions: {
			details: true,
			unavailable: 'Use Edit details for variations.',
		},
	};
	actions.find( ( a ) => a.id === 'edit-details' ).callback( [ variation ] );
	expect( onEditDetails ).toHaveBeenCalledWith( variation );
	for ( const id of [
		'open-in-editor',
		'view-product',
		'duplicate-product',
		'trash-product',
	] ) {
		const action = actions.find( ( a ) => a.id === id );
		expect( action.isEligible( variation ) ).toBe( false );
		action.callback( [ variation ] );
	}
	expect( navigate ).not.toHaveBeenCalled();
	expect( onMakeChange ).not.toHaveBeenCalled();
} );

it( 'cancelled sold warning performs no navigation, while fresh confirmed action can proceed', () => {
	const navigate = jest.fn();
	const confirmNative = jest
		.fn()
		.mockReturnValueOnce( false )
		.mockReturnValueOnce( true );
	const row = product();
	row.native_actions.trash.confirm = 'Native sales warning';
	const trash = rowActions( { navigate, confirmNative } ).find(
		( a ) => a.id === 'trash-product'
	);
	trash.callback( [ row ] );
	expect( navigate ).not.toHaveBeenCalled();
	trash.callback( [ row ] );
	expect( confirmNative ).toHaveBeenCalledWith( 'Native sales warning' );
	expect( navigate ).toHaveBeenCalledWith( '/native-trash-11' );
} );

it( 'missing destinations never fall back to a constructed parent/editor URL', () => {
	const navigate = jest.fn();
	const actions = rowActions( { navigate } );
	for ( const action of actions.filter( ( a ) => a.id !== 'edit-details' ) ) {
		expect( action.isEligible( { id: 11 } ) ).toBe( false );
		action.callback?.( [ { id: 11 } ] );
	}
	expect( navigate ).not.toHaveBeenCalled();
} );
