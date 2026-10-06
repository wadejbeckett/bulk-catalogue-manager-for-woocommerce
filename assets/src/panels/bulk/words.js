/**
 * Numbers and counted phrases for the bulk panel, always through the
 * WordPress i18n functions so a count reads naturally in every language.
 */
import { _n, sprintf } from '@wordpress/i18n';

/**
 * The admin page's language (WordPress sets `<html lang>` from the user's
 * locale), or the browser's when there is none.
 *
 * @return {string|undefined} BCP 47 tag.
 */
export function adminLocale() {
	const lang =
		typeof document !== 'undefined' && document.documentElement.lang;
	return lang || undefined;
}

/**
 * A whole number with the admin language's grouping, such as "4,312".
 *
 * @param {number} value Number.
 * @return {string} Formatted number.
 */
export function formatNumber( value ) {
	const number = Number( value ) || 0;
	try {
		return new Intl.NumberFormat( adminLocale() ).format( number );
	} catch {
		return String( number );
	}
}

/**
 * "1 product" or "4,312 products".
 *
 * @param {number} count Count.
 * @return {string} Phrase.
 */
export function productsPhrase( count ) {
	return sprintf(
		/* translators: %s: a number of products, such as "4,312". */
		_n(
			'%s product',
			'%s products',
			count,
			'bulk-catalogue-manager-for-woocommerce'
		),
		formatNumber( count )
	);
}

/**
 * "1 variation" or "3,915 variations".
 *
 * @param {number} count Count.
 * @return {string} Phrase.
 */
export function variationsPhrase( count ) {
	return sprintf(
		/* translators: %s: a number of variations, such as "3,915". */
		_n(
			'%s variation',
			'%s variations',
			count,
			'bulk-catalogue-manager-for-woocommerce'
		),
		formatNumber( count )
	);
}

/**
 * "1 item" or "1,284 items": a count of products and variations together,
 * where the server does not say how many of each (ticket 11).
 *
 * @param {number} count Count.
 * @return {string} Phrase.
 */
export function itemsPhrase( count ) {
	return sprintf(
		/* translators: %s: a number of products and variations together, such as "1,284". */
		_n(
			'%s item',
			'%s items',
			count,
			'bulk-catalogue-manager-for-woocommerce'
		),
		formatNumber( count )
	);
}

/**
 * Several names as one list, "Regular price and Sale price", in the
 * reader's language where the browser knows how.
 *
 * @param {Array<string>} items Names.
 * @return {string} The list.
 */
export function listPhrase( items ) {
	const list = items.filter( Boolean );
	try {
		return new Intl.ListFormat( adminLocale(), {
			style: 'long',
			type: 'conjunction',
		} ).format( list );
	} catch {
		return list.join( ', ' );
	}
}
