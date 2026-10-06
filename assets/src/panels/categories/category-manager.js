/**
 * <CategoryManager>: the details pane that creates a category or edits an
 * existing one's name, slug, description and parent (SPEC §4.7; T63), through
 * POST /categories and PATCH /categories/{id}. Refusals (a duplicate name, a
 * slug in use, a parent inside the category) show in §4.8 wording with the
 * technical line behind Show details; a save refreshes the tree. Ticket 22:
 * WooCommerce's own Display type and category image, read for an existing
 * category through GET /categories/{id}; the image is chosen in the media
 * library and only changed values are sent.
 */
import {
	Button,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { announce } from './announce';
import ErrorNotice, { errorMessage } from './error-notice';
import { createCategory, fetchCategory, updateCategory } from './requests';
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
 * WooCommerce's display types; '' is Default.
 *
 * @return {Array<Object>} Options.
 */
function displayTypes() {
	return [
		{
			value: '',
			label: __( 'Default', 'bulk-catalogue-manager-for-woocommerce' ),
		},
		{
			value: 'products',
			label: __( 'Products', 'bulk-catalogue-manager-for-woocommerce' ),
		},
		{
			value: 'subcategories',
			label: __(
				'Subcategories',
				'bulk-catalogue-manager-for-woocommerce'
			),
		},
		{
			value: 'both',
			label: __( 'Both', 'bulk-catalogue-manager-for-woocommerce' ),
		},
	];
}

/**
 * Open the media library to choose one image.
 *
 * @param {Function} onChoose Called with { id, url }.
 */
function chooseImage( onChoose ) {
	const frame = window.wp.media( {
		title: __( 'Category image', 'bulk-catalogue-manager-for-woocommerce' ),
		button: {
			text: __( 'Use image', 'bulk-catalogue-manager-for-woocommerce' ),
		},
		library: { type: 'image' },
		multiple: false,
	} );
	frame.on( 'select', () => {
		const chosen = frame.state().get( 'selection' ).first()?.toJSON();
		if ( chosen && Number.isInteger( chosen.id ) && chosen.id > 0 ) {
			onChoose( {
				id: chosen.id,
				url: chosen.sizes?.thumbnail?.url || chosen.url || '',
			} );
		}
	} );
	frame.open();
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
	// Ticket 22: native display type and image. A new category starts at
	// Default with no image; an existing one's are read before they can change.
	const fresh = { display_type: '', thumbnail_id: 0, thumbnail_url: null };
	const [ details, setDetails ] = useState( () =>
		categoryId && canManage
			? { state: 'loading' }
			: { state: 'ready', ...fresh }
	);
	const [ chosen, setChosen ] = useState( fresh );
	useEffect( () => {
		if ( ! categoryId || ! canManage ) {
			return undefined;
		}
		let live = true;
		setDetails( { state: 'loading' } );
		Promise.resolve()
			.then( () => fetchCategory( categoryId ) )
			.then(
				( answer ) => {
					const item = answer?.item;
					if ( ! live ) {
						return;
					}
					if ( typeof item?.display_type !== 'string' ) {
						setDetails( { state: 'failed' } );
						return;
					}
					const read = {
						display_type: item.display_type,
						thumbnail_id: Number( item.thumbnail_id ) || 0,
						thumbnail_url: item.thumbnail_url || null,
					};
					setDetails( { state: 'ready', ...read } );
					setChosen( read );
				},
				() => live && setDetails( { state: 'failed' } )
			);
		return () => {
			live = false;
		};
	}, [ categoryId, canManage ] );
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
		if ( details.state === 'ready' ) {
			if ( chosen.display_type !== details.display_type ) {
				data.display_type = chosen.display_type;
			}
			if ( chosen.thumbnail_id !== details.thumbnail_id ) {
				data.thumbnail_id = chosen.thumbnail_id;
			}
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
			if ( categoryId && details.state === 'ready' ) {
				setDetails( { state: 'ready', ...chosen } );
			}
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
			if ( failure?.data?.applied ) {
				// Made but not recorded (SILENT-FAILURES-9): show the tree as it now is.
				tree.reload();
			}
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
			{ details.state === 'failed' && (
				<p className="bulkcat-cat-manager__note">
					{ __(
						'The display type and image could not be loaded, so they are left as they are.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</p>
			) }
			{ details.state === 'ready' && (
				<>
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __(
							'Display type',
							'bulk-catalogue-manager-for-woocommerce'
						) }
						help={ __(
							'What the category page shows: products, its subcategories, or both.',
							'bulk-catalogue-manager-for-woocommerce'
						) }
						value={ chosen.display_type }
						options={ displayTypes() }
						onChange={ ( value ) =>
							setChosen( ( current ) => ( {
								...current,
								display_type: value,
							} ) )
						}
					/>
					<fieldset className="bulkcat-cat-manager__image">
						<legend>
							{ __(
								'Image',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</legend>
						{ chosen.thumbnail_id > 0 && chosen.thumbnail_url && (
							<img
								src={ chosen.thumbnail_url }
								alt={ __(
									'Category image',
									'bulk-catalogue-manager-for-woocommerce'
								) }
								width="60"
								height="60"
							/>
						) }
						{ chosen.thumbnail_id > 0 && ! chosen.thumbnail_url && (
							<span>
								{ __(
									'An image is set.',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</span>
						) }
						{ typeof window.wp?.media === 'function' && (
							<Button
								variant="secondary"
								onClick={ () =>
									chooseImage( ( image ) =>
										setChosen( ( current ) => ( {
											...current,
											thumbnail_id: image.id,
											thumbnail_url: image.url,
										} ) )
									)
								}
								__next40pxDefaultSize
							>
								{ chosen.thumbnail_id > 0
									? __(
											'Replace image',
											'bulk-catalogue-manager-for-woocommerce'
										)
									: __(
											'Choose image',
											'bulk-catalogue-manager-for-woocommerce'
										) }
							</Button>
						) }
						{ chosen.thumbnail_id > 0 && (
							<Button
								variant="tertiary"
								isDestructive
								onClick={ () =>
									setChosen( ( current ) => ( {
										...current,
										thumbnail_id: 0,
										thumbnail_url: null,
									} ) )
								}
								__next40pxDefaultSize
							>
								{ __(
									'Remove image',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</Button>
						) }
					</fieldset>
				</>
			) }
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
