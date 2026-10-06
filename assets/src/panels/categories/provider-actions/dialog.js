/**
 * An add-on's action on the chosen categories (contract version 2,
 * category target; docs/EXTENSIONS-v2.md §5): its arguments, Include
 * subcategories when a chosen category has any, then Preview — every
 * category record by path with its outcome and the add-on's own words — and
 * Apply, which sends exactly the categories the preview captured through a
 * durable grant (POST /requests, then POST /batches) and follows the
 * background job to its end. Changing anything after a preview asks for a
 * new one; a preview the server finds stale says so and asks again.
 * Confirmation follows the screen's one policy (bulk/confirm.js): a second
 * click at or above the threshold, or when the add-on always asks.
 */
import { Button, CheckboxControl, Modal, Notice } from '@wordpress/components';
import { useContext, useMemo, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { request } from '../../../api';
import { envelope, keptHolder, sendWithGrant } from '../../../requests';
import { ShellContext } from '../../../shell';
import { tabScope, tabStore } from '../../../tab-store';
import { useConfirmApply, DEFAULT_THRESHOLD } from '../../bulk/confirm';
import {
	defaultArgs,
	operationProblem,
	settingsValues,
	SPECIAL_ARGS,
	toRequestOperations,
	unavailableReason,
} from '../../bulk/descriptors';
import { FINISHED, PREVIEW_STALE, useBatchProgress } from '../../bulk/hooks';
import { BatchProgress } from '../../history';
import { announce } from '../announce';
import ErrorNotice from '../error-notice';
import { joinPath, pathNames } from '../tree-model';
import ArgumentField from './argument-field';
import {
	applyIntent,
	finishedSentence,
	hasSubcategories,
	outcomeLabel,
	previewBody,
	rowText,
	scopeSentence,
} from './model';

/** The Apply grant, kept for the tab, so a retry or a reload never applies twice. */
let holder = null;

/**
 * The screen's category Apply holder.
 *
 * @param {Object} settings Localised settings.
 * @return {Object} `{ current, store }`.
 */
function applyHolder( settings ) {
	if ( ! holder ) {
		holder = keptHolder(
			tabStore( tabScope( settings, 'category-apply' ) )
		);
	}
	return holder;
}

/**
 * Forget the held grant (tests).
 */
export function resetCategoryApplyGrant() {
	holder = null;
}

/**
 * @param {Object}        props          Props.
 * @param {Object}        props.action   The action's `/fields` descriptor.
 * @param {Object}        props.registry The `/fields` payload.
 * @param {Object}        props.settings The screen's localised settings.
 * @param {Array<number>} props.ids      The chosen categories.
 * @param {Object}        props.tree     useCategoryTree() result.
 * @param {Function}      props.onClose  Closes the dialog.
 * @param {Function}      props.onDone   Closes it after a change, keeping these categories chosen.
 * @return {Element} The dialog.
 */
export default function ProviderActionDialog( {
	action,
	registry,
	settings,
	ids,
	tree,
	onClose,
	onDone,
} ) {
	const [ args, setArgs ] = useState( () => defaultArgs( registry, action ) );
	const [ subcategories, setSubcategories ] = useState( false );
	const [ preview, setPreview ] = useState( null );
	const [ busy, setBusy ] = useState( '' );
	const [ error, setError ] = useState( null );
	const [ stale, setStale ] = useState( false );
	const [ batchId, setBatchId ] = useState( 0 );
	const announced = useRef( false );
	const progress = useBatchProgress( batchId );
	// As after a product change, the change can be opened in History.
	const navigate = useContext( ShellContext )?.navigate || null;
	const report = progress.report;
	const finished = !! report && FINISHED.includes( report.status );

	const blocked = unavailableReason( action );
	const one = ids.length === 1;
	const object = one ? `term:${ action.target }:${ ids[ 0 ] }` : '';
	const offerSubcategories = hasSubcategories( tree, ids );
	const operation = { action: action.id, args };
	const problem = blocked ? null : operationProblem( registry, operation );
	const values = settingsValues( settings );
	const names = ids.map( ( id ) => joinPath( pathNames( tree.byId, id ) ) );
	const properties = Object.entries( action.args?.properties || {} ).filter(
		( [ name ] ) => ! SPECIAL_ARGS.includes( name )
	);

	const changed = ( next ) => {
		setPreview( null );
		setStale( false );
		setError( null );
		next();
	};

	const runPreview = async () => {
		setBusy( 'preview' );
		setError( null );
		setStale( false );
		try {
			const answer = await request( 'categories/actions/preview', {
				method: 'POST',
				data: previewBody(
					toRequestOperations( registry, [ operation ] ),
					ids,
					offerSubcategories && subcategories
				),
			} );
			setPreview( answer );
			announce( answer.summary );
		} catch ( failure ) {
			setError( failure || {} );
		}
		setBusy( '' );
	};

	const apply = async () => {
		setBusy( 'apply' );
		setError( null );
		try {
			const batch = await sendWithGrant( applyHolder( settings ), {
				key: preview.hash,
				operation: 'batch.apply',
				intent: applyIntent( preview ),
				send: ( grant ) =>
					request( 'batches', {
						method: 'POST',
						data: {
							...envelope( grant ),
							preview_hash: preview.hash,
						},
					} ),
			} );
			setBatchId( Number( batch?.batch_id ) || 0 );
		} catch ( failure ) {
			if ( failure?.code === PREVIEW_STALE ) {
				setPreview( null );
				setStale( true );
			} else {
				setError( failure || {} );
			}
		}
		setBusy( '' );
	};

	const count = Number( preview?.categories?.count ) || 0;
	const reasons = useMemo(
		() => preview?.external?.reasons || [],
		[ preview ]
	);
	const gate = useConfirmApply( {
		count,
		threshold: values.confirm_threshold ?? DEFAULT_THRESHOLD,
		reasons,
		onApply: apply,
	} );
	const changes = Number( preview?.totals?.would_change ) || 0;

	if ( finished && ! announced.current ) {
		announced.current = true;
		announce( finishedSentence( report ) );
	}

	return (
		<Modal
			title={ action.label }
			onRequestClose={ busy ? () => {} : onClose }
			className="bulkcat-cat-provider"
		>
			<p className="bulkcat-cat-provider__for">
				{ sprintf(
					/* translators: %s: how many categories are chosen, such as "3". */
					_n(
						'For %s chosen category:',
						'For %s chosen categories:',
						ids.length,
						'bulk-catalogue-manager-for-woocommerce'
					),
					ids.length.toLocaleString()
				) }{ ' ' }
				{ names.join( ', ' ) }
			</p>
			{ blocked ? (
				<Notice status="warning" isDismissible={ false }>
					{ blocked }
				</Notice>
			) : (
				<>
					{ action.description && <p>{ action.description }</p> }
					{ ! batchId && (
						<div className="bulkcat-cat-provider__args">
							{ properties.map( ( [ name, property ] ) => (
								<ArgumentField
									key={ name }
									id={ `bulkcat-cat-provider-${ name }` }
									name={ name }
									property={ property }
									value={ args[ name ] }
									action={ action.id }
									object={ object }
									reason={
										problem?.arg === name
											? problem.reason
											: ''
									}
									onChange={ ( value ) =>
										changed( () =>
											setArgs( ( was ) => ( {
												...was,
												[ name ]: value,
											} ) )
										)
									}
								/>
							) ) }
							{ offerSubcategories && (
								<CheckboxControl
									__nextHasNoMarginBottom
									label={ __(
										'Include subcategories',
										'bulk-catalogue-manager-for-woocommerce'
									) }
									help={ __(
										'Also change every category below the chosen ones, as they are when you preview.',
										'bulk-catalogue-manager-for-woocommerce'
									) }
									checked={ subcategories }
									onChange={ ( on ) =>
										changed( () => setSubcategories( on ) )
									}
								/>
							) }
						</div>
					) }
					{ stale && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'The categories or the add-on changed since this preview. Preview again to see what the change will do now.',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</Notice>
					) }
					{ error && (
						<ErrorNotice
							error={ error }
							fallback={ __(
								'This could not be checked or done. Try again in a moment.',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						/>
					) }
					{ preview && ! batchId && (
						<PreviewList preview={ preview } />
					) }
					{ batchId > 0 && (
						<div className="bulkcat-cat-provider__progress">
							{ report && ! finished && (
								<BatchProgress report={ report } />
							) }
							{ ! report && (
								<p>
									{ __(
										'Applying the change…',
										'bulk-catalogue-manager-for-woocommerce'
									) }
								</p>
							) }
							{ finished && (
								<p role="status">
									{ finishedSentence( report ) }
								</p>
							) }
						</div>
					) }
					{ gate.armed && (
						<div
							className="bulkcat-confirm__ask"
							role="group"
							aria-labelledby="bulkcat-cat-provider-ask"
						>
							<p id="bulkcat-cat-provider-ask">
								{ sprintf(
									/* translators: %s: a number of categories, such as "12". */
									_n(
										'This will change %s category. Apply it?',
										'This will change %s categories. Apply it?',
										count,
										'bulk-catalogue-manager-for-woocommerce'
									),
									count.toLocaleString()
								) }
							</p>
							{ gate.reasons.length > 0 && (
								<ul>
									{ gate.reasons.map( ( reason ) => (
										<li key={ reason }>{ reason }</li>
									) ) }
								</ul>
							) }
						</div>
					) }
				</>
			) }
			<div className="bulkcat-cat-confirm__buttons">
				{ batchId > 0 ? (
					<>
						{ navigate && (
							<Button
								variant="secondary"
								onClick={ () =>
									navigate( 'history', { batch: batchId } )
								}
								__next40pxDefaultSize
							>
								{ __(
									'View in History',
									'bulk-catalogue-manager-for-woocommerce'
								) }
							</Button>
						) }
						<Button
							variant="primary"
							onClick={ () => onDone( ids ) }
							__next40pxDefaultSize
						>
							{ __(
								'Close',
								'bulk-catalogue-manager-for-woocommerce'
							) }
						</Button>
					</>
				) : (
					<>
						<Button
							variant="tertiary"
							onClick={ gate.armed ? gate.cancel : onClose }
							disabled={ !! busy }
							__next40pxDefaultSize
						>
							{ gate.armed
								? __(
										'Not yet',
										'bulk-catalogue-manager-for-woocommerce'
									)
								: __(
										'Cancel',
										'bulk-catalogue-manager-for-woocommerce'
									) }
						</Button>
						{ ! blocked && (
							<Button
								variant={ preview ? 'secondary' : 'primary' }
								isBusy={ busy === 'preview' }
								disabled={ !! busy || !! problem || gate.armed }
								accessibleWhenDisabled
								onClick={ runPreview }
								__next40pxDefaultSize
							>
								{ preview
									? __(
											'Preview again',
											'bulk-catalogue-manager-for-woocommerce'
										)
									: __(
											'Preview',
											'bulk-catalogue-manager-for-woocommerce'
										) }
							</Button>
						) }
						{ preview && (
							<Button
								variant="primary"
								isBusy={ busy === 'apply' }
								disabled={ !! busy || changes < 1 }
								accessibleWhenDisabled
								onClick={
									gate.armed ? gate.confirm : gate.request
								}
								__next40pxDefaultSize
							>
								{ gate.armed
									? sprintf(
											/* translators: %s: a number of categories, such as "12". */
											_n(
												'Yes, change %s category',
												'Yes, change %s categories',
												count,
												'bulk-catalogue-manager-for-woocommerce'
											),
											count.toLocaleString()
										)
									: sprintf(
											/* translators: %s: a number of categories, such as "12". */
											_n(
												'Apply to %s category',
												'Apply to %s categories',
												count,
												'bulk-catalogue-manager-for-woocommerce'
											),
											count.toLocaleString()
										) }
							</Button>
						) }
					</>
				) }
			</div>
		</Modal>
	);
}

/**
 * The preview: its summary, how many categories it covers, the add-on's
 * warnings, and each category record by path with its outcome and words.
 *
 * @param {Object} props         Props.
 * @param {Object} props.preview The preview's answer.
 * @return {Element} The list.
 */
function PreviewList( { preview } ) {
	const warnings = preview.external?.warnings || [];
	return (
		<div className="bulkcat-cat-provider__preview">
			<p className="bulkcat-cat-provider__summary">
				<strong>{ preview.summary }</strong>{ ' ' }
				{ scopeSentence( preview ) }
			</p>
			{ warnings.map( ( warning ) => (
				<Notice
					key={ warning.code }
					status="warning"
					isDismissible={ false }
				>
					{ warning.sentence }
				</Notice>
			) ) }
			<ul
				className="bulkcat-cat-provider__records"
				aria-label={ __(
					'Categories in this change',
					'bulk-catalogue-manager-for-woocommerce'
				) }
			>
				{ preview.records.map( ( record ) => (
					<li
						key={ record.id }
						className={ `bulkcat-cat-provider__record is-${ record.outcome }` }
					>
						<span className="bulkcat-cat-provider__path">
							{ record.path }
						</span>
						<span className="bulkcat-cat-provider__outcome">
							{ outcomeLabel( record.outcome ) }
						</span>
						{ record.rows.map( ( row, index ) => (
							<span
								key={ row.field + index }
								className="bulkcat-cat-provider__row"
							>
								{ rowText( row ) }
								{ ( row.notes || [] ).map( ( note ) => (
									<span
										key={ note }
										className="bulkcat-cat-provider__note"
									>
										{ note }
									</span>
								) ) }
							</span>
						) ) }
					</li>
				) ) }
			</ul>
		</div>
	);
}
