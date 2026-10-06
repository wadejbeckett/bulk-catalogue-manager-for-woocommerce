/**
 * The export dialog's words and request bodies (SPEC §10; ticket 10, T103).
 * Kept apart from the dialog so each rule has one place: which row choices
 * are offered, what the summary and the file are asked for, and what the
 * summary says.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

import {
	formatNumber,
	itemsPhrase,
	listPhrase,
	productsPhrase,
	variationsPhrase,
} from '../../bulk/words';
import { descriptorsOf } from '../fields';
import { countPhrase, waitingReason } from '../selection/model';

/** The row choice for the exact selection. */
export const SELECTED = 'selected';

/** The row choice for everything matching the current filters. */
export const FILTER = 'filter';

/**
 * The choice the dialog opens with: Selected whenever anything is selected
 * (ruling R13), else the filter choice.
 *
 * @param {Object} selection `{ token, count, byType, waiting }` (panelSelection()).
 * @return {string} SELECTED or FILTER.
 */
export function initialChoice( selection ) {
	return Number( selection?.count ) > 0 ? SELECTED : FILTER;
}

/**
 * Whether the selection is still being saved: ticks made just before Export
 * was clicked are on their way to the server (1.3.4).
 *
 * @param {Object} selection panelSelection().
 * @return {boolean} Saving.
 */
export function isSaving( selection ) {
	return (
		!! selection?.waiting &&
		selection.waiting === waitingReason( { status: 'saving' } )
	);
}

/**
 * A count of rows in the catalogue's mode: products, variations, or items
 * where both are listed together (ticket 11: a count that can include
 * variations says so).
 *
 * @param {string} mode  The view's mode.
 * @param {number} count Rows.
 * @return {string} Phrase.
 */
export function modePhrase( mode, count ) {
	if ( mode === 'variations' ) {
		return variationsPhrase( count );
	}
	if ( mode === 'products_and_variations' ) {
		return itemsPhrase( count );
	}
	return productsPhrase( count );
}

/**
 * The two row choices, as radio buttons.
 *
 * @param {Object}      selection     panelSelection().
 * @param {Object}      filter        What the filter choice is known to be.
 * @param {string}      filter.mode   The view's mode.
 * @param {Object|null} filter.answer The filter choice's last summary, or null.
 * @return {Array<Object>} `{ value, label, note, disabled }` each.
 */
export function rowChoices( selection, { mode, answer = null } = {} ) {
	const count = Number( selection?.count ) || 0;
	// Ticks still being saved are a selection on its way, not nothing.
	const coming = ! count && isSaving( selection );
	return [
		{
			value: SELECTED,
			label: count
				? sprintf(
						/* translators: %s: what is selected, such as "25 products and 3 variations". */
						__(
							'Selected: %s',
							'bulk-catalogue-manager-for-woocommerce'
						),
						countPhrase( count, selection.byType )
					)
				: __( 'Selected', 'bulk-catalogue-manager-for-woocommerce' ),
			note:
				count || coming
					? ''
					: __(
							'Nothing selected',
							'bulk-catalogue-manager-for-woocommerce'
						),
			disabled: ! count && ! coming,
		},
		{
			value: FILTER,
			label: answer?.whole_catalogue
				? sprintf(
						/* translators: %s: how many rows the catalogue has, such as "90 products" or "1,284 items". */
						__(
							'The whole catalogue (%s)',
							'bulk-catalogue-manager-for-woocommerce'
						),
						modePhrase( mode, answer.rows )
					)
				: __(
						'All matching the current filters',
						'bulk-catalogue-manager-for-woocommerce'
					),
			note: '',
			disabled: false,
		},
	];
}

/**
 * What the summary and the file are asked for: the selection's token or the
 * filter arguments, the view's columns and the identifier switch. The
 * summary adds `summary`, the file the nonce and the confirmation. A
 * selection is never narrowed by the filter (ruling R10), so only its token
 * and the columns go with it.
 *
 * @param {Object}  args             Arguments.
 * @param {string}  args.choice      SELECTED or FILTER.
 * @param {Object}  args.selection   panelSelection().
 * @param {Object}  args.filterArgs  exportArgs(): the filter and `columns`.
 * @param {boolean} args.identifiers The identifier switch.
 * @return {Object} Body.
 */
export function rowsBody( { choice, selection, filterArgs, identifiers } ) {
	if ( choice === SELECTED ) {
		return {
			selection: selection?.token || '',
			columns: filterArgs.columns,
			identifiers: !! identifiers,
		};
	}
	return { ...filterArgs, identifiers: !! identifiers };
}

/**
 * The columns line: the file's columns are the table's.
 *
 * @param {Array<string>} columns  exportColumns().
 * @param {Object|Array}  registry GET /fields answer.
 * @return {string} Sentence.
 */
export function columnsSentence( columns, registry ) {
	const labels = new Map(
		descriptorsOf( registry ).map( ( field ) => [ field.key, field.label ] )
	);
	return sprintf(
		/* translators: %s: the column names, such as "Name, SKU and Regular price". */
		__(
			'Columns: %s, as the table shows them. Change them with the column chooser.',
			'bulk-catalogue-manager-for-woocommerce'
		),
		listPhrase( columns.map( ( key ) => labels.get( key ) ) )
	);
}

/**
 * What the summary says: the file's rows, and for a selection how many
 * selected items are left out.
 *
 * @param {Object} answer The summary.
 * @param {string} choice SELECTED or FILTER.
 * @return {string} Sentence.
 */
export function summarySentence( answer, choice ) {
	const rows = Number( answer?.rows ) || 0;
	const file = sprintf(
		/* translators: %s: a number of rows, such as "1,284". */
		_n(
			'The file will have %s row.',
			'The file will have %s rows.',
			rows,
			'bulk-catalogue-manager-for-woocommerce'
		),
		formatNumber( rows )
	);
	if ( choice !== SELECTED ) {
		return file;
	}
	const left = Number( answer?.left_out ) || 0;
	return [
		file,
		sprintf(
			/* translators: %s: a number of selected products and variations, such as "2". */
			_n(
				'%s selected item will be left out.',
				'%s selected items will be left out.',
				left,
				'bulk-catalogue-manager-for-woocommerce'
			),
			formatNumber( left )
		),
	].join( ' ' );
}

/**
 * The file name line.
 *
 * @param {Object} answer The summary.
 * @return {string} Sentence, or '' without a name.
 */
export function filenameSentence( answer ) {
	return answer?.filename
		? sprintf(
				/* translators: %s: the CSV file's name, such as "catalogue-selected-2026-10-05-1432.csv". */
				__( 'File name: %s', 'bulk-catalogue-manager-for-woocommerce' ),
				answer.filename
			)
		: '';
}

/**
 * The warnings a file for re-import needs (rulings R2, R3): values the
 * importer would not read back as they are. Only with the switch on.
 *
 * @param {Object}  answer      The summary.
 * @param {boolean} identifiers The identifier switch.
 * @return {Array<string>} Sentences.
 */
export function guardSentences( answer, identifiers ) {
	if ( ! identifiers || ! answer ) {
		return [];
	}
	const out = [];
	const skus = Number( answer.guarded_identifiers ) || 0;
	const names = Number( answer.guarded_names ) || 0;
	if ( skus > 0 ) {
		out.push(
			sprintf(
				/* translators: %s: a number of rows, such as "3". "%%" is a percent sign. */
				_n(
					'%s row has a SKU that WooCommerce’s importer would not keep as it is. It starts with -, +, = or @, or has line breaks, repeated or edge spaces, tags or %% codes that the importer removes. Correct these SKUs, or remove the leading apostrophe, before re-importing.',
					'%s rows have a SKU that WooCommerce’s importer would not keep as it is. It starts with -, +, = or @, or has line breaks, repeated or edge spaces, tags or %% codes that the importer removes. Correct these SKUs, or remove the leading apostrophe, before re-importing.',
					skus,
					'bulk-catalogue-manager-for-woocommerce'
				),
				formatNumber( skus )
			)
		);
	}
	if ( names > 0 ) {
		out.push(
			sprintf(
				/* translators: %s: a number of rows, such as "3". */
				_n(
					'%s row has a name that starts with -, +, = or @, so the file puts an apostrophe in front of it. WooCommerce’s importer keeps that apostrophe: remove it before re-importing.',
					'%s rows have a name that starts with -, +, = or @, so the file puts an apostrophe in front of it. WooCommerce’s importer keeps that apostrophe: remove it before re-importing.',
					names,
					'bulk-catalogue-manager-for-woocommerce'
				),
				formatNumber( names )
			)
		);
	}
	return out;
}

/**
 * What the dialog says when Selected is chosen but the selection is gone:
 * cleared elsewhere, or found gone when a lapsed lease was renewed. The
 * dialog never switches the choice itself (ruling R13).
 *
 * @return {string} Sentence.
 */
export function goneSentence() {
	return __(
		'Your selection is no longer kept, so there is nothing selected to export. Choose the products again, or export all matching the current filters.',
		'bulk-catalogue-manager-for-woocommerce'
	);
}

/**
 * Why Download waits for the selection, in the dialog's words: the
 * selection's own reasons speak of checking a change, so the two the
 * dialog knows are said for an export; any other (such as signing in
 * again) is shown as it is.
 *
 * @param {string} waiting panelSelection().waiting.
 * @return {string} Sentence, or ''.
 */
export function waitingSentence( waiting ) {
	if ( ! waiting ) {
		return '';
	}
	if ( waiting === waitingReason( { status: 'saving' } ) ) {
		return __(
			'Your selection is still being saved. Download is available once it is.',
			'bulk-catalogue-manager-for-woocommerce'
		);
	}
	if ( waiting === waitingReason( { status: 'uncertain' } ) ) {
		return __(
			'A change to your selection was not confirmed. Close this dialog, try the change again or clear the selection, then export.',
			'bulk-catalogue-manager-for-woocommerce'
		);
	}
	return waiting;
}
