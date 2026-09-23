/**
 * The "Visible on site" switch (SPEC §5.3, §14; T45). Three positions from
 * the registry's switch field: on (shop and search), off (hidden from both)
 * and a neutral Partly for either middle state, whose tooltip names the exact
 * state and whose click offers the two real choices rather than writing.
 * A grey badge beside it names a status that keeps the product off the site,
 * and the switch stays operable. A change is one PATCH of the catalogue
 * visibility; the row is pending until it returns and a failure puts the
 * switch back with the §4.8 sentence.
 */
import { Button, Dropdown, Tooltip } from '@wordpress/components';
import { useContext, useId, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { announce } from '../../categories/announce';
import { CellContext, choiceLabel } from '../cells';
import { VISIBILITY } from '../defaults';
import { rolesOf } from '../fields';
import { failureText } from './rules';
import { EditContext } from './scope';

const ON = 'on';
const OFF = 'off';
const PARTLY = 'partly';

/**
 * The switch position of a row: its switch field, or worked out from the
 * four-way visibility when only that is present.
 *
 * @param {Object} item Row.
 * @return {string} on, off, partly, or '' when neither is known.
 */
export function positionOf( item ) {
	const value = item?.[ VISIBILITY.field ];
	if ( [ ON, OFF, PARTLY ].includes( value ) ) {
		return value;
	}
	const exact = item?.[ VISIBILITY.terms ];
	if ( exact === undefined || exact === null || exact === '' ) {
		return '';
	}
	if ( exact === VISIBILITY.writes[ ON ] ) {
		return ON;
	}
	return exact === VISIBILITY.writes[ OFF ] ? OFF : PARTLY;
}

/**
 * The product's name for accessible names and sentences.
 *
 * @param {Object}        item        Row.
 * @param {Array<Object>} descriptors Registry descriptors.
 * @return {string} Name.
 */
export function productName( item, descriptors ) {
	const title = rolesOf( descriptors ).title;
	return String( ( title && item?.[ title ] ) || `#${ item?.id }` );
}

/**
 * The switch cell.
 *
 * @param {Object} props            Props.
 * @param {Object} props.item       Row.
 * @param {Object} props.descriptor The switch field's descriptor.
 * @return {Element} Switch.
 */
export default function VisibleSwitch( { item, descriptor } ) {
	const scope = useContext( EditContext );
	const { pending } = useContext( CellContext );
	const [ shown, setShown ] = useState( null );
	const describedBy = useId();
	const descriptors = scope?.descriptors || [];
	const position = shown || positionOf( item );
	if ( ! position ) {
		return null;
	}
	const byKey = ( key ) => descriptors.find( ( field ) => field.key === key );
	const terms = byKey( VISIBILITY.terms );
	const status = byKey( VISIBILITY.status );
	const isPending = pending.has( item.id );
	const name = productName( item, descriptors );
	const accessibleName = sprintf(
		/* translators: 1: the switch's name, "Visible on site", 2: a product name. */
		__( '%1$s, %2$s', 'bulk-catalogue-manager-for-woocommerce' ),
		descriptor.label,
		name
	);
	const statusValue = item[ VISIBILITY.status ];
	const offSite =
		statusValue !== undefined &&
		statusValue !== null &&
		statusValue !== VISIBILITY.onSite;

	const write = ( next ) => {
		if ( ! scope || isPending || next === position ) {
			return;
		}
		const before = position;
		setShown( next );
		scope
			.commit(
				item,
				{ [ VISIBILITY.terms ]: VISIBILITY.writes[ next ] },
				[ VISIBILITY.field, VISIBILITY.terms, VISIBILITY.status ]
			)
			.then( () => setShown( null ) )
			.catch( ( error ) => {
				setShown( before === positionOf( item ) ? null : before );
				const message = sprintf(
					/* translators: 1: a product name, 2: what happened or what to do, as a sentence. */
					__(
						'We could not change whether %1$s is visible on site, so it is as it was. %2$s',
						'bulk-catalogue-manager-for-woocommerce'
					),
					name,
					failureText(
						error,
						__(
							'Try again in a moment.',
							'bulk-catalogue-manager-for-woocommerce'
						)
					)
				);
				scope.notify( {
					error: {
						message,
						code: error?.code,
						data: error?.data,
					},
					message,
				} );
				announce( message, 'assertive' );
			} );
	};

	const badge = offSite && (
		<span className="bcm-badge is-neutral bcm-switch__status">
			{ status
				? choiceLabel( status, statusValue )
				: String( statusValue ) }
		</span>
	);

	if ( position === PARTLY ) {
		const exact = item[ VISIBILITY.terms ];
		const tip = sprintf(
			/* translators: %s: the exact catalogue visibility, such as "Shop only". */
			__(
				'Partly visible: %s. Choose on or off to change it.',
				'bulk-catalogue-manager-for-woocommerce'
			),
			terms && exact
				? choiceLabel( terms, exact )
				: __(
						'in the shop or in search results, not both',
						'bulk-catalogue-manager-for-woocommerce'
					)
		);
		return (
			<span className="bcm-switch" data-bcm-row={ item.id }>
				<Dropdown
					className="bcm-switch__partly-wrap"
					contentClassName="bcm-switch__choices"
					popoverProps={ { placement: 'bottom-start' } }
					renderToggle={ ( { isOpen, onToggle } ) => (
						<Tooltip text={ tip }>
							<button
								type="button"
								className="bcm-switch__button is-partly"
								aria-label={ accessibleName }
								aria-describedby={ describedBy }
								aria-haspopup="true"
								aria-expanded={ isOpen }
								aria-disabled={ isPending || undefined }
								onClick={ () => ! isPending && onToggle() }
							>
								<span
									className="bcm-switch__track"
									aria-hidden="true"
								>
									<span className="bcm-switch__knob" />
								</span>
								<span className="bcm-switch__label">
									{ choiceLabel( descriptor, PARTLY ) }
								</span>
							</button>
						</Tooltip>
					) }
					renderContent={ ( { onClose } ) => (
						<div
							className="bcm-switch__menu"
							role="group"
							aria-label={ accessibleName }
						>
							<p className="bcm-switch__menu-title">{ tip }</p>
							{ [ ON, OFF ].map( ( next ) => (
								<Button
									key={ next }
									variant="tertiary"
									onClick={ () => {
										onClose();
										write( next );
									} }
								>
									{ terms
										? choiceLabel(
												terms,
												VISIBILITY.writes[ next ]
											)
										: choiceLabel( descriptor, next ) }
								</Button>
							) ) }
						</div>
					) }
				/>
				<span id={ describedBy } className="bcm-sr-only">
					{ tip }
				</span>
				{ badge }
			</span>
		);
	}

	const on = position === ON;
	let label = __( 'Hidden', 'bulk-catalogue-manager-for-woocommerce' );
	if ( on ) {
		label = offSite
			? __( 'When published', 'bulk-catalogue-manager-for-woocommerce' )
			: __( 'On site', 'bulk-catalogue-manager-for-woocommerce' );
	}
	return (
		<span
			className={ 'bcm-switch' + ( isPending ? ' is-pending' : '' ) }
			data-bcm-row={ item.id }
		>
			<button
				type="button"
				role="switch"
				className="bcm-switch__button"
				aria-checked={ on }
				aria-label={ accessibleName }
				aria-describedby={ offSite ? describedBy : undefined }
				aria-disabled={ isPending || undefined }
				aria-busy={ isPending || undefined }
				onClick={ () => write( on ? OFF : ON ) }
			>
				<span className="bcm-switch__track" aria-hidden="true">
					<span className="bcm-switch__knob" />
				</span>
				<span className="bcm-switch__label">{ label }</span>
			</button>
			{ offSite && (
				<span id={ describedBy } className="bcm-sr-only">
					{ sprintf(
						/* translators: %s: a status such as "Draft". */
						__(
							'%s: not on the site until it is published.',
							'bulk-catalogue-manager-for-woocommerce'
						),
						status
							? choiceLabel( status, statusValue )
							: String( statusValue )
					) }
				</span>
			) }
			{ badge }
		</span>
	);
}
