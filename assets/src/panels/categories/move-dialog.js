/**
 * The Move to… dialog (SPEC §4.7, §14; T64): the same move as a drag,
 * without a pointer. Choose the new parent and the place among its
 * categories; the new path is shown before anything is sent.
 */
import { Button, Modal, SelectControl } from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import ErrorNotice from './error-notice';
import { childrenOf, joinPath, parentOptions, previewPath } from './tree-model';

const LAST = 'last';

/**
 * The dialog.
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.tree    useCategoryTree() result.
 * @param {number}   props.id      The category moved.
 * @param {Function} props.onMove  Called with ( id, parent, position|undefined ); returns a promise.
 * @param {Function} props.onClose Called to close the dialog.
 * @return {Element} The dialog.
 */
export default function MoveDialog( { tree, id, onMove, onClose } ) {
	const entry = tree.byId.get( id );
	const [ parent, setParent ] = useState( String( entry?.parent || 0 ) );
	const [ place, setPlace ] = useState( LAST );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const parents = useMemo(
		() => parentOptions( tree.byId, id ),
		[ tree.byId, id ]
	);
	const siblings = childrenOf(
		tree.nodes,
		tree.byId,
		Number( parent )
	).filter( ( each ) => each !== id );
	const places = [
		{
			value: LAST,
			label: __( 'At the end', 'bulk-catalogue-manager-for-woocommerce' ),
		},
		...siblings.map( ( each, index ) => ( {
			value: String( index ),
			label: sprintf(
				/* translators: %s: a category name. */
				__( 'Before “%s”', 'bulk-catalogue-manager-for-woocommerce' ),
				tree.byId.get( each ).node.name
			),
		} ) ),
	];

	if ( ! entry ) {
		return null;
	}

	const submit = async ( event ) => {
		event.preventDefault();
		setBusy( true );
		setError( null );
		try {
			await onMove(
				id,
				Number( parent ),
				place === LAST ? undefined : Number( place )
			);
			onClose();
		} catch ( failure ) {
			setBusy( false );
			setError( failure || {} );
		}
	};

	return (
		<Modal
			title={ sprintf(
				/* translators: %s: a category name. */
				__( 'Move “%s”', 'bulk-catalogue-manager-for-woocommerce' ),
				entry.node.name
			) }
			onRequestClose={ onClose }
			className="bulkcat-cat-move"
		>
			<form onSubmit={ submit }>
				{ error && (
					<ErrorNotice
						error={ error }
						fallback={ __(
							'The category could not be moved. Try again in a moment.',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					/>
				) }
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __(
						'Move into',
						'bulk-catalogue-manager-for-woocommerce'
					) }
					value={ parent }
					options={ parents }
					onChange={ ( value ) => {
						setParent( value );
						setPlace( LAST );
					} }
				/>
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __(
						'Position',
						'bulk-catalogue-manager-for-woocommerce'
					) }
					value={ place }
					options={ places }
					onChange={ setPlace }
				/>
				<p className="bulkcat-cat-move__preview">
					{ sprintf(
						/* translators: %s: the category's new path, such as "Clothing › T-Shirts". */
						__(
							'New place: %s',
							'bulk-catalogue-manager-for-woocommerce'
						),
						joinPath(
							previewPath( tree.byId, id, Number( parent ) )
						)
					) }
				</p>
				<div className="bulkcat-cat-manager__actions">
					<Button
						variant="primary"
						type="submit"
						isBusy={ busy }
						disabled={ busy }
						__next40pxDefaultSize
					>
						{ __(
							'Move',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</Button>
					<Button
						variant="tertiary"
						onClick={ onClose }
						__next40pxDefaultSize
					>
						{ __(
							'Cancel',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</Button>
				</div>
			</form>
		</Modal>
	);
}
