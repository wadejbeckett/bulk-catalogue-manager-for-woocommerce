/**
 * Filter chips (T41, SPEC §4.1): every active filter as a sentence such as
 * "Stock quantity is 0 or less" or "Category is Shoes or its
 * sub-categories", built from the registry's labels and choice labels, each
 * with a button that clears that filter alone, and Clear filters.
 *
 * A chip is built from the same `filterArg()` the query uses, so a chip is
 * shown exactly when its filter narrows the rows and says what is sent.
 */
import { Button } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';

import { formatNumber, listPhrase } from '../../bulk/words';
import { TREE_TERM_SOURCES } from '../defaults';
import { filterArg, filterKind } from './filters';

/**
 * A list read as alternatives, "Draft or Pending review", in the reader's
 * language where the browser knows how.
 *
 * @param {Array<string>} items Names.
 * @return {string} The list.
 */
export function orPhrase( items ) {
	try {
		const lang =
			typeof document !== 'undefined' && document.documentElement.lang;
		return new Intl.ListFormat( lang || undefined, {
			style: 'long',
			type: 'disjunction',
		} ).format( items );
	} catch {
		return items.join( ', ' );
	}
}

/**
 * A date bound as the reader writes dates, the time left out.
 *
 * @param {string} value `Y-m-d` or `Y-m-d H:i:s`, GMT.
 * @return {string} Date.
 */
export function datePhrase( value ) {
	const time = new Date( `${ value.slice( 0, 10 ) }T00:00:00Z` );
	if ( Number.isNaN( time.getTime() ) ) {
		return value;
	}
	try {
		const lang =
			typeof document !== 'undefined' && document.documentElement.lang;
		return new Intl.DateTimeFormat( lang || undefined, {
			day: 'numeric',
			month: 'long',
			year: 'numeric',
			timeZone: 'UTC',
		} ).format( time );
	} catch {
		return value.slice( 0, 10 );
	}
}

/**
 * "7 days", "1 week": an "in the past" span.
 *
 * @param {number} count Count.
 * @param {string} unit  days, weeks, months or years.
 * @return {string} Span.
 */
export function spanPhrase( count, unit ) {
	const number = formatNumber( count );
	switch ( unit ) {
		case 'weeks':
			return sprintf(
				/* translators: %s: a number of weeks, such as "2". */
				_n(
					'%s week',
					'%s weeks',
					count,
					'bulk-catalogue-manager-for-woocommerce'
				),
				number
			);
		case 'months':
			return sprintf(
				/* translators: %s: a number of months, such as "3". */
				_n(
					'%s month',
					'%s months',
					count,
					'bulk-catalogue-manager-for-woocommerce'
				),
				number
			);
		case 'years':
			return sprintf(
				/* translators: %s: a number of years, such as "1". */
				_n(
					'%s year',
					'%s years',
					count,
					'bulk-catalogue-manager-for-woocommerce'
				),
				number
			);
		default:
			return sprintf(
				/* translators: %s: a number of days, such as "7". */
				_n(
					'%s day',
					'%s days',
					count,
					'bulk-catalogue-manager-for-woocommerce'
				),
				number
			);
	}
}

/**
 * The names of a filter's chosen values: choice labels from the registry,
 * term names from `termNames`, the value itself otherwise.
 *
 * @param {Object}   descriptor Registry descriptor.
 * @param {Array<*>} values     Chosen values.
 * @param {Object}   termNames  source => Map( id => name ).
 * @return {Array<string>} Names.
 */
function valueNames( descriptor, values, termNames ) {
	const elements = descriptor.dataviews?.elements || [];
	const names = termNames?.[ descriptor.source ];
	return values.map( ( value ) => {
		if ( descriptor.source ) {
			return names instanceof Map && names.has( Number( value ) )
				? names.get( Number( value ) )
				: formatNumber( value );
		}
		const element = elements.find(
			( each ) => String( each.value ) === String( value )
		);
		return element ? element.label : String( value );
	} );
}

/**
 * The sentence for a list filter (a choice or terms).
 *
 * @param {Object}        descriptor Registry descriptor.
 * @param {string}        operator   isAny, isNone or isAll.
 * @param {Array<string>} names      Chosen values' names.
 * @return {string} Sentence.
 */
function listSentence( descriptor, operator, names ) {
	const label = descriptor.label;
	if ( TREE_TERM_SOURCES.includes( descriptor.source ) ) {
		const several = names.length > 1;
		if ( operator === 'isNone' ) {
			if ( several ) {
				return sprintf(
					/* translators: %s: several category names, such as "Shoes or Hats". */
					__(
						'Category is not %s, or their sub-categories',
						'bulk-catalogue-manager-for-woocommerce'
					),
					orPhrase( names )
				);
			}
			return sprintf(
				/* translators: %s: a category name, such as "Shoes". */
				__(
					'Category is not %s or its sub-categories',
					'bulk-catalogue-manager-for-woocommerce'
				),
				orPhrase( names )
			);
		}
		if ( operator === 'isAll' ) {
			return sprintf(
				/* translators: %s: category names, such as "Shoes and Sale". */
				__(
					'In every one of %s, or their sub-categories',
					'bulk-catalogue-manager-for-woocommerce'
				),
				listPhrase( names )
			);
		}
		if ( several ) {
			return sprintf(
				/* translators: %s: several category names, such as "Shoes or Hats". */
				__(
					'Category is %s, or their sub-categories',
					'bulk-catalogue-manager-for-woocommerce'
				),
				orPhrase( names )
			);
		}
		return sprintf(
			/* translators: %s: a category name, such as "Shoes". */
			__(
				'Category is %s or its sub-categories',
				'bulk-catalogue-manager-for-woocommerce'
			),
			orPhrase( names )
		);
	}
	if ( operator === 'isNone' ) {
		return sprintf(
			/* translators: 1: a field such as "Status", 2: one or more values, such as "Draft or Pending review". */
			__( '%1$s is not %2$s', 'bulk-catalogue-manager-for-woocommerce' ),
			label,
			orPhrase( names )
		);
	}
	if ( operator === 'isAll' ) {
		return sprintf(
			/* translators: 1: a field such as "Tags", 2: values, such as "Summer and Sale". */
			__(
				'%1$s include all of %2$s',
				'bulk-catalogue-manager-for-woocommerce'
			),
			label,
			listPhrase( names )
		);
	}
	return sprintf(
		/* translators: 1: a field such as "Status", 2: one or more values, such as "Draft or Pending review". */
		__( '%1$s is %2$s', 'bulk-catalogue-manager-for-woocommerce' ),
		label,
		orPhrase( names )
	);
}

/**
 * The sentence for one complete filter argument.
 *
 * @param {Object} descriptor Registry descriptor.
 * @param {Object} arg        `{ operator, value }` from filterArg().
 * @param {Object} termNames  source => Map( id => name ).
 * @return {string} Sentence.
 */
export function chipSentence( descriptor, arg, termNames = {} ) {
	const label = descriptor.label;
	const { operator, value } = arg;
	switch ( filterKind( descriptor ) ) {
		case 'terms':
		case 'choice':
			return listSentence(
				descriptor,
				operator,
				valueNames( descriptor, value, termNames )
			);
		case 'boolean':
			if ( value ) {
				return sprintf(
					/* translators: %s: a field such as "Featured". */
					__( '%s is on', 'bulk-catalogue-manager-for-woocommerce' ),
					label
				);
			}
			return sprintf(
				/* translators: %s: a field such as "Featured". */
				__( '%s is off', 'bulk-catalogue-manager-for-woocommerce' ),
				label
			);
		case 'range':
			if ( operator === 'between' ) {
				return sprintf(
					/* translators: 1: a field such as "Regular price", 2: the lower bound, 3: the upper bound. */
					__(
						'%1$s is between %2$s and %3$s',
						'bulk-catalogue-manager-for-woocommerce'
					),
					label,
					String( value[ 0 ] ),
					String( value[ 1 ] )
				);
			}
			if ( operator === 'lessThanOrEqual' ) {
				return sprintf(
					/* translators: 1: a field such as "Stock quantity", 2: a number, such as "0". */
					__(
						'%1$s is %2$s or less',
						'bulk-catalogue-manager-for-woocommerce'
					),
					label,
					String( value )
				);
			}
			return sprintf(
				/* translators: 1: a field such as "Stock quantity", 2: a number, such as "10". */
				__(
					'%1$s is %2$s or more',
					'bulk-catalogue-manager-for-woocommerce'
				),
				label,
				String( value )
			);
		case 'date':
			if ( operator === 'inThePast' ) {
				return sprintf(
					/* translators: 1: a date field such as "Last modified", 2: a span, such as "7 days". */
					__(
						'%1$s is in the last %2$s',
						'bulk-catalogue-manager-for-woocommerce'
					),
					label,
					spanPhrase( value.value, value.unit )
				);
			}
			if ( operator === 'before' ) {
				return sprintf(
					/* translators: 1: a date field such as "Last modified", 2: a date. */
					__(
						'%1$s is before %2$s',
						'bulk-catalogue-manager-for-woocommerce'
					),
					label,
					datePhrase( value )
				);
			}
			return sprintf(
				/* translators: 1: a date field such as "Last modified", 2: a date. */
				__(
					'%1$s is after %2$s',
					'bulk-catalogue-manager-for-woocommerce'
				),
				label,
				datePhrase( value )
			);
		default:
			return label;
	}
}

/**
 * The chips for a view: the search first, then each complete filter in the
 * view's order. Each chip's `clear( view )` returns the view without it,
 * back on page 1.
 *
 * @param {Object}        view        DataViews view.
 * @param {Array<Object>} descriptors Registry descriptors.
 * @param {Object}        termNames   source => Map( id => name ).
 * @return {Array<Object>} `{ id, label, sentence, clear }`.
 */
export function filterChips( view, descriptors, termNames = {} ) {
	const chips = [];
	const search = ( view.search || '' ).trim();
	if ( search ) {
		chips.push( {
			id: 'search',
			label: __( 'Search', 'bulk-catalogue-manager-for-woocommerce' ),
			sentence: sprintf(
				/* translators: %s: the text searched for. */
				__(
					'Name or SKU contains “%s”',
					'bulk-catalogue-manager-for-woocommerce'
				),
				search
			),
			clear: ( current ) => ( { ...current, search: '', page: 1 } ),
		} );
	}
	( view.filters || [] ).forEach( ( filter ) => {
		const descriptor = descriptors.find(
			( each ) => each.key === filter.field
		);
		const arg = filterArg( filter, descriptor );
		if ( ! arg ) {
			return;
		}
		chips.push( {
			id: filter.field,
			label: descriptor.label,
			sentence: chipSentence( descriptor, arg, termNames ),
			clear: ( current ) => ( {
				...current,
				page: 1,
				filters: ( current.filters || [] ).filter(
					( each ) => each.field !== filter.field
				),
			} ),
		} );
	} );
	return chips;
}

/**
 * The chip row: one chip per active filter, each clearing itself, and Clear
 * filters. Nothing renders when nothing filters.
 *
 * The search chip's button is **Clear search** (T84, SPEC §4.1): with
 * `onClearSearch` it is the deliberate control that clears the search and
 * the selection, unlike editing the search box, even through empty text.
 *
 * @param {Object}        props               Props.
 * @param {Array<Object>} props.chips         filterChips().
 * @param {Function}      props.onChange      Receives an updater `( view ) => view`.
 * @param {Function}      props.onClearAll    Clear every filter and the search.
 * @param {Function}      props.onClearSearch Clear search: the search and the selection.
 * @return {Element|null} Chips.
 */
export function FilterChips( {
	chips,
	onChange,
	onClearAll,
	onClearSearch = null,
} ) {
	if ( ! chips.length ) {
		return null;
	}
	return (
		<div className="bulkcat-chips">
			<ul
				className="bulkcat-chips__list"
				aria-label={ __(
					'Active filters',
					'bulk-catalogue-manager-for-woocommerce'
				) }
			>
				{ chips.map( ( chip ) => (
					<li key={ chip.id } className="bulkcat-chips__chip">
						<span>{ chip.sentence }</span>
						{ chip.id === 'search' && onClearSearch ? (
							<Button
								size="small"
								icon="no-alt"
								className="bulkcat-chips__clear"
								label={ __(
									'Clear search',
									'bulk-catalogue-manager-for-woocommerce'
								) }
								showTooltip
								onClick={ onClearSearch }
							/>
						) : (
							<Button
								size="small"
								icon="no-alt"
								className="bulkcat-chips__clear"
								label={ sprintf(
									/* translators: %s: a filter as a sentence, such as "Stock quantity is 0 or less". */
									__(
										'Clear the filter “%s”',
										'bulk-catalogue-manager-for-woocommerce'
									),
									chip.sentence
								) }
								onClick={ () => onChange( chip.clear ) }
							/>
						) }
					</li>
				) ) }
			</ul>
			<Button variant="link" onClick={ onClearAll }>
				{ __(
					'Clear filters',
					'bulk-catalogue-manager-for-woocommerce'
				) }
			</Button>
		</div>
	);
}
