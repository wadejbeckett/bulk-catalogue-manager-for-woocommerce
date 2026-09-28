/**
 * Review selected products (T84, SPEC §4.1): the whole selection, a page at a
 * time from the server's inspection, including rows no search on screen
 * shows, with the counts by type and Remove on every row. A member that no
 * longer exists, or that the user may no longer read, keeps its place as "no
 * longer available", without any of its details, and can be removed too.
 *
 * A slide-over like the details drawer: focus moves into it, Tab stays in
 * it, Escape or Close returns focus to what opened it.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { announce } from '../../categories/announce';
import { formatNumber } from '../../bulk/words';
import { selectedWords } from './model';

/** Elements a Tab press may land on inside the drawer. */
const FOCUSABLE =
	'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * A member's type in words.
 *
 * @param {Object} item Inspected member.
 * @return {string} Label.
 */
function typeLabel( item ) {
	return item.object_type === 'product_variation'
		? __( 'Variation', 'bulk-catalogue-manager-for-woocommerce' )
		: __( 'Product', 'bulk-catalogue-manager-for-woocommerce' );
}

/**
 * The review drawer.
 *
 * @param {Object}   props           Props.
 * @param {Object}   props.selection useSelection().
 * @param {Function} props.onClose   Close it.
 * @param {number}   props.perPage   Rows per page.
 * @return {Element} Drawer.
 */
export default function SelectedProducts( {
	selection,
	onClose,
	perPage = 25,
} ) {
	const panel = useRef( null );
	const heading = useRef( null );
	const [ page, setPage ] = useState( 1 );
	const [ answer, setAnswer ] = useState( null );
	const [ failed, setFailed ] = useState( false );
	const [ removing, setRemoving ] = useState( 0 );
	const { inspectPage } = selection;
	const key = selection.current?.key || '';

	// Focus in on open, back to the opener on close (§14).
	useEffect( () => {
		const opener = panel.current?.ownerDocument?.activeElement;
		heading.current?.focus();
		return () => {
			if ( opener && typeof opener.focus === 'function' ) {
				opener.focus();
			}
		};
	}, [] );

	// The page, read again whenever the selection's snapshot changes.
	useEffect( () => {
		let live = true;
		if ( ! key ) {
			setAnswer( null );
			return undefined;
		}
		setFailed( false );
		inspectPage( page, perPage )
			.then( ( next ) => {
				if ( ! live ) {
					return;
				}
				const pages = Number( next?.pagination?.total_pages ) || 1;
				if ( page > pages ) {
					setPage( pages );
					return;
				}
				setAnswer( next );
			} )
			.catch( () => live && setFailed( true ) );
		return () => {
			live = false;
		};
	}, [ key, page, perPage, inspectPage ] );

	const remove = ( item ) => {
		setRemoving( item.id );
		const name =
			item.state === 'available' && item.name
				? String( item.name )
				: __(
						'The product that is no longer available',
						'bulk-catalogue-manager-for-woocommerce'
					);
		selection.remove( [ item.id ] ).then( ( ok ) => {
			setRemoving( 0 );
			if ( ok ) {
				announce(
					sprintf(
						/* translators: %s: a product's name. */
						__(
							'%s was removed from the selection.',
							'bulk-catalogue-manager-for-woocommerce'
						),
						name
					)
				);
				heading.current?.focus();
			}
		} );
	};

	const onKeyDown = ( event ) => {
		if ( event.key === 'Escape' ) {
			event.stopPropagation();
			onClose();
			return;
		}
		if ( event.key !== 'Tab' || ! panel.current ) {
			return;
		}
		const items = Array.from( panel.current.querySelectorAll( FOCUSABLE ) );
		if ( ! items.length ) {
			return;
		}
		const first = items[ 0 ];
		const last = items[ items.length - 1 ];
		const at = panel.current.ownerDocument.activeElement;
		if ( event.shiftKey && ( at === first || at === heading.current ) ) {
			event.preventDefault();
			last.focus();
		} else if ( ! event.shiftKey && at === last ) {
			event.preventDefault();
			first.focus();
		}
	};

	const items = answer?.items || [];
	const pages = Number( answer?.pagination?.total_pages ) || 1;
	const busy = selection.status !== 'idle';
	return (
		<>
			{ /* A pointer-only shortcut; Close and Escape do the same. */ }
			<div
				className="bulkcat-drawer__scrim"
				aria-hidden="true"
				onClick={ onClose }
			/>
			{ /* The drawer's own keys: Escape closes it and Tab stays inside it (§14). */ }
			{ /* eslint-disable-next-line jsx-a11y/no-noninteractive-element-interactions */ }
			<aside
				ref={ panel }
				className="bulkcat-drawer bulkcat-review"
				role="dialog"
				aria-modal="true"
				aria-labelledby="bulkcat-review-title"
				onKeyDown={ onKeyDown }
			>
				<header className="bulkcat-drawer__head">
					<h2
						id="bulkcat-review-title"
						ref={ heading }
						tabIndex={ -1 }
					>
						{ __(
							'Selected products',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</h2>
					<button
						type="button"
						className="bulkcat-drawer__close"
						onClick={ onClose }
						aria-label={ __(
							'Close the selected products',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					>
						<span aria-hidden="true">×</span>
					</button>
				</header>
				<div className="bulkcat-drawer__body">
					<p className="bulkcat-review__count" role="status">
						{ selection.hasSelection
							? selectedWords( selection.count, selection.byType )
							: __(
									'Nothing is selected.',
									'bulk-catalogue-manager-for-woocommerce'
								) }
					</p>
					<p className="bulkcat-drawer__hint">
						{ __(
							'Everything selected, including products the list is not showing now. Removing one leaves the rest selected.',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</p>
					{ failed && (
						<p className="bulkcat-review__error" role="alert">
							{ __(
								'The selected products could not be listed. Close this and try again.',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</p>
					) }
					{ selection.hasSelection && ! answer && ! failed && (
						<p className="bulkcat-drawer__loading">
							{ __(
								'Loading the selected products…',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</p>
					) }
					{ items.length > 0 && (
						<ul className="bulkcat-review__list">
							{ items.map( ( item ) => {
								const available = item.state === 'available';
								const name = available
									? String( item.name || '' )
									: __(
											'No longer available',
											'bulk-catalogue-manager-for-woocommerce'
										);
								return (
									<li
										key={ item.id }
										className={
											'bulkcat-review__item' +
											( available
												? ''
												: ' is-unavailable' )
										}
									>
										<span className="bulkcat-review__name">
											{ name }
										</span>
										<span className="bulkcat-review__meta">
											{ typeLabel( item ) }
											{ available && item.sku
												? ' · ' + String( item.sku )
												: '' }
										</span>
										<button
											type="button"
											className="button button-small"
											disabled={
												busy || removing === item.id
											}
											onClick={ () => remove( item ) }
											aria-label={
												available
													? sprintf(
															/* translators: %s: a product's name. */
															__(
																'Remove %s from the selection',
																'bulk-catalogue-manager-for-woocommerce'
															),
															name
														)
													: __(
															'Remove this product that is no longer available from the selection',
															'bulk-catalogue-manager-for-woocommerce'
														)
											}
										>
											{ __(
												'Remove',
												'bulk-catalogue-manager-for-woocommerce'
											) }
										</button>
									</li>
								);
							} ) }
						</ul>
					) }
					{ pages > 1 && (
						<nav
							className="bulkcat-review__pages"
							aria-label={ __(
								'Pages of selected products',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						>
							<button
								type="button"
								className="button button-small"
								disabled={ page <= 1 }
								onClick={ () => setPage( page - 1 ) }
							>
								{ __(
									'Previous',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</button>
							<span>
								{ sprintf(
									/* translators: 1: this page's number, such as "2"; 2: the number of pages, such as "5". */
									__(
										'Page %1$s of %2$s',
										'bulk-catalogue-manager-for-woocommerce'
									),
									formatNumber( page ),
									formatNumber( pages )
								) }
							</span>
							<button
								type="button"
								className="button button-small"
								disabled={ page >= pages }
								onClick={ () => setPage( page + 1 ) }
							>
								{ __(
									'Next',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</button>
						</nav>
					) }
					<p>
						<button
							type="button"
							className="button"
							onClick={ onClose }
						>
							{ __(
								'Close',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</button>
					</p>
				</div>
			</aside>
		</>
	);
}
