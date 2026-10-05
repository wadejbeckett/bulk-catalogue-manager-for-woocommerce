/**
 * Plain words for the history screen (SPEC §4.6, §4.8): what a batch did,
 * its outcome, its reversibility state, how a journalled value reads and
 * when something happened. Everything here is a pure function of the
 * routes' own fields and GET /fields, so the panels and their tests share it.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * Text with its HTML entities decoded, without parsing it as markup.
 *
 * @param {string} text Stored text.
 * @return {string} Text as a person reads it.
 */
export function decodeEntities( text ) {
	const named = {
		amp: '&',
		lt: '<',
		gt: '>',
		quot: '"',
		apos: "'",
		nbsp: '\u00a0',
	};
	return String( text ).replace(
		/&(#x[0-9a-f]+|#[0-9]+|[a-z]+);/gi,
		( whole, code ) => {
			if ( code[ 0 ] === '#' ) {
				const number =
					code[ 1 ].toLowerCase() === 'x'
						? parseInt( code.slice( 2 ), 16 )
						: parseInt( code.slice( 1 ), 10 );
				return Number.isFinite( number ) && number > 0
					? String.fromCodePoint( number )
					: whole;
			}
			return named[ code.toLowerCase() ] ?? whole;
		}
	);
}

/**
 * A change row's before or after as a person reads it: the route's own
 * words where it gave them (category names, paths, images, display types;
 * ticket 133), else the value formatted by its field.
 *
 * @param {Object} row   Change row.
 * @param {string} which `old` or `new`.
 * @param {Object} field The field's descriptor, if known.
 * @return {string} Text.
 */
export function rowValue( row, which, field ) {
	const text = row?.[ which + '_text' ];
	if ( typeof text === 'string' && text !== '' ) {
		return text;
	}
	return formatValue( row?.[ which + '_value' ], field );
}

/**
 * The heading of a changes table's first column: Category when every row
 * is a category, Product when none is, else Product or category.
 *
 * @param {Array<Object>} rows Change rows.
 * @return {string} Heading.
 */
export function objectHeading( rows ) {
	const terms = ( rows || [] ).filter(
		// A native category write (`term`) or an add-on's change to a category: anything not a product or variation.
		( row ) =>
			!! row.object_type &&
			! [ 'product', 'variation' ].includes( row.object_type )
	);
	if ( rows?.length && terms.length === rows.length ) {
		return __( 'Category', 'bulk-catalogue-manager-for-woocommerce' );
	}
	return terms.length
		? __( 'Product or category', 'bulk-catalogue-manager-for-woocommerce' )
		: __( 'Product', 'bulk-catalogue-manager-for-woocommerce' );
}

/** The reversibility states of GET /batches (Engine\Reversibility::STATES). */
export const STATES = {
	REVERSIBLE: 'reversible',
	PARTIAL: 'partially_reversible',
	IN_PROGRESS: 'in_progress',
	WITH_ERRORS: 'completed_with_errors',
	NOT_REVERSIBLE: 'not_reversible',
};

/** Batch statuses that are still being worked (Reversibility::ACTIVE). */
export const ACTIVE_STATUSES = [ 'queued', 'running', 'undoing' ];

/**
 * A field label as it reads inside a sentence: "Regular price" becomes
 * "regular price", while an initialism such as "SKU" stays as it is.
 *
 * @param {string} label The registry label.
 * @return {string} Label for mid-sentence use.
 */
export function inSentence( label ) {
	const text = String( label || '' );
	if ( text.length > 1 && text[ 1 ] === text[ 1 ].toLowerCase() ) {
		return text[ 0 ].toLowerCase() + text.slice( 1 );
	}
	return text;
}

/**
 * The registry's descriptors keyed by field key.
 *
 * @param {Array<Object>} fields The `fields` of GET /fields.
 * @return {Object} Descriptor by key.
 */
export function fieldsByKey( fields ) {
	const out = {};
	( fields || [] ).forEach( ( field ) => {
		out[ field.key ] = field;
	} );
	return out;
}

/**
 * A field's label from the registry, never its storage key: the route's
 * own `field_label` when it gave one, else the registry's label, else a
 * plain stand-in.
 *
 * @param {string} key   Field key.
 * @param {Object} byKey Descriptors by key.
 * @param {string} given The route's `field_label`, if any.
 * @return {string} Label.
 */
export function fieldLabel( key, byKey, given = '' ) {
	if ( given && given !== key ) {
		return given;
	}
	if ( byKey?.[ key ]?.label ) {
		return byKey[ key ].label;
	}
	return __( 'A field', 'bulk-catalogue-manager-for-woocommerce' );
}

/**
 * A journalled value as a person reads it: a choice by its label, a
 * yes/no switch in words, a list joined, and nothing as "Empty".
 *
 * @param {*}      value The value.
 * @param {Object} field The field's descriptor, if known.
 * @return {string} Text.
 */
export function formatValue( value, field ) {
	if ( typeof value === 'string' ) {
		// Ticket 133: stored names keep HTML entities ("&amp;"); a person reads the character.
		value = decodeEntities( value );
	}
	if ( value === null || value === undefined || value === '' ) {
		return __( 'Empty', 'bulk-catalogue-manager-for-woocommerce' );
	}
	if ( Array.isArray( value ) ) {
		return value.length
			? value.map( ( each ) => formatValue( each, field ) ).join( ', ' )
			: __( 'Empty', 'bulk-catalogue-manager-for-woocommerce' );
	}
	if ( field?.type === 'boolean' || typeof value === 'boolean' ) {
		const on = value === true || value === 'yes' || value === 1;
		return on
			? __( 'Yes', 'bulk-catalogue-manager-for-woocommerce' )
			: __( 'No', 'bulk-catalogue-manager-for-woocommerce' );
	}
	const choices = field?.choices || field?.dataviews?.elements || [];
	const choice = choices.find(
		( each ) => String( each.value ) === String( value )
	);
	if ( choice ) {
		return choice.label;
	}
	if ( typeof value === 'object' ) {
		return JSON.stringify( value );
	}
	return String( value );
}

/**
 * One operation in plain words, such as "Increased the regular price by 8%".
 *
 * @param {Object} operation `{ action, args }`.
 * @param {Object} byKey     Field descriptors by key.
 * @param {Object} actions   Action descriptors by id (GET /fields `actions`).
 * @return {string} Sentence.
 */
export function describeOperation( operation, byKey = {}, actions = {} ) {
	const args = operation?.args || {};
	const field = byKey[ args.field ];
	if ( operation?.action === 'set_value' && field?.nativeStatus ) {
		return sprintf(
			/* translators: %s: the status requested by an accepted legacy change, which may have restored a remembered status. */
			__(
				'Legacy Status change requested %s',
				'bulk-catalogue-manager-for-woocommerce'
			),
			formatValue( args.value, field )
		);
	}
	const label = inSentence( fieldLabel( args.field, byKey ) );
	switch ( operation?.action ) {
		case 'set_status':
		case 'set_value':
			return sprintf(
				/* translators: 1: a field, such as "regular price"; 2: the value it was set to. */
				__(
					'Set the %1$s to %2$s',
					'bulk-catalogue-manager-for-woocommerce'
				),
				label,
				formatValue( args.value, field )
			);
		case 'clear':
			return sprintf(
				/* translators: %s: a field, such as "sale price". */
				__(
					'Cleared the %s',
					'bulk-catalogue-manager-for-woocommerce'
				),
				label
			);
		case 'adjust': {
			const up = args.direction !== 'decrease';
			const amount = String( args.amount ?? '' );
			if ( args.by === 'percent' ) {
				return up
					? sprintf(
							/* translators: 1: a field, such as "regular price"; 2: a percentage without its sign, such as "8". */
							__(
								'Increased the %1$s by %2$s%%',
								'bulk-catalogue-manager-for-woocommerce'
							),
							label,
							amount
						)
					: sprintf(
							/* translators: 1: a field, such as "regular price"; 2: a percentage without its sign, such as "8". */
							__(
								'Decreased the %1$s by %2$s%%',
								'bulk-catalogue-manager-for-woocommerce'
							),
							label,
							amount
						);
			}
			return up
				? sprintf(
						/* translators: 1: a field, such as "regular price"; 2: an amount, such as "5.00". */
						__(
							'Increased the %1$s by %2$s',
							'bulk-catalogue-manager-for-woocommerce'
						),
						label,
						amount
					)
				: sprintf(
						/* translators: 1: a field, such as "regular price"; 2: an amount, such as "5.00". */
						__(
							'Decreased the %1$s by %2$s',
							'bulk-catalogue-manager-for-woocommerce'
						),
						label,
						amount
					);
		}
		case 'round':
			return sprintf(
				/* translators: %s: a field, such as "regular price". */
				__(
					'Rounded the %s',
					'bulk-catalogue-manager-for-woocommerce'
				),
				label
			);
		case 'copy_price':
			return Number( args.discount ) > 0
				? sprintf(
						/* translators: 1: a price field, such as "sale price"; 2: a percentage without its sign, such as "15". */
						__(
							'Copied the other price into the %1$s, then took %2$s%% off',
							'bulk-catalogue-manager-for-woocommerce'
						),
						label,
						String( args.discount )
					)
				: sprintf(
						/* translators: %s: a price field, such as "sale price". */
						__(
							'Copied the other price into the %s',
							'bulk-catalogue-manager-for-woocommerce'
						),
						label
					);
		case 'find_replace':
			return sprintf(
				/* translators: 1: the text found; 2: the text put in its place; 3: a field, such as "name". */
				__(
					'Replaced “%1$s” with “%2$s” in the %3$s',
					'bulk-catalogue-manager-for-woocommerce'
				),
				String( args.find ?? '' ),
				String( args.replace ?? '' ),
				label
			);
		case 'affix_sku':
			return args.position === 'suffix'
				? sprintf(
						/* translators: 1: the text added; 2: a field, such as "SKU". */
						__(
							'Added “%1$s” to the end of the %2$s',
							'bulk-catalogue-manager-for-woocommerce'
						),
						String( args.text ?? '' ),
						label
					)
				: sprintf(
						/* translators: 1: the text added; 2: a field, such as "SKU". */
						__(
							'Added “%1$s” to the start of the %2$s',
							'bulk-catalogue-manager-for-woocommerce'
						),
						String( args.text ?? '' ),
						label
					);
		case 'add_terms':
			return sprintf(
				/* translators: %s: a field, such as "categories". */
				__( 'Added %s', 'bulk-catalogue-manager-for-woocommerce' ),
				label
			);
		case 'remove_terms':
			return sprintf(
				/* translators: %s: a field, such as "categories". */
				__( 'Removed %s', 'bulk-catalogue-manager-for-woocommerce' ),
				label
			);
		case 'replace_terms':
			return sprintf(
				/* translators: %s: a field, such as "categories". */
				__(
					'Replaced the %s',
					'bulk-catalogue-manager-for-woocommerce'
				),
				label
			);
		default: {
			const action = actions[ operation?.action ];
			if (
				action?.label &&
				label &&
				String( action.label )
					.toLowerCase()
					.includes( String( label ).toLowerCase() )
			) {
				// 1.3.4: "Add customer groups", never "Add customer groups: customer groups".
				return action.label;
			}
			return action?.label
				? sprintf(
						/* translators: 1: an action, such as "Round prices"; 2: a field, such as "regular price". */
						__(
							'%1$s: %2$s',
							'bulk-catalogue-manager-for-woocommerce'
						),
						action.label,
						label
					)
				: __(
						'Changed some products',
						'bulk-catalogue-manager-for-woocommerce'
					);
		}
	}
}

/**
 * What a batch did, in plain words: "Undo of #418" for an undo batch (the
 * route's own label), each operation described and joined for the rest.
 *
 * @param {Object} batch   A GET /batches row.
 * @param {Object} byKey   Field descriptors by key.
 * @param {Object} actions Action descriptors by id.
 * @return {string} Title.
 */
export function describeBatch( batch, byKey = {}, actions = {} ) {
	if ( batch?.source === 'undo' ) {
		return (
			batch.undo?.label ||
			sprintf(
				/* translators: %s: the number of the change undone, such as "418". */
				__( 'Undo of #%s', 'bulk-catalogue-manager-for-woocommerce' ),
				String( batch.undo_of ?? '' )
			)
		);
	}
	if ( batch?.headline ) {
		// Ticket 133: a category change in the server's words, with the categories' names.
		return batch.headline;
	}
	const operations = batch?.operations || [];
	// T108: an add-on's change keeps the labels its manifest recorded, used where the registry has none (the add-on has gone).
	const kept = batch?.external || {};
	const keys = { ...byKey };
	Object.entries( kept.fields || {} ).forEach( ( [ key, label ] ) => {
		keys[ key ] = keys[ key ] || { key, label };
	} );
	const known = { ...actions };
	Object.entries( kept.actions || {} ).forEach( ( [ id, label ] ) => {
		known[ id ] = known[ id ] || { id, label };
	} );
	if ( ! operations.length ) {
		return batch?.source === 'tree'
			? __(
					'Changed a category',
					'bulk-catalogue-manager-for-woocommerce'
				)
			: __(
					'Changed some products',
					'bulk-catalogue-manager-for-woocommerce'
				);
	}
	return operations
		.map( ( operation ) => describeOperation( operation, keys, known ) )
		.join( ' · ' );
}

/**
 * Where a batch came from, in words.
 *
 * @param {string} source bulk, inline, tree or undo.
 * @return {string} Words.
 */
export function sourceLabel( source ) {
	switch ( source ) {
		case 'inline':
			return __(
				'Edited in the grid',
				'bulk-catalogue-manager-for-woocommerce'
			);
		case 'tree':
			return __(
				'Category manager',
				'bulk-catalogue-manager-for-woocommerce'
			);
		case 'undo':
			return __( 'Undo', 'bulk-catalogue-manager-for-woocommerce' );
		default:
			return __(
				'Bulk change',
				'bulk-catalogue-manager-for-woocommerce'
			);
	}
}

/**
 * The batch statuses, each with its plain label, for the outcome filter and chip.
 *
 * @return {Array<{value: string, label: string}>} Statuses.
 */
export function statusElements() {
	return [
		{
			value: 'queued',
			label: __(
				'Waiting to start',
				'bulk-catalogue-manager-for-woocommerce'
			),
		},
		{
			value: 'running',
			label: __( 'Running', 'bulk-catalogue-manager-for-woocommerce' ),
		},
		{
			value: 'complete',
			label: __( 'Done', 'bulk-catalogue-manager-for-woocommerce' ),
		},
		{
			value: 'partial',
			// Rows skipped or failed: a card says which (outcomeLabel()).
			label: __(
				'Done with skipped or failed rows',
				'bulk-catalogue-manager-for-woocommerce'
			),
		},
		{
			value: 'failed',
			label: __( 'Failed', 'bulk-catalogue-manager-for-woocommerce' ),
		},
		{
			value: 'cancelled',
			label: __( 'Cancelled', 'bulk-catalogue-manager-for-woocommerce' ),
		},
		{
			value: 'undoing',
			label: __(
				'Being undone',
				'bulk-catalogue-manager-for-woocommerce'
			),
		},
		{
			value: 'undone',
			label: __( 'Undone', 'bulk-catalogue-manager-for-woocommerce' ),
		},
	];
}

/**
 * A status's label.
 *
 * @param {string} status Batch status.
 * @return {string} Label.
 */
export function statusLabel( status ) {
	const found = statusElements().find( ( each ) => each.value === status );
	return found ? found.label : String( status || '' );
}

/**
 * Whether a finished batch's only problems were rows skipped by design (a
 * step the preview said would be skipped: not applicable, already at the
 * value): it ended partial with nothing failed (1.3.4). Such a change has
 * no errors (Engine\Reversibility::with_errors()).
 *
 * @param {Object} batch Batch report.
 * @return {boolean} Skipped rows only.
 */
export function skippedOnly( batch ) {
	return (
		batch?.status === 'partial' && ! ( Number( batch?.failed_items ) > 0 )
	);
}

/**
 * A batch's outcome, in words: its status's label, except that a partial
 * batch says whether rows failed or were only skipped.
 *
 * @param {Object} batch Batch report.
 * @return {string} Label.
 */
export function outcomeLabel( batch ) {
	if ( batch?.status === 'partial' ) {
		return skippedOnly( batch )
			? __(
					'Done, some rows skipped',
					'bulk-catalogue-manager-for-woocommerce'
				)
			: __(
					'Done with errors',
					'bulk-catalogue-manager-for-woocommerce'
				);
	}
	return statusLabel( batch?.status );
}

/**
 * Why a batch can no longer be undone, as one plain sentence (§4.8).
 *
 * @param {string|null} reason   Reversibility reason.
 * @param {string}      provider For `provider_missing` and `provider_unavailable` (T108), the add-on's name.
 * @param {string}      detail   For `provider_unavailable`, the add-on's own reason, plain text.
 * @return {string} Sentence.
 */
export function reasonSentence( reason, provider = '', detail = '' ) {
	switch ( reason ) {
		case 'provider_unavailable': {
			// Registered but cannot run: its own reason follows the core's words.
			const lead = sprintf(
				/* translators: %s: the name of an add-on, such as "Example add-on". */
				__(
					'%s cannot run now, so this change cannot be undone yet.',
					'bulk-catalogue-manager-for-woocommerce'
				),
				provider ||
					__(
						'The add-on that made this change',
						'bulk-catalogue-manager-for-woocommerce'
					)
			);
			return detail ? lead + ' ' + detail : lead;
		}
		case 'provider_missing':
			return provider
				? sprintf(
						/* translators: %s: the name of an add-on, such as "Example add-on". */
						__(
							'Needs %s to be active to undo.',
							'bulk-catalogue-manager-for-woocommerce'
						),
						provider
					)
				: __(
						'Needs the add-on that made this change to be active to undo.',
						'bulk-catalogue-manager-for-woocommerce'
					);
		case 'pruned':
			return __(
				'The saved values for this change were removed when old history was cleared, so it cannot be undone.',
				'bulk-catalogue-manager-for-woocommerce'
			);
		case 'already_undone':
			return __(
				'This change has already been undone.',
				'bulk-catalogue-manager-for-woocommerce'
			);
		case 'outside_retention':
			return __(
				'This change is older than the history is kept for, so it can no longer be undone.',
				'bulk-catalogue-manager-for-woocommerce'
			);
		case 'nothing_applied':
			return __(
				'Nothing was changed by this batch, so there is nothing to undo.',
				'bulk-catalogue-manager-for-woocommerce'
			);
		default:
			return __(
				'This change can no longer be undone.',
				'bulk-catalogue-manager-for-woocommerce'
			);
	}
}

/**
 * How many rows, as a chip: "1,248 items". The batch's count holds products
 * and variations together, and the list does not say how many of each, so
 * it never calls them all products (ticket 11).
 *
 * @param {number} count Items.
 * @return {string} Text.
 */
export function rowsLabel( count ) {
	const n = Number( count ) || 0;
	return sprintf(
		/* translators: %s: a number of products and variations together, such as "1,284". */
		_n(
			'%s item',
			'%s items',
			n,
			'bulk-catalogue-manager-for-woocommerce'
		),
		n.toLocaleString()
	);
}

/**
 * A GMT `Y-m-d H:i:s` time as a Date.
 *
 * @param {string} gmt Time from a route.
 * @return {Date|null} Date, or null when absent or unreadable.
 */
export function parseGmt( gmt ) {
	if ( ! gmt ) {
		return null;
	}
	const date = new Date( String( gmt ).replace( ' ', 'T' ) + 'Z' );
	return Number.isNaN( date.getTime() ) ? null : date;
}

/**
 * A GMT time in the viewer's locale: "23 Sept 2026, 14:32".
 *
 * @param {string} gmt Time from a route.
 * @return {string} Text.
 */
export function formatWhen( gmt ) {
	const date = parseGmt( gmt );
	if ( ! date ) {
		return '';
	}
	return date.toLocaleString( undefined, {
		dateStyle: 'medium',
		timeStyle: 'short',
	} );
}

/**
 * How long ago, in the viewer's language: "2 hours ago".
 *
 * @param {string} gmt Time from a route.
 * @param {number} now Current time in ms.
 * @return {string} Text.
 */
export function formatAgo( gmt, now = Date.now() ) {
	const date = parseGmt( gmt );
	if ( ! date || typeof Intl?.RelativeTimeFormat !== 'function' ) {
		return '';
	}
	const seconds = Math.round( ( date.getTime() - now ) / 1000 );
	const format = new Intl.RelativeTimeFormat( undefined, {
		numeric: 'auto',
	} );
	const steps = [
		[ 60, 'second' ],
		[ 60, 'minute' ],
		[ 24, 'hour' ],
		[ 30, 'day' ],
		[ 12, 'month' ],
	];
	let value = seconds;
	for ( const [ size, unit ] of steps ) {
		if ( Math.abs( value ) < size ) {
			return format.format( value, unit );
		}
		value = Math.round( value / size );
	}
	return format.format( value, 'year' );
}

/**
 * A local calendar date or time as the routes' filter form: ISO 8601 in UTC.
 *
 * @param {Date} date Date.
 * @return {string} `YYYY-MM-DDTHH:MM:SSZ`.
 */
export function toIsoUtc( date ) {
	return date.toISOString().replace( /\.\d{3}Z$/, 'Z' );
}

/**
 * The start of a local calendar day (`YYYY-MM-DD`), optionally some days on.
 *
 * @param {string} day  Date.
 * @param {number} plus Days to add.
 * @return {Date|null} Local midnight, or null when unreadable.
 */
export function localDay( day, plus = 0 ) {
	const match = /^(\d{4})-(\d{2})-(\d{2})/.exec( String( day || '' ) );
	if ( ! match ) {
		return null;
	}
	return new Date(
		Number( match[ 1 ] ),
		Number( match[ 2 ] ) - 1,
		Number( match[ 3 ] ) + plus
	);
}

/**
 * A DataViews date filter as the routes' `after` and `before` arguments.
 * Supports the operators the history screen offers: in the past, between
 * (inclusive), on, before and after.
 *
 * @param {Object} filter DataViews filter `{ operator, value }`.
 * @param {number} now    Current time in ms.
 * @return {{after?: string, before?: string}} Query arguments.
 */
export function dateRange( filter, now = Date.now() ) {
	if ( ! filter || filter.value === undefined || filter.value === null ) {
		return {};
	}
	const { operator, value } = filter;
	const out = {};
	const set = ( key, date ) => {
		if ( date ) {
			out[ key ] = toIsoUtc( date );
		}
	};
	switch ( operator ) {
		case 'inThePast': {
			const amount = Number( value?.value );
			if ( ! amount ) {
				return {};
			}
			const days =
				{ days: 1, weeks: 7, months: 30, years: 365 }[ value.unit ] ||
				1;
			set( 'after', new Date( now - amount * days * 86400000 ) );
			break;
		}
		case 'between':
			if ( Array.isArray( value ) ) {
				set( 'after', localDay( value[ 0 ] ) );
				set( 'before', localDay( value[ 1 ], 1 ) );
			}
			break;
		case 'on':
			set( 'after', localDay( value ) );
			set( 'before', localDay( value, 1 ) );
			break;
		case 'before':
			set( 'before', localDay( value ) );
			break;
		case 'beforeInc':
			set( 'before', localDay( value, 1 ) );
			break;
		case 'after':
			set( 'after', localDay( value, 1 ) );
			break;
		case 'afterInc':
			set( 'after', localDay( value ) );
			break;
	}
	return out;
}

/** The date operators the history and audit filters offer. */
export const DATE_OPERATORS = [
	'inThePast',
	'between',
	'on',
	'before',
	'after',
];

/**
 * The single value of an "is" style filter, or undefined.
 *
 * @param {Object} filter DataViews filter.
 * @return {*} Value.
 */
export function singleValue( filter ) {
	if ( ! filter ) {
		return undefined;
	}
	const value = Array.isArray( filter.value )
		? filter.value[ 0 ]
		: filter.value;
	return value === '' || value === null ? undefined : value;
}
