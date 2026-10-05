/**
 * The seam between a category action's arguments and the controls that
 * edit them. Every argument kind is drawn by the bulk panel's ArgControl,
 * so a category change offers exactly the controls a product change does;
 * a `lookup` argument goes to the shared LookupPicker
 * (components/lookup-picker), which searches and pages the add-on's choices
 * through GET /lookup, scoped to one category (`term:product_cat:<id>`) when
 * the change is for one category, else to none.
 */
import { ArgControl } from '../../bulk/controls';

/**
 * One argument's control.
 *
 * @param {Object}   props          Props.
 * @param {string}   props.id       Control id.
 * @param {string}   props.name     Argument name.
 * @param {Object}   props.property Its JSON Schema property.
 * @param {*}        props.value    Value.
 * @param {Function} props.onChange Receives the new value.
 * @param {string}   props.action   The action id.
 * @param {string}   props.object   An ObjectRef key for one category, or ''.
 * @param {string}   props.reason   Why it is refused, or ''.
 * @return {Element} The control.
 */
export default function ArgumentField( {
	id,
	name,
	property,
	value,
	onChange,
	action,
	object = '',
	reason = '',
} ) {
	return (
		<ArgControl
			id={ id }
			name={ name }
			property={ property }
			value={ value }
			onChange={ onChange }
			field={ null }
			terms={ {} }
			reason={ reason }
			action={ action }
			object={ object }
		/>
	);
}
