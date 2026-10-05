/**
 * The export dialog (SPEC §10, §14; ticket 10, T103). Export CSV opens it:
 *
 * - Rows: Selected, the exact selection, chosen whenever anything is
 *   selected (ruling R13), or everything matching the current filters, which
 *   reads "The whole catalogue (N products)" and needs a confirmation when
 *   nothing narrows it. The choice is made from the selection once it is
 *   saved: opened while ticks are still on their way, the dialog holds
 *   Selected and waits, then chooses from the saved selection (1.3.4).
 *   After that the dialog never changes the choice itself.
 * - Columns: the table's, as one line; there is no second field picker.
 * - Include identifiers for re-import: off at every opening.
 * - The summary: rows, left-out items and the file name, asked again when
 *   the choice, the token or the switch changes; with the switch on, the
 *   values WooCommerce's importer would not read back are counted.
 *
 * Download waits for a summary of what is chosen, for the selection to be
 * saved, and for the whole-catalogue confirmation. The dialog is new each
 * time it opens, so every choice starts afresh; Modal traps focus, closes
 * on Escape and gives focus back to Export.
 */
import {
	Button,
	CheckboxControl,
	Modal,
	Notice,
	Spinner,
	ToggleControl,
} from '@wordpress/components';
import { useId, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { plainMessage } from '../../../errors';
import { ErrorNotice } from '../states';
import {
	FILTER,
	SELECTED,
	columnsSentence,
	filenameSentence,
	goneSentence,
	guardSentences,
	initialChoice,
	isSaving,
	rowChoices,
	rowsBody,
	summarySentence,
	waitingSentence,
} from './export-model';
import { useExportSummary } from './use-export-summary';

/**
 * The dialog.
 *
 * @param {Object}       props                  Props.
 * @param {Object}       props.selection        panelSelection(): `{ kind, token, key, count, byType, waiting }`.
 * @param {Object}       props.filterArgs       exportArgs(): the filter and `columns`.
 * @param {Object|Array} props.registry         GET /fields answer.
 * @param {Function}     props.onRenewSelection Renews a lapsed selection; returns a promise.
 * @param {Function}     props.onDownload       Called with `{ body, confirmAll }`.
 * @param {Function}     props.onClose          Closes the dialog.
 * @param {Function}     props.fetchSummary     The summary request (tests).
 * @return {Element} Dialog.
 */
export default function ExportDialog( {
	selection,
	filterArgs,
	registry,
	onRenewSelection,
	onDownload,
	onClose,
	fetchSummary,
} ) {
	const id = `bulkcat-export-${ useId().replace( /\W/g, '' ) }`;
	// Null while ticks made before opening are still being saved: the
	// choice is then made from the saved selection, once (1.3.4).
	const [ chosen, setChosen ] = useState( () =>
		isSaving( selection ) ? null : initialChoice( selection )
	);
	if ( chosen === null && ! isSaving( selection ) ) {
		setChosen( initialChoice( selection ) );
	}
	const choice = chosen ?? SELECTED;
	const [ identifiers, setIdentifiers ] = useState( false );
	const [ confirmAll, setConfirmAll ] = useState( false );
	const [ filterAnswer, setFilterAnswer ] = useState( null );

	const waiting =
		choice === SELECTED ? waitingSentence( selection?.waiting ) : '';
	// Selected stays chosen when the selection goes (cleared elsewhere, or
	// found gone by the renewal after a lapsed lease): say so.
	const gone = choice === SELECTED && ! waiting && ! selection?.token;
	const body = rowsBody( { choice, selection, filterArgs, identifiers } );
	const summary = useExportSummary( {
		body,
		enabled:
			choice === FILTER ||
			( !! selection?.token && ! selection?.waiting ),
		onRenew: onRenewSelection,
		...( fetchSummary ? { ask: fetchSummary } : {} ),
	} );
	const answer = summary.ready ? summary.answer : null;
	if ( choice === FILTER && answer && answer !== filterAnswer ) {
		setFilterAnswer( answer );
	}

	// Whether the filter choice is the whole catalogue does not change with
	// the switch, so its confirmation stays in place while the summary is
	// asked again; Download still waits for that summary.
	const whole =
		choice === FILTER && !! ( answer || filterAnswer )?.whole_catalogue;
	const canDownload =
		!! answer &&
		! waiting &&
		( choice === FILTER || !! selection?.token ) &&
		( ! whole || confirmAll );
	const choices = rowChoices( selection, {
		mode: filterArgs.mode,
		answer: filterAnswer,
	} );
	const warnings = guardSentences( answer, identifiers );

	const choose = ( value ) => {
		setChosen( value );
		setConfirmAll( false );
	};

	return (
		<Modal
			title={ __(
				'Export CSV',
				'bulk-catalogue-manager-for-woocommerce'
			) }
			className="bulkcat-export-dialog"
			onRequestClose={ onClose }
		>
			<fieldset className="bulkcat-export-dialog__rows">
				<legend>
					{ __( 'Rows', 'bulk-catalogue-manager-for-woocommerce' ) }
				</legend>
				{ choices.map( ( option ) => {
					const inputId = `${ id }-${ option.value }`;
					return (
						<div
							key={ option.value }
							className="bulkcat-export-dialog__choice"
						>
							<input
								type="radio"
								id={ inputId }
								name={ `${ id }-rows` }
								value={ option.value }
								checked={ choice === option.value }
								disabled={ option.disabled }
								aria-describedby={
									option.note
										? `${ inputId }-note`
										: undefined
								}
								onChange={ () => choose( option.value ) }
							/>
							<label htmlFor={ inputId }>{ option.label }</label>
							{ option.note && (
								<span
									id={ `${ inputId }-note` }
									className="bulkcat-export-dialog__note"
								>
									{ option.note }
								</span>
							) }
						</div>
					);
				} ) }
				{ whole && (
					<CheckboxControl
						__nextHasNoMarginBottom
						className="bulkcat-export-dialog__confirm"
						label={ __(
							'Yes, export the whole catalogue',
							'bulk-catalogue-manager-for-woocommerce'
						) }
						checked={ confirmAll }
						onChange={ setConfirmAll }
					/>
				) }
			</fieldset>
			<p className="bulkcat-export-dialog__columns">
				{ columnsSentence( filterArgs.columns, registry ) }
			</p>
			<ToggleControl
				__nextHasNoMarginBottom
				label={ __(
					'Include identifiers for re-import',
					'bulk-catalogue-manager-for-woocommerce'
				) }
				help={ __(
					'Puts ID, SKU, Parent and Type first, so the file can go back through Products → Import to update the same products.',
					'bulk-catalogue-manager-for-woocommerce'
				) }
				checked={ identifiers }
				onChange={ setIdentifiers }
			/>
			<div className="bulkcat-export-dialog__summary" aria-live="polite">
				{ waiting && <p>{ waiting }</p> }
				{ gone && <p>{ goneSentence() }</p> }
				{ ( summary.status === 'loading' ||
					summary.status === 'renewing' ) && (
					<p className="bulkcat-export-dialog__counting">
						<Spinner />
						{ __(
							'Counting the rows…',
							'bulk-catalogue-manager-for-woocommerce'
						) }
					</p>
				) }
				{ answer && (
					<>
						<p>{ summarySentence( answer, choice ) }</p>
						<p className="bulkcat-export-dialog__filename">
							{ filenameSentence( answer ) }
						</p>
					</>
				) }
			</div>
			{ summary.status === 'error' && (
				<ErrorNotice
					message={ plainMessage(
						summary.error,
						__(
							'The rows could not be counted. Try again.',
							'bulk-catalogue-manager-for-woocommerce'
						)
					) }
					error={ summary.error }
					onRetry={ summary.retry }
				/>
			) }
			{ warnings.map( ( warning ) => (
				<Notice
					key={ warning }
					status="warning"
					isDismissible={ false }
					className="bulkcat-export-dialog__warning"
				>
					{ warning }
				</Notice>
			) ) }
			<div className="bulkcat-export-dialog__actions">
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Cancel', 'bulk-catalogue-manager-for-woocommerce' ) }
				</Button>
				<Button
					variant="primary"
					disabled={ ! canDownload }
					accessibleWhenDisabled
					onClick={ () =>
						canDownload &&
						onDownload( { body, confirmAll: whole && confirmAll } )
					}
				>
					{ __(
						'Download',
						'bulk-catalogue-manager-for-woocommerce'
					) }
				</Button>
			</div>
		</Modal>
	);
}
