/**
 * The row of views above the catalogue (SPEC §4.1, §10; T52), as the
 * prototype draws it: a segmented group of chips, the built-ins first (All
 * products, On sale, Hidden from site, Low stock, as the registry allows),
 * then the owner's saved views in their order, then "+ Save this view". A
 * chip is pressed while the screen shows exactly that view.
 *
 * Each saved view has a menu: Update with what is on screen, Rename,
 * Duplicate, Move earlier, Move later, Open the screen with this view (or
 * stop), and Delete. Chips can also be dragged into a new order. The first
 * time the list arrives, the default view is applied, so the screen opens
 * with it.
 */
import {
	Button,
	Dropdown,
	MenuGroup,
	MenuItem,
	Modal,
	TextControl,
} from '@wordpress/components';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { ErrorNotice } from '../states';
import { applyBuiltIn, builtInViews, showsBuiltIn } from './presets';
import { fromSaved, showsSaved } from './serialize';
import { moved, useSavedViews } from './use-saved-views';

/**
 * The name dialog for saving and renaming.
 *
 * @param {Object}   props          Props.
 * @param {string}   props.title    Dialog title.
 * @param {string}   props.initial  Starting name.
 * @param {string}   props.submit   Button label.
 * @param {boolean}  props.busy     A write is in flight.
 * @param {Function} props.onSubmit Called with the name.
 * @param {Function} props.onClose  Close.
 * @param {string}   props.error    Why the last attempt was refused, or ''.
 * @return {Element} Dialog.
 */
function NameDialog( {
	title,
	initial,
	submit,
	busy,
	onSubmit,
	onClose,
	error,
} ) {
	const [ name, setName ] = useState( initial );
	return (
		<Modal title={ title } onRequestClose={ onClose } size="small">
			<form
				onSubmit={ ( event ) => {
					event.preventDefault();
					if ( name.trim() ) {
						onSubmit( name.trim() );
					}
				} }
			>
				<TextControl
					__next40pxDefaultSize
					label={ __(
						'Name',
						'bulk-catalogue-manager-for-woocommerce'
					) }
					help={ __(
						'Keeps the filters, search, sort, columns, layout, page size and category you have on now.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
					value={ name }
					onChange={ setName }
					maxLength={ 60 }
				/>
				{ error && (
					<p className="bulkcat-views__error" role="alert">
						{ error }
					</p>
				) }
				<div className="bulkcat-views__dialog-actions">
					<Button variant="tertiary" onClick={ onClose }>
						{ __(
							'Cancel',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</Button>
					<Button
						variant="primary"
						type="submit"
						isBusy={ busy }
						disabled={ busy || ! name.trim() }
						accessibleWhenDisabled
					>
						{ submit }
					</Button>
				</div>
			</form>
		</Modal>
	);
}

/**
 * The confirmation before a saved view is deleted.
 *
 * @param {Object}   props           Props.
 * @param {string}   props.name      The view's name.
 * @param {boolean}  props.busy      A write is in flight.
 * @param {Function} props.onConfirm Delete.
 * @param {Function} props.onClose   Keep it.
 * @return {Element} Dialog.
 */
function DeleteDialog( { name, busy, onConfirm, onClose } ) {
	return (
		<Modal
			title={ __(
				'Delete this view?',
				'bulk-catalogue-manager-for-woocommerce'
			) }
			onRequestClose={ onClose }
			size="small"
		>
			<p>
				{ sprintf(
					/* translators: %s: a saved view's name. */
					__(
						'"%s" will be removed from your saved views. Your products are not changed.',
						'bulk-catalogue-manager-for-woocommerce'
					),
					name
				) }
			</p>
			<div className="bulkcat-views__dialog-actions">
				<Button variant="tertiary" onClick={ onClose }>
					{ __(
						'Keep it',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</Button>
				<Button
					variant="primary"
					isDestructive
					isBusy={ busy }
					disabled={ busy }
					accessibleWhenDisabled
					onClick={ onConfirm }
				>
					{ __(
						'Delete view',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</Button>
			</div>
		</Modal>
	);
}

/**
 * One saved view's menu.
 *
 * @param {Object}   props            Props.
 * @param {Object}   props.item       The saved view.
 * @param {boolean}  props.isFirst    It is first.
 * @param {boolean}  props.isLast     It is last.
 * @param {boolean}  props.isOnScreen The screen shows it unchanged.
 * @param {Function} props.onAction   Called with an action name.
 * @return {Element} Menu.
 */
function ViewMenu( { item, isFirst, isLast, isOnScreen, onAction } ) {
	return (
		<Dropdown
			popoverProps={ { placement: 'bottom-start' } }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<button
					type="button"
					className="bulkcat-views__more"
					aria-expanded={ isOpen }
					aria-label={ sprintf(
						/* translators: %s: a saved view's name. */
						__(
							'Options for %s',
							'bulk-catalogue-manager-for-woocommerce'
						),
						item.name
					) }
					onClick={ onToggle }
				>
					<span aria-hidden="true">⋯</span>
				</button>
			) }
			renderContent={ ( { onClose } ) => {
				const pick = ( action ) => () => {
					onClose();
					onAction( action, item );
				};
				return (
					<>
						<MenuGroup>
							<MenuItem
								onClick={ pick( 'update' ) }
								disabled={ isOnScreen }
							>
								{ __(
									'Update with what is on screen',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</MenuItem>
							<MenuItem onClick={ pick( 'rename' ) }>
								{ __(
									'Rename',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</MenuItem>
							<MenuItem onClick={ pick( 'duplicate' ) }>
								{ __(
									'Duplicate',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</MenuItem>
						</MenuGroup>
						<MenuGroup>
							<MenuItem
								onClick={ pick( 'earlier' ) }
								disabled={ isFirst }
							>
								{ __(
									'Move earlier',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</MenuItem>
							<MenuItem
								onClick={ pick( 'later' ) }
								disabled={ isLast }
							>
								{ __(
									'Move later',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</MenuItem>
						</MenuGroup>
						<MenuGroup>
							<MenuItem onClick={ pick( 'default' ) }>
								{ item.isDefault
									? __(
											'Stop opening the screen with this view',
											'bulk-catalogue-manager-for-woocommerce'
										)
									: __(
											'Open the screen with this view',
											'bulk-catalogue-manager-for-woocommerce'
										) }
							</MenuItem>
							<MenuItem
								onClick={ pick( 'delete' ) }
								isDestructive
							>
								{ __(
									'Delete',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</MenuItem>
						</MenuGroup>
					</>
				);
			} }
		/>
	);
}

/**
 * The chips row.
 *
 * @param {Object}      props              Props.
 * @param {Object}      props.registry     GET /fields answer.
 * @param {Object}      props.view         Current DataViews view.
 * @param {Object|null} props.category     Current category panel value.
 * @param {Object}      props.baseView     The screen's starting view (defaultView()), under a saved view's keys.
 * @param {Function}    props.onApply      Called with ( view, category ) to show a view.
 * @param {boolean}     props.applyDefault Apply the default view once the list arrives.
 * @param {Object}      props.saved        useSavedViews() result, for tests or a shared hook.
 * @return {Element} Row.
 */
export default function SavedViewsBar( {
	registry,
	view,
	category = null,
	baseView = {},
	onApply,
	applyDefault = true,
	saved: given,
} ) {
	const own = useSavedViews();
	const saved = given || own;
	const presets = useMemo( () => builtInViews( registry ), [ registry ] );
	const [ dialog, setDialog ] = useState( null );
	const [ dragging, setDragging ] = useState( null );
	const opened = useRef( false );
	const apply = useRef( onApply );
	apply.current = onApply;

	const { hasLoaded, items, defaultId } = saved;
	useEffect( () => {
		if ( ! hasLoaded || opened.current ) {
			return;
		}
		opened.current = true;
		const first = items.find( ( item ) => item.id === defaultId );
		if ( applyDefault && first ) {
			const state = fromSaved( first, baseView );
			apply.current( state.view, state.category );
		}
	}, [ hasLoaded, items, defaultId, applyDefault, baseView ] );

	const ids = items.map( ( item ) => item.id );
	const { clearError } = saved;
	const close = useCallback( () => {
		setDialog( null );
		clearError();
	}, [ clearError ] );
	const quietly = ( promise ) => promise.catch( () => {} );

	const act = ( action, item ) => {
		switch ( action ) {
			case 'update':
				quietly( saved.update( item.id, view, category ) );
				break;
			case 'duplicate':
				quietly( saved.duplicate( item.id ) );
				break;
			case 'earlier':
			case 'later':
				quietly(
					saved.reorder(
						moved( ids, item.id, action === 'earlier' ? -1 : 1 )
					)
				);
				break;
			case 'default':
				quietly( saved.setDefault( item.id, ! item.isDefault ) );
				break;
			default:
				setDialog( { action, item } );
		}
	};

	const drop = ( target ) => {
		const next = moved( ids, dragging, target );
		setDragging( null );
		if ( next !== ids ) {
			quietly( saved.reorder( next ) );
		}
	};

	return (
		<div className="bulkcat-views">
			{ saved.error && ! dialog && (
				<ErrorNotice
					message={
						saved.error?.message && saved.error?.code
							? saved.error.message
							: __(
									'Your saved views could not be loaded or changed. Check your connection and try again.',
									'bulk-catalogue-manager-for-woocommerce'
								)
					}
					error={ saved.error }
					onRetry={ saved.clearError }
				/>
			) }
			<div
				className="bulkcat-views__chips"
				role="group"
				aria-label={ __(
					'Saved views',
					'bulk-catalogue-manager-for-woocommerce'
				) }
			>
				{ presets.map( ( preset ) => (
					<button
						key={ preset.id }
						type="button"
						className="bulkcat-views__chip"
						aria-pressed={ showsBuiltIn( preset, view, category ) }
						onClick={ () => {
							const state = applyBuiltIn( preset, view );
							onApply( state.view, state.category );
						} }
					>
						{ preset.name }
					</button>
				) ) }
				{ items.map( ( item, index ) => (
					<span
						key={ item.id }
						className={
							'bulkcat-views__saved' +
							( dragging === item.id ? ' is-dragging' : '' )
						}
						draggable
						onDragStart={ ( event ) => {
							setDragging( item.id );
							if ( event.dataTransfer ) {
								event.dataTransfer.effectAllowed = 'move';
							}
						} }
						onDragOver={ ( event ) => {
							if ( dragging ) {
								event.preventDefault();
							}
						} }
						onDrop={ ( event ) => {
							event.preventDefault();
							drop( item.id );
						} }
						onDragEnd={ () => setDragging( null ) }
					>
						<button
							type="button"
							className="bulkcat-views__chip"
							aria-pressed={ showsSaved( item, view, category ) }
							onClick={ () => {
								const state = fromSaved( item, baseView );
								onApply( state.view, state.category );
							} }
						>
							{ item.name }
							{ item.isDefault && (
								<span className="bulkcat-views__default">
									{ __(
										'(opens first)',
										'bulk-catalogue-manager-for-woocommerce'
									) }
								</span>
							) }
						</button>
						<ViewMenu
							item={ item }
							isFirst={ index === 0 }
							isLast={ index === items.length - 1 }
							isOnScreen={ showsSaved( item, view, category ) }
							onAction={ act }
						/>
					</span>
				) ) }
				<button
					type="button"
					className="bulkcat-views__chip is-save"
					onClick={ () => setDialog( { action: 'save' } ) }
				>
					{ __(
						'+ Save this view',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</button>
			</div>
			{ dialog?.action === 'save' && (
				<NameDialog
					title={ __(
						'Save this view',
						'bulk-catalogue-manager-for-woocommerce'
					) }
					initial=""
					submit={ __(
						'Save view',
						'bulk-catalogue-manager-for-woocommerce'
					) }
					busy={ saved.busy }
					error={ saved.error?.message || '' }
					onClose={ close }
					onSubmit={ ( name ) =>
						quietly(
							saved.save( name, view, category ).then( close )
						)
					}
				/>
			) }
			{ dialog?.action === 'rename' && (
				<NameDialog
					title={ __(
						'Rename this view',
						'bulk-catalogue-manager-for-woocommerce'
					) }
					initial={ dialog.item.name }
					submit={ __(
						'Rename',
						'bulk-catalogue-manager-for-woocommerce'
					) }
					busy={ saved.busy }
					error={ saved.error?.message || '' }
					onClose={ close }
					onSubmit={ ( name ) =>
						quietly(
							saved.rename( dialog.item.id, name ).then( close )
						)
					}
				/>
			) }
			{ dialog?.action === 'delete' && (
				<DeleteDialog
					name={ dialog.item.name }
					busy={ saved.busy }
					onClose={ close }
					onConfirm={ () =>
						quietly( saved.remove( dialog.item.id ).then( close ) )
					}
				/>
			) }
		</div>
	);
}
