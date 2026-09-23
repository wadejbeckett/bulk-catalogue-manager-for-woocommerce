/**
 * Cell renderers chosen by the registry's value type, never by field key.
 * Every value renders as text (SPEC §13): the rendered price's markup is read
 * for its text only.
 */
import { createContext, useContext } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { ROW, TONES } from './defaults';

/**
 * What cells read from the table around them: term names per source and the
 * rows with a change in flight (pending and read-only, T46).
 */
export const CellContext = createContext( {
	termNames: {},
	pending: new Set(),
} );

const isEmpty = ( value ) =>
	value === undefined || value === null || value === '';

/**
 * The classic product editor for a row: a variation opens its product.
 *
 * @param {Object} item Row.
 * @return {string} Admin URL, relative to wp-admin.
 */
export function editUrl( item ) {
	const id = Number( item?.[ ROW.parentId ] ) || Number( item?.id );
	return `post.php?post=${ id }&action=edit`;
}

/**
 * The label of a choice value, from the registry's choices.
 *
 * @param {Object} descriptor Registry descriptor.
 * @param {*}      value      Value.
 * @return {string} Label, or the value itself when it is not a listed choice.
 */
export function choiceLabel( descriptor, value ) {
	const choices = descriptor.choices || descriptor.dataviews?.elements || [];
	const found = choices.find( ( choice ) => choice.value === value );
	return found ? found.label : String( value );
}

/**
 * The text of server-rendered price markup.
 *
 * @param {string} html Markup.
 * @return {string} Text.
 */
export function textOf( html ) {
	if ( isEmpty( html ) ) {
		return '';
	}
	const doc = new window.DOMParser().parseFromString(
		String( html ),
		'text/html'
	);
	// WooCommerce adds a screen-reader sentence to a price range ("Price
	// range: $10.00 through $69.00"); the cell shows what is on screen.
	doc.body
		.querySelectorAll( '.screen-reader-text' )
		.forEach( ( node ) => node.remove() );
	return ( doc.body.textContent || '' ).replace( /\s+/g, ' ' ).trim();
}

/**
 * A date in the viewer's locale.
 *
 * @param {string} value Date as the route gives it.
 * @return {string} Formatted date, or '' when there is none.
 */
export function formatDate( value ) {
	if ( isEmpty( value ) ) {
		return '';
	}
	const date = new Date( String( value ).replace( ' ', 'T' ) );
	return Number.isNaN( date.getTime() )
		? String( value )
		: date.toLocaleDateString( undefined, {
				year: 'numeric',
				month: 'short',
				day: 'numeric',
			} );
}

function Badge( { value, children } ) {
	const tone = TONES[ value ] || 'neutral';
	return <span className={ `bcm-badge is-${ tone }` }>{ children }</span>;
}

function Terms( { descriptor, value } ) {
	const { termNames } = useContext( CellContext );
	const ids = ( Array.isArray( value ) ? value : [ value ] ).filter(
		( id ) => ! isEmpty( id ) && Number( id ) > 0
	);
	if ( ! ids.length ) {
		return null;
	}
	const names = termNames[ descriptor.source ];
	if ( ! names ) {
		return (
			<span className="bcm-cell__muted">
				{ sprintf(
					/* translators: %d: how many terms (categories, tags or classes) the product has. */
					_n(
						'%d assigned',
						'%d assigned',
						ids.length,
						'bulk-catalogue-manager-for-woocommerce'
					),
					ids.length
				) }
			</span>
		);
	}
	return (
		<span className="bcm-terms">
			{ ids.map( ( id ) => (
				<span key={ id } className="bcm-badge is-term">
					{ names.get( Number( id ) ) ?? `#${ id }` }
				</span>
			) ) }
		</span>
	);
}

function Title( { item, descriptor } ) {
	const summary = item[ ROW.attributeSummary ];
	return (
		<span className="bcm-title">
			<a className="bcm-title__link" href={ editUrl( item ) }>
				{ isEmpty( item[ descriptor.key ] )
					? __(
							'(no name)',
							'bulk-catalogue-manager-for-woocommerce'
						)
					: String( item[ descriptor.key ] ) }
			</a>
			{ ! isEmpty( summary ) && (
				<span className="bcm-title__summary">{ summary }</span>
			) }
		</span>
	);
}

function Value( { item, descriptor, companion, role } ) {
	const value = item[ descriptor.key ];
	if ( role === 'title' ) {
		return <Title item={ item } descriptor={ descriptor } />;
	}
	if ( descriptor.type === 'image' ) {
		const src = item[ ROW.thumbnailUrl ];
		return src ? (
			<img
				className="bcm-thumb"
				src={ src }
				alt=""
				width="32"
				height="32"
				loading="lazy"
			/>
		) : (
			<span className="bcm-thumb is-empty" aria-hidden="true" />
		);
	}
	switch ( descriptor.type ) {
		case 'enum': {
			if ( isEmpty( value ) ) {
				return null;
			}
			const extra = companion ? item[ companion.key ] : undefined;
			return (
				<Badge value={ value }>
					{ isEmpty( extra )
						? choiceLabel( descriptor, value )
						: sprintf(
								/* translators: 1: a choice such as "In stock", 2: a number such as the stock quantity. */
								__(
									'%1$s · %2$s',
									'bulk-catalogue-manager-for-woocommerce'
								),
								choiceLabel( descriptor, value ),
								String( extra )
							) }
				</Badge>
			);
		}
		case 'boolean':
			if ( isEmpty( value ) ) {
				return null;
			}
			return value
				? __( 'Yes', 'bulk-catalogue-manager-for-woocommerce' )
				: __( 'No', 'bulk-catalogue-manager-for-woocommerce' );
		case 'terms':
		case 'term':
			return <Terms descriptor={ descriptor } value={ value } />;
		case 'datetime':
			return formatDate( value );
		case 'html':
			return textOf( value );
		default:
			return isEmpty( value ) ? null : String( value );
	}
}

function Cell( props ) {
	const { pending } = useContext( CellContext );
	const isPending = pending.has( props.item?.id );
	return (
		<span
			className={ 'bcm-cell' + ( isPending ? ' is-pending' : '' ) }
			aria-busy={ isPending || undefined }
		>
			<Value { ...props } />
		</span>
	);
}

/**
 * A cell for one row and field.
 *
 * @param {Object} props            Props.
 * @param {Object} props.item       Row.
 * @param {Object} props.descriptor Registry descriptor.
 * @param {Object} props.companion  Descriptor shown alongside, if any.
 * @param {string} props.role       'title', 'media', 'id' or ''.
 * @return {Element} Cell.
 */
export function renderCell( props ) {
	return <Cell { ...props } />;
}
