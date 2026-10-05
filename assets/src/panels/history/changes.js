/**
 * A batch's changes, in place under its card (SPEC §4.6; T36, T57): GET
 * /batches/{id}/changes a page at a time, with product, field, before,
 * after, result and time, searchable within the page on screen. A batch
 * that finished with errors opens on its failing and skipped rows, each
 * with its plain reason and the technical line behind Show details.
 */
import { Button, SearchControl, SelectControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { request } from '../../api';
import { fieldLabel, formatWhen, objectHeading, rowValue } from './describe';
import ErrorNotice from './error-notice';
import { useHistoryExport } from './export';

export const CHANGES_PER_PAGE = 25;

/**
 * A change row's result in words.
 *
 * @param {string} result applied, skipped or failed.
 * @return {string} Words.
 */
export function resultLabel( result ) {
	switch ( result ) {
		case 'applied':
			return __( 'Changed', 'bulk-catalogue-manager-for-woocommerce' );
		case 'skipped':
			return __( 'Skipped', 'bulk-catalogue-manager-for-woocommerce' );
		case 'failed':
			return __( 'Failed', 'bulk-catalogue-manager-for-woocommerce' );
		default:
			return __( 'Waiting', 'bulk-catalogue-manager-for-woocommerce' );
	}
}

/**
 * The result filter for what the table shows.
 *
 * @param {string} show all, or problems (failed and skipped rows).
 * @return {Object} `{ result }` or nothing.
 */
export function filterOf( show ) {
	return show === 'problems' ? { result: [ 'failed', 'skipped' ] } : {};
}

/**
 * The rows of a page matching a search, on product, field, values and reason.
 *
 * @param {Array<Object>} rows   Rows.
 * @param {string}        search Search text.
 * @param {Object}        byKey  Field descriptors by key.
 * @return {Array<Object>} Matching rows.
 */
export function searchRows( rows, search, byKey ) {
	const needle = String( search || '' )
		.trim()
		.toLowerCase();
	if ( ! needle ) {
		return rows;
	}
	return rows.filter( ( row ) =>
		[
			row.name,
			fieldLabel( row.field, byKey, row.field_label ),
			rowValue( row, 'old', byKey[ row.field ] ),
			rowValue( row, 'new', byKey[ row.field ] ),
			row.message,
			resultLabel( row.result ),
		]
			.join( ' ' )
			.toLowerCase()
			.includes( needle )
	);
}

/**
 * One result cell: the word, and for a skipped or failed row its reason
 * with the technical line behind Show details.
 *
 * @param {Object} props     Props.
 * @param {Object} props.row Change row.
 * @return {Element} Cell content.
 */
function Result( { row } ) {
	const [ open, setOpen ] = useState( false );
	return (
		<>
			<span
				className={ `bulkcat-result bulkcat-result--${ row.result }` }
			>
				{ resultLabel( row.result ) }
			</span>
			{ row.result !== 'applied' && row.message && (
				<span className="bulkcat-result__reason">{ row.message }</span>
			) }
			{ row.result !== 'applied' && row.detail && (
				<>
					<Button
						variant="link"
						size="small"
						aria-expanded={ open }
						onClick={ () => setOpen( ( was ) => ! was ) }
					>
						{ open
							? __(
									'Hide details',
									'bulk-catalogue-manager-for-woocommerce'
								)
							: __(
									'Show details',
									'bulk-catalogue-manager-for-woocommerce'
								) }
					</Button>
					{ open && (
						<span className="bulkcat-result__detail">
							{ row.detail }
						</span>
					) }
				</>
			) }
		</>
	);
}

/**
 * The changes table.
 *
 * @param {Object}   props              Props.
 * @param {Object}   props.batch        The batch row.
 * @param {Object}   props.byKey        Field descriptors by key.
 * @param {boolean}  props.problemsOnly Open on the failing and skipped rows.
 * @param {Function} props.onNotice     Show a screen notice; with it, the rows can be downloaded.
 * @return {Element} Table.
 */
export default function Changes( { batch, byKey, problemsOnly, onNotice } ) {
	const [ show, setShow ] = useState( problemsOnly ? 'problems' : 'all' );
	const [ page, setPage ] = useState( 1 );
	const [ search, setSearch ] = useState( '' );
	const [ answer, setAnswer ] = useState( null );
	const [ error, setError ] = useState( null );
	const exporter = useHistoryExport( onNotice );

	useEffect( () => {
		let live = true;
		const query = { page, per_page: CHANGES_PER_PAGE, ...filterOf( show ) };
		request( `batches/${ batch.batch_id }/changes`, { query } )
			.then( ( data ) => {
				if ( live ) {
					setAnswer( data );
					setError( null );
				}
			} )
			.catch( ( caught ) => live && setError( caught ) );
		return () => {
			live = false;
		};
	}, [ batch.batch_id, page, show ] );

	const rows = searchRows( answer?.items || [], search, byKey );
	const total = answer?.pagination?.total || 0;
	const pages = answer?.pagination?.totalPages || 1;
	const caption = sprintf(
		/* translators: %s: the change's number, such as "401". */
		__(
			'Change #%s: every product, field, before and after value is kept.',
			'bulk-catalogue-manager-for-woocommerce'
		),
		String( batch.batch_id )
	);

	return (
		<div className="bulkcat-changes">
			<div className="bulkcat-changes__bar">
				<SearchControl
					__nextHasNoMarginBottom
					label={ __(
						'Search these changes',
						'bulk-catalogue-manager-for-woocommerce'
					) }
					value={ search }
					onChange={ setSearch }
					size="compact"
				/>
				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ __(
						'Show',
						'bulk-catalogue-manager-for-woocommerce'
					) }
					hideLabelFromVision
					value={ show }
					options={ [
						{
							value: 'all',
							label: __(
								'Every row',
								'bulk-catalogue-manager-for-woocommerce'
							),
						},
						{
							value: 'problems',
							label: __(
								'Failed and skipped rows',
								'bulk-catalogue-manager-for-woocommerce'
							),
						},
					] }
					onChange={ ( value ) => {
						setShow( value );
						setPage( 1 );
					} }
				/>
				{ onNotice && (
					<Button
						variant="link"
						onClick={ () =>
							exporter.run( 'changes', {
								batch: batch.batch_id,
								...filterOf( show ),
							} )
						}
						isBusy={ exporter.busy }
						disabled={ exporter.busy }
						accessibleWhenDisabled
					>
						{ __(
							'Download these changes (CSV)',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</Button>
				) }
			</div>
			{ error && (
				<ErrorNotice
					error={ error }
					fallback={ __(
						'The changes could not be loaded. Reload the page and try again.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				/>
			) }
			{ ! answer && ! error && (
				<p className="bulkcat-changes__loading">
					{ __(
						'Loading the changes…',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</p>
			) }
			{ answer && (
				<div className="bulkcat-changes__scroll">
					<table className="bulkcat-changes__table">
						<caption>{ caption }</caption>
						<thead>
							<tr>
								<th scope="col">
									{ objectHeading( answer?.items ) }
								</th>
								<th scope="col">
									{ __(
										'Field',
										'bulk-catalogue-manager-for-woocommerce'
									) }
								</th>
								<th scope="col">
									{ __(
										'Before',
										'bulk-catalogue-manager-for-woocommerce'
									) }
								</th>
								<th scope="col">
									{ __(
										'After',
										'bulk-catalogue-manager-for-woocommerce'
									) }
								</th>
								<th scope="col">
									{ __(
										'Result',
										'bulk-catalogue-manager-for-woocommerce'
									) }
								</th>
								<th scope="col">
									{ __(
										'Time',
										'bulk-catalogue-manager-for-woocommerce'
									) }
								</th>
							</tr>
						</thead>
						<tbody>
							{ rows.map( ( row ) => (
								<tr key={ row.change_id }>
									<th scope="row">{ row.name }</th>
									<td>
										{ fieldLabel(
											row.field,
											byKey,
											row.field_label
										) }
									</td>
									<td>
										{ rowValue(
											row,
											'old',
											byKey[ row.field ]
										) }
									</td>
									<td>
										{ rowValue(
											row,
											'new',
											byKey[ row.field ]
										) }
									</td>
									<td>
										<Result row={ row } />
									</td>
									<td>{ formatWhen( row.applied_gmt ) }</td>
								</tr>
							) ) }
						</tbody>
					</table>
					{ ! rows.length && (
						<p className="bulkcat-changes__empty">
							{ search
								? __(
										'No change on this page matches your search.',
										'bulk-catalogue-manager-for-woocommerce'
									)
								: __(
										'There are no rows to show.',
										'bulk-catalogue-manager-for-woocommerce'
									) }
						</p>
					) }
				</div>
			) }
			{ answer && pages > 1 && (
				<nav
					className="bulkcat-changes__pages"
					aria-label={ __(
						'Pages of changes',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				>
					<Button
						variant="secondary"
						size="small"
						disabled={ page <= 1 }
						onClick={ () => setPage( page - 1 ) }
					>
						{ __(
							'Previous',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</Button>
					<span>
						{ sprintf(
							/* translators: 1: current page; 2: number of pages; 3: number of rows. */
							__(
								'Page %1$s of %2$s (%3$s rows)',
								'bulk-catalogue-manager-for-woocommerce'
							),
							page.toLocaleString(),
							pages.toLocaleString(),
							total.toLocaleString()
						) }
					</span>
					<Button
						variant="secondary"
						size="small"
						disabled={ page >= pages }
						onClick={ () => setPage( page + 1 ) }
					>
						{ __(
							'Next',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</Button>
				</nav>
			) }
		</div>
	);
}
