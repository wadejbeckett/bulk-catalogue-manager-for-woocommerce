/**
 * <CategoryManager>: the details pane that creates a category or edits an
 * existing one's name, slug, description and parent (SPEC §4.7; T63), through
 * POST /categories and PATCH /categories/{id}. Refusals (a duplicate name, a
 * slug in use, a parent inside the category) show in §4.8 wording with the
 * technical line behind Show details; a save refreshes the tree.
 */
import {
	Button,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { announce } from './announce';
import ErrorNotice, { errorMessage } from './error-notice';
import { createCategory, updateCategory } from './requests';
import { parentOptions } from './tree-model';

/**
 * The browser's own check of a form, in the server's wording: null when fine.
 *
 * @param {Object} form { name, slug }.
 * @return {Object|null} A refusal shaped like the server's.
 */
export function validateCategory( form ) {
	if ( ! form.name.trim() ) {
		return {
			code: 'bulkcat_name_required',
			message: __(
				'A category needs a name. Enter a name and try again.',
				'bulk-catalogue-manager-for-woocommerce'
			),
		};
	}
	if ( form.slug.trim() && ! /[\p{L}\p{N}]/u.test( form.slug ) ) {
		return {
			code: 'bulkcat_slug_invalid',
			message: __(
				'A slug needs at least one letter or number. Enter a slug like summer-sale.',
				'bulk-catalogue-manager-for-woocommerce'
			),
		};
	}
	return null;
}

/**
 * The details pane.
 *
 * @param {Object}      props            Props.
 * @param {Object}      props.tree       useCategoryTree() result.
 * @param {number|null} props.categoryId The category to edit; null creates one.
 * @param {number}      props.parentId   Parent a new category starts under; 0 for the top level.
 * @param {boolean}     props.canManage  Whether the user has manage_product_terms.
 * @param {Function}    props.onSaved    Called with the saved category ({ id, name, slug, parent, … }).
 * @param {Function}    props.onCancel   Called when the owner closes the pane without saving.
 * @return {Element} The pane.
 */
export default function CategoryManager( {
	tree,
	categoryId = null,
	parentId = 0,
	canManage = false,
	onSaved = () => {},
	onCancel,
} ) {
	const entry = categoryId ? tree.byId.get( categoryId ) : null;
	// A description saved here since the tree was read, else the tree's own.
	const knownDescription = categoryId
		? ( tree.descriptions?.get( categoryId ) ?? entry?.node.description )
		: '';
	const initial = useMemo(
		() => ( {
			name: entry?.node.name || '',
			slug: entry?.node.slug || '',
			description: knownDescription || '',
			parent: String( entry ? entry.parent || 0 : parentId || 0 ),
		} ),
		// Start again only when another category is opened.
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ categoryId ]
	);
	const [ form, setForm ] = useState( initial );
	const [ error, setError ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const options = useMemo(
		() => parentOptions( tree.byId, categoryId || 0 ),
		[ tree.byId, categoryId ]
	);

	if ( categoryId && ! entry ) {
		return (
			<div className="bulkcat-cat-manager">
				<p>
					{ __(
						'That category could not be found. It may have been deleted; reload the categories and try again.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</p>
			</div>
		);
	}

	const change = ( key ) => ( value ) => {
		setForm( ( current ) => ( { ...current, [ key ]: value } ) );
		setError( null );
	};

	const submit = async ( event ) => {
		event.preventDefault();
		if ( saving ) {
			return;
		}
		const refusal = validateCategory( form );
		if ( refusal ) {
			setError( refusal );
			announce( refusal.message, 'assertive' );
			return;
		}
		const data = {};
		[ 'name', 'slug', 'description' ].forEach( ( key ) => {
			const value =
				key === 'description' ? form[ key ] : form[ key ].trim();
			if ( ! categoryId ? value !== '' : value !== initial[ key ] ) {
				data[ key ] = value;
			}
		} );
		if ( ! categoryId || form.parent !== initial.parent ) {
			data.parent = Number( form.parent ) || 0;
		}
		if ( categoryId && ! Object.keys( data ).length ) {
			onCancel?.();
			return;
		}
		setSaving( true );
		try {
			const answer = categoryId
				? await updateCategory( categoryId, data )
				: await createCategory( data );
			const item = answer?.item || {};
			if ( categoryId ) {
				tree.remember?.( item );
				tree.reload();
				announce(
					sprintf(
						/* translators: %s: a category name. */
						__(
							'Category "%s" saved.',
							'bulk-catalogue-manager-for-woocommerce'
						),
						item.name || form.name.trim()
					)
				);
			} else if ( Number.isInteger( item.id ) && item.id > 0 ) {
				// The tree owner refreshes once and its visible notice announces the result.
				tree.recordCreated( item, form.name.trim() );
			}
			setSaving( false );
			onSaved( item, answer );
		} catch ( failure ) {
			setSaving( false );
			setError( failure || {} );
			announce(
				errorMessage(
					failure,
					__(
						'The category could not be saved. Try again in a moment.',
						'bulk-catalogue-manager-for-woocommerce'
					)
				),
				'assertive'
			);
		}
	};

	const heading = categoryId
		? sprintf(
				/* translators: %s: a category name. */
				__( 'Edit “%s”', 'bulk-catalogue-manager-for-woocommerce' ),
				entry.node.name
			)
		: __( 'Add a category', 'bulk-catalogue-manager-for-woocommerce' );

	if ( ! canManage ) {
		return (
			<div className="bulkcat-cat-manager">
				<h3 className="bulkcat-cat-manager__title">{ heading }</h3>
				<p className="bulkcat-cat-readonly">
					{ __(
						'You can browse and filter by categories, but changing them needs permission to manage product categories.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</p>
			</div>
		);
	}

	return (
		<form className="bulkcat-cat-manager" onSubmit={ submit } noValidate>
			<h3 className="bulkcat-cat-manager__title">{ heading }</h3>
			{ error && (
				<ErrorNotice
					error={ error }
					fallback={ __(
						'The category could not be saved. Try again in a moment.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				/>
			) }
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Name', 'bulk-catalogue-manager-for-woocommerce' ) }
				value={ form.name }
				onChange={ change( 'name' ) }
				required
			/>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __( 'Slug', 'bulk-catalogue-manager-for-woocommerce' ) }
				help={ __(
					'Used in the category’s web address. Leave it empty to make one from the name.',
					'bulk-catalogue-manager-for-woocommerce'
				) }
				value={ form.slug }
				onChange={ change( 'slug' ) }
			/>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ __(
					'Parent category',
					'bulk-catalogue-manager-for-woocommerce'
				) }
				value={ form.parent }
				options={ options }
				onChange={ change( 'parent' ) }
			/>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __(
					'Description',
					'bulk-catalogue-manager-for-woocommerce'
				) }
				help={
					categoryId && knownDescription === undefined
						? __(
								'Leave this empty to keep the current description.',
								'bulk-catalogue-manager-for-woocommerce'
							)
						: undefined
				}
				value={ form.description }
				onChange={ change( 'description' ) }
				rows={ 3 }
			/>
			<div className="bulkcat-cat-manager__actions">
				<Button
					variant="primary"
					type="submit"
					isBusy={ saving }
					disabled={ saving }
					accessibleWhenDisabled
					__next40pxDefaultSize
				>
					{ categoryId
						? __(
								'Save category',
								'bulk-catalogue-manager-for-woocommerce'
							)
						: __(
								'Add category',
								'bulk-catalogue-manager-for-woocommerce'
							) }
				</Button>
				{ onCancel && (
					<Button
						variant="tertiary"
						onClick={ onCancel }
						__next40pxDefaultSize
					>
						{ __(
							'Cancel',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</Button>
				) }
			</div>
		</form>
	);
}
