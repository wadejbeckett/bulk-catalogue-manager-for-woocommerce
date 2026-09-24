/**
 * One setting on the Settings tab, rendered from its description in
 * GET /settings: its plain label and help, the control its type calls for
 * and, under it, the reason a save refused it.
 */
import {
	Notice,
	RadioControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * A typed number as it is sent: a whole number when it is one, otherwise
 * the text itself for the server to refuse by name.
 *
 * @param {string} text What was typed.
 * @return {number|string} Value.
 */
export function numberFrom( text ) {
	return /^-?\d+$/.test( text.trim() ) ? Number( text.trim() ) : text;
}

/**
 * The control for a number, with its unit beside it.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.setting  Description.
 * @param {*}        props.value    Current value.
 * @param {Function} props.onChange Change handler.
 * @param {boolean}  props.disabled Read-only.
 * @param {string}   props.label    Label, when not the setting's own.
 * @return {Element} Control.
 */
function NumberField( { setting, value, onChange, disabled, label } ) {
	return (
		<div className="bulkcat-settings__number">
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				type="number"
				label={ label || setting.label }
				value={ value === undefined || value === null ? '' : value }
				min={ setting.min }
				max={ setting.max }
				step={ 1 }
				disabled={ disabled }
				onChange={ ( text ) =>
					onChange( numberFrom( String( text ) ) )
				}
			/>
			{ setting.unit && (
				<span className="bulkcat-settings__unit">{ setting.unit }</span>
			) }
		</div>
	);
}

/**
 * A number with special values offered as choices (keep history for: a
 * number of days, or indefinitely).
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.setting  Description.
 * @param {*}        props.value    Current value.
 * @param {Function} props.onChange Change handler.
 * @param {boolean}  props.disabled Read-only.
 * @return {Element} Control.
 */
function NumberWithSpecial( { setting, value, onChange, disabled } ) {
	const special = setting.special.find( ( each ) => each.value === value );
	const fallback =
		setting.default === setting.special[ 0 ].value
			? setting.min
			: setting.default;
	return (
		<div className="bulkcat-settings__special">
			<RadioControl
				label={ setting.label }
				selected={ special ? String( special.value ) : 'number' }
				options={ [
					{
						value: 'number',
						label: __(
							'For a set number of days',
							'bulk-catalogue-manager-for-woocommerce'
						),
					},
					...setting.special.map( ( each ) => ( {
						value: String( each.value ),
						label: each.label,
					} ) ),
				] }
				disabled={ disabled }
				onChange={ ( chosen ) => {
					const picked = setting.special.find(
						( each ) => String( each.value ) === chosen
					);
					onChange( picked ? picked.value : fallback );
				} }
			/>
			{ ! special && (
				<NumberField
					setting={ setting }
					value={ value }
					onChange={ onChange }
					disabled={ disabled }
					label={ __(
						'Number of days',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				/>
			) }
		</div>
	);
}

/**
 * The control a setting's description calls for.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.setting  Description.
 * @param {*}        props.value    Current value.
 * @param {Function} props.onChange Change handler.
 * @param {boolean}  props.disabled Read-only.
 * @return {Element} Control.
 */
function Control( { setting, value, onChange, disabled } ) {
	if ( setting.special ) {
		return (
			<NumberWithSpecial
				setting={ setting }
				value={ value }
				onChange={ onChange }
				disabled={ disabled }
			/>
		);
	}
	if ( setting.choices && setting.type === 'boolean' ) {
		return (
			<RadioControl
				label={ setting.label }
				selected={ String( Boolean( value ) ) }
				options={ setting.choices.map( ( choice ) => ( {
					value: String( choice.value ),
					label: choice.label,
				} ) ) }
				disabled={ disabled }
				onChange={ ( chosen ) => onChange( chosen === 'true' ) }
			/>
		);
	}
	if ( setting.choices ) {
		return (
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ setting.label }
				value={ String( value ) }
				options={ setting.choices.map( ( choice ) => ( {
					value: String( choice.value ),
					label: choice.label,
				} ) ) }
				disabled={ disabled }
				onChange={ ( chosen ) => {
					const picked = setting.choices.find(
						( choice ) => String( choice.value ) === chosen
					);
					onChange( picked ? picked.value : chosen );
				} }
			/>
		);
	}
	if ( setting.type === 'boolean' ) {
		return (
			<ToggleControl
				__nextHasNoMarginBottom
				label={ setting.label }
				checked={ Boolean( value ) }
				disabled={ disabled }
				onChange={ ( checked ) => onChange( checked ) }
			/>
		);
	}
	return (
		<NumberField
			setting={ setting }
			value={ value }
			onChange={ onChange }
			disabled={ disabled }
		/>
	);
}

/**
 * One setting row.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.setting  Description from GET /settings.
 * @param {*}        props.value    Current (unsaved) value.
 * @param {string}   props.error    Why the last save refused it, or ''.
 * @param {Function} props.onChange Change handler.
 * @param {boolean}  props.disabled Read-only.
 * @return {Element} Row.
 */
export default function SettingRow( {
	setting,
	value,
	error,
	onChange,
	disabled,
} ) {
	return (
		<div
			className={
				'bulkcat-settings__row' +
				( error ? ' bulkcat-settings__row--error' : '' )
			}
			data-setting={ setting.key }
		>
			<Control
				setting={ setting }
				value={ value }
				onChange={ onChange }
				disabled={ disabled }
			/>
			{ setting.help && (
				<p className="bulkcat-settings__help">{ setting.help }</p>
			) }
			{ setting.warning && value === true && (
				<Notice status="warning" isDismissible={ false }>
					{ setting.warning }
				</Notice>
			) }
			{ error && <p className="bulkcat-settings__error">{ error }</p> }
		</div>
	);
}
