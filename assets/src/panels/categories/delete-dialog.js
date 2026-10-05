/**
 * Delete… and Merge into… (ticket 24): the confirmation that states what
 * WooCommerce will do — the categories removed, the subcategories that move
 * up, the products affected and those that fall back to the default
 * category, or the products moved into the target — says plainly that it
 * cannot be undone, and confirms with the preview's token.
 */
import { Button, Modal, SelectControl } from '@wordpress/components';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import ErrorNotice from './error-notice';
import { deleteCategories, mergeCategories } from './manage-requests';
import { joinPath, pathNames } from './tree-model';

/**
 * The categories a merge may go into: every category not being merged, by path.
 *
 * @param {Map<number, Object>} byId Index.
 * @param {Array<number>}       ids  The categories merged.
 * @return {Array<{value: string, label: string}>} Options.
 */
export function targetOptions( byId, ids ) {
	const options = [];
	byId.forEach( ( entry, each ) => {
		if ( ! ids.includes( each ) ) {
			options.push( {
				value: String( each ),
				label: joinPath( pathNames( byId, each ) ),
			} );
		}
	} );
	return options;
}

/**
 * What the change will do, as sentences.
 *
 * @param {Object} preview The route's preview.
 * @param {number} into    The merge target, or 0.
 * @return {Array<string>} Sentences.
 */
export function consequences( preview, into ) {
	const lines = [];
	const products = Number( preview?.products ) || 0;
	const moved = Number( preview?.subcategories ) || 0;
	const fallback = Number( preview?.fallback ) || 0;
	if ( into ) {
		lines.push(
			sprintf(
				/* translators: 1: how many products, such as "15"; 2: a category path, such as "Kitchen › Pots". */
				_n(
					'%1$s product moves into %2$s.',
					'%1$s products move into %2$s.',
					products,
					'bulk-catalogue-manager-for-woocommerce'
				),
				products.toLocaleString(),
				preview?.into?.path || ''
			)
		);
	} else {
		lines.push(
			sprintf(
				/* translators: %s: how many products, such as "15". */
				_n(
					'%s product loses this category. The product itself is kept.',
					'%s products lose these categories. The products themselves are kept.',
					products,
					'bulk-catalogue-manager-for-woocommerce'
				),
				products.toLocaleString()
			)
		);
		if ( fallback > 0 ) {
			lines.push(
				sprintf(
					/* translators: 1: how many products, such as "3"; 2: the default category, such as "Uncategorized". */
					_n(
						'%1$s product has no other category, so it goes into the default category, %2$s.',
						'%1$s products have no other category, so they go into the default category, %2$s.',
						fallback,
						'bulk-catalogue-manager-for-woocommerce'
					),
					fallback.toLocaleString(),
					preview?.default?.path || ''
				)
			);
		}
	}
	if ( moved > 0 ) {
		lines.push(
			sprintf(
				/* translators: %s: how many subcategories, such as "2". */
				_n(
					'%s subcategory moves up one level.',
					'%s subcategories move up one level.',
					moved,
					'bulk-catalogue-manager-for-woocommerce'
				),
				moved.toLocaleString()
			)
		);
	}
	return lines;
}

/**
 * The dialog.
 *
 * @param {Object}        props         Props.
 * @param {Object}        props.tree    useCategoryTree() result.
 * @param {Array<number>} props.ids     The categories chosen.
 * @param {boolean}       props.merge   Merge into… rather than Delete….
 * @param {string}        props.notice  A sentence shown under the list, or null.
 * @param {Function}      props.onDone  Called with ( answer, into ) after it ran.
 * @param {Function}      props.onClose Close it.
 * @return {Element} Dialog.
 */
export default function DeleteDialog( {
	tree,
	ids,
	merge,
	notice = null,
	onDone,
	onClose,
} ) {
	const options = useMemo(
		() => ( merge ? targetOptions( tree.byId, ids ) : [] ),
		[ merge, tree.byId, ids ]
	);
	const [ into, setInto ] = useState(
		merge ? options[ 0 ]?.value || '' : ''
	);
	const [ preview, setPreview ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const target = merge ? Number( into ) || 0 : 0;

	useEffect( () => {
		let live = true;
		setPreview( null );
		setError( null );
		if ( merge && ! target ) {
			return undefined;
		}
		const run = merge ? mergeCategories : deleteCategories;
		run( ids, { preview: true }, target )
			.then( ( answer ) => live && setPreview( answer ) )
			.catch( ( failure ) => live && setError( failure || {} ) );
		return () => {
			live = false;
		};
	}, [ ids, merge, target ] );

	const names = ids.map( ( id ) => joinPath( pathNames( tree.byId, id ) ) );
	const title = merge
		? _n(
				'Merge this category into another',
				'Merge these categories into another',
				ids.length,
				'bulk-catalogue-manager-for-woocommerce'
			)
		: _n(
				'Delete this category',
				'Delete these categories',
				ids.length,
				'bulk-catalogue-manager-for-woocommerce'
			);

	const confirm = async () => {
		setBusy( true );
		setError( null );
		try {
			const run = merge ? mergeCategories : deleteCategories;
			const answer = await run( ids, { token: preview.token }, target );
			onDone?.( answer, merge ? preview.into : null );
			onClose();
		} catch ( failure ) {
			setBusy( false );
			setError( failure || {} );
		}
	};

	return (
		<Modal
			title={ title }
			onRequestClose={ onClose }
			className="bulkcat-cat-confirm"
		>
			<ul>
				{ names.map( ( name, index ) => (
					<li key={ ids[ index ] }>{ name }</li>
				) ) }
			</ul>
			{ notice && <p>{ notice }</p> }
			{ merge && (
				<SelectControl
					__next40pxDefaultSize
					__nextHasNoMarginBottom
					label={ __(
						'Merge into',
						'bulk-catalogue-manager-for-woocommerce'
					) }
					value={ into }
					options={ options }
					onChange={ setInto }
				/>
			) }
			{ error && (
				<ErrorNotice
					error={ error }
					fallback={ __(
						'This could not be checked or done. Reload the categories and try again.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				/>
			) }
			{ ! preview && ! error && (
				<p>
					{ __(
						'Checking what this would change…',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</p>
			) }
			{ preview && (
				<>
					{ consequences( preview, target ).map( ( line ) => (
						<p key={ line }>{ line }</p>
					) ) }
					<p className="bulkcat-cat-confirm__permanent">
						{ merge
							? __(
									'The merged categories are then deleted. A deleted category cannot be brought back, so this cannot be undone.',
									'bulk-catalogue-manager-for-woocommerce'
								)
							: __(
									'A deleted category cannot be brought back, so this cannot be undone.',
									'bulk-catalogue-manager-for-woocommerce'
								) }
					</p>
				</>
			) }
			<div className="bulkcat-cat-confirm__buttons">
				<Button
					variant="tertiary"
					onClick={ onClose }
					__next40pxDefaultSize
				>
					{ __( 'Cancel', 'bulk-catalogue-manager-for-woocommerce' ) }
				</Button>
				<Button
					variant="primary"
					isDestructive
					isBusy={ busy }
					disabled={ ! preview || busy }
					accessibleWhenDisabled
					onClick={ confirm }
					__next40pxDefaultSize
				>
					{ merge
						? __(
								'Merge',
								'bulk-catalogue-manager-for-woocommerce'
							)
						: __(
								'Delete',
								'bulk-catalogue-manager-for-woocommerce'
							) }
				</Button>
			</div>
		</Modal>
	);
}
