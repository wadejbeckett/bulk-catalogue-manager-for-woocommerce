/**
 * The grid's states (SPEC §4.1 States): loading, no products at all, no
 * products matching the filters, and a failed read.
 */
import { Button, Notice } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import { choiceLabel } from './cells';
import { hasValue } from './query';

/** Skeleton widths, in pixels, cycled so the rows do not look like stripes. */
const WIDTHS = [ 140, 176, 212, 158 ];

/**
 * Skeleton rows at the table's real row height while a page loads, so the
 * page does not jump when the rows arrive. The rows carry no information, so
 * they are hidden from assistive technology and the state is one status line.
 *
 * @param {Object} props         Props.
 * @param {number} props.rows    Rows to draw.
 * @param {number} props.columns Columns after the product cell.
 * @return {Element} Skeleton.
 */
export function LoadingRows( { rows = 10, columns = 6 } ) {
	return (
		<div className="bulkcat-skeleton">
			<table className="bulkcat-skeleton__table" aria-hidden="true">
				<tbody>
					{ Array.from( { length: rows }, ( _, row ) => (
						<tr key={ row } className="bulkcat-skeleton__row">
							<td className="bulkcat-skeleton__product">
								<span className="bulkcat-skel is-thumb" />
								<span
									className="bulkcat-skel"
									style={ {
										width: WIDTHS[ row % WIDTHS.length ],
									} }
								/>
							</td>
							{ Array.from(
								{ length: columns },
								( __unused, c ) => (
									<td key={ c }>
										<span className="bulkcat-skel" />
									</td>
								)
							) }
						</tr>
					) ) }
				</tbody>
			</table>
			<p className="bulkcat-skeleton__status" role="status">
				{ __(
					'Loading products…',
					'bulk-catalogue-manager-for-woocommerce'
				) }
			</p>
		</div>
	);
}

/**
 * The shop has no products at all: the sentence and a way to add one, and
 * nothing else on screen.
 *
 * @return {Element} State.
 */
export function NoProducts() {
	return (
		<div className="bulkcat-state">
			<p className="bulkcat-state__title">
				{ __(
					'There are no products yet.',
					'bulk-catalogue-manager-for-woocommerce'
				) }
			</p>
			<p>
				<a
					className="button button-primary"
					href="post-new.php?post_type=product"
				>
					{ __(
						'Add a new product',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</a>
			</p>
		</div>
	);
}

/**
 * Each active filter as a short sentence, the search first.
 *
 * @param {Object}        view        DataViews view.
 * @param {Array<Object>} descriptors Registry descriptors.
 * @return {Array<string>} Sentences.
 */
export function activeFilters( view, descriptors ) {
	const out = [];
	const search = ( view.search || '' ).trim();
	if ( search ) {
		out.push(
			sprintf(
				/* translators: %s: the text searched for. */
				__(
					'Name or SKU contains “%s”',
					'bulk-catalogue-manager-for-woocommerce'
				),
				search
			)
		);
	}
	( view.filters || [] )
		.filter( ( filter ) => hasValue( filter.value ) )
		.forEach( ( filter ) => {
			const descriptor = descriptors.find(
				( field ) => field.key === filter.field
			);
			const values = (
				Array.isArray( filter.value ) ? filter.value : [ filter.value ]
			).map( ( value ) =>
				descriptor ? choiceLabel( descriptor, value ) : String( value )
			);
			out.push(
				sprintf(
					/* translators: 1: a field such as "Stock status", 2: the chosen values, comma separated. */
					__(
						'%1$s: %2$s',
						'bulk-catalogue-manager-for-woocommerce'
					),
					descriptor?.label || filter.field,
					values.join( ', ' )
				)
			);
		} );
	return out;
}

/**
 * Nothing matches the filters: the sentence, what is filtering, and a way
 * back to the whole catalogue.
 *
 * @param {Object}        props            Props.
 * @param {Array<string>} props.filters    Active filter sentences.
 * @param {Function}      props.onClear    Clear every filter.
 * @param {boolean}       props.variations The grid lists variations only (T67).
 * @return {Element} State.
 */
export function NoMatches( { filters, onClear, variations = false } ) {
	let title = __(
		'No products match these filters.',
		'bulk-catalogue-manager-for-woocommerce'
	);
	if ( variations ) {
		title = filters.length
			? __(
					'No variations match these filters.',
					'bulk-catalogue-manager-for-woocommerce'
				)
			: __(
					'No variations match.',
					'bulk-catalogue-manager-for-woocommerce'
				);
	}
	return (
		<div className="bulkcat-state">
			<p className="bulkcat-state__title">{ title }</p>
			{ filters.length > 0 && (
				<ul
					className="bulkcat-state__filters"
					aria-label={ __(
						'Active filters',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				>
					{ filters.map( ( sentence ) => (
						<li key={ sentence }>{ sentence }</li>
					) ) }
				</ul>
			) }
			{ ( ! variations || filters.length > 0 ) && (
				<Button variant="primary" onClick={ onClear }>
					{ __(
						'Clear filters',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</Button>
			) }
		</div>
	);
}

/**
 * A read failed: what failed, Try again, and the technical line behind Show
 * details (SPEC §4.8): the code and the HTTP status, never the server's own
 * text (ticket 11; ../../errors.js). Rows already on screen stay where they
 * are.
 *
 * @param {Object}   props         Props.
 * @param {string}   props.message What failed, as a sentence.
 * @param {Error}    props.error   The failure.
 * @param {Function} props.onRetry Try again.
 * @return {Element} Notice.
 */
export function ErrorNotice( { message, error, onRetry } ) {
	const detail = [
		error?.code,
		error?.data?.status &&
			sprintf(
				/* translators: %d: an HTTP status code such as 400. */
				__( 'HTTP %d', 'bulk-catalogue-manager-for-woocommerce' ),
				error.data.status
			),
	]
		.filter( Boolean )
		.join( ' · ' );
	return (
		<Notice
			className="bulkcat-error"
			status="error"
			isDismissible={ false }
			actions={ [
				{
					label: __(
						'Try again',
						'bulk-catalogue-manager-for-woocommerce'
					),
					onClick: onRetry,
					variant: 'primary',
				},
			] }
		>
			<p>{ message }</p>
			{ detail && (
				<details className="bulkcat-error__details">
					<summary>
						{ __(
							'Show details',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</summary>
					<code>{ detail }</code>
				</details>
			) }
		</Notice>
	);
}
