/**
 * The confirmation policy for every Apply (SPEC §4.5, §11; T48): one hook
 * decides and one component renders it, for the bulk panel, an undo and a
 * category batch alike.
 *
 * - Below the *Confirm at or above this many rows* setting, Apply is one
 *   click.
 * - At or above it, Apply restates the count in words and needs a second
 *   click.
 * - A change that takes products off the site (Status set to Draft or
 *   Pending Review), switches Shown in shop and search off, hides a product
 *   from the catalogue, or clears a field always asks, whatever the count,
 *   naming what it will do (FRONTEND-4: as the setting's help says). Other
 *   Status warnings stay in Preview.
 * - Nothing ever asks the owner to type a word, and a preview never confirms.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { actionById, fieldByKey } from './descriptors';
import { countPhrase } from '../catalogue/selection/model';
import { formatNumber, productsPhrase } from './words';

/** The threshold when the settings carry none (Settings\Schema default). */
export const DEFAULT_THRESHOLD = 200;

/**
 * A choice's label for a field value.
 *
 * @param {Object} field Field descriptor.
 * @param {*}      value Value.
 * @return {string} Label.
 */
function choiceLabel( field, value ) {
	const choice = ( field?.choices || [] ).find(
		( each ) => each.value === String( value )
	);
	return choice ? choice.label : String( value );
}

/**
 * How setting a field to a value hides the product, from the registry's
 * choice flags (`hidesProduct`, `hidesAs`): 'off_site', 'hidden' or ''.
 *
 * @param {Object} field Field descriptor.
 * @param {*}      value The value set.
 * @return {string} The way it hides, or '' when it does not.
 */
function hidesAs( field, value ) {
	const choice = ( field?.choices || [] ).find(
		( each ) => String( each.value ) === String( value )
	);
	return choice?.hidesProduct === true ? String( choice.hidesAs || '' ) : '';
}

/**
 * The sentences naming what an always-confirm change will do, one per step
 * that drafts, hides or empties (SPEC §4.5).
 *
 * @param {Object}        registry   The `/fields` payload.
 * @param {Array<Object>} operations Operations `[ { action, args } ]`.
 * @param {number}        count      Products it applies to.
 * @return {Array<string>} Sentences; empty when none always confirms.
 */
export function alwaysConfirmReasons( registry, operations, count ) {
	const products = productsPhrase( count );
	const reasons = [];
	( operations || [] ).forEach( ( operation ) => {
		const action = actionById( registry, operation.action );
		const field = fieldByKey( registry, operation.args?.field );
		if ( ! action || ! field ) {
			return;
		}
		if ( action.provider ) {
			// An add-on's action (T105) confirms when it says so, in its own words.
			if ( action.alwaysConfirm ) {
				reasons.push(
					action.confirmReason ||
						sprintf(
							/* translators: 1: an action's name, such as "Replace labels"; 2: a number of products, such as "24 products". */
							__(
								'%1$s always asks before it changes %2$s.',
								'bulk-catalogue-manager-for-woocommerce'
							),
							action.label,
							products
						)
				);
			}
			return;
		}
		const sets = Object.prototype.hasOwnProperty.call(
			operation.args,
			'value'
		);
		const value = operation.args.value;
		if (
			( action.editKinds || [] ).includes( 'clear' ) ||
			( sets && field.nullable && ( value === '' || value === null ) )
		) {
			reasons.push(
				sprintf(
					/* translators: 1: a field, such as "Sale price"; 2: a number of products, such as "24 products". */
					__(
						'This empties %1$s on %2$s.',
						'bulk-catalogue-manager-for-woocommerce'
					),
					field.label,
					products
				)
			);
			return;
		}
		if ( ! sets ) {
			return;
		}
		const hides = hidesAs( field, value );
		if ( hides === 'off_site' ) {
			reasons.push(
				sprintf(
					/* translators: 1: a field, "Status"; 2: its new value, such as "Draft"; 3: a number of products, such as "24 products". */
					__(
						'This sets %1$s to %2$s on %3$s, which takes them off the site.',
						'bulk-catalogue-manager-for-woocommerce'
					),
					field.label,
					choiceLabel( field, value ),
					products
				)
			);
			return;
		}
		if ( hides === 'hidden' ) {
			reasons.push(
				sprintf(
					/* translators: 1: a field, such as "Shown in shop and search"; 2: its new value, such as "Hidden"; 3: a number of products, such as "24 products". */
					__(
						'This sets %1$s to %2$s on %3$s: they leave the shop and search results, but can still be opened by link and bought. To take them off sale, set Status to Draft.',
						'bulk-catalogue-manager-for-woocommerce'
					),
					field.label,
					choiceLabel( field, value ),
					products
				)
			);
		}
	} );
	return reasons;
}

/**
 * The policy for one Apply.
 *
 * @param {Object}        options           Options.
 * @param {number}        options.count     Rows it applies to.
 * @param {number}        options.threshold The *confirm above this many rows* setting.
 * @param {Array<string>} options.reasons   Always-confirm sentences.
 * @return {{required: boolean, overThreshold: boolean, reasons: Array<string>}} Policy.
 */
export function confirmPolicy( { count, threshold, reasons = [] } ) {
	const limit = Number.isFinite( Number( threshold ) )
		? Number( threshold )
		: DEFAULT_THRESHOLD;
	const overThreshold = Number( count ) >= limit;
	return {
		required: overThreshold || reasons.length > 0,
		overThreshold,
		reasons,
	};
}

/**
 * The shared confirmation state for an Apply.
 *
 * @param {Object}        options           Options.
 * @param {number}        options.count     Rows it applies to.
 * @param {number}        options.threshold The *confirm above this many rows* setting.
 * @param {Array<string>} options.reasons   Always-confirm sentences.
 * @param {Function}      options.onApply   Runs the apply.
 * @return {Object} `{ required, overThreshold, reasons, armed, request, confirm, cancel }`.
 */
export function useConfirmApply( { count, threshold, reasons = [], onApply } ) {
	const policy = confirmPolicy( { count, threshold, reasons } );
	const [ armed, setArmed ] = useState( false );
	const key = count + '|' + threshold + '|' + reasons.join( '|' );

	// Whatever the owner was asked about has changed: ask again from the start.
	useEffect( () => setArmed( false ), [ key ] );

	return {
		...policy,
		armed,
		request() {
			if ( policy.required ) {
				setArmed( true );
				return;
			}
			onApply();
		},
		confirm() {
			setArmed( false );
			onApply();
		},
		cancel() {
			setArmed( false );
		},
	};
}

/**
 * The Apply button and, when the policy asks, the second click.
 *
 * @param {Object}        props                Props.
 * @param {number}        props.count          Rows it applies to.
 * @param {Object|null}   props.byType         `{ products, variations }` of that count, from the preview, or null (ticket 11).
 * @param {number}        props.threshold      The *confirm above this many rows* setting.
 * @param {Array<string>} props.reasons        Always-confirm sentences.
 * @param {Function}      props.onApply        Runs the apply.
 * @param {string}        props.label          Apply button text.
 * @param {string}        props.confirmLabel   Second-click button text.
 * @param {boolean}       props.disabled       Whether Apply is disabled.
 * @param {string}        props.disabledReason Its one-line reason.
 * @param {boolean}       props.busy           Whether an apply is running.
 * @return {Element} Apply control.
 */
export function ConfirmApply( {
	count,
	byType = null,
	threshold,
	reasons = [],
	onApply,
	label,
	confirmLabel,
	disabled = false,
	disabledReason = '',
	busy = false,
} ) {
	const gate = useConfirmApply( { count, threshold, reasons, onApply } );
	const applyRef = useRef( null );
	const yesRef = useRef( null );
	const wasArmed = useRef( false );

	useEffect( () => {
		if ( gate.armed ) {
			yesRef.current?.focus();
		} else if ( wasArmed.current ) {
			applyRef.current?.focus();
		}
		wasArmed.current = gate.armed;
	}, [ gate.armed ] );

	const words = formatNumber( count );
	// Products and variations apart when the change reaches variations (ticket 11).
	const mixed = Number( byType?.variations ) > 0;
	const phrase = mixed
		? countPhrase( count, byType )
		: productsPhrase( count );
	// Escape on the second click dismisses it (and not the panel around it).
	const onEscape = ( event ) => {
		if ( event.key === 'Escape' ) {
			event.stopPropagation();
			event.preventDefault();
			gate.cancel();
		}
	};
	const applyText =
		label ||
		sprintf(
			/* translators: %s: a number of products, such as "24 products", or of products and variations, such as "1,200 products and 50 variations". */
			__( 'Apply to %s', 'bulk-catalogue-manager-for-woocommerce' ),
			phrase
		);
	const yesText =
		confirmLabel ||
		sprintf(
			/* translators: %s: a number of products, such as "1,284 products", or of products and variations, such as "1,200 products and 50 variations". */
			__( 'Yes, change %s', 'bulk-catalogue-manager-for-woocommerce' ),
			phrase
		);

	return (
		<div className="bulkcat-confirm">
			{ /* FRONTEND-7: busy is aria-disabled, so focus returned here after
			   a confirm stays on the button while the change is sent. */ }
			<button
				ref={ applyRef }
				type="button"
				className="button button-primary"
				disabled={ disabled || gate.armed }
				aria-disabled={ busy || undefined }
				aria-describedby={
					disabled && disabledReason
						? 'bulkcat-confirm-disabled'
						: undefined
				}
				onClick={ busy ? undefined : gate.request }
			>
				{ applyText }
			</button>
			{ disabled && disabledReason && (
				<p className="bulkcat-bulk__hint" id="bulkcat-confirm-disabled">
					{ disabledReason }
				</p>
			) }
			{ gate.armed && (
				<div
					className="bulkcat-confirm__ask"
					role="group"
					aria-labelledby="bulkcat-confirm-title"
				>
					<p
						id="bulkcat-confirm-title"
						className="bulkcat-confirm__title"
					>
						{ mixed
							? sprintf(
									/* translators: %s: a number of products and variations, such as "1,200 products and 50 variations". */
									__(
										'This will change %s. Apply it?',
										'bulk-catalogue-manager-for-woocommerce'
									),
									phrase
								)
							: sprintf(
									/* translators: %s: a number of products, such as "1,284". */
									_n(
										'This will change %s product. Apply it?',
										'This will change %s products. Apply it?',
										count,
										'bulk-catalogue-manager-for-woocommerce'
									),
									words
								) }
					</p>
					{ gate.reasons.length > 0 && (
						<ul className="bulkcat-confirm__reasons">
							{ gate.reasons.map( ( reason ) => (
								<li key={ reason }>{ reason }</li>
							) ) }
						</ul>
					) }
					<p className="bulkcat-bulk__hint">
						{ __(
							'You can undo this afterwards from History.',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</p>
					<div className="bulkcat-confirm__acts">
						<button
							ref={ yesRef }
							type="button"
							className="button button-primary"
							onClick={ gate.confirm }
							onKeyDown={ onEscape }
						>
							{ yesText }
						</button>
						<button
							type="button"
							className="button"
							onClick={ gate.cancel }
							onKeyDown={ onEscape }
						>
							{ __(
								'Not yet',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</button>
					</div>
				</div>
			) }
		</div>
	);
}
