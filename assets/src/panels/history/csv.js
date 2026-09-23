/**
 * STOPGAP client-side CSV for the history exports (T58).
 *
 * SPEC §10 and T58 route every export through GET /bcm/v1/export and its
 * one writer (src/Export/CsvWriter.php), streamed server-side. That route
 * exports catalogue rows only today: it has no row source for a batch's
 * changes or for the audit log, and no source for the batch list. Until
 * the PHP follow-up adds those sources, the screen builds the file in the
 * browser from the same read routes, following the filter across every
 * page (100 rows per request, up to MAX_ROWS) rather than only the page on
 * screen, with the writer's rules: UTF-8 with a byte-order mark, every
 * field quoted, and a field beginning `=`, `+`, `-` or `@` prefixed with an
 * apostrophe so a spreadsheet does not run it as a formula. Nothing is
 * stored; the file is handed to the browser as a download.
 *
 * Replace exportRows() with a link to GET /export once it takes these
 * sources; nothing else on the screen needs to change.
 */
import { request } from '../../api';

/** The most rows the stopgap reads before stopping (100 requests). */
export const MAX_ROWS = 10000;

const PAGE = 100;

/**
 * One CSV field: quoted, quotes doubled, and a formula prefix neutralised.
 *
 * @param {*} value Cell value.
 * @return {string} Field.
 */
export function csvField( value ) {
	let text = '';
	if ( value !== null && value !== undefined ) {
		text =
			typeof value === 'object'
				? JSON.stringify( value )
				: String( value );
	}
	if ( /^[=+\-@]/.test( text ) ) {
		text = "'" + text;
	}
	return '"' + text.replace( /"/g, '""' ) + '"';
}

/**
 * A CSV document: a byte-order mark, the header and the rows.
 *
 * @param {Array<{label: string, value: Function}>} columns   Columns in order.
 * @param {Array<Object>}                           rows      Rows.
 * @param {string}                                  separator Field separator.
 * @return {string} CSV text.
 */
export function toCsv( columns, rows, separator = ',' ) {
	const line = ( cells ) => cells.map( csvField ).join( separator );
	return (
		'﻿' +
		[
			line( columns.map( ( column ) => column.label ) ),
			...rows.map( ( row ) =>
				line( columns.map( ( column ) => column.value( row ) ) )
			),
		].join( '\r\n' ) +
		'\r\n'
	);
}

/**
 * Every row a read route answers for a filter, page by page.
 *
 * @param {string} route Route under bcm/v1.
 * @param {Object} query Filter arguments (no paging).
 * @return {Promise<{rows: Array<Object>, truncated: boolean}>} Rows.
 */
export async function readAll( route, query ) {
	const rows = [];
	let page = 1;
	let pages = 1;
	do {
		const answer = await request( route, {
			query: { ...query, page, per_page: PAGE },
		} );
		rows.push( ...( answer?.items || [] ) );
		pages = answer?.pagination?.totalPages || 1;
		page++;
	} while ( page <= pages && rows.length < MAX_ROWS );
	return { rows: rows.slice( 0, MAX_ROWS ), truncated: page <= pages };
}

/**
 * Hand a CSV to the browser as a download.
 *
 * @param {string} name File name.
 * @param {string} text CSV text.
 */
export function download( name, text ) {
	const blob = new window.Blob( [ text ], {
		type: 'text/csv;charset=utf-8',
	} );
	const url = window.URL.createObjectURL( blob );
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = name;
	document.body.appendChild( link );
	link.click();
	link.remove();
	window.setTimeout( () => window.URL.revokeObjectURL( url ), 0 );
}

/**
 * Export every row of a read route matching a filter as a CSV download.
 *
 * @param {Object} options           Options.
 * @param {string} options.route     Route under bcm/v1.
 * @param {Object} options.query     Filter arguments.
 * @param {Array}  options.columns   Columns `{ label, value( row ) }` in order.
 * @param {string} options.name      File name.
 * @param {string} options.separator Field separator (the CSV separator setting).
 * @return {Promise<{count: number, truncated: boolean}>} What was written.
 */
export async function exportRows( {
	route,
	query,
	columns,
	name,
	separator = ',',
} ) {
	const { rows, truncated } = await readAll( route, query );
	download( name, toCsv( columns, rows, separator ) );
	return { count: rows.length, truncated };
}
