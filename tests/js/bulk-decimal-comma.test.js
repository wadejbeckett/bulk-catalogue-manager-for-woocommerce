/**
 * FRONTEND-10: a bulk step's number takes one decimal comma, as an inline
 * edit does ("2,5" is 2.5), and is sent with a point.
 */
import {
	operationProblem,
	toRequestOperations,
} from '../../assets/src/panels/bulk/descriptors';

const REGISTRY = require( '../Unit/Fields/fields.snapshot.json' );

const adjust = ( amount ) => ( {
	action: 'adjust',
	args: {
		field: 'regular_price',
		direction: 'increase',
		by: 'percent',
		amount,
		skip_already: true,
	},
} );

it( 'accepts a decimal comma in a step’s number and sends a point', () => {
	expect( operationProblem( REGISTRY, adjust( '2,5' ) ) ).toBeNull();
	expect(
		toRequestOperations( REGISTRY, [ adjust( '2,5' ) ] )[ 0 ].args.amount
	).toBe( '2.5' );
} );

it( 'still refuses what is not one number', () => {
	expect( operationProblem( REGISTRY, adjust( '2,5,1' ) )?.arg ).toBe(
		'amount'
	);
	expect( operationProblem( REGISTRY, adjust( '1.000,5' ) )?.arg ).toBe(
		'amount'
	);
	expect(
		toRequestOperations( REGISTRY, [ adjust( '10' ) ] )[ 0 ].args.amount
	).toBe( '10' );
} );
