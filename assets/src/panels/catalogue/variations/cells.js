/**
 * Cells of the row tree: the disclosure control and count in a variable
 * row's name cell, the attribute chips in a variation row's, the footer row
 * under an open product, and the read-only cells cellState() describes.
 * Every value renders as text.
 */
import { Button } from '@wordpress/components';
import { createContext } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { choiceLabel, textOf } from '../cells';
import { ROW } from '../defaults';
import { STATES, cellState } from './cell-state';
import { KINDS, metaOf } from './model';
import { countOf } from './registry';

/**
 * True inside a cell cellState() does not let the owner edit (a locked cell,
 * a variation's name): the editing layer (edit/index.js) reads it and draws
 * the plain value, so a parent's value shown greyed on a variation row is
 * never an editor for the parent.
 */
export const LockedContext = createContext( false );

const isBlank = ( value ) =>
	value === undefined || value === null || value === '';

/** Separator between attribute values in a summary (Query\Hydrator). */
const SUMMARY_SEPARATOR = ' / ';

/**
 * The attribute chips of a variation: one per attribute, labelled from the
 * row's attribute pairs ("Size: M"), else one per value of its summary
 * ("Blue / Large").
 *
 * @param {Object} row Variation row.
 * @return {Array<string>} Chip texts.
 */
export function chipsOf( row ) {
	const pairs = row?.[ ROW.attributes ];
	if ( Array.isArray( pairs ) && pairs.length ) {
		return pairs
			.filter( ( pair ) => pair && ! isBlank( pair.label ) )
			.map( ( pair ) =>
				sprintf(
					/* translators: 1: an attribute, such as "Size"; 2: its value, such as "M". */
					__(
						'%1$s: %2$s',
						'bulk-catalogue-manager-for-woocommerce'
					),
					pair.label,
					isBlank( pair.value )
						? __( 'Any', 'bulk-catalogue-manager-for-woocommerce' )
						: pair.value
				)
			);
	}
	const summary = row?.[ ROW.attributeSummary ];
	return isBlank( summary )
		? []
		: String( summary )
				.split( SUMMARY_SEPARATOR )
				.map( ( part ) => part.trim() )
				.filter( Boolean );
}

/**
 * A read-only cell with its reason: the reason is the tooltip and is read
 * out after the value, and Enter opens nothing (T46 asks cellState() too).
 *
 * @param {Object}  props          Props.
 * @param {string}  props.kind     STATES value.
 * @param {string}  props.reason   Sentence.
 * @param {Element} props.children Value.
 * @return {Element} Cell.
 */
function Locked( { kind, reason, children } ) {
	// Focusable so a keyboard user reaches the tooltip's reason; the key
	// handler only stops Enter reaching a row-level editor, it adds no action.
	return (
		// eslint-disable-next-line jsx-a11y/no-static-element-interactions
		<span
			className={ `bulkcat-locked is-${ kind }` }
			title={ reason || undefined }
			data-bulkcat-editable="false"
			data-bulkcat-cell={ kind }
			tabIndex={ 0 }
			onKeyDown={ ( event ) => {
				if ( event.key === 'Enter' ) {
					event.preventDefault();
					event.stopPropagation();
				}
			} }
		>
			<LockedContext.Provider value={ true }>
				{ children }
			</LockedContext.Provider>
			{ reason && (
				<span className="screen-reader-text">{ ` ${ reason }` }</span>
			) }
		</span>
	);
}

/**
 * The disclosure control, product name and count of a variable row.
 *
 * @param {Object}  props      Props.
 * @param {Object}  props.row  Row.
 * @param {Element} props.name The name cell's usual content.
 * @param {Object}  props.ctx  Tree context.
 * @param {Object}  props.meta META value.
 * @return {Element} Cell.
 */
function ParentTitle( { row, name, ctx, meta } ) {
	const count = countOf( row, ctx.roles );
	const title = String( row[ ctx.titleKey ] ?? '' );
	const toggle = () => ctx.toggle( row );
	const label = meta.open
		? sprintf(
				/* translators: %s: product name. */
				__(
					'Hide the variations of %s',
					'bulk-catalogue-manager-for-woocommerce'
				),
				title
			)
		: sprintf(
				/* translators: %s: product name. */
				__(
					'Show the variations of %s',
					'bulk-catalogue-manager-for-woocommerce'
				),
				title
			);
	return (
		<span className="bulkcat-vparent">
			<button
				type="button"
				className="bulkcat-disclosure"
				aria-expanded={ meta.open }
				aria-label={ label }
				aria-busy={ meta.entry?.status === 'loading' || undefined }
				onClick={ toggle }
				onKeyDown={ ( event ) => {
					if ( event.key === 'Enter' || event.key === ' ' ) {
						event.preventDefault();
						event.stopPropagation();
						toggle();
					}
				} }
				onKeyUp={ ( event ) => {
					// Space activates a button on key up; it was handled above.
					if ( event.key === ' ' ) {
						event.preventDefault();
					}
				} }
			>
				<span
					className="bulkcat-disclosure__chevron"
					aria-hidden="true"
				/>
			</button>
			{ name }
			{ count !== null && (
				<span className="bulkcat-pill">
					{ sprintf(
						/* translators: %d: how many variations a product has. */
						_n(
							'%d option',
							'%d options',
							count,
							'bulk-catalogue-manager-for-woocommerce'
						),
						count
					) }
				</span>
			) }
		</span>
	);
}

/**
 * A variation row's name cell: its attribute chips, and in a flat list the
 * product it belongs to.
 *
 * @param {Object}  props      Props.
 * @param {Object}  props.row  Row.
 * @param {Object}  props.meta META value.
 * @param {Element} props.name The name cell's usual content (no summary).
 * @return {Element} Cell.
 */
function VariationTitle( { row, meta, name } ) {
	const chips = chipsOf( row );
	const parentName = row[ ROW.parentName ] || meta.parent?.name;
	return (
		<span
			className={
				'bulkcat-vrow ' + ( meta.level > 0 ? 'is-child' : 'is-flat' )
			}
		>
			{ chips.length ? (
				<span className="bulkcat-chips">
					{ chips.map( ( chip, index ) => (
						<span key={ index } className="bulkcat-chip">
							{ chip }
						</span>
					) ) }
				</span>
			) : (
				<LockedContext.Provider value={ true }>
					{ name }
				</LockedContext.Provider>
			) }
			{ meta.level === 0 && ! isBlank( parentName ) && (
				<span className="bulkcat-vrow__parent">{ parentName }</span>
			) }
		</span>
	);
}

/**
 * The footer row under an open product: the count, Select all options and
 * Hide options; Show all when more exist than are shown; the loading,
 * failed and no-variations states.
 *
 * @param {Object} props      Props.
 * @param {Object} props.meta META value of the footer row.
 * @param {Object} props.ctx  Tree context.
 * @return {Element} Footer.
 */
function Footer( { meta, ctx } ) {
	const { parent, entry, shown, total } = meta;
	const name = String( parent[ ctx.titleKey ] ?? '' );
	const hide = (
		<Button variant="link" onClick={ () => ctx.close( parent ) }>
			{ __( 'Hide options', 'bulk-catalogue-manager-for-woocommerce' ) }
		</Button>
	);
	if ( ! entry || entry.status === 'loading' || entry.status === 'stale' ) {
		return (
			<span className="bulkcat-vfoot" role="status">
				{ __(
					'Loading options…',
					'bulk-catalogue-manager-for-woocommerce'
				) }
			</span>
		);
	}
	if ( entry.status === 'error' ) {
		return (
			<span className="bulkcat-vfoot is-error">
				<span role="alert">
					{ __(
						'The options could not be loaded.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</span>
				<Dot />
				<Button variant="link" onClick={ () => ctx.retry( parent ) }>
					{ __(
						'Try again',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</Button>
				<Dot />
				{ hide }
			</span>
		);
	}
	if ( ! total ) {
		return (
			<span className="bulkcat-vfoot">
				{ __(
					'This product has no variations yet.',
					'bulk-catalogue-manager-for-woocommerce'
				) }
				<Dot />
				{ hide }
			</span>
		);
	}
	return (
		<span className="bulkcat-vfoot">
			<span>
				{ sprintf(
					/* translators: 1: how many options (variations), 2: product name. */
					_n(
						'%1$s option under %2$s',
						'%1$s options under %2$s',
						total,
						'bulk-catalogue-manager-for-woocommerce'
					),
					total.toLocaleString(),
					name
				) }
			</span>
			<Dot />
			<Button variant="link" onClick={ () => ctx.selectAll( parent ) }>
				{ __(
					'Select all options',
					'bulk-catalogue-manager-for-woocommerce'
				) }
			</Button>
			<Dot />
			{ hide }
			{ total > shown && (
				<>
					<Dot />
					<Button
						variant="link"
						onClick={ () => ctx.showAll( parent, total ) }
					>
						{ sprintf(
							/* translators: %s: how many options (variations) the product has. */
							__(
								'Show all %s options',
								'bulk-catalogue-manager-for-woocommerce'
							),
							total.toLocaleString()
						) }
					</Button>
				</>
			) }
		</span>
	);
}

function Dot() {
	return (
		<span className="bulkcat-vfoot__dot" aria-hidden="true">
			·
		</span>
	);
}

/**
 * A read-only cell's value.
 *
 * @param {Object}   props            Props.
 * @param {Object}   props.state      cellState().
 * @param {Object}   props.row        Row.
 * @param {Object}   props.descriptor Descriptor.
 * @param {Function} props.inner      The column's usual renderer.
 * @param {Object}   props.ctx        Tree context.
 * @return {Element} Value.
 */
function LockedValue( { state, row, descriptor, inner, ctx } ) {
	switch ( state.kind ) {
		case STATES.mixed:
			return __( 'Mixed', 'bulk-catalogue-manager-for-woocommerce' );
		case STATES.range:
			return sprintf(
				/* translators: 1: lowest value, 2: highest value. */
				__( '%1$s – %2$s', 'bulk-catalogue-manager-for-woocommerce' ),
				String( state.min ),
				String( state.max )
			);
		case STATES.summary: {
			if ( isBlank( state.value ) ) {
				return null;
			}
			const label = choiceLabel( descriptor, state.value );
			return (
				<span className="bulkcat-badge is-derived">
					{ state.quantity === null
						? label
						: sprintf(
								/* translators: 1: a stock status such as "In stock", 2: the variations' quantities added up. */
								__(
									'%1$s · %2$s',
									'bulk-catalogue-manager-for-woocommerce'
								),
								label,
								String( state.quantity )
							) }
				</span>
			);
		}
		case STATES.parentOnly:
			return state.parent ? (
				inner( { item: state.parent } )
			) : (
				<span className="bulkcat-cell__muted">
					{ __(
						'Same as product',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</span>
			);
		case STATES.notApplicable:
			return (
				<span className="bulkcat-cell__muted">
					{ __(
						'Does not apply',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</span>
			);
		default: {
			// A derived parent value: what the variations share, or before
			// they are loaded the rendered price range for the first price.
			if ( ! isBlank( state.value ) ) {
				return String( state.value );
			}
			if ( ctx.firstDerived === descriptor.key && ctx.roles.price ) {
				const range = textOf( row[ ctx.roles.price ] );
				if ( range ) {
					return range;
				}
			}
			return (
				<span className="bulkcat-cell__muted">
					{ __(
						'Set on each option',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</span>
			);
		}
	}
}

/**
 * A column's cell in the row tree.
 *
 * @param {Object}   props            Props.
 * @param {Object}   props.item       Row as drawn.
 * @param {Object}   props.descriptor Descriptor.
 * @param {Function} props.inner      The column's usual renderer.
 * @param {Object}   props.ctx        Tree context.
 * @return {Element|null} Cell.
 */
export function TreeCell( { item, descriptor, inner, ctx } ) {
	const meta = metaOf( item );
	const isTitle = descriptor.key === ctx.titleKey;
	if ( meta.kind === KINDS.footer ) {
		return isTitle ? <Footer meta={ meta } ctx={ ctx } /> : null;
	}
	if ( isTitle && meta.kind === KINDS.parent ) {
		return (
			<ParentTitle
				row={ item }
				meta={ meta }
				ctx={ ctx }
				name={ inner( { item } ) }
			/>
		);
	}
	if ( isTitle && meta.kind === KINDS.variation ) {
		return (
			<VariationTitle
				row={ item }
				meta={ meta }
				name={ inner( {
					item: { ...item, [ ROW.attributeSummary ]: '' },
				} ) }
			/>
		);
	}
	if ( isTitle ) {
		return inner( { item } );
	}
	const state = cellState( item, descriptor, ctx.descriptors );
	if ( state.kind === STATES.own ) {
		return inner( { item } );
	}
	return (
		<Locked kind={ state.kind } reason={ state.reason }>
			<LockedValue
				state={ state }
				row={ item }
				descriptor={ descriptor }
				inner={ inner }
				ctx={ ctx }
			/>
		</Locked>
	);
}
