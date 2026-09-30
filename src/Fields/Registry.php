<?php
/**
 * The field registry (SPEC §5.1, §5.2): every version 1 field declared once,
 * plus the read-only columns. Grid, REST, preview, journal and writer all read
 * fields from here, so none of them can disagree about a field's type,
 * storage, rules, getter or setter.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Fields;

use BulkCatalogueManager\Availability\Outcome;
use BulkCatalogueManager\Operations\Group;
use BulkCatalogueManager\Rest\Refusal;

defined( 'ABSPATH' ) || exit;

/**
 * Every field, in the order the §5.2 table lists them, then the read-only columns.
 */
final class Registry {
	/**
	 * Price, stock and date range filter operators.
	 */
	private const RANGE = [ 'between', 'greaterThanOrEqual', 'lessThanOrEqual' ];

	/**
	 * The instance runtime code shares.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Fields by key.
	 *
	 * @var array<string, Field>
	 */
	private array $fields = [];

	/**
	 * Internal fields by key (T88): resolved by get(), listed only by internal().
	 *
	 * @var array<string, Field>
	 */
	private array $internal = [];

	/**
	 * Build every field with its translated labels. Code outside tests passes
	 * nothing; a test double passes its own list, such as every field plus a
	 * synthetic one, to show what derives from the registry. The internal
	 * fields (the Off record) are always present, since the write path and
	 * undo resolve them by key whatever the list.
	 *
	 * @param list<Field>|null $fields The fields, or null for the §5.2 fields and the read-only columns.
	 * @throws \LogicException When two fields share a key.
	 */
	public function __construct( ?array $fields = null ) {
		$given = $fields ?? [ ...self::editable_fields(), ...self::column_fields() ];
		$keys  = array_map( static fn( Field $field ): string => $field->key(), $given );
		foreach ( self::internal_fields() as $field ) {
			if ( ! in_array( $field->key(), $keys, true ) ) {
				$given[] = $field;
			}
		}
		foreach ( $given as $field ) {
			if ( isset( $this->fields[ $field->key() ] ) || isset( $this->internal[ $field->key() ] ) ) {
				throw new \LogicException( esc_html( sprintf( 'Field "%s" is declared twice.', $field->key() ) ) );
			}
			if ( $field->internal() ) {
				$this->internal[ $field->key() ] = $field;
			} else {
				$this->fields[ $field->key() ] = $field;
			}
		}
	}

	/**
	 * The shared registry.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Every public field, keyed by field key, in registry order. Internal
	 * fields are never listed here (internal()).
	 *
	 * @return array<string, Field>
	 */
	public function all(): array {
		return $this->fields;
	}

	/**
	 * Every field key, in registry order.
	 *
	 * @return string[]
	 */
	public function keys(): array {
		return array_keys( $this->fields );
	}

	/**
	 * Whether a key is a public field: a column, filter or view may name it.
	 * An internal field is not one.
	 *
	 * @param string $key Field key.
	 */
	public function has( string $key ): bool {
		return isset( $this->fields[ $key ] );
	}

	/**
	 * One field, public or internal, or null for an unknown key. The journal,
	 * preview, writer and undo resolve fields through this.
	 *
	 * @param string $key Field key.
	 */
	public function get( string $key ): ?Field {
		return $this->fields[ $key ] ?? $this->internal[ $key ] ?? null;
	}

	/**
	 * The internal fields, keyed by field key (T88): today the Off record,
	 * `availability_off`, journalled with the status (SPEC §5.5, §9.3).
	 *
	 * @return array<string, Field>
	 */
	public function internal(): array {
		return $this->internal;
	}

	/**
	 * The fields that accept at least one edit kind.
	 *
	 * @return array<string, Field>
	 */
	public function editable(): array {
		return array_filter( $this->fields, static fn( Field $field ): bool => ! $field->read_only() );
	}

	/**
	 * The read-only columns.
	 *
	 * @return array<string, Field>
	 */
	public function columns(): array {
		return array_filter( $this->fields, static fn( Field $field ): bool => $field->read_only() );
	}

	/**
	 * The fields the grid may filter on, in registry order.
	 *
	 * @return array<string, Field>
	 */
	public function filterable(): array {
		return array_filter( $this->fields, static fn( Field $field ): bool => $field->filterable() );
	}

	/**
	 * The fields the grid may sort on, in registry order.
	 *
	 * @return array<string, Field>
	 */
	public function sortable(): array {
		return array_filter( $this->fields, static fn( Field $field ): bool => $field->sortable() );
	}

	/**
	 * The client-facing list `GET /bulkcat/v1/fields` returns.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function to_client(): array {
		return array_values( array_map( static fn( Field $field ): array => $field->to_client(), $this->fields ) );
	}

	/**
	 * The rule linking the two prices (SPEC §5.4): a sale price has to be
	 * below the regular price. An empty sale price always passes. Given the
	 * canonical values an object would end with; a refusal skips the row
	 * rather than clearing anything.
	 *
	 * @param string $regular Regular price, canonical.
	 * @param string $sale    Sale price, canonical.
	 */
	public static function check_prices( string $regular, string $sale ): bool|\WP_Error {
		if ( '' === $sale || '' === $regular || (float) $sale < (float) $regular ) {
			return true;
		}
		return Refusal::invalid(
			'bulkcat_invalid_value',
			__( 'The sale price has to be below the regular price, so this product was skipped. Lower the sale price, or choose to clear it where it is not below the regular price.', 'bulk-catalogue-manager-for-woocommerce' )
		);
	}

	/**
	 * The §5.2 fields.
	 *
	 * @return Field[]
	 */
	private static function editable_fields(): array {
		$per_product    = static fn( string $label ): string => sprintf(
			/* translators: %s: a field's name, such as "Featured". */
			__( '%s belongs to the product, not to each variation.', 'bulk-catalogue-manager-for-woocommerce' ),
			$label
		);
		$variable_price = __( 'A variable product takes its price from its variations. Change the variations instead.', 'bulk-catalogue-manager-for-woocommerce' );

		$catalogue_visibility = __( 'Catalogue visibility', 'bulk-catalogue-manager-for-woocommerce' );
		$visible_on_site      = __( 'Shown in shop and search', 'bulk-catalogue-manager-for-woocommerce' );
		$featured             = __( 'Featured', 'bulk-catalogue-manager-for-woocommerce' );
		$sold_individually    = __( 'Sold individually', 'bulk-catalogue-manager-for-woocommerce' );
		$reviews              = __( 'Reviews', 'bulk-catalogue-manager-for-woocommerce' );
		$visibility_storage   = Storage::taxonomy( 'product_visibility', [ 'exclude-from-catalog', 'exclude-from-search' ] );

		$dimension = static fn( string $key, string $label ): Field => new Field(
			key: $key,
			label: $label,
			type: Field::DECIMAL,
			storage: Storage::meta( '_' . $key ),
			edit_kinds: [ Field::SET, Field::CLEAR ],
			variation_rule: Field::PER_VARIATION,
			group: Group::SHIPPING_TAX,
			getter: 'get_' . $key,
			setter: 'set_' . $key,
			inline: true,
			nullable: true
		);

		return [
			new Field(
				key: 'name',
				label: __( 'Name', 'bulk-catalogue-manager-for-woocommerce' ),
				type: Field::TEXT,
				storage: Storage::post( 'post_title' ),
				edit_kinds: [ Field::SET, Field::FIND_REPLACE ],
				variation_rule: Field::DERIVED,
				group: Group::ORGANISATION,
				getter: 'get_name',
				setter: 'set_name',
				variation_note: __( 'A variation takes its name from its product and its attributes, so it cannot be changed here.', 'bulk-catalogue-manager-for-woocommerce' ),
				max_length: 200,
				kses: true,
				sortable: true
			),
			new Field(
				key: 'status',
				label: __( 'Status', 'bulk-catalogue-manager-for-woocommerce' ),
				type: Field::ENUM,
				storage: Storage::post( 'post_status' ),
				edit_kinds: [ Field::SET ],
				variation_rule: Field::CONDITIONAL,
				group: Group::PUBLISHING,
				getter: 'get_status',
				setter: 'set_status',
				choices: [
					'publish' => __( 'Published', 'bulk-catalogue-manager-for-woocommerce' ),
					// T114: a scheduled product reads, filters and validates as Scheduled; it is never set (SPEC §5.5).
					'future'  => Outcome::status_label( 'future' ),
					'draft'   => __( 'Draft', 'bulk-catalogue-manager-for-woocommerce' ),
					'pending' => __( 'Pending review', 'bulk-catalogue-manager-for-woocommerce' ),
					'private' => __( 'Private', 'bulk-catalogue-manager-for-woocommerce' ),
				],
				write_choices: [ 'publish', 'draft', 'pending', 'private' ],
				variation_choices: [ 'publish', 'private' ],
				variation_note: __( 'A variation can only be published or private.', 'bulk-catalogue-manager-for-woocommerce' ),
				inline: true,
				filter_operators: [ 'isAny', 'isNone' ],
				default_order: 5,
				// Not published yet, so off the site for buyers until its date, as WooCommerce's is_visible() and is_purchasable() read it.
				hiding_choices: [
					'future'  => Field::HIDES_OFF_SITE,
					'draft'   => Field::HIDES_OFF_SITE,
					'pending' => Field::HIDES_OFF_SITE,
				],
				filter_choices: [
					'off'             => __( 'Off', 'bulk-catalogue-manager-for-woocommerce' ),
					'changed_outside' => __( 'Changed outside this screen', 'bulk-catalogue-manager-for-woocommerce' ),
				]
			),
			new Field(
				key: 'catalog_visibility',
				label: $catalogue_visibility,
				type: Field::ENUM,
				storage: $visibility_storage,
				edit_kinds: [ Field::SET ],
				variation_rule: Field::PARENT_ONLY,
				group: Group::PUBLISHING,
				getter: 'get_catalog_visibility',
				setter: 'set_catalog_visibility',
				choices: [
					'visible' => __( 'Shop and search results', 'bulk-catalogue-manager-for-woocommerce' ),
					'catalog' => __( 'Shop only', 'bulk-catalogue-manager-for-woocommerce' ),
					'search'  => __( 'Search results only', 'bulk-catalogue-manager-for-woocommerce' ),
					'hidden'  => __( 'Hidden', 'bulk-catalogue-manager-for-woocommerce' ),
				],
				variation_note: $per_product( $catalogue_visibility ),
				filter_operators: [ 'isAny', 'isNone' ],
				hiding_choices: [ 'hidden' => Field::HIDES_FROM_SHOP ]
			),
			new Field(
				key: 'visible_on_site',
				label: $visible_on_site,
				type: Field::ENUM,
				storage: $visibility_storage,
				edit_kinds: [ Field::SET ],
				variation_rule: Field::PARENT_ONLY,
				group: Group::PUBLISHING,
				getter: 'get_catalog_visibility',
				setter: 'set_catalog_visibility',
				inline: true,
				// T93 (SPEC §5.3): a listing control, named for what it does; the key stays
				// for saved views, journals and CSV ids. Status is the Off/On control.
				choices: [
					'on'     => __( 'Shown', 'bulk-catalogue-manager-for-woocommerce' ),
					'off'    => __( 'Hidden', 'bulk-catalogue-manager-for-woocommerce' ),
					'partly' => __( 'Partly', 'bulk-catalogue-manager-for-woocommerce' ),
				],
				write_choices: [ 'on', 'off' ],
				variation_note: $per_product( $visible_on_site ),
				adapter: Field::ADAPTER_SWITCH,
				hiding_choices: [ 'off' => Field::HIDES_FROM_SHOP ]
			),
			new Field(
				key: 'featured',
				label: $featured,
				type: Field::BOOLEAN,
				storage: Storage::taxonomy( 'product_visibility', [ 'featured' ] ),
				edit_kinds: [ Field::SET ],
				variation_rule: Field::PARENT_ONLY,
				group: Group::PUBLISHING,
				getter: 'get_featured',
				setter: 'set_featured',
				inline: true,
				variation_note: $per_product( $featured ),
				filter_operators: [ 'is' ]
			),
			new Field(
				key: 'category_ids',
				label: __( 'Categories', 'bulk-catalogue-manager-for-woocommerce' ),
				type: Field::TERMS,
				storage: Storage::taxonomy( 'product_cat' ),
				edit_kinds: [ Field::SET, Field::ADD, Field::REMOVE ],
				variation_rule: Field::PARENT_ONLY,
				group: Group::ORGANISATION,
				getter: 'get_category_ids',
				setter: 'set_category_ids',
				variation_note: __( 'Categories belong to the product, not to each variation.', 'bulk-catalogue-manager-for-woocommerce' ),
				source: 'categories',
				filter_operators: [ 'isAny', 'isNone', 'isAll' ],
				default_order: 2
			),
			new Field(
				key: 'tag_ids',
				label: __( 'Tags', 'bulk-catalogue-manager-for-woocommerce' ),
				type: Field::TERMS,
				storage: Storage::taxonomy( 'product_tag' ),
				edit_kinds: [ Field::SET, Field::ADD, Field::REMOVE ],
				variation_rule: Field::PARENT_ONLY,
				group: Group::ORGANISATION,
				getter: 'get_tag_ids',
				setter: 'set_tag_ids',
				variation_note: __( 'Tags belong to the product, not to each variation.', 'bulk-catalogue-manager-for-woocommerce' ),
				source: 'tags',
				filter_operators: [ 'isAny', 'isNone', 'isAll' ]
			),
			new Field(
				key: 'regular_price',
				label: __( 'Regular price', 'bulk-catalogue-manager-for-woocommerce' ),
				type: Field::MONEY,
				storage: Storage::meta( '_regular_price', [ 'min_price', 'max_price' ] ),
				edit_kinds: [ Field::SET, Field::ADJUST, Field::ROUND, Field::COPY_FROM_SALE ],
				variation_rule: Field::PER_VARIATION,
				group: Group::PRICING,
				getter: 'get_regular_price',
				setter: 'set_regular_price',
				inline: true,
				nullable: true,
				variable_parent_note: $variable_price,
				sortable: true,
				filter_operators: self::RANGE,
				default_order: 3
			),
			new Field(
				key: 'sale_price',
				label: __( 'Sale price', 'bulk-catalogue-manager-for-woocommerce' ),
				type: Field::MONEY,
				storage: Storage::meta( '_sale_price' ),
				edit_kinds: [ Field::SET, Field::CLEAR, Field::ADJUST, Field::ROUND, Field::COPY_FROM_REGULAR ],
				variation_rule: Field::PER_VARIATION,
				group: Group::PRICING,
				getter: 'get_sale_price',
				setter: 'set_sale_price',
				inline: true,
				nullable: true,
				variable_parent_note: $variable_price
			),
			new Field(
				key: 'stock_status',
				label: __( 'Stock status', 'bulk-catalogue-manager-for-woocommerce' ),
				type: Field::ENUM,
				storage: Storage::meta( '_stock_status', [ 'stock_status' ] ),
				edit_kinds: [ Field::SET ],
				variation_rule: Field::PER_VARIATION,
				group: Group::STOCK,
				getter: 'get_stock_status',
				setter: 'set_stock_status',
				inline: true,
				choices: [
					'instock'     => __( 'In stock', 'bulk-catalogue-manager-for-woocommerce' ),
					'outofstock'  => __( 'Out of stock', 'bulk-catalogue-manager-for-woocommerce' ),
					'onbackorder' => __( 'On backorder', 'bulk-catalogue-manager-for-woocommerce' ),
				],
				sortable: true,
				filter_operators: [ 'isAny', 'isNone' ],
				default_order: 4
			),
			new Field(
				key: 'manage_stock',
				label: __( 'Manage stock', 'bulk-catalogue-manager-for-woocommerce' ),
				type: Field::BOOLEAN,
				storage: Storage::meta( '_manage_stock' ),
				edit_kinds: [ Field::SET ],
				variation_rule: Field::PER_VARIATION,
				group: Group::STOCK,
				getter: 'get_manage_stock',
				setter: 'set_manage_stock',
				filter_operators: [ 'is' ]
			),
			new Field(
				key: 'stock_quantity',
				label: __( 'Stock quantity', 'bulk-catalogue-manager-for-woocommerce' ),
				type: Field::INTEGER,
				storage: Storage::meta( '_stock', [ 'stock_quantity' ] ),
				edit_kinds: [ Field::SET, Field::ADJUST ],
				variation_rule: Field::CONDITIONAL,
				group: Group::STOCK,
				getter: 'get_stock_quantity',
				setter: 'set_stock_quantity',
				inline: true,
				nullable: true,
				variation_note: __( 'Stock quantity only applies where stock is managed, so it was not changed here. Turn on Manage stock first.', 'bulk-catalogue-manager-for-woocommerce' ),
				requires: Field::REQUIRES_MANAGED_STOCK,
				sortable: true,
				filter_operators: self::RANGE
			),
			new Field(
				key: 'sku',
				label: __( 'SKU', 'bulk-catalogue-manager-for-woocommerce' ),
				type: Field::TEXT,
				storage: Storage::meta( '_sku', [ 'sku' ] ),
				edit_kinds: [ Field::SET, Field::FIND_REPLACE, Field::PREFIX, Field::SUFFIX ],
				variation_rule: Field::PER_VARIATION,
				group: Group::ORGANISATION,
				getter: 'get_sku',
				setter: 'set_sku',
				inline: true,
				nullable: true,
				max_length: 100,
				unique: true,
				sortable: true,
				default_order: 1
			),
			new Field(
				key: 'weight',
				label: __( 'Weight', 'bulk-catalogue-manager-for-woocommerce' ),
				type: Field::DECIMAL,
				storage: Storage::meta( '_weight' ),
				edit_kinds: [ Field::SET, Field::ADJUST, Field::CLEAR ],
				variation_rule: Field::PER_VARIATION,
				group: Group::SHIPPING_TAX,
				getter: 'get_weight',
				setter: 'set_weight',
				inline: true,
				nullable: true
			),
			$dimension( 'length', __( 'Length', 'bulk-catalogue-manager-for-woocommerce' ) ),
			$dimension( 'width', __( 'Width', 'bulk-catalogue-manager-for-woocommerce' ) ),
			$dimension( 'height', __( 'Height', 'bulk-catalogue-manager-for-woocommerce' ) ),
			new Field(
				key: 'shipping_class_id',
				label: __( 'Shipping class', 'bulk-catalogue-manager-for-woocommerce' ),
				type: Field::TERM,
				storage: Storage::taxonomy( 'product_shipping_class' ),
				edit_kinds: [ Field::SET ],
				variation_rule: Field::PER_VARIATION,
				group: Group::SHIPPING_TAX,
				getter: 'get_shipping_class_id',
				setter: 'set_shipping_class_id',
				source: 'shipping_classes',
				filter_operators: [ 'isAny', 'isNone' ]
			),
			new Field(
				key: 'tax_status',
				label: __( 'Tax status', 'bulk-catalogue-manager-for-woocommerce' ),
				type: Field::ENUM,
				storage: Storage::meta( '_tax_status', [ 'tax_status' ] ),
				edit_kinds: [ Field::SET ],
				variation_rule: Field::PER_VARIATION,
				group: Group::SHIPPING_TAX,
				getter: 'get_tax_status',
				setter: 'set_tax_status',
				choices: [
					'taxable'  => __( 'Taxable', 'bulk-catalogue-manager-for-woocommerce' ),
					'shipping' => __( 'Shipping only', 'bulk-catalogue-manager-for-woocommerce' ),
					'none'     => __( 'None', 'bulk-catalogue-manager-for-woocommerce' ),
				]
			),
			new Field(
				key: 'tax_class',
				label: __( 'Tax class', 'bulk-catalogue-manager-for-woocommerce' ),
				type: Field::ENUM,
				storage: Storage::meta( '_tax_class', [ 'tax_class' ] ),
				edit_kinds: [ Field::SET ],
				variation_rule: Field::PER_VARIATION,
				group: Group::SHIPPING_TAX,
				getter: 'get_tax_class',
				setter: 'set_tax_class',
				choices: static fn(): array => self::tax_classes(),
				variation_only_choices: [ 'parent' ],
				filter_operators: [ 'isAny', 'isNone' ]
			),
			new Field(
				key: 'sold_individually',
				label: $sold_individually,
				type: Field::BOOLEAN,
				storage: Storage::meta( '_sold_individually' ),
				edit_kinds: [ Field::SET ],
				variation_rule: Field::PARENT_ONLY,
				group: Group::STOCK,
				getter: 'get_sold_individually',
				setter: 'set_sold_individually',
				variation_note: $per_product( $sold_individually )
			),
			new Field(
				key: 'reviews_allowed',
				label: $reviews,
				type: Field::BOOLEAN,
				storage: Storage::post( 'comment_status' ),
				edit_kinds: [ Field::SET ],
				variation_rule: Field::PARENT_ONLY,
				group: Group::PUBLISHING,
				getter: 'get_reviews_allowed',
				setter: 'set_reviews_allowed',
				variation_note: $per_product( $reviews )
			),
		];
	}

	/**
	 * The internal fields (T88): the Off record the Status switch writes
	 * with the status (SPEC §5.5), journalled as **Status before Off**.
	 *
	 * @return Field[]
	 */
	private static function internal_fields(): array {
		return [
			\BulkCatalogueManager\Publishing\PublicationPassword::field(),
			new Field(
				key: \BulkCatalogueManager\Availability\OffRecord::FIELD,
				label: __( 'Status before Off', 'bulk-catalogue-manager-for-woocommerce' ),
				type: Field::TEXT,
				storage: Storage::meta( \BulkCatalogueManager\Availability\OffRecord::META_KEY ),
				edit_kinds: [ Field::SET, Field::CLEAR ],
				variation_rule: Field::PARENT_ONLY,
				getter: 'get_meta',
				setter: 'update_meta_data',
				nullable: true,
				variation_note: __( 'A variation is never switched Off on its own, so it has no saved status.', 'bulk-catalogue-manager-for-woocommerce' ),
				adapter: Field::ADAPTER_OFF_RECORD,
				internal: true
			),
		];
	}

	/**
	 * The read-only columns (SPEC §5.2, after the table).
	 *
	 * @return Field[]
	 */
	private static function column_fields(): array {
		return [
			new Field(
				key: 'id',
				label: __( 'ID', 'bulk-catalogue-manager-for-woocommerce' ),
				type: Field::ID,
				storage: Storage::post( 'ID' ),
				edit_kinds: [],
				variation_rule: Field::PER_VARIATION,
				getter: 'get_id',
				setter: null,
				adapter: Field::ADAPTER_PLAIN,
				sortable: true
			),
			new Field(
				key: 'type',
				label: __( 'Type', 'bulk-catalogue-manager-for-woocommerce' ),
				type: Field::ENUM,
				storage: Storage::taxonomy( 'product_type' ),
				edit_kinds: [],
				variation_rule: Field::PER_VARIATION,
				getter: 'get_type',
				setter: null,
				choices: static fn(): array => self::product_types(),
				adapter: Field::ADAPTER_PLAIN,
				filter_operators: [ 'isAny', 'isNone' ]
			),
			new Field(
				key: 'thumbnail',
				label: __( 'Image', 'bulk-catalogue-manager-for-woocommerce' ),
				type: Field::IMAGE,
				storage: Storage::meta( '_thumbnail_id' ),
				edit_kinds: [],
				variation_rule: Field::PER_VARIATION,
				getter: 'get_image_id',
				setter: null
			),
			new Field(
				key: 'price_html',
				label: __( 'Price', 'bulk-catalogue-manager-for-woocommerce' ),
				type: Field::HTML,
				storage: Storage::derived( [ 'min_price', 'max_price' ] ),
				edit_kinds: [],
				variation_rule: Field::PER_VARIATION,
				getter: 'get_price_html',
				setter: null,
				adapter: Field::ADAPTER_PLAIN
			),
			new Field(
				key: 'date_created',
				label: __( 'Date created', 'bulk-catalogue-manager-for-woocommerce' ),
				type: Field::DATETIME,
				storage: Storage::post( 'post_date_gmt' ),
				edit_kinds: [],
				variation_rule: Field::PER_VARIATION,
				getter: 'get_date_created',
				setter: null,
				adapter: Field::ADAPTER_DATE,
				sortable: true,
				filter_operators: [ 'before', 'after', 'inThePast' ]
			),
			new Field(
				key: 'date_modified',
				label: __( 'Last modified', 'bulk-catalogue-manager-for-woocommerce' ),
				type: Field::DATETIME,
				storage: Storage::post( 'post_modified_gmt' ),
				edit_kinds: [],
				variation_rule: Field::PER_VARIATION,
				getter: 'get_date_modified',
				setter: null,
				adapter: Field::ADAPTER_DATE,
				sortable: true,
				filter_operators: [ 'before', 'after', 'inThePast' ]
			),
			new Field(
				key: 'units_sold',
				label: __( 'Total sales', 'bulk-catalogue-manager-for-woocommerce' ),
				type: Field::INTEGER,
				storage: Storage::meta( 'total_sales', [ 'total_sales' ] ),
				edit_kinds: [],
				variation_rule: Field::PER_VARIATION,
				getter: 'get_total_sales',
				setter: null,
				sortable: true
			),
			new Field(
				key: 'variation_count',
				label: __( 'Variations', 'bulk-catalogue-manager-for-woocommerce' ),
				type: Field::INTEGER,
				storage: Storage::derived(),
				edit_kinds: [],
				variation_rule: Field::PARENT_ONLY,
				getter: 'get_children',
				setter: null,
				adapter: Field::ADAPTER_COUNT
			),
		];
	}

	/**
	 * The shop's tax classes, standard first, then "same as product", which
	 * only a variation may take.
	 *
	 * @return array<string, string>
	 */
	private static function tax_classes(): array {
		$classes = [ '' => __( 'Standard', 'bulk-catalogue-manager-for-woocommerce' ) ];
		if ( class_exists( \WC_Tax::class ) ) {
			$slugs = \WC_Tax::get_tax_class_slugs();
			$names = \WC_Tax::get_tax_classes();
			foreach ( $slugs as $index => $slug ) {
				$classes[ (string) $slug ] = (string) ( $names[ $index ] ?? $slug );
			}
		}
		$classes['parent'] = __( 'Same as product', 'bulk-catalogue-manager-for-woocommerce' );
		return $classes;
	}

	/**
	 * Product types WooCommerce knows, plus variation.
	 *
	 * @return array<string, string>
	 */
	private static function product_types(): array {
		$types              = function_exists( 'wc_get_product_types' ) ? (array) wc_get_product_types() : [
			'simple'   => __( 'Simple product', 'bulk-catalogue-manager-for-woocommerce' ),
			'grouped'  => __( 'Grouped product', 'bulk-catalogue-manager-for-woocommerce' ),
			'external' => __( 'External/Affiliate product', 'bulk-catalogue-manager-for-woocommerce' ),
			'variable' => __( 'Variable product', 'bulk-catalogue-manager-for-woocommerce' ),
		];
		$types['variation'] = __( 'Variation', 'bulk-catalogue-manager-for-woocommerce' );
		return $types;
	}
}
