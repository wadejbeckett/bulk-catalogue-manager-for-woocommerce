/**
 * The catalogue's presentation preset: the one module allowed to name a field
 * key or a row property (tests/js/catalogue-fields.test.js scans for them).
 *
 * Which fields exist, their labels, types, choices and sortability come from
 * GET /fields alone (fields.js). What lives here is only the starting view the
 * owner sees before choosing columns (SPEC §4.1 Columns), which field's value
 * is shown alongside another's, the tone of a choice's badge, and the names of
 * the properties the server adds to every row (RouteSchema::row_extras()). A
 * key named here that the registry does not list is ignored, so dropping a
 * field on the server removes its column without a client change.
 */

/**
 * Columns shown by default after the product cell, which carries the
 * thumbnail and the name: the fields the registry flags `defaultColumn`, in
 * its `defaultOrder` (Fields\Registry, T40).
 *
 * @param {Array<Object>} descriptors Registry descriptors.
 * @return {Array<string>} Field keys.
 */
export function defaultColumns( descriptors ) {
	return ( descriptors || [] )
		.filter( ( field ) => field.defaultColumn === true )
		.sort(
			( a, b ) =>
				( Number( a.defaultOrder ) || 0 ) -
				( Number( b.defaultOrder ) || 0 )
		)
		.map( ( field ) => field.key );
}

/**
 * The choices the registry flags as hiding a product (`hidesProduct`),
 * narrowed to one way of hiding (`hidesAs`): field key => values.
 *
 * @param {Array<Object>} descriptors Registry descriptors.
 * @param {string}        as          'off_site' or 'hidden'.
 * @return {Object} Field key => choice values.
 */
function choicesHidingAs( descriptors, as ) {
	const out = {};
	( descriptors || [] ).forEach( ( field ) => {
		const values = ( field.choices || [] )
			.filter(
				( choice ) =>
					choice.hidesProduct === true && choice.hidesAs === as
			)
			.map( ( choice ) => String( choice.value ) );
		if ( values.length ) {
			out[ field.key ] = values;
		}
	} );
	return out;
}

/**
 * A field whose cell also shows another field's value: stock with its
 * quantity, the regular price with the sale price under it (T75).
 */
export const COMPANIONS = {
	stock_status: 'stock_quantity',
	regular_price: 'sale_price',
};

/** Badge tone per choice value; a value not listed is neutral. */
export const TONES = {
	publish: 'good',
	instock: 'good',
	onbackorder: 'warn',
	outofstock: 'bad',
};

/** Properties every row carries besides its fields (RouteSchema::row_extras()). */
export const ROW = {
	thumbnailUrl: 'thumbnail_url',
	parentId: 'parent_id',
	parentName: 'parent_name',
	attributeSummary: 'attribute_summary',
	attributes: 'attributes', // [ { label, value } ] of a variation.
};

/**
 * The field the category panel filters by (T62). Its `isAny` filter includes
 * the chosen categories' descendants on the server (Query\ProductQuery).
 */
export const CATEGORY_FILTER = 'category_ids';

/** Page sizes offered (SPEC §4.1); the first is the default. */
export const PER_PAGE_SIZES = [ 25, 50, 100 ];

/** Term sources the client can name through a bulkcat/v1 route: source => route. */
export const TERM_ROUTES = {
	categories: 'categories',
	tags: 'terms/product_tag',
	shipping_classes: 'terms/product_shipping_class',
};

/**
 * Choices that take a product off the site (`hidesAs: 'off_site'`, such as
 * Draft): field key => values. Setting one always confirms (SPEC §4.5).
 *
 * @param {Array<Object>} descriptors Registry descriptors.
 * @return {Object} Field key => choice values.
 */
export function offSiteChoices( descriptors ) {
	return choicesHidingAs( descriptors, 'off_site' );
}

/**
 * Choices that hide a product from the shop and from search (`hidesAs:
 * 'hidden'`): field key => values, the Visible on site switch first.
 *
 * @param {Array<Object>} descriptors Registry descriptors.
 * @return {Object} Field key => choice values.
 */
export function hidingChoices( descriptors ) {
	const all = choicesHidingAs( descriptors, 'hidden' );
	const { [ VISIBILITY.field ]: first, ...rest } = all;
	return first ? { [ VISIBILITY.field ]: first, ...rest } : rest;
}

// T41, T42: filters and the selection bar ---------------------------------

/**
 * Term sources whose terms form a tree, so a filter on them includes every
 * term below the chosen ones (Query\ProductQuery expands them) and its chip
 * says so: "Category is Shoes or its sub-categories".
 */
export const TREE_TERM_SOURCES = [ 'categories' ];

/**
 * The selection bar's Show on site and Hide from site (SPEC §4.1, §5.3): the
 * switch field and its two real values. Either opens the bulk change panel
 * with this field set, so it is previewed like any change.
 */
export const QUICK_VISIBILITY = {
	field: 'visible_on_site',
	show: 'on',
	hide: 'off',
};

/**
 * The "Visible on site" switch (SPEC §5.3, T45): the switch field, the
 * four-way catalogue visibility field it simplifies, the status field whose
 * off-site values earn the grey badge beside it, the status value that puts a
 * product on the site, and the catalogue visibility each real position
 * writes. A middle state (shop only, search only) is never written by the
 * switch; the owner chooses one of these two.
 */
export const VISIBILITY = {
	field: 'visible_on_site',
	terms: 'catalog_visibility',
	status: 'status',
	onSite: 'publish',
	writes: { on: 'visible', off: 'hidden' },
};

/**
 * Fields a row also carries so its cells know what they can edit (T46): the
 * switch needs the exact visibility and the status, a price needs the product
 * type (a variable product's price is derived) and the stock quantity needs
 * whether stock is managed. field key => keys fetched with it.
 */
export const CONTEXT = {
	visible_on_site: [ 'catalog_visibility', 'status' ],
	regular_price: [ 'type' ],
	sale_price: [ 'type' ],
	stock_quantity: [ 'manage_stock' ],
};

/** The product type whose own price is derived from its variations. */
export const VARIABLE_TYPE = { field: 'type', value: 'variable' };

/**
 * A field that only applies where a switch field is on (Stock quantity where
 * stock is managed): field key => switch field key. The registry's note for
 * the field says why when it is off.
 */
export const REQUIRES = {
	stock_quantity: 'manage_stock',
};
