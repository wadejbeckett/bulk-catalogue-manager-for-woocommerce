/**
 * The products in a category, in the details pane of the Categories tab
 * (SPEC §4.7): one page of GET /products at a time, filtered to the category
 * with or without its subcategories, with the product cell and the default
 * columns the registry names (never a field key here). Each product links to
 * the product grid filtered to the category and searched for it.
 */
import { Button, CheckboxControl } from '@wordpress/components';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { request } from '../../api';
import { panelHref } from '../../router';
import { renderCell } from '../catalogue/cells';
import { COMPANIONS, defaultColumns } from '../catalogue/defaults';
import { descriptorsOf, queryFields, rolesOf } from '../catalogue/fields';
import { categoryArgs } from '../catalogue/query';
import ErrorNotice from './error-notice';
import { catalogueParams } from './workspace-model';

const PER_PAGE = 10;

/**
 * A link to the product grid with these arguments; a plain click stays in
 * the screen.
 *
 * @param {Object}   props          Props.
 * @param {Object}   props.params   catalogueParams() result.
 * @param {Function} props.navigate The shell's navigate( panel, params ), or null.
 * @param {string}   props.label    Accessible name, when it differs from the text.
 * @param {Element}  props.children Text.
 * @return {Element} Link.
 */
export function GridLink( { params, navigate, label, children } ) {
	const url = new URL( panelHref( 'catalogue', window.location.href ) );
	Object.entries( params ).forEach( ( [ key, value ] ) =>
		url.searchParams.set( key, value )
	);
	return (
		<a
			href={ url.toString() }
			aria-label={ label }
			onClick={ ( event ) => {
				if (
					! navigate ||
					event.metaKey ||
					event.ctrlKey ||
					event.shiftKey ||
					event.altKey ||
					event.button !== 0
				) {
					return;
				}
				event.preventDefault();
				navigate( 'catalogue', params );
			} }
		>
			{ children }
		</a>
	);
}

/**
 * The columns: the product cell, then the registry's default columns that
 * are not term lists (the category is the one shown).
 *
 * @param {Object} registry GET /fields answer.
 * @return {Object} { roles, columns, fields, byKey }.
 */
function layout( registry ) {
	const descriptors = descriptorsOf( registry );
	const roles = rolesOf( descriptors );
	const byKey = new Map(
		descriptors.map( ( field ) => [ field.key, field ] )
	);
	const columns = defaultColumns( descriptors )
		.map( ( key ) => byKey.get( key ) )
		.filter(
			( field ) =>
				field &&
				field.key !== roles.title &&
				field.type !== 'terms' &&
				field.type !== 'term'
		);
	const fields = queryFields(
		{
			titleField: roles.title,
			mediaField: roles.media,
			fields: columns.map( ( field ) => field.key ),
		},
		descriptors
	);
	return { roles, columns, fields, byKey };
}

/**
 * The list.
 *
 * @param {Object}   props          Props.
 * @param {number}   props.id       Category id.
 * @param {string}   props.name     Category name.
 * @param {Object}   props.registry GET /fields answer, or null while it loads.
 * @param {Function} props.navigate The shell's navigate( panel, params ), or null.
 * @param {number}   props.revision Changes when the tree is read again, so the list is too.
 * @return {Element} The list.
 */
export default function CategoryProducts( {
	id,
	name,
	registry,
	navigate = null,
	revision = 0,
} ) {
	const [ includeChildren, setIncludeChildren ] = useState( true );
	const [ page, setPage ] = useState( 1 );
	const [ state, setState ] = useState( { status: 'loading' } );
	const [ attempt, setAttempt ] = useState( 0 );
	const shape = useMemo( () => registry && layout( registry ), [ registry ] );

	useEffect( () => {
		setPage( 1 );
	}, [ id, includeChildren ] );

	useEffect( () => {
		if ( ! shape ) {
			return undefined;
		}
		let live = true;
		setState( ( was ) => ( { ...was, status: 'loading' } ) );
		request( 'products', {
			query: {
				page,
				per_page: PER_PAGE,
				fields: shape.fields,
				...categoryArgs( { id, includeChildren } ),
			},
		} ).then(
			( answer ) =>
				live &&
				setState( {
					status: 'ready',
					items: Array.isArray( answer?.items ) ? answer.items : [],
					total: Number( answer?.pagination?.total ) || 0,
					pages: Number( answer?.pagination?.totalPages ) || 0,
				} ),
			( error ) => live && setState( { status: 'failed', error } )
		);
		return () => {
			live = false;
		};
	}, [ shape, id, includeChildren, page, attempt, revision ] );

	const heading = sprintf(
		/* translators: %s: a category name. */
		__( 'Products in %s', 'bulk-catalogue-manager-for-woocommerce' ),
		name
	);
	const pages = Math.max( 1, state.pages || 0 );

	return (
		<section
			className="bulkcat-cat-products"
			aria-labelledby={ `bulkcat-cat-products-${ id }` }
		>
			<div className="bulkcat-cat-products__head">
				<h3
					id={ `bulkcat-cat-products-${ id }` }
					className="bulkcat-cat-pane__subtitle"
				>
					{ __(
						'Products',
						'bulk-catalogue-manager-for-woocommerce'
					) }
					{ state.status !== 'failed' &&
						Number.isFinite( state.total ) && (
							<span className="bulkcat-cat-products__total">
								{ state.total.toLocaleString() }
							</span>
						) }
				</h3>
				<CheckboxControl
					__nextHasNoMarginBottom
					label={ __(
						'Include products in subcategories',
						'bulk-catalogue-manager-for-woocommerce'
					) }
					checked={ includeChildren }
					onChange={ setIncludeChildren }
				/>
				<GridLink
					params={ catalogueParams( [ id ] ) }
					navigate={ navigate }
				>
					{ __(
						'Show them in the product grid',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</GridLink>
			</div>
			{ state.status === 'failed' && (
				<div className="bulkcat-cat-products__state">
					<ErrorNotice
						error={ state.error }
						fallback={ __(
							'The products in this category could not be loaded.',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					/>
					<Button
						variant="secondary"
						onClick={ () => setAttempt( ( n ) => n + 1 ) }
					>
						{ __(
							'Try again',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</Button>
				</div>
			) }
			{ ( ! shape ||
				( state.status === 'loading' && ! state.items ) ) && (
				<p className="bulkcat-cat-products__state">
					{ __(
						'Loading products…',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</p>
			) }
			{ shape && state.items && state.items.length === 0 && (
				<p className="bulkcat-cat-products__state">
					{ includeChildren
						? __(
								'No products are in this category or its subcategories.',
								'bulk-catalogue-manager-for-woocommerce'
							)
						: __(
								'No products are in this category itself.',
								'bulk-catalogue-manager-for-woocommerce'
							) }
				</p>
			) }
			{ shape && state.items && state.items.length > 0 && (
				<>
					<table
						className={
							'bulkcat-cat-products__table widefat striped' +
							( state.status === 'loading' ? ' is-loading' : '' )
						}
						aria-label={ heading }
						aria-busy={ state.status === 'loading' || undefined }
					>
						<thead>
							<tr>
								<th scope="col">
									{ __(
										'Product',
										'bulk-catalogue-manager-for-woocommerce'
									) }
								</th>
								{ shape.columns.map( ( field ) => (
									<th scope="col" key={ field.key }>
										{ field.label }
									</th>
								) ) }
							</tr>
						</thead>
						<tbody>
							{ state.items.map( ( item ) => {
								const title = String(
									item[ shape.roles.title ] ?? ''
								);
								return (
									<tr key={ item.id }>
										<td className="bulkcat-cat-products__name">
											{ shape.roles.media &&
												renderCell( {
													item,
													descriptor: shape.byKey.get(
														shape.roles.media
													),
													role: 'media',
												} ) }
											<GridLink
												params={ catalogueParams(
													[ id ],
													{ search: title }
												) }
												navigate={ navigate }
											>
												{ title ||
													__(
														'(no name)',
														'bulk-catalogue-manager-for-woocommerce'
													) }
											</GridLink>
										</td>
										{ shape.columns.map( ( field ) => (
											<td key={ field.key }>
												{ renderCell( {
													item,
													descriptor: field,
													companion: shape.byKey.get(
														COMPANIONS[ field.key ]
													),
													role: '',
												} ) }
											</td>
										) ) }
									</tr>
								);
							} ) }
						</tbody>
					</table>
					<div className="bulkcat-cat-products__pages">
						<Button
							variant="secondary"
							size="compact"
							disabled={ page <= 1 }
							accessibleWhenDisabled
							onClick={ () => setPage( ( n ) => n - 1 ) }
							label={ __(
								'Previous page',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						>
							‹
						</Button>
						<span>
							{ sprintf(
								/* translators: 1: the page shown, 2: how many pages there are. */
								__(
									'Page %1$d of %2$d',
									'bulk-catalogue-manager-for-woocommerce'
								),
								page,
								pages
							) }
						</span>
						<Button
							variant="secondary"
							size="compact"
							disabled={ page >= pages }
							accessibleWhenDisabled
							onClick={ () => setPage( ( n ) => n + 1 ) }
							label={ __(
								'Next page',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						>
							›
						</Button>
						<span className="bulkcat-cat-products__count">
							{ sprintf(
								/* translators: %s: how many products the list holds. */
								_n(
									'%s product',
									'%s products',
									state.total,
									'bulk-catalogue-manager-for-woocommerce'
								),
								state.total.toLocaleString()
							) }
						</span>
					</div>
				</>
			) }
		</section>
	);
}
