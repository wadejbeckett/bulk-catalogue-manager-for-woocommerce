/**
 * DataViews fields from the field registry (GET /fields, SPEC §5.1). Every
 * column, label, choice list and sort option is built from the payload; the
 * only field keys the client knows are the preset in defaults.js.
 */
import { COMPANIONS, DEFAULT_COLUMNS, PER_PAGE_SIZES } from './defaults';
import { renderCell } from './cells';

/**
 * The registry's field descriptors, from the payload or a bare list.
 *
 * @param {Object|Array} registry GET /fields answer, or its `fields`.
 * @return {Array<Object>} Descriptors that carry a DataViews definition.
 */
export function descriptorsOf( registry ) {
	const list = Array.isArray( registry ) ? registry : registry?.fields;
	return ( Array.isArray( list ) ? list : [] ).filter(
		( field ) => field?.key && field.dataviews
	);
}

/**
 * The fields with a role in the table, found by what the registry says about
 * them rather than by key: the row's identity (type `id`), its title (the one
 * text field that cannot be hidden) and its image (type `image`).
 *
 * @param {Array<Object>} descriptors Registry descriptors.
 * @return {{id: string|undefined, title: string|undefined, media: string|undefined}} Keys.
 */
export function rolesOf( descriptors ) {
	const find = ( test ) => descriptors.find( test )?.key;
	return {
		id: find( ( field ) => field.type === 'id' ),
		title: find(
			( field ) =>
				field.type === 'text' && field.dataviews.enableHiding === false
		),
		media: find( ( field ) => field.type === 'image' ),
	};
}

/**
 * The DataViews fields for the registry. Sorting follows the registry's
 * `sortable` flag; filters wait for T41, so none is offered yet.
 *
 * @param {Object|Array} registry GET /fields answer.
 * @return {Array<Object>} DataViews fields.
 */
export function fieldsFromRegistry( registry ) {
	const descriptors = descriptorsOf( registry );
	const roles = rolesOf( descriptors );
	const byKey = new Map(
		descriptors.map( ( field ) => [ field.key, field ] )
	);
	return descriptors.map( ( descriptor ) => {
		const companion = byKey.get( COMPANIONS[ descriptor.key ] );
		const role =
			Object.keys( roles ).find(
				( name ) => roles[ name ] === descriptor.key
			) || '';
		return {
			...descriptor.dataviews,
			id: descriptor.key,
			label: descriptor.label || descriptor.dataviews.label,
			enableSorting: descriptor.sortable === true,
			filterBy: false,
			descriptor, // The registry's word on variations (variations/).
			render: ( { item } ) =>
				renderCell( { item, descriptor, companion, role } ),
		};
	} );
}

/**
 * The fields a page must carry for a view: the row's identity, the product
 * cell's title and image, every visible column and each one's companion.
 *
 * @param {Object}        view        DataViews view.
 * @param {Array<Object>} descriptors Registry descriptors.
 * @return {Array<string>} Field keys, each once, in the registry's order.
 */
export function queryFields( view, descriptors ) {
	const roles = rolesOf( descriptors );
	const wanted = new Set(
		[ roles.id, view.titleField, view.mediaField, ...( view.fields || [] ) ]
			.filter( Boolean )
			.flatMap( ( key ) => [ key, COMPANIONS[ key ] ] )
	);
	return descriptors
		.map( ( field ) => field.key )
		.filter( ( key ) => wanted.has( key ) );
}

/**
 * The page size to start with: the setting's value when it is one of the
 * offered sizes, otherwise the first (SPEC §11 Rows per page).
 *
 * @param {Object} settings Localised settings (window.bcmSettings).
 * @return {number} Rows per page.
 */
export function defaultPerPage( settings ) {
	const chosen = Number( settings?.settings?.rows_per_page );
	return PER_PAGE_SIZES.includes( chosen ) ? chosen : PER_PAGE_SIZES[ 0 ];
}

/**
 * The view the catalogue opens with. Sorting is left to the server (newest
 * first) until the owner picks a column.
 *
 * @param {Object|Array} registry GET /fields answer.
 * @param {Object}       settings Localised settings.
 * @return {Object} DataViews view.
 */
export function defaultView( registry, settings ) {
	const descriptors = descriptorsOf( registry );
	const roles = rolesOf( descriptors );
	const known = new Set( descriptors.map( ( field ) => field.key ) );
	return {
		type: 'table',
		page: 1,
		perPage: defaultPerPage( settings ),
		search: '',
		filters: [],
		titleField: roles.title,
		mediaField: roles.media,
		showMedia: !! roles.media,
		fields: DEFAULT_COLUMNS.filter(
			( key ) =>
				known.has( key ) && key !== roles.title && key !== roles.media
		),
		layout: {},
	};
}
