/**
 * Move several categories into one parent (SPEC §4.7): the Move to… of the
 * workspace's selection. Each chosen category that is not already inside
 * another chosen one moves to the end of the new parent, one move at a time,
 * as a drag or Move to… would move it; a refusal stops the rest and is shown.
 */
import { Button, Modal, SelectControl } from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import ErrorNotice from './error-notice';
import { isInside, parentOptions } from './tree-model';
import { topmost } from './workspace-model';

/**
 * The dialog.
 *
 * @param {Object}   props         Props.
 * @param {Object}   props.tree    useCategoryTree() result.
 * @param {number[]} props.ids     The chosen categories.
 * @param {Function} props.onMove  Called with ( id, parent ); returns a promise.
 * @param {Function} props.onClose Called to close the dialog.
 * @return {Element} The dialog.
 */
export default function MoveManyDialog( { tree, ids, onMove, onClose } ) {
	const moved = useMemo(
		() => topmost( tree.byId, ids ),
		[ tree.byId, ids ]
	);
	const parents = useMemo(
		() =>
			parentOptions( tree.byId, 0 ).filter(
				( option ) =>
					! ids.some( ( id ) =>
						isInside( tree.byId, id, Number( option.value ) )
					)
			),
		[ tree.byId, ids ]
	);
	const [ parent, setParent ] = useState( parents[ 0 ]?.value || '0' );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const title = sprintf(
		/* translators: %d: how many categories are moved. */
		_n(
			'Move %d category',
			'Move %d categories',
			ids.length,
			'bulk-catalogue-manager-for-woocommerce'
		),
		ids.length
	);

	const submit = async ( event ) => {
		event.preventDefault();
		setBusy( true );
		setError( null );
		for ( const id of moved ) {
			try {
				await onMove( id, Number( parent ) );
			} catch ( failure ) {
				setBusy( false );
				setError( failure || {} );
				return;
			}
		}
		onClose();
	};

	return (
		<Modal
			title={ title }
			onRequestClose={ onClose }
			className="bulkcat-cat-move"
		>
			<form onSubmit={ submit }>
				{ error && (
					<ErrorNotice
						error={ error }
						fallback={ __(
							'Not every category could be moved. The ones moved before the problem stay moved; try the rest again in a moment.',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					/>
				) }
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __(
						'New parent category',
						'bulk-catalogue-manager-for-woocommerce'
					) }
					help={ __(
						'Each one goes to the end of the new parent’s categories, with everything inside it.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
					value={ parent }
					options={ parents }
					onChange={ setParent }
				/>
				<div className="bulkcat-cat-manager__actions">
					<Button
						variant="primary"
						type="submit"
						isBusy={ busy }
						disabled={ busy }
						accessibleWhenDisabled
						__next40pxDefaultSize
					>
						{ title }
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
