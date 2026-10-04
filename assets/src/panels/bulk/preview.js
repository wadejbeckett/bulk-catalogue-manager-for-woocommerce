/**
 * The preview (SPEC §4.4, T50), rendered from the POST /preview answer in
 * order: the summary sentence, the counts, retained legacy outcomes when present, the sample table, the warnings; then the sample
 * controls. Nothing here writes.
 */
import { useId } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { termOptions } from './controls';
import { fieldByKey } from './descriptors';
import {
	availabilityLines,
	outcomeLabel,
	publicationSentence,
	statusName,
} from './availability';
import { countPhrase } from '../catalogue/selection/model';
import {
	formatNumber,
	listPhrase,
	productsPhrase,
	variationsPhrase,
} from './words';

/** Sample sizes the route takes (Rest\PreviewController). */
export const SAMPLE_SIZES = [ 25, 100, 'all' ];

/**
 * A stored value as the owner reads it.
 *
 * @param {Object|null} field Field descriptor.
 * @param {*}           value Value.
 * @param {Object}      terms Term options by source.
 * @return {string} Text.
 */
export function formatValue( field, value, terms ) {
	if ( value === null || value === undefined || value === '' ) {
		return __( '(empty)', 'bulk-catalogue-manager-for-woocommerce' );
	}
	if ( typeof value === 'boolean' ) {
		return value
			? __( 'On', 'bulk-catalogue-manager-for-woocommerce' )
			: __( 'Off', 'bulk-catalogue-manager-for-woocommerce' );
	}
	if ( Array.isArray( value ) || field?.type === 'term' ) {
		const list = Array.isArray( value ) ? value : [ value ];
		if ( ! Array.isArray( value ) && Number( value ) === 0 ) {
			return __( 'None', 'bulk-catalogue-manager-for-woocommerce' );
		}
		if ( list.length === 0 ) {
			return __( '(none)', 'bulk-catalogue-manager-for-woocommerce' );
		}
		const options = termOptions( field, terms );
		return list
			.map(
				( id ) =>
					options.find( ( option ) => option.id === Number( id ) )
						?.label || '#' + id
			)
			.join( ', ' );
	}
	const choice = ( field?.choices || [] ).find(
		( each ) => each.value === String( value )
	);
	return choice ? choice.label : String( value );
}

/**
 * An add-on's warnings (T106, docs/EXTENSIONS.md §10), aggregated by the
 * server by code, each object once: each its own sentence with its count,
 * as "<sentence> — 1,200 products", or products and variations apart when
 * variations carry it (ticket 11: a count that can include variations says so).
 *
 * @param {Object|null} external The preview's `external` block.
 * @return {Array<{kind: string, text: string}>} Warnings.
 */
export function externalWarnings( external ) {
	return ( external?.warnings || [] ).map( ( warning ) => {
		const count = Number( warning.count ) || 0;
		const variations = Number( warning.by_type?.variation ) || 0;
		return {
			kind: warning.code,
			text: sprintf(
				/* translators: 1: an add-on's warning, such as "Some products will carry more than three labels."; 2: how many products, such as "1,200 products", or products and variations, such as "1,200 products and 50 variations". */
				__( '%1$s — %2$s', 'bulk-catalogue-manager-for-woocommerce' ),
				warning.sentence,
				variations > 0
					? countPhrase( count, {
							products: Number( warning.by_type?.product ) || 0,
							variations,
						} )
					: productsPhrase( count )
			),
		};
	} );
}

/**
 * The warning sentences, in the block's order (Engine\Preview::WARNINGS).
 * Negative stock is counted in the words of what the change reaches
 * (ticket 11's counting rule, T114): products, variations, or items when it
 * reaches both, from the preview's `totals.by_type`.
 *
 * @param {Object}      warnings Counts by kind.
 * @param {Object|null} byType   The preview's `totals.by_type`, or null.
 * @return {Array<{kind: string, text: string}>} Sentences for the kinds counted.
 */
export function warningSentences( warnings, byType = null ) {
	const count = ( kind ) => Number( warnings?.[ kind ] ) || 0;
	const out = [];
	const add = ( kind, text ) => {
		if ( count( kind ) > 0 ) {
			out.push( { kind, text } );
		}
	};
	add(
		'sale_above_regular',
		sprintf(
			/* translators: %s: a number, such as "3". */
			_n(
				'%s sale price would not be below its regular price.',
				'%s sale prices would not be below their regular price.',
				count( 'sale_above_regular' ),
				'bulk-catalogue-manager-for-woocommerce'
			),
			formatNumber( count( 'sale_above_regular' ) )
		)
	);
	add(
		'price_not_positive',
		sprintf(
			/* translators: %s: a number, such as "3". */
			_n(
				'%s price would fall to zero or below.',
				'%s prices would fall to zero or below.',
				count( 'price_not_positive' ),
				'bulk-catalogue-manager-for-woocommerce'
			),
			formatNumber( count( 'price_not_positive' ) )
		)
	);
	add( 'stock_negative', negativeStock( count( 'stock_negative' ), byType ) );
	add(
		'sku_duplicate',
		sprintf(
			/* translators: %s: a number, such as "3". */
			_n(
				'%s SKU would duplicate another product’s, so it would not be applied.',
				'%s SKUs would duplicate another product’s, so they would not be applied.',
				count( 'sku_duplicate' ),
				'bulk-catalogue-manager-for-woocommerce'
			),
			formatNumber( count( 'sku_duplicate' ) )
		)
	);
	add(
		'publication_password_cleared',
		sprintf(
			/* translators: %s: the number of products whose publication passwords will be cleared. */
			_n(
				'The publication password will be cleared on %s product.',
				'Publication passwords will be cleared on %s products.',
				count( 'publication_password_cleared' ),
				'bulk-catalogue-manager-for-woocommerce'
			),
			formatNumber( count( 'publication_password_cleared' ) )
		)
	);
	return out;
}

/**
 * "Stock would go below zero on 3 products." — or variations, or items when
 * the change reaches both.
 *
 * @param {number}      count  How many.
 * @param {Object|null} byType The preview's `totals.by_type`, or null.
 * @return {string} Sentence.
 */
function negativeStock( count, byType ) {
	const products = Number( byType?.product?.matched ) || 0;
	const variations = Number( byType?.variation?.matched ) || 0;
	if ( products > 0 && variations > 0 ) {
		return sprintf(
			/* translators: %s: a number of products and variations together, such as "3". */
			_n(
				'Stock would go below zero on %s item.',
				'Stock would go below zero on %s items.',
				count,
				'bulk-catalogue-manager-for-woocommerce'
			),
			formatNumber( count )
		);
	}
	if ( variations > 0 ) {
		return sprintf(
			/* translators: %s: a number of variations, such as "3". */
			_n(
				'Stock would go below zero on %s variation.',
				'Stock would go below zero on %s variations.',
				count,
				'bulk-catalogue-manager-for-woocommerce'
			),
			formatNumber( count )
		);
	}
	return sprintf(
		/* translators: %s: a number, such as "3". */
		_n(
			'Stock would go below zero on %s product.',
			'Stock would go below zero on %s products.',
			count,
			'bulk-catalogue-manager-for-woocommerce'
		),
		formatNumber( count )
	);
}

/**
 * The summary sentence: "This will change Regular price on 1,284 products
 * and 3,915 variations."
 *
 * @param {Object}        response   The /preview answer.
 * @param {Array<string>} fieldNames Labels of the fields the change names.
 * @return {string} Sentence.
 */
export function summarySentence( response, fieldNames ) {
	if ( typeof response?.summary === 'string' && response.summary ) {
		// The server's own sentence (T69), which counts products and
		// variations apart.
		return response.summary;
	}
	const totals = response?.totals || {};
	const changed = Number( totals.would_change ) || 0;
	const variations = Number( totals.variations ) || 0;
	const fields = listPhrase( fieldNames );
	if ( variations > 0 ) {
		return sprintf(
			/* translators: 1: fields, such as "Regular price"; 2: products, such as "1,284 products"; 3: variations, such as "3,915 variations". */
			__(
				'This will change %1$s on %2$s and %3$s.',
				'bulk-catalogue-manager-for-woocommerce'
			),
			fields,
			productsPhrase( changed ),
			variationsPhrase( variations )
		);
	}
	return sprintf(
		/* translators: 1: fields, such as "Regular price"; 2: products, such as "1,284 products". */
		__(
			'This will change %1$s on %2$s.',
			'bulk-catalogue-manager-for-woocommerce'
		),
		fields,
		productsPhrase( changed )
	);
}

/**
 * A sample row's current value as text: an add-on's own words (T106, never a
 * raw value), or the stored value formatted by its field.
 *
 * @param {Object|null} field Field descriptor.
 * @param {Object}      row   Sample row.
 * @param {Object}      terms Term options by source.
 * @return {string} Text.
 */
function nowText( field, row, terms ) {
	if ( row.external ) {
		return row.current || '—';
	}
	return formatValue( field, row.current, terms );
}

/**
 * One sample row's After cell.
 *
 * @param {Object} props          Props.
 * @param {Object} props.row      Sample row.
 * @param {Object} props.field    Field descriptor.
 * @param {Object} props.terms    Term options by source.
 * @param {Object} props.registry The `/fields` payload, for a Status row's Off/On words.
 * @return {Element} Cell content.
 */
function AfterCell( { row, field, terms, registry } ) {
	// A Status step's Off/On outcome (T91) says what happens in its own words.
	const outcome = row.note
		? outcomeLabel( registry, row.note, row.context || {} )
		: '';
	if ( outcome ) {
		return (
			<span
				className={
					row.state === 'changed'
						? 'bulkcat-bulk__to'
						: 'bulkcat-bulk__note'
				}
			>
				{ outcome }
			</span>
		);
	}
	if ( row.state === 'already' ) {
		return (
			<span className="bulkcat-bulk__note">
				{ __(
					'Already correct, left alone',
					'bulk-catalogue-manager-for-woocommerce'
				) }
			</span>
		);
	}
	if ( row.state === 'skipped' || row.state === 'failed' ) {
		return (
			<span className="bulkcat-bulk__note">
				{ sprintf(
					/* translators: %s: the plain reason, such as "A variable product takes its price from its variations." */
					__(
						'Will be skipped: %s',
						'bulk-catalogue-manager-for-woocommerce'
					),
					row.reason
				) }
			</span>
		);
	}
	return (
		<>
			{ row.replaces && (
				<strong className="bulkcat-bulk__replace">
					{ __(
						'Replaces the whole list with',
						'bulk-catalogue-manager-for-woocommerce'
					) }{ ' ' }
				</strong>
			) }
			<strong className="bulkcat-bulk__to">
				{ row.external
					? row.new
					: formatValue( field, row.new, terms ) }
			</strong>
			{ publicationSentence( registry, row.context ) && (
				<span className="bulkcat-bulk__note">
					{ publicationSentence( registry, row.context ) }
				</span>
			) }
			{ row.password_cleared === true && (
				<span className="bulkcat-bulk__note">
					{ __(
						'The publication password will be cleared.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</span>
			) }
			{ row.external && row.change && (
				<span className="bulkcat-bulk__change">{ row.change }</span>
			) }
			{ row.external && ( row.notes || [] ).length > 0 && (
				<ul className="bulkcat-bulk__notes">
					{ row.notes.map( ( note, index ) => (
						<li key={ index }>{ note }</li>
					) ) }
				</ul>
			) }
		</>
	);
}

/**
 * The preview's Off and On block (T91, SPEC §9.1): each counted outcome in
 * words, conflicts first, then the variations a Published step leaves as they
 * are, and the products changed elsewhere while Off by name.
 *
 * @param {Object}      props          Props.
 * @param {Object}      props.registry The `/fields` payload.
 * @param {Object|null} props.block    The preview's `availability` block.
 * @return {Element|null} The block, or nothing when no step sets Status.
 */
function AvailabilityBlock( { registry, block } ) {
	const lines = availabilityLines( registry, block );
	const conflicts = Array.isArray( block?.conflicts ) ? block.conflicts : [];
	if ( ! block || ( lines.length === 0 && conflicts.length === 0 ) ) {
		return null;
	}
	return (
		<div className="bulkcat-bulk__card" data-block="availability">
			<h3>
				{ __(
					'Legacy Status outcomes',
					'bulk-catalogue-manager-for-woocommerce'
				) }
			</h3>
			<ul className="bulkcat-bulk__lines">
				{ lines.map( ( line ) => (
					<li key={ line.code } className={ 'is-' + line.code }>
						{ line.text }
					</li>
				) ) }
			</ul>
			{ conflicts.length > 0 && (
				<>
					<p className="bulkcat-bulk__lbl">
						{ __(
							'Changed by someone else while Off',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</p>
					<ul className="bulkcat-bulk__conflicts">
						{ conflicts.map( ( conflict ) => (
							<li key={ conflict.object_id }>
								{ sprintf(
									/* translators: 1: a product's name; 2: its status now, such as "Private"; 3: the status saved before Off, such as "Published". */
									__(
										'%1$s: now %2$s, saved as %3$s',
										'bulk-catalogue-manager-for-woocommerce'
									),
									conflict.name,
									statusName( registry, conflict.current ),
									statusName( registry, conflict.saved )
								) }
							</li>
						) ) }
					</ul>
				</>
			) }
		</div>
	);
}

/**
 * The preview.
 *
 * @param {Object}        props               Props.
 * @param {Object}        props.response      The /preview answer.
 * @param {Object}        props.registry      The `/fields` payload.
 * @param {Array<Object>} props.operations    The operations previewed.
 * @param {Object}        props.terms         Term options by source.
 * @param {number|string} props.sampleSize    25, 100 or 'all'.
 * @param {Function}      props.onSampleSize  Receives a new sample size.
 * @param {boolean}       props.changedOnly   Whether only changed rows show.
 * @param {Function}      props.onChangedOnly Receives the filter's position.
 * @param {Function}      props.onDownload    Downloads the sample, or null until the export route exists.
 * @param {boolean}       props.busy          Whether a new preview is computing.
 * @return {Element} Preview.
 */
export function PreviewResult( {
	response,
	registry,
	operations,
	terms,
	sampleSize,
	onSampleSize,
	changedOnly,
	onChangedOnly,
	onDownload = null,
	busy = false,
} ) {
	if ( response?.serializer === 2 ) {
		return (
			<TypedPreview
				response={ response }
				registry={ registry }
				sampleSize={ sampleSize }
				onSampleSize={ onSampleSize }
				changedOnly={ changedOnly }
				onChangedOnly={ onChangedOnly }
				busy={ busy }
			/>
		);
	}
	const totals = response?.totals || {};
	const byType = totals.by_type || {};
	const variationRows = Number( byType.variation?.matched ) || 0;
	const sample = Array.isArray( response?.sample ) ? response.sample : [];
	const rows = changedOnly
		? sample.filter( ( row ) => row.state === 'changed' )
		: sample;
	const failures = Object.entries( totals.would_fail || {} );
	const warnings = [
		...warningSentences( response?.warnings, byType ),
		...externalWarnings( response?.external ),
	];
	const unreadable = Array.isArray( response?.unreadable )
		? response.unreadable
		: [];
	// T83: every chosen object that cannot be changed now, even beyond the
	// listed ones; they stay in the change and are recorded as not changed.
	const unavailable =
		Number( response?.unavailable_count ?? unreadable.length ) || 0;
	const allRows = Object.values( totals.rows || {} ).reduce(
		( sum, each ) => sum + ( Number( each ) || 0 ),
		0
	);
	const fieldNames = [
		...new Set(
			operations
				.map( ( operation ) =>
					fieldByKey( registry, operation.args?.field )
				)
				.filter( Boolean )
				.map( ( field ) => field.label )
		),
	];

	return (
		<div className="bulkcat-bulk__preview" aria-busy={ busy || undefined }>
			<p className="bulkcat-bulk__summary">
				{ summarySentence( response, fieldNames ) }
			</p>
			{ totals.estimated && (
				<p className="bulkcat-bulk__hint">
					{ sprintf(
						/* translators: %s: a number of products, such as "10,000". */
						__(
							'These counts are worked out from the first %s and scaled to the whole selection.',
							'bulk-catalogue-manager-for-woocommerce'
						),
						productsPhrase( totals.computed )
					) }
				</p>
			) }

			<div className="bulkcat-bulk__card">
				<h3>
					{ __(
						'What would happen',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</h3>
				<dl className="bulkcat-bulk__counts">
					<div className="bulkcat-bulk__kv">
						<dt>
							{ __(
								'Will change',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</dt>
						<dd>{ formatNumber( totals.would_change ) }</dd>
					</div>
					{ variationRows > 0 && (
						<>
							<div className="bulkcat-bulk__kv is-sub">
								<dt>
									{ __(
										'Products that will change',
										'bulk-catalogue-manager-for-woocommerce'
									) }
								</dt>
								<dd>
									{ formatNumber(
										byType.product?.would_change
									) }
								</dd>
							</div>
							<div className="bulkcat-bulk__kv is-sub">
								<dt>
									{ __(
										'Variations that will change',
										'bulk-catalogue-manager-for-woocommerce'
									) }
								</dt>
								<dd>
									{ formatNumber(
										byType.variation?.would_change
									) }
								</dd>
							</div>
						</>
					) }
					<div className="bulkcat-bulk__kv">
						<dt>
							{ __(
								'Already at the target value',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</dt>
						<dd>{ formatNumber( totals.already ) }</dd>
					</div>
					{ failures.length === 0 ? (
						<div className="bulkcat-bulk__kv">
							<dt>
								{ __(
									'Cannot change',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</dt>
							<dd>{ formatNumber( 0 ) }</dd>
						</div>
					) : (
						failures.map( ( [ reason, count ] ) => (
							<div
								className="bulkcat-bulk__kv is-fail"
								key={ reason }
							>
								<dt>
									{ __(
										'Cannot change',
										'bulk-catalogue-manager-for-woocommerce'
									) }
									<span className="bulkcat-bulk__why is-flush">
										{ reason }
									</span>
								</dt>
								<dd>{ formatNumber( count ) }</dd>
							</div>
						) )
					) }
				</dl>
			</div>

			<AvailabilityBlock
				registry={ registry }
				block={ response?.availability || null }
			/>

			<div className="bulkcat-bulk__samplebar">
				<fieldset className="bulkcat-bulk__seg">
					<legend className="bulkcat-bulk__lbl">
						{ __(
							'Rows to show',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</legend>
					{ SAMPLE_SIZES.map( ( size ) => (
						<button
							key={ size }
							type="button"
							className="button button-small"
							aria-pressed={ sampleSize === size }
							disabled={ busy }
							onClick={ () => onSampleSize( size ) }
						>
							{ size === 'all'
								? __(
										'Every row up to 1,000',
										'bulk-catalogue-manager-for-woocommerce'
									)
								: formatNumber( size ) }
						</button>
					) ) }
				</fieldset>
				<label
					className="bulkcat-bulk__check"
					htmlFor="bulkcat-bulk-changed-only"
				>
					<input
						id="bulkcat-bulk-changed-only"
						type="checkbox"
						checked={ changedOnly }
						onChange={ ( event ) =>
							onChangedOnly( event.target.checked )
						}
					/>
					<span>
						{ __(
							'Only rows that change',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</span>
				</label>
				<button
					type="button"
					className="button button-small"
					disabled={ ! onDownload }
					aria-describedby={
						onDownload ? undefined : 'bulkcat-bulk-download-why'
					}
					onClick={ () => onDownload && onDownload() }
				>
					{ __(
						'Download sample CSV',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</button>
				{ ! onDownload && (
					<span
						className="bulkcat-bulk__hint"
						id="bulkcat-bulk-download-why"
					>
						{ __(
							'The download arrives with the export.',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</span>
				) }
			</div>

			<div className="bulkcat-bulk__scroll">
				<table className="bulkcat-bulk__table">
					<caption className="screen-reader-text">
						{ __(
							'A sample of the rows this change would touch',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</caption>
					<thead>
						<tr>
							<th scope="col">
								{ __(
									'Product',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</th>
							<th scope="col">
								{ __(
									'What changes',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</th>
							<th scope="col">
								{ __(
									'Now',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</th>
							<th scope="col">
								{ __(
									'After',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( row, index ) => {
							const field = fieldByKey( registry, row.field );
							const classes = [
								row.object_type === 'variation'
									? 'is-variation'
									: '',
								[ 'skipped', 'failed' ].includes( row.state )
									? 'is-skip'
									: '',
								row.state === 'already' ? 'is-already' : '',
							]
								.filter( Boolean )
								.join( ' ' );
							return (
								<tr
									key={
										row.object_id +
										':' +
										row.field +
										':' +
										index
									}
									className={ classes || undefined }
									data-state={ row.state }
								>
									<th scope="row">{ row.name }</th>
									<td>
										{ row.fieldLabel ||
											field?.label ||
											row.field }
									</td>
									<td className="bulkcat-bulk__from">
										{ row.state === 'changed' ? (
											<del>
												{ nowText( field, row, terms ) }
											</del>
										) : (
											nowText( field, row, terms )
										) }
									</td>
									<td>
										<AfterCell
											row={ row }
											field={ field }
											terms={ terms }
											registry={ registry }
										/>
									</td>
								</tr>
							);
						} ) }
					</tbody>
				</table>
			</div>
			<p className="bulkcat-bulk__hint">
				{ sample.length < allRows
					? sprintf(
							/* translators: 1: rows shown, such as "25"; 2: rows in all, such as "1,284". */
							__(
								'The first %1$s of %2$s rows are shown. The same change applies to the rest.',
								'bulk-catalogue-manager-for-woocommerce'
							),
							formatNumber( sample.length ),
							formatNumber( allRows )
						)
					: __(
							'Every row is shown.',
							'bulk-catalogue-manager-for-woocommerce'
						) }
			</p>

			{ warnings.length > 0 && (
				<div
					className="bulkcat-bulk__notice is-warn"
					data-block="warnings"
				>
					<strong>
						{ __(
							'Check these before applying',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</strong>
					<ul>
						{ warnings.map( ( warning ) => (
							<li
								key={ warning.kind }
								data-warning={ warning.kind }
							>
								{ warning.text }
							</li>
						) ) }
					</ul>
				</div>
			) }

			{ unavailable > 0 && (
				<div
					className="bulkcat-bulk__notice is-warn"
					data-block="unreadable"
				>
					<strong>
						{ sprintf(
							/* translators: %s: a number, such as "3". */
							_n(
								'%s chosen product cannot be changed now. It stays in this change and will be recorded as not changed.',
								'%s chosen products cannot be changed now. They stay in this change and will be recorded as not changed.',
								unavailable,
								'bulk-catalogue-manager-for-woocommerce'
							),
							formatNumber( unavailable )
						) }
					</strong>
					<p>
						{ unreadable
							.slice( 0, 50 )
							.map( ( id ) => '#' + id )
							.join( ', ' ) }
					</p>
				</div>
			) }
		</div>
	);
}

/**
 * Render the accepted S2 typed sample envelope without inventing product fields or value snapshots.
 * @param {Object}   root0               Root0.
 * @param {Object}   root0.response      Response.
 * @param {Object}   root0.registry      Registry.
 * @param {*}        root0.sampleSize    SampleSize.
 * @param {Function} root0.onSampleSize  OnSampleSize.
 * @param {boolean}  root0.changedOnly   ChangedOnly.
 * @param {Function} root0.onChangedOnly OnChangedOnly.
 * @param {boolean}  root0.busy          Busy.
 */
function TypedPreview( {
	response,
	registry,
	sampleSize,
	onSampleSize,
	changedOnly,
	onChangedOnly,
	busy,
} ) {
	const totals = response.totals || {};
	const rows = ( response.sample || [] ).filter(
		( row ) => ! changedOnly || row.state === 'changed'
	);
	const controlId = useId();
	const kindLabel = ( kind ) =>
		( {
			term: __( 'Category', 'bulk-catalogue-manager-for-woocommerce' ),
			variation: __(
				'Variation',
				'bulk-catalogue-manager-for-woocommerce'
			),
			product: __( 'Product', 'bulk-catalogue-manager-for-woocommerce' ),
		} )[ kind ] || '';
	const stateLabel = ( state ) =>
		( {
			changed: __(
				'Will change',
				'bulk-catalogue-manager-for-woocommerce'
			),
			already: __(
				'Already at the target value',
				'bulk-catalogue-manager-for-woocommerce'
			),
			failed: __(
				'Cannot change',
				'bulk-catalogue-manager-for-woocommerce'
			),
			skipped: __( 'Skipped', 'bulk-catalogue-manager-for-woocommerce' ),
		} )[ state ] || '';
	return (
		<div className="bulkcat-bulk__preview" aria-busy={ busy }>
			<p>
				{ sprintf(
					/* translators: %d: number of selected records that would change. */ _n(
						'%d selected item would change.',
						'%d selected items would change.',
						totals.would_change || 0,
						'bulk-catalogue-manager-for-woocommerce'
					),
					totals.would_change || 0
				) }
			</p>
			{ totals.estimated && (
				<p>
					{ __(
						'This preview covers a sample of the selection.',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</p>
			) }
			<dl>
				<dt>
					{ __(
						'Already at the target value',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</dt>
				<dd>{ totals.already || 0 }</dd>
				{ Object.entries( totals.would_fail || {} ).map(
					( [ reason, count ] ) => (
						<div key={ reason }>
							<dt>{ reason }</dt>
							<dd>{ count }</dd>
						</div>
					)
				) }
			</dl>
			<label htmlFor={ controlId + '-changed' }>
				{ __(
					'Changed items only',
					'bulk-catalogue-manager-for-woocommerce'
				) }
				<input
					id={ controlId + '-changed' }
					type="checkbox"
					checked={ changedOnly }
					onChange={ ( event ) =>
						onChangedOnly( event.target.checked )
					}
				/>
			</label>
			<table>
				<thead>
					<tr>
						<th>
							{ __(
								'Item',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</th>
						<th>
							{ __(
								'Field',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</th>
						<th>
							{ __(
								'Result',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</th>
					</tr>
				</thead>
				<tbody>
					{ rows.map( ( row, index ) => (
						<tr
							key={ JSON.stringify( [
								row.target,
								row.field,
								index,
							] ) }
						>
							<td>
								{ kindLabel( row.target.kind ) } #
								{ row.target.id }
							</td>
							<td>
								{ fieldByKey( registry, row.field )?.label ||
									'' }
							</td>
							<td>
								{ stateLabel( row.state ) }
								{ row.reason && <p>{ row.reason }</p> }
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
			<label htmlFor={ controlId + '-sample' }>
				{ __(
					'Sample size',
					'bulk-catalogue-manager-for-woocommerce'
				) }
				<select
					id={ controlId + '-sample' }
					value={ sampleSize }
					disabled={ busy }
					onChange={ ( event ) =>
						onSampleSize(
							event.target.value === 'all'
								? 'all'
								: Number( event.target.value )
						)
					}
				>
					{ SAMPLE_SIZES.map( ( size ) => (
						<option key={ size } value={ size }>
							{ size === 'all'
								? __(
										'All',
										'bulk-catalogue-manager-for-woocommerce'
									)
								: size }
						</option>
					) ) }
				</select>
			</label>
		</div>
	);
}
