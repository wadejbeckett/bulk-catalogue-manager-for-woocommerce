/**
 * What a cell may do on its row (SPEC §5.2, §8, T68): whether it is the
 * row's own editable value, or read-only with a reason — a variable parent's
 * value worked out from its variations (a range, a stock summary, or "Mixed"
 * where they disagree), a parent-only field on a variation row (shown with
 * the product's value, greyed), or a conditional field that does not apply
 * to that variation. The inline editor (T46) asks cellState() before opening
 * and opens nothing when `editable` is false.
 */
import { __, sprintf } from '@wordpress/i18n';

import { choiceLabel } from '../cells';
import { COMPANIONS } from '../defaults';
import { KINDS, metaOf, shownOf } from './model';
import { conditionOf, isStockSummary } from './registry';

/** Cell states. */
export const STATES = {
	own: 'own',
	derived: 'derived',
	range: 'range',
	summary: 'summary',
	mixed: 'mixed',
	parentOnly: 'parent_only',
	notApplicable: 'not_applicable',
};

const isBlank = ( value ) =>
	value === undefined || value === null || value === '';

/**
 * The reason a parent-only field is greyed on a variation row: the
 * registry's note for that field, or the general one.
 *
 * @param {Object} descriptor Descriptor.
 * @return {string} Sentence.
 */
export function parentOnlyReason( descriptor ) {
	return (
		descriptor.variationNote ||
		__(
			'Belongs to the product, not to each variation.',
			'bulk-catalogue-manager-for-woocommerce'
		)
	);
}

/**
 * The reason a variable parent's cell is worked out from its variations.
 *
 * @param {Object} descriptor Descriptor.
 * @return {string} Sentence.
 */
export function derivedReason( descriptor ) {
	return (
		descriptor.variableParentNote ||
		__(
			'This is worked out from the product’s variations. Change the variations instead.',
			'bulk-catalogue-manager-for-woocommerce'
		)
	);
}

/**
 * The reason a parent cell reads Mixed.
 *
 * @param {Object} descriptor Descriptor.
 * @param {string} detail     What differs, already a sentence, or ''.
 * @return {string} Sentence.
 */
function mixedReason( descriptor, detail ) {
	return [
		sprintf(
			/* translators: %s: a field's label, such as "Sale price". */
			__(
				'%s differs between this product’s variations, so there is no single value to show or change here.',
				'bulk-catalogue-manager-for-woocommerce'
			),
			descriptor.label
		),
		detail,
		derivedReason( descriptor ),
	]
		.filter( Boolean )
		.join( ' ' );
}

/**
 * A variable parent's stock cell from its loaded variations: one status
 * (with the managed quantities added up) or Mixed with the count per status.
 *
 * @param {Object}        descriptor The stock field.
 * @param {Array<Object>} children   Loaded variation rows.
 * @return {Object} State.
 */
function stockSummary( descriptor, children ) {
	const companion = COMPANIONS[ descriptor.key ];
	const counts = new Map();
	children.forEach( ( child ) => {
		const value = child[ descriptor.key ];
		counts.set( value, ( counts.get( value ) || 0 ) + 1 );
	} );
	const quantities = children
		.map( ( child ) => child[ companion ] )
		.filter( ( value ) => ! isBlank( value ) && ! isNaN( Number( value ) ) )
		.map( Number );
	if ( counts.size === 1 ) {
		const [ value ] = counts.keys();
		return {
			kind: STATES.summary,
			editable: false,
			value,
			quantity: quantities.length
				? quantities.reduce( ( a, b ) => a + b, 0 )
				: null,
			reason: derivedReason( descriptor ),
		};
	}
	const detail = sprintf(
		/* translators: %s: a list such as "5 In stock, 3 Out of stock". */
		__(
			'The variations are: %s.',
			'bulk-catalogue-manager-for-woocommerce'
		),
		[ ...counts.entries() ]
			.map( ( [ value, count ] ) =>
				sprintf(
					/* translators: 1: how many variations, 2: a stock status such as "In stock". */
					__( '%1$d %2$s', 'bulk-catalogue-manager-for-woocommerce' ),
					count,
					isBlank( value )
						? __(
								'not set',
								'bulk-catalogue-manager-for-woocommerce'
							)
						: choiceLabel( descriptor, value )
				)
			)
			.join( ', ' )
	);
	return {
		kind: STATES.mixed,
		editable: false,
		reason: mixedReason( descriptor, detail ),
	};
}

/**
 * A variable parent's derived value from its loaded variations: the one
 * value they share, a range when they all have a number, otherwise Mixed.
 *
 * @param {Object}        descriptor Descriptor.
 * @param {Array<Object>} children   Loaded variation rows.
 * @return {Object} State.
 */
function derivedValue( descriptor, children ) {
	const values = children.map( ( child ) => child[ descriptor.key ] );
	const distinct = new Set(
		values.map( ( value ) => String( value ?? '' ) )
	);
	if ( distinct.size <= 1 ) {
		return {
			kind: STATES.derived,
			editable: false,
			value: values[ 0 ],
			reason: derivedReason( descriptor ),
		};
	}
	const numbers = values.filter(
		( value ) => ! isBlank( value ) && ! isNaN( Number( value ) )
	);
	if (
		numbers.length === values.length &&
		[ 'money', 'decimal', 'integer' ].includes( descriptor.type )
	) {
		const sorted = [ ...numbers ].sort(
			( a, b ) => Number( a ) - Number( b )
		);
		return {
			kind: STATES.range,
			editable: false,
			min: sorted[ 0 ],
			max: sorted[ sorted.length - 1 ],
			reason: derivedReason( descriptor ),
		};
	}
	const set = values.filter( ( value ) => ! isBlank( value ) ).length;
	return {
		kind: STATES.mixed,
		editable: false,
		reason: mixedReason(
			descriptor,
			set && set < values.length
				? sprintf(
						/* translators: 1: how many variations have a value, 2: how many variations there are. */
						__(
							'%1$d of %2$d variations have one.',
							'bulk-catalogue-manager-for-woocommerce'
						),
						set,
						values.length
					)
				: ''
		),
	};
}

/**
 * The state of one cell.
 *
 * @param {Object}        row         Row as drawn (buildRows()).
 * @param {Object}        descriptor  Registry descriptor of the column.
 * @param {Array<Object>} descriptors Every registry descriptor.
 * @return {{kind: string, editable: boolean, reason?: string, value?: *, min?: *, max?: *, quantity?: number|null, parent?: Object|null}} State.
 */
export function cellState( row, descriptor, descriptors = [] ) {
	const meta = metaOf( row );
	const own = { kind: STATES.own, editable: ! descriptor.readOnly };
	if ( meta.kind === KINDS.variation ) {
		if ( descriptor.variations === 'parent_only' ) {
			return {
				kind: STATES.parentOnly,
				editable: false,
				reason: parentOnlyReason( descriptor ),
				parent: meta.parent || null,
			};
		}
		if ( descriptor.variations === 'derived' ) {
			return {
				kind: STATES.derived,
				editable: false,
				reason: descriptor.variationNote,
			};
		}
		const condition = conditionOf( descriptor, descriptors );
		if ( condition && row[ condition.key ] === false ) {
			return {
				kind: STATES.notApplicable,
				editable: false,
				reason: descriptor.variationNote,
			};
		}
		return own;
	}
	if ( meta.kind === KINDS.parent ) {
		const children = shownOf( meta.entry );
		const loaded = meta.entry?.status === 'loaded' && children.length > 0;
		if ( isStockSummary( descriptor ) ) {
			return loaded
				? stockSummary( descriptor, children )
				: {
						kind: STATES.summary,
						editable: false,
						value: row[ descriptor.key ],
						quantity: null,
						reason: derivedReason( descriptor ),
					};
		}
		if ( descriptor.variableParentNote ) {
			return loaded
				? derivedValue( descriptor, children )
				: {
						kind: STATES.derived,
						editable: false,
						value: undefined,
						reason: derivedReason( descriptor ),
					};
		}
	}
	return own;
}
