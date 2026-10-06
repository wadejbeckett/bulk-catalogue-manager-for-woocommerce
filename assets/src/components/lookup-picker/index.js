/**
 * A searchable, paged picker over an add-on's lookup argument
 * (docs/EXTENSIONS-v2.md §4): choices come one bounded page at a time from
 * GET /lookup, never all at once. Shared by the bulk panel and any other
 * screen that offers an add-on action (import { LookupPicker }).
 *
 * The value is a list of chosen items `{ id, label, detail? }` (at most one
 * when `multiple` is false), so labels survive paging and searching; the
 * caller sends only the ids. A combobox with a listbox (WAI-ARIA 1.2): Arrow
 * keys move, Enter chooses, Escape closes, Home and End jump;
 * loading, no matches and failure are said in words.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { request } from '../../api';
import { plainMessage } from '../../errors';
import './style.scss';

/** Wait after typing before searching, in milliseconds. */
export const SEARCH_DELAY = 250;

let counter = 0;

/**
 * The picker.
 *
 * @param {Object}        props          Props.
 * @param {string}        props.action   The add-on action id.
 * @param {string}        props.argument The lookup argument name.
 * @param {string}        props.label    Visible label.
 * @param {string}        props.help     The argument's rule sentence, shown under the field.
 * @param {boolean}       props.multiple Whether several items may be chosen.
 * @param {number}        props.maxItems The most items (several only).
 * @param {Array<Object>} props.value    Chosen items: `{ id, label, detail? }`.
 * @param {Function}      props.onChange Receives the new list of chosen items.
 * @param {string}        props.object   A row's object key, such as "product:42"; '' for a bulk change.
 * @param {string}        props.reason   A sentence saying why the value is refused, or ''.
 * @param {boolean}       props.disabled Whether it is read-only.
 * @return {Element} Element.
 */
export function LookupPicker( {
	action,
	argument,
	label,
	help = '',
	multiple = false,
	maxItems = 100,
	value = [],
	onChange,
	object = '',
	reason = '',
	disabled = false,
} ) {
	const [ base ] = useState( () => `bulkcat-lookup-${ ++counter }` );
	const [ query, setQuery ] = useState( '' );
	const [ open, setOpen ] = useState( false );
	const [ items, setItems ] = useState( [] );
	const [ page, setPage ] = useState( 0 );
	const [ hasMore, setHasMore ] = useState( false );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ active, setActive ] = useState( -1 );
	const ticket = useRef( 0 );
	const timer = useRef( null );
	const chosen = Array.isArray( value ) ? value : [];
	const chosenIds = new Set( chosen.map( ( item ) => item.id ) );
	const full = multiple && chosen.length >= maxItems;

	const load = ( search, number ) => {
		const mine = ++ticket.current;
		setLoading( true );
		setError( '' );
		const args = { action, argument, query: search, page: number };
		if ( object ) {
			args.object = object;
		}
		return request( 'lookup', { query: args } ).then(
			( answer ) => {
				if ( mine !== ticket.current ) {
					return;
				}
				const fresh = Array.isArray( answer?.items )
					? answer.items
					: [];
				setItems( ( before ) => {
					if ( number === 1 ) {
						return fresh;
					}
					const seen = new Set( before.map( ( item ) => item.id ) );
					return [
						...before,
						...fresh.filter( ( item ) => ! seen.has( item.id ) ),
					];
				} );
				setPage( number );
				setHasMore( !! answer?.hasMore );
				setLoading( false );
				if ( number === 1 ) {
					setActive( -1 );
				}
			},
			( failure ) => {
				if ( mine !== ticket.current ) {
					return;
				}
				setLoading( false );
				setError(
					plainMessage(
						failure,
						__(
							'The choices could not be loaded.',
							'bulk-catalogue-manager-for-woocommerce'
						)
					)
				);
			}
		);
	};

	useEffect( () => () => clearTimeout( timer.current ), [] );

	const show = () => {
		if ( disabled ) {
			return;
		}
		setOpen( true );
		if ( page === 0 && ! loading ) {
			load( query.trim(), 1 );
		}
	};

	const search = ( text ) => {
		setQuery( text );
		setOpen( true );
		clearTimeout( timer.current );
		timer.current = setTimeout(
			() => load( text.trim(), 1 ),
			SEARCH_DELAY
		);
	};

	const choose = ( item ) => {
		if ( ! item || disabled ) {
			return;
		}
		if ( chosenIds.has( item.id ) ) {
			if ( multiple ) {
				onChange( chosen.filter( ( each ) => each.id !== item.id ) );
			}
			return;
		}
		if ( ! multiple ) {
			onChange( [ item ] );
			setOpen( false );
			return;
		}
		if ( full ) {
			return;
		}
		onChange( [ ...chosen, item ] );
	};

	const keyDown = ( event ) => {
		const last = items.length - 1;
		switch ( event.key ) {
			case 'ArrowDown':
				event.preventDefault();
				if ( ! open ) {
					show();
					return;
				}
				setActive( ( current ) => Math.min( last, current + 1 ) );
				return;
			case 'ArrowUp':
				event.preventDefault();
				setActive( ( current ) => Math.max( 0, current - 1 ) );
				return;
			case 'Home':
				if ( open && items.length ) {
					event.preventDefault();
					setActive( 0 );
				}
				return;
			case 'End':
				if ( open && items.length ) {
					event.preventDefault();
					setActive( last );
				}
				return;
			case 'Enter':
				if ( open && active >= 0 ) {
					event.preventDefault();
					choose( items[ active ] );
				}
				return;
			case 'Escape':
				if ( open ) {
					// Close the list only, not the panel or dialog around it.
					event.preventDefault();
					event.stopPropagation();
					setOpen( false );
				}
		}
	};

	const listId = `${ base }-list`;
	const statusId = `${ base }-status`;
	const helpId = `${ base }-help`;
	const optionId = ( index ) => `${ base }-option-${ index }`;
	let status = '';
	if ( open && loading ) {
		status = __(
			'Loading choices…',
			'bulk-catalogue-manager-for-woocommerce'
		);
	} else if ( open && ! error && page > 0 && ! items.length ) {
		status = __( 'No matches.', 'bulk-catalogue-manager-for-woocommerce' );
	} else if ( full ) {
		status = sprintf(
			/* translators: %d: the most items that may be chosen. */
			__(
				'You have chosen the most allowed (%d).',
				'bulk-catalogue-manager-for-woocommerce'
			),
			maxItems
		);
	}

	return (
		<div
			className={ `bulkcat-lookup${ reason ? ' has-problem' : '' }` }
			onBlur={ ( event ) => {
				if ( ! event.currentTarget.contains( event.relatedTarget ) ) {
					setOpen( false );
				}
			} }
		>
			<label
				className="bulkcat-lookup__label"
				htmlFor={ `${ base }-input` }
			>
				{ label }
			</label>
			{ chosen.length > 0 && (
				<ul
					className="bulkcat-lookup__chosen"
					aria-label={ sprintf(
						/* translators: %s: the argument's label, such as "Recipients". */
						__(
							'Chosen %s',
							'bulk-catalogue-manager-for-woocommerce'
						),
						label
					) }
				>
					{ chosen.map( ( item ) => (
						<li key={ item.id } className="bulkcat-lookup__chip">
							<span>{ item.label }</span>
							{ ! disabled && (
								<button
									type="button"
									className="bulkcat-lookup__remove"
									aria-label={ sprintf(
										/* translators: %s: a chosen item's label. */
										__(
											'Remove %s',
											'bulk-catalogue-manager-for-woocommerce'
										),
										item.label
									) }
									onClick={ () =>
										onChange(
											chosen.filter(
												( each ) => each.id !== item.id
											)
										)
									}
								>
									×
								</button>
							) }
						</li>
					) ) }
				</ul>
			) }
			<input
				id={ `${ base }-input` }
				type="search"
				className="bulkcat-lookup__input"
				role="combobox"
				autoComplete="off"
				aria-autocomplete="list"
				aria-expanded={ open ? 'true' : 'false' }
				aria-controls={ listId }
				aria-activedescendant={
					open && active >= 0 && items[ active ]
						? optionId( active )
						: undefined
				}
				aria-describedby={ `${ helpId } ${ statusId }` }
				aria-invalid={ reason ? 'true' : undefined }
				placeholder={ __(
					'Search…',
					'bulk-catalogue-manager-for-woocommerce'
				) }
				value={ query }
				disabled={ disabled }
				onFocus={ show }
				onClick={ show }
				onChange={ ( event ) => search( event.target.value ) }
				onKeyDown={ keyDown }
			/>
			<p id={ helpId } className="bulkcat-lookup__help">
				{ reason || help }
			</p>
			<p
				id={ statusId }
				className="bulkcat-lookup__status"
				role="status"
				aria-live="polite"
			>
				{ status }
			</p>
			{ open && error && (
				<div className="bulkcat-lookup__error" role="alert">
					<span>{ error }</span>{ ' ' }
					<button
						type="button"
						className="button-link"
						onClick={ () =>
							load( query.trim(), page > 0 ? page : 1 )
						}
					>
						{ __(
							'Try again',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</button>
				</div>
			) }
			<ul
				id={ listId }
				className="bulkcat-lookup__list"
				role="listbox"
				aria-label={ label }
				aria-multiselectable={ multiple ? 'true' : undefined }
				hidden={ ! open || ! items.length }
			>
				{ open &&
					items.map( ( item, index ) => {
						const selected = chosenIds.has( item.id );
						const blocked = ! selected && full;
						return (
							// eslint-disable-next-line jsx-a11y/click-events-have-key-events -- The combobox input handles the keyboard (aria-activedescendant).
							<li
								key={ item.id }
								id={ optionId( index ) }
								role="option"
								aria-selected={ selected ? 'true' : 'false' }
								aria-disabled={ blocked ? 'true' : undefined }
								className={ `bulkcat-lookup__option${
									index === active ? ' is-active' : ''
								}${ selected ? ' is-selected' : '' }` }
								onMouseDown={ ( event ) =>
									event.preventDefault()
								}
								onClick={ () => ! blocked && choose( item ) }
							>
								<span className="bulkcat-lookup__option-label">
									{ item.label }
								</span>
								{ item.detail && (
									<span className="bulkcat-lookup__option-detail">
										{ item.detail }
									</span>
								) }
							</li>
						);
					} ) }
			</ul>
			{ open && hasMore && ! loading && ! error && (
				<button
					type="button"
					className="button bulkcat-lookup__more"
					onMouseDown={ ( event ) => event.preventDefault() }
					onClick={ () => load( query.trim(), page + 1 ) }
				>
					{ __(
						'Show more',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</button>
			) }
		</div>
	);
}

/**
 * The ids to send for a lookup value: one id, or a list.
 *
 * @param {Array<Object>|*} value    Chosen items.
 * @param {boolean}         multiple Whether several items may be chosen.
 * @return {string|Array<string>|undefined} Ids.
 */
export function lookupIds( value, multiple ) {
	const ids = ( Array.isArray( value ) ? value : [] )
		.map( ( item ) => ( typeof item === 'string' ? item : item?.id ) )
		.filter( Boolean );
	return multiple ? ids : ids[ 0 ];
}
