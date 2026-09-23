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
 * thumbnail and the name (the registry's image field and its title field).
 */
export const DEFAULT_COLUMNS = [
	'sku',
	'status',
	'visible_on_site',
	'regular_price',
	'sale_price',
	'stock_status',
	'category_ids',
];

/** A field whose cell also shows another field's value: stock with its quantity. */
export const COMPANIONS = {
	stock_status: 'stock_quantity',
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
};

/**
 * The field the category panel filters by (T62). Its `isAny` filter includes
 * the chosen categories' descendants on the server (Query\ProductQuery).
 */
export const CATEGORY_FILTER = 'category_ids';

/** Page sizes offered (SPEC §4.1); the first is the default. */
export const PER_PAGE_SIZES = [ 25, 50, 100 ];

/** Term sources the client can name through a bcm/v1 route: source => route. */
export const TERM_ROUTES = {
	categories: 'categories',
};

/**
 * Choices that take a product off the site when set in bulk: field key =>
 * values. Setting one always confirms (SPEC §4.5; panels/bulk/confirm.js).
 */
export const OFF_SITE_CHOICES = {
	status: [ 'draft', 'pending' ],
};

/**
 * Choices that hide a product from the shop and from search when set in bulk:
 * field key => values. Setting one always confirms (SPEC §4.5).
 */
export const HIDING_CHOICES = {
	visible_on_site: [ 'off' ],
	catalog_visibility: [ 'hidden' ],
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
