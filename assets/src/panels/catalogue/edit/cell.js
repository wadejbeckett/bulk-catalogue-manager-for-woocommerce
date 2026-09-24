/**
 * Inline cell editing (SPEC §4.1, §4.8, §14; T46). A cell for a field the
 * registry marks inline-editable opens on click or Enter, commits on Enter
 * or blur and reverts on Escape. A value the registry's rules refuse — the
 * client pre-check, or the server's 400 naming the field — shows its §4.8
 * sentence in place and leaves the cell open with the typed value intact.
 * A commit is one PATCH; the row is pending until it returns and the answer
 * replaces the row. A field that does not apply to the row says why.
 */
import {
	useContext,
	useEffect,
	useId,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { announce } from '../../categories/announce';
import { CellContext } from '../cells';
import {
	allowedChoices,
	comparable,
	failureText,
	fieldError,
	toRequestValue,
	unavailableReason,
	validate,
} from './rules';
import { EditContext } from './scope';
import { productName } from './switch';

/** How long the Saved tick stays, in milliseconds. */
const SAVED_FOR = 2500;

/**
 * The text an editor starts with for a value.
 *
 * @param {Object} descriptor Registry descriptor.
 * @param {*}      value      Value.
 * @return {string} Text.
 */
function initialText( descriptor, value ) {
	if ( descriptor.type === 'boolean' ) {
		return value ? '1' : '0';
	}
	return value === undefined || value === null ? '' : String( value );
}

/**
 * What the editor's text means as a value for the field.
 *
 * @param {Object} descriptor Registry descriptor.
 * @param {string} text       Text.
 * @return {*} Value.
 */
function typedValue( descriptor, text ) {
	return descriptor.type === 'boolean' ? text === '1' : text;
}

/**
 * The cell (row id and field) whose trigger takes focus back after a save
 * that redraws its row, such as a variation's row read again after its edit
 * (T75): the redrawn cell is a new component, so it looks here on mount.
 */
let focusAfterSave = null;

/**
 * An inline-editable cell.
 *
 * @param {Object}  props            Props.
 * @param {Object}  props.item       Row.
 * @param {Object}  props.descriptor Registry descriptor.
 * @param {Element} props.display    The cell as it renders when not editing.
 * @return {Element} Cell.
 */
export default function InlineCell( { item, descriptor, display } ) {
	const scope = useContext( EditContext );
	const { pending } = useContext( CellContext );
	const [ editing, setEditing ] = useState( false );
	const [ text, setText ] = useState( '' );
	const [ error, setError ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ saved, setSaved ] = useState( false );
	const [ why, setWhy ] = useState( false );
	const trigger = useRef( null );
	const input = useRef( null );
	const closing = useRef( false );
	const refocus = useRef( false );
	const live = useRef( true );
	const errorId = useId();

	const name = productName( item, scope?.descriptors || [] );
	const reason = unavailableReason( descriptor, item );
	const isPending = pending.has( item.id );
	const editName = sprintf(
		/* translators: 1: a field's name, such as "SKU", 2: a product name. */
		__( '%1$s, %2$s', 'bulk-catalogue-manager-for-woocommerce' ),
		descriptor.label,
		name
	);

	const cellKey = `${ item.id }:${ descriptor.key }`;
	useEffect( () => {
		// Only when the redraw left focus nowhere: the owner may have moved on.
		const doc = trigger.current?.ownerDocument;
		if (
			focusAfterSave === cellKey &&
			( ! doc?.activeElement || doc.activeElement === doc.body )
		) {
			trigger.current?.focus();
		}
		return () => {
			live.current = false;
		};
		// On mount only: a redrawn cell picks up the focus its save left.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	useEffect( () => {
		if ( editing ) {
			input.current?.focus();
			if ( input.current?.select ) {
				input.current.select();
			}
		} else if ( refocus.current ) {
			refocus.current = false;
			trigger.current?.focus();
		}
	}, [ editing ] );

	useEffect( () => {
		if ( ! saved ) {
			return;
		}
		const timer = setTimeout(
			() => live.current && setSaved( false ),
			SAVED_FOR
		);
		return () => clearTimeout( timer );
	}, [ saved ] );

	const open = () => {
		if ( reason ) {
			setWhy( true );
			return;
		}
		if ( isPending || ! scope ) {
			return;
		}
		closing.current = false;
		setText( initialText( descriptor, item[ descriptor.key ] ) );
		setError( '' );
		setEditing( true );
	};

	const close = ( focusTrigger ) => {
		closing.current = true;
		refocus.current = focusTrigger;
		setError( '' );
		setEditing( false );
	};

	const commit = ( focusTrigger ) => {
		if ( saving || closing.current ) {
			return;
		}
		const value = typedValue( descriptor, text );
		if (
			comparable( descriptor, value ) ===
			comparable( descriptor, item[ descriptor.key ] )
		) {
			close( focusTrigger );
			return;
		}
		const refused = validate( descriptor, value, item );
		if ( refused ) {
			setError( refused );
			return;
		}
		setSaving( true );
		setError( '' );
		if ( focusTrigger ) {
			focusAfterSave = cellKey;
		}
		scope
			.commit( item, {
				[ descriptor.key ]: toRequestValue( descriptor, value ),
			} )
			.then( () => {
				// The row may be read again and redrawn after this answer;
				// the redrawn cell keeps the focus for a moment (T75).
				setTimeout( () => {
					if ( focusAfterSave === cellKey ) {
						focusAfterSave = null;
					}
				}, 3000 );
				if ( ! live.current ) {
					return;
				}
				setSaving( false );
				setSaved( true );
				close( focusTrigger );
				announce(
					sprintf(
						/* translators: 1: a field's name, 2: a product name. */
						__(
							'%1$s saved for %2$s.',
							'bulk-catalogue-manager-for-woocommerce'
						),
						descriptor.label,
						name
					)
				);
			} )
			.catch( ( failure ) => {
				if ( focusAfterSave === cellKey ) {
					focusAfterSave = null;
				}
				if ( ! live.current ) {
					return;
				}
				setSaving( false );
				const sentence =
					fieldError( failure, descriptor.key ) ||
					sprintf(
						/* translators: 1: a field's name, 2: a product name, 3: what happened or what to do, as a sentence. */
						__(
							'We could not save %1$s for %2$s. %3$s',
							'bulk-catalogue-manager-for-woocommerce'
						),
						descriptor.label,
						name,
						failureText(
							failure,
							__(
								'Try again in a moment.',
								'bulk-catalogue-manager-for-woocommerce'
							)
						)
					);
				setError( sentence );
			} );
	};

	const onKeyDown = ( event ) => {
		if ( event.key === 'Enter' ) {
			event.preventDefault();
			event.stopPropagation();
			commit( true );
		} else if ( event.key === 'Escape' ) {
			event.preventDefault();
			event.stopPropagation();
			close( true );
		}
	};

	if ( editing ) {
		const shared = {
			ref: input,
			className: 'bcm-inline__input',
			'aria-label': editName,
			'aria-invalid': error ? true : undefined,
			'aria-describedby': error ? errorId : undefined,
			'aria-busy': saving || undefined,
			readOnly: saving || undefined,
			value: text,
			onChange: ( event ) => setText( event.target.value ),
			onKeyDown,
			onBlur: () => commit( false ),
		};
		let control;
		if ( descriptor.type === 'enum' || descriptor.type === 'boolean' ) {
			const choices =
				descriptor.type === 'boolean'
					? [
							{
								value: '1',
								label: __(
									'Yes',
									'bulk-catalogue-manager-for-woocommerce'
								),
							},
							{
								value: '0',
								label: __(
									'No',
									'bulk-catalogue-manager-for-woocommerce'
								),
							},
						]
					: allowedChoices( descriptor, item );
			control = (
				<select { ...shared } disabled={ saving || undefined }>
					{ choices.map( ( choice ) => (
						<option key={ choice.value } value={ choice.value }>
							{ choice.label }
						</option>
					) ) }
				</select>
			);
		} else {
			const numeric = [ 'money', 'decimal', 'integer' ].includes(
				descriptor.type
			);
			control = (
				<input
					{ ...shared }
					type="text"
					inputMode={ numeric ? 'decimal' : undefined }
					maxLength={ descriptor.maxLength || undefined }
				/>
			);
		}
		return (
			<span
				className={
					'bcm-inline is-editing' + ( error ? ' has-error' : '' )
				}
				data-bcm-row={ item.id }
			>
				{ control }
				{ error && (
					<span
						id={ errorId }
						className="bcm-inline__error"
						role="alert"
					>
						{ error }
					</span>
				) }
			</span>
		);
	}

	return (
		<span className="bcm-inline" data-bcm-row={ item.id }>
			<button
				ref={ trigger }
				type="button"
				className={
					'bcm-inline__trigger' + ( reason ? ' is-unavailable' : '' )
				}
				aria-label={ sprintf(
					/* translators: %s: a field's name and a product name, such as "SKU, Blue Widget". */
					__( 'Edit %s', 'bulk-catalogue-manager-for-woocommerce' ),
					editName
				) }
				aria-describedby={ why && reason ? errorId : undefined }
				aria-disabled={ reason || isPending ? true : undefined }
				onClick={ open }
				onKeyDown={ ( event ) => {
					if ( event.key === 'Enter' ) {
						event.preventDefault();
						open();
					}
				} }
			>
				{ display }
			</button>
			{ saved && (
				<span className="bcm-inline__saved" role="status">
					{ __( 'Saved', 'bulk-catalogue-manager-for-woocommerce' ) }
				</span>
			) }
			{ why && reason && (
				<span id={ errorId } className="bcm-inline__why" role="status">
					{ reason }
				</span>
			) }
		</span>
	);
}
