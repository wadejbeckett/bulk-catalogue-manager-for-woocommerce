<?php
/**
 * One field of the registry (SPEC §5.1, §5.2): an immutable description of
 * a single native WooCommerce value, carrying everything any part of the
 * plugin needs to read, check or write it. Journal, preview and writer go
 * through read(), clean() and write() here, so none of them names a getter,
 * setter or storage key of its own.
 *
 * The getter and setter contract: read() calls the field's named WooCommerce
 * CRUD getter in edit context and returns the value in the field's canonical
 * form — the same form clean() produces — so a journalled old value, a
 * previewed new value and the value written compare directly. write() checks
 * the value and the object, then calls the named CRUD setter; it never saves,
 * because the caller saves once after every operation on the object.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Fields;

use BulkCatalogueManager\Availability\OffRecord;
use BulkCatalogueManager\Operations\Group;
use BulkCatalogueManager\Rest\Refusal;

defined( 'ABSPATH' ) || exit;

/**
 * A field definition.
 */
final class Field {
	/*
	 * Value types. The read-only columns use id, datetime, html and image.
	 */
	public const TEXT     = 'text';
	public const ENUM     = 'enum';
	public const BOOLEAN  = 'boolean';
	public const MONEY    = 'money';
	public const DECIMAL  = 'decimal';
	public const INTEGER  = 'integer';
	public const TERMS    = 'terms';
	public const TERM     = 'term';
	public const ID       = 'id';
	public const DATETIME = 'datetime';
	public const HTML     = 'html';
	public const IMAGE    = 'image';

	/**
	 * Every type.
	 */
	public const TYPES = [ self::TEXT, self::ENUM, self::BOOLEAN, self::MONEY, self::DECIMAL, self::INTEGER, self::TERMS, self::TERM, self::ID, self::DATETIME, self::HTML, self::IMAGE ];

	/**
	 * A choice that takes the product off the site altogether: its status
	 * keeps it from shoppers (draft, pending review).
	 */
	public const HIDES_OFF_SITE = 'off_site';

	/**
	 * A choice that hides the product from the shop and from search while it
	 * stays published (catalogue visibility hidden, Shown in shop and search off).
	 */
	public const HIDES_FROM_SHOP = 'hidden';

	/**
	 * How a choice can hide a product (`hidesAs` in the client payload).
	 */
	public const HIDES = [ self::HIDES_OFF_SITE, self::HIDES_FROM_SHOP ];

	/*
	 * Variation rules (SPEC §5.2 last column, §8). Per variation: each
	 * variation has its own value. Parent only: the value belongs to the
	 * product and is never offered on, or pushed down to, a variation.
	 * Derived: a variation's value is computed by WooCommerce and read-only.
	 * Conditional: offered on a variation only under a condition — a narrower
	 * set of choices, or stock being managed.
	 */
	public const PER_VARIATION = 'per_variation';
	public const PARENT_ONLY   = 'parent_only';
	public const DERIVED       = 'derived';
	public const CONDITIONAL   = 'conditional';

	/**
	 * Every variation rule.
	 */
	public const VARIATION_RULES = [ self::PER_VARIATION, self::PARENT_ONLY, self::DERIVED, self::CONDITIONAL ];

	/*
	 * Edit kinds (SPEC §5.2, §6). T24 maps each to its operation class.
	 */
	public const SET               = 'set';
	public const CLEAR             = 'clear';
	public const ADJUST            = 'adjust';
	public const ROUND             = 'round';
	public const COPY_FROM_SALE    = 'copy_from_sale';
	public const COPY_FROM_REGULAR = 'copy_from_regular';
	public const FIND_REPLACE      = 'find_replace';
	public const PREFIX            = 'prefix';
	public const SUFFIX            = 'suffix';
	public const ADD               = 'add';
	public const REMOVE            = 'remove';

	/**
	 * Every edit kind.
	 */
	public const EDIT_KINDS = [ self::SET, self::CLEAR, self::ADJUST, self::ROUND, self::COPY_FROM_SALE, self::COPY_FROM_REGULAR, self::FIND_REPLACE, self::PREFIX, self::SUFFIX, self::ADD, self::REMOVE ];

	/*
	 * How read() and write() reach the object. Edit: getter( 'edit' ) and
	 * setter( value ). Plain: getter() with no argument. Date: getter( 'edit' )
	 * returning a date object, read as ISO 8601 or null. Count: getter() returning
	 * a list, read as its length. Switch: the catalogue visibility getter and
	 * setter, read and written as the §5.3 switch.
	 */
	public const ADAPTER_EDIT   = 'edit';
	public const ADAPTER_PLAIN  = 'plain';
	public const ADAPTER_DATE   = 'date';
	public const ADAPTER_COUNT  = 'count';
	public const ADAPTER_SWITCH = 'switch';

	/**
	 * The Off record (SPEC §5.5; T88): read as Availability\OffRecord::raw(),
	 * the canonical JSON of the product's own record or '', and written
	 * through OffRecord::write() — '' removes it — never through a setter.
	 */
	public const ADAPTER_OFF_RECORD = 'off_record';

	/**
	 * The condition a conditional field needs: the object manages its stock.
	 */
	public const REQUIRES_MANAGED_STOCK = 'managed_stock';

	/**
	 * Catalogue visibility value => switch position (SPEC §5.3).
	 */
	private const SWITCH_READ = [
		'visible' => 'on',
		'hidden'  => 'off',
		'catalog' => 'partly',
		'search'  => 'partly',
	];

	/**
	 * Switch position => catalogue visibility value. "Partly" is never written.
	 */
	private const SWITCH_WRITE = [
		'on'  => 'visible',
		'off' => 'hidden',
	];

	/**
	 * Choices, value => label, resolved on first use when given as a closure.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $resolved_choices = null;

	/**
	 * The user background work acts for (Field::acting_as()), or null for the current user.
	 *
	 * @var int|null
	 */
	private static ?int $acting_user = null;

	/**
	 * Describe a field. The registry is the only caller.
	 *
	 * @param string                                 $key                    Field key: lower case, as journalled in `bulkcat_changes.prop`.
	 * @param string                                 $label                  Plain-English, translated label.
	 * @param string                                 $type                   One of TYPES.
	 * @param Storage                                $storage                Where WooCommerce keeps it.
	 * @param string[]                               $edit_kinds             Accepted edit kinds; empty for a read-only column.
	 * @param string                                 $variation_rule         One of VARIATION_RULES.
	 * @param string                                 $getter                 WooCommerce CRUD getter method.
	 * @param string|null                            $setter                 WooCommerce CRUD setter method; null when read-only.
	 * @param bool                                   $inline                 Whether the grid edits it in place.
	 * @param bool                                   $nullable               Whether empty is a valid value.
	 * @param array<string, string>|\Closure         $choices                For an enum, value => label, or a closure returning them.
	 * @param string[]|null                          $write_choices          Values that may be written; null for every choice.
	 * @param string[]|null                          $variation_choices      Values a variation may take; null for every writable choice.
	 * @param string[]                               $variation_only_choices Values only a variation may take.
	 * @param string                                 $variation_note         Why it is unavailable on a variation, or under its condition.
	 * @param string                                 $variable_parent_note   Why it is unavailable on a variable product; '' when it is available.
	 * @param string                                 $requires               '' or REQUIRES_MANAGED_STOCK.
	 * @param string                                 $adapter                One of the ADAPTER_ constants.
	 * @param int                                    $max_length             Longest text accepted; 0 for no limit.
	 * @param string                                 $source                 For a term field, which list the client offers: categories, tags or shipping_classes.
	 * @param bool                                   $kses                   Whether text passes the post HTML filter for a user without unfiltered_html.
	 * @param bool                                   $unique                 Whether the value must be unique across products and variations.
	 * @param bool                                   $sortable               Whether the grid may sort on it.
	 * @param string[]                               $filter_operators       DataViews filter operators; empty when it is not a filter.
	 * @param string                                 $group                  The bulk action panel group it sits in (Operations\Group); '' for a read-only column.
	 * @param int                                    $default_order          Its place among the grid's default columns after the product cell (T40, SPEC §4.1), from 1; 0 when it is not shown by default.
	 * @param array<string, string>                  $hiding_choices         For an enum, the choices that take a product out of the shopper's sight when set, value => HIDES_OFF_SITE or HIDES_FROM_SHOP (SPEC §4.5, §5.3).
	 * @param bool                                   $internal               Whether the field is internal (T88): resolved by key for the journal, preview, writer and undo, but never a column, filter, sort, bulk panel field, route argument or public action's field.
	 * @param array<string, string>                  $filter_choices         For a filterable enum, values its filter also takes that are never written, value => label (T92: Status's Off and Changed outside this screen); never in choices(), writable_choices() or the REST enum.
	 * @throws \InvalidArgumentException When the description is inconsistent.
	 */
	public function __construct(
		private readonly string $key,
		private readonly string $label,
		private readonly string $type,
		private readonly Storage $storage,
		private readonly array $edit_kinds,
		private readonly string $variation_rule,
		private readonly string $getter,
		private readonly ?string $setter,
		private readonly bool $inline = false,
		private readonly bool $nullable = false,
		private readonly array|\Closure $choices = [],
		private readonly ?array $write_choices = null,
		private readonly ?array $variation_choices = null,
		private readonly array $variation_only_choices = [],
		private readonly string $variation_note = '',
		private readonly string $variable_parent_note = '',
		private readonly string $requires = '',
		private readonly string $adapter = self::ADAPTER_EDIT,
		private readonly int $max_length = 0,
		private readonly string $source = '',
		private readonly bool $kses = false,
		private readonly bool $unique = false,
		private readonly bool $sortable = false,
		private readonly array $filter_operators = [],
		private readonly string $group = '',
		private readonly int $default_order = 0,
		private readonly array $hiding_choices = [],
		private readonly bool $internal = false,
		private readonly array $filter_choices = []
	) {
		$problem = match ( true ) {
			1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $key ) => 'its key is not a lower-case field key',
			'' === trim( $label )                                  => 'it has no label',
			! in_array( $type, self::TYPES, true )                 => 'its type is unknown',
			! in_array( $variation_rule, self::VARIATION_RULES, true ) => 'its variation rule is unknown',
			[] !== array_diff( $edit_kinds, self::EDIT_KINDS )     => 'it names an unknown edit kind',
			count( array_unique( $edit_kinds ) ) !== count( $edit_kinds ) => 'it names an edit kind twice',
			( [] === $edit_kinds ) !== ( null === $setter )        => 'a field with edit kinds needs a setter and a read-only one none',
			$inline && ! in_array( self::SET, $edit_kinds, true )  => 'an inline field must accept set',
			in_array( self::CLEAR, $edit_kinds, true ) && ! $nullable => 'only a nullable field can be cleared',
			'' !== $group && ! in_array( $group, Group::ALL, true ) => 'its group is unknown',
			'' !== $group && [] === $edit_kinds                    => 'a read-only column has no group',
			0 > $default_order                                     => 'its default column place is negative',
			[] !== $hiding_choices && self::ENUM !== $type         => 'only a field of choices can hide a product',
			0 < count( array_diff( $hiding_choices, self::HIDES ) ) => 'it hides a product in an unknown way',
			is_array( $choices ) && [] !== array_diff( array_keys( $hiding_choices ), array_map( 'strval', array_keys( $choices ) ) ) => 'a choice that hides a product is not one of its choices',
			self::PER_VARIATION !== $variation_rule && self::CONDITIONAL !== $variation_rule && [] !== $edit_kinds && '' === $variation_note => 'it needs a note saying why a variation cannot take it',
			$internal && ( '' !== $group || 0 < $default_order || [] !== $filter_operators || $inline || $sortable ) => 'an internal field has no group, default column, filter, sort or inline edit',
			[] !== $filter_choices && ( self::ENUM !== $type || [] === $filter_operators ) => 'only a filterable field of choices takes filter-only choices',
			default                                                => '',
		};
		if ( '' !== $problem ) {
			throw new \InvalidArgumentException( esc_html( sprintf( 'Field "%s" is invalid: %s.', $key, $problem ) ) );
		}
	}

	/**
	 * The field key: what the client, the REST API and the journal call it.
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * The plain-English label.
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * One of TYPES.
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Where WooCommerce keeps the value. Internal: never sent to the client.
	 */
	public function storage(): Storage {
		return $this->storage;
	}

	/**
	 * Accepted edit kinds, in declaration order.
	 *
	 * @return string[]
	 */
	public function edit_kinds(): array {
		return $this->edit_kinds;
	}

	/**
	 * Whether the field accepts an edit kind.
	 *
	 * @param string $kind Edit kind.
	 */
	public function accepts( string $kind ): bool {
		return in_array( $kind, $this->edit_kinds, true );
	}

	/**
	 * The bulk action panel group (one of Operations\Group::ALL), or '' for a
	 * read-only column.
	 */
	public function group(): string {
		return $this->group;
	}

	/**
	 * One of VARIATION_RULES.
	 */
	public function variation_rule(): string {
		return $this->variation_rule;
	}

	/**
	 * Whether the field applies only where the object manages its stock
	 * (stock quantity, SPEC §8), so a refusal there skips the row.
	 */
	public function requires_managed_stock(): bool {
		return self::REQUIRES_MANAGED_STOCK === $this->requires;
	}

	/**
	 * Whether a variable product holds a value of its own for this field
	 * (T69). A price does not: a variable product takes it from its
	 * variations, so the field is unavailable there (SPEC §8).
	 */
	public function applies_to_variable_parent(): bool {
		return ! $this->read_only() && '' === $this->variable_parent_note;
	}

	/**
	 * Whether the value must be unique across products and variations (the SKU).
	 */
	public function unique(): bool {
		return $this->unique;
	}

	/**
	 * The sentence for a unique value another object already holds (SPEC §4.8).
	 *
	 * @param string $value The value in use, such as a SKU.
	 */
	public static function clash_reason( string $value ): string {
		return sprintf(
			/* translators: %s: the SKU that is already in use. */
			__( 'A product with the SKU %s already exists, so this SKU was not applied.', 'bulk-catalogue-manager-for-woocommerce' ),
			$value
		);
	}

	/**
	 * Whether the field is a read-only column.
	 */
	public function read_only(): bool {
		return [] === $this->edit_kinds;
	}

	/**
	 * Whether the field is internal (T88): resolved by key, listed nowhere a
	 * client sees and accepted by no public action.
	 */
	public function internal(): bool {
		return $this->internal;
	}

	/**
	 * Whether the grid edits it in place.
	 */
	public function inline(): bool {
		return $this->inline;
	}

	/**
	 * Whether empty is a valid value.
	 */
	public function nullable(): bool {
		return $this->nullable;
	}

	/**
	 * Whether the grid may sort on it.
	 */
	public function sortable(): bool {
		return $this->sortable;
	}

	/**
	 * DataViews filter operators, in declaration order; empty when the field
	 * is not a filter. Route arguments are generated from these (T14).
	 *
	 * @return string[]
	 */
	public function filter_operators(): array {
		return $this->filter_operators;
	}

	/**
	 * Whether the grid may filter on it.
	 */
	public function filterable(): bool {
		return [] !== $this->filter_operators;
	}

	/**
	 * The WooCommerce CRUD getter.
	 */
	public function getter(): string {
		return $this->getter;
	}

	/**
	 * The WooCommerce CRUD setter, or null for a read-only column.
	 */
	public function setter(): ?string {
		return $this->setter;
	}

	/**
	 * Every choice of an enum, value => label, including values that can be
	 * read but not written.
	 *
	 * @return array<string, string>
	 */
	public function choices(): array {
		if ( null === $this->resolved_choices ) {
			$choices                = $this->choices instanceof \Closure ? ( $this->choices )() : $this->choices;
			$this->resolved_choices = [];
			foreach ( $choices as $value => $label ) {
				$this->resolved_choices[ (string) $value ] = (string) $label;
			}
		}
		return $this->resolved_choices;
	}

	/**
	 * Values the field's filter takes besides its choices, value => label
	 * (T92): never a choice, never written.
	 *
	 * @return array<string, string>
	 */
	public function filter_choices(): array {
		return $this->filter_choices;
	}

	/**
	 * Values that may be written.
	 *
	 * @return string[]
	 */
	public function writable_choices(): array {
		return $this->write_choices ?? array_keys( $this->choices() );
	}

	/**
	 * Run work on behalf of a user other than the current one: background
	 * work, which has no current user, acting for the batch's recorded user.
	 * Whether text may keep markup the post HTML filter would remove is then
	 * that user's `unfiltered_html`, as it would be in their own edit.
	 *
	 * @param int|null $user_id The user acted for, or null for the current user.
	 * @param callable $work    fn(): mixed.
	 * @return mixed What the work returned.
	 */
	public static function acting_as( ?int $user_id, callable $work ): mixed {
		$previous          = self::$acting_user;
		self::$acting_user = $user_id;
		try {
			return $work();
		} finally {
			self::$acting_user = $previous;
		}
	}

	/**
	 * Whether the user acted for (or the current user) may write unfiltered HTML.
	 */
	private static function may_write_unfiltered_html(): bool {
		if ( null === self::$acting_user ) {
			return current_user_can( 'unfiltered_html' );
		}
		return self::$acting_user > 0 && user_can( self::$acting_user, 'unfiltered_html' );
	}

	/**
	 * The sanitiser: the value in the field's canonical form. Run after
	 * validate(); given an invalid value it returns the field's empty value
	 * rather than guessing.
	 *
	 * @param mixed $value Submitted value.
	 */
	public function sanitise( mixed $value ): mixed {
		if ( self::ADAPTER_OFF_RECORD === $this->adapter ) {
			$record = OffRecord::read( $value );
			return null === $record ? '' : $record->to_json();
		}
		switch ( $this->type ) {
			case self::TEXT:
				$text = is_scalar( $value ) ? trim( (string) $value ) : '';
				if ( $this->kses ) {
					return self::may_write_unfiltered_html() ? $text : wp_kses_post( $text );
				}
				return sanitize_text_field( $text );
			case self::ENUM:
				$text = is_scalar( $value ) ? (string) $value : '';
				return in_array( $text, array_keys( $this->choices() ), true ) ? $text : '';
			case self::BOOLEAN:
				return true === Values::boolean( $value );
			case self::MONEY:
			case self::DECIMAL:
				$decimal = Values::is_empty( $value ) ? null : Values::decimal( $value );
				if ( null === $decimal ) {
					return '';
				}
				return self::MONEY === $this->type ? wc_format_decimal( $decimal, wc_get_price_decimals() ) : wc_format_decimal( $decimal );
			case self::INTEGER:
				return Values::is_empty( $value ) ? null : Values::integer( $value );
			case self::TERMS:
				return Values::id_list( $value ) ?? [];
			case self::TERM:
				return max( 0, Values::integer( $value ) ?? 0 );
			default:
				return $value;
		}
	}

	/**
	 * The validator: true, or a WP_Error whose message says what to enter
	 * (SPEC §4.8). Checks the value alone; write() adds the checks that need
	 * the object.
	 *
	 * @param mixed $value Submitted value.
	 */
	public function validate( mixed $value ): bool|\WP_Error {
		if ( $this->read_only() ) {
			return self::error(
				'bulkcat_read_only',
				/* translators: %s: a field's name, such as "Total sales". */
				sprintf( __( '%s cannot be changed here. It is shown for information only.', 'bulk-catalogue-manager-for-woocommerce' ), $this->label )
			);
		}
		if ( $this->nullable && Values::is_empty( $value ) ) {
			return true;
		}
		if ( self::ADAPTER_OFF_RECORD === $this->adapter ) {
			return null !== OffRecord::read( $value ) ? true : self::error( 'bulkcat_invalid_value', self::off_record_rule() );
		}
		$ok = match ( $this->type ) {
			self::TEXT    => $this->valid_text( $value ),
			self::ENUM    => is_string( $value ) && in_array( $value, $this->writable_choices(), true ),
			self::BOOLEAN => null !== Values::boolean( $value ),
			self::MONEY, self::DECIMAL => self::non_negative( $value ),
			self::INTEGER => null !== Values::integer( $value ),
			self::TERMS   => $this->valid_terms( $value ),
			self::TERM    => $this->valid_term( $value ),
			default       => false,
		};
		return $ok ? true : self::error( 'bulkcat_invalid_value', $this->rule_text() );
	}

	/**
	 * Validate, then sanitise: the canonical value, or the reason it was refused.
	 *
	 * @param mixed $value Submitted value.
	 */
	public function clean( mixed $value ): mixed {
		$valid = $this->validate( $value );
		return true === $valid ? $this->sanitise( $value ) : $valid;
	}

	/**
	 * Why this field cannot be written on this object, in plain English, or
	 * null when it can (SPEC §8 rules 3 and "a variable parent has no price").
	 *
	 * @param object $product A WooCommerce product or variation.
	 */
	public function unavailable_reason( object $product ): ?string {
		if ( $this->read_only() ) {
			return sprintf(
				/* translators: %s: a field's name, such as "Total sales". */
				__( '%s cannot be changed here. It is shown for information only.', 'bulk-catalogue-manager-for-woocommerce' ),
				$this->label
			);
		}
		$is_variation = self::is_type( $product, 'variation' );
		if ( $is_variation && ( self::PARENT_ONLY === $this->variation_rule || self::DERIVED === $this->variation_rule ) ) {
			return $this->variation_note;
		}
		if ( ! $is_variation && '' !== $this->variable_parent_note && self::is_type( $product, 'variable' ) ) {
			return $this->variable_parent_note;
		}
		if ( self::REQUIRES_MANAGED_STOCK === $this->requires && true !== self::call( $product, 'get_manage_stock', true ) ) {
			return $this->variation_note;
		}
		return null;
	}

	/**
	 * The getter path: the object's current value in canonical form.
	 *
	 * @param object $product A WooCommerce product or variation.
	 */
	public function read( object $product ): mixed {
		switch ( $this->adapter ) {
			case self::ADAPTER_PLAIN:
				return self::call( $product, $this->getter, false );
			case self::ADAPTER_COUNT:
				$list = self::call( $product, $this->getter, false );
				return is_array( $list ) ? count( $list ) : 0;
			case self::ADAPTER_DATE:
				$date = self::call( $product, $this->getter, true );
				return is_object( $date ) && method_exists( $date, 'date' ) ? (string) $date->date( 'c' ) : null;
			case self::ADAPTER_SWITCH:
				$visibility = (string) self::call( $product, $this->getter, true );
				return self::SWITCH_READ[ $visibility ] ?? 'partly';
			case self::ADAPTER_OFF_RECORD:
				return OffRecord::raw( $product );
			default:
				$value = self::call( $product, $this->getter, true );
				return match ( $this->type ) {
					self::BOOLEAN => true === Values::boolean( $value ),
					self::INTEGER => null === $value || '' === $value ? null : Values::integer( $value ),
					self::TERMS   => array_values( array_map( 'intval', (array) $value ) ),
					self::TERM, self::ID, self::IMAGE => (int) $value,
					self::MONEY, self::DECIMAL, self::TEXT, self::ENUM, self::HTML => null === $value ? '' : (string) $value,
					default => $value,
				};
		}
	}

	/**
	 * The dry run of write(): the WP_Error write() would return for this
	 * value on this object, or null when it would set it. Preview and the
	 * row validator call this, so what they report is exactly what the
	 * write would do. Sets nothing.
	 *
	 * @param object $subject A WooCommerce product or variation.
	 * @param mixed  $value   Submitted value.
	 */
	public function refusal( object $subject, mixed $value ): ?\WP_Error {
		if ( $this->read_only() ) {
			$valid = $this->validate( $value );
			return $valid instanceof \WP_Error ? $valid : null;
		}
		$reason = $this->unavailable_reason( $subject );
		if ( null !== $reason ) {
			return self::error( 'bulkcat_not_available', $reason );
		}
		$clean = $this->clean( $value );
		if ( $clean instanceof \WP_Error ) {
			return $clean;
		}
		$refusal = $this->object_refusal( $subject, $clean );
		return null === $refusal ? null : self::error( 'bulkcat_invalid_value', $refusal );
	}

	/**
	 * The setter path: check the value against the field and the object
	 * (refusal()), then set it through the CRUD setter. Does not save.
	 *
	 * @param object $product A WooCommerce product or variation.
	 * @param mixed  $value   Submitted value.
	 * @return mixed The canonical value set, or a WP_Error saying why nothing was set.
	 */
	public function write( object $product, mixed $value ): mixed {
		$refusal = $this->refusal( $product, $value );
		if ( null !== $refusal ) {
			return $refusal;
		}
		$clean = $this->clean( $value );
		if ( self::ADAPTER_OFF_RECORD === $this->adapter ) {
			OffRecord::write( $product, (string) $clean );
			return $clean;
		}
		$stored = self::ADAPTER_SWITCH === $this->adapter ? self::SWITCH_WRITE[ $clean ] : $clean;
		$product->{$this->setter}( $stored );
		return $clean;
	}

	/**
	 * The REST schema fragment for the value as the API returns it.
	 *
	 * @return array<string, mixed>
	 */
	public function rest_schema(): array {
		$schema = match ( $this->type ) {
			self::TEXT, self::HTML => [ 'type' => 'string' ],
			self::ENUM             => [
				'type' => 'string',
				'enum' => array_keys( $this->choices() ),
			],
			self::BOOLEAN          => [ 'type' => 'boolean' ],
			self::MONEY, self::DECIMAL => [ 'type' => 'string' ],
			self::INTEGER          => [ 'type' => $this->nullable ? [ 'integer', 'null' ] : 'integer' ],
			self::TERMS            => [
				'type'        => 'array',
				'items'       => [
					'type'    => 'integer',
					'minimum' => 1,
				],
				'maxItems'    => Values::MAX_TERMS,
				'uniqueItems' => true,
			],
			self::TERM             => [
				'type'    => 'integer',
				'minimum' => 0,
			],
			self::ID, self::IMAGE  => [
				'type'    => 'integer',
				'minimum' => 0,
			],
			self::DATETIME         => [
				'type'   => [ 'string', 'null' ],
				'format' => 'date-time',
			],
		};
		if ( $this->max_length > 0 ) {
			$schema['maxLength'] = $this->max_length;
		}
		return [ 'description' => $this->label ] + $schema + [
			'context'  => [ 'view', 'edit' ],
			'readonly' => $this->read_only(),
		];
	}

	/**
	 * A REST route argument for writing this field (T14 generates route args
	 * from these): the schema plus this field's validator and sanitiser.
	 *
	 * @return array<string, mixed>
	 */
	public function rest_arg(): array {
		$arg = $this->rest_schema();
		unset( $arg['context'], $arg['readonly'] );
		if ( self::ENUM === $this->type ) {
			$arg['enum'] = $this->writable_choices();
		}
		$arg['validate_callback'] = [ $this, 'validate' ];
		$arg['sanitize_callback'] = [ $this, 'sanitise' ];
		return $arg;
	}

	/**
	 * The DataViews field definition the grid builds its column from.
	 *
	 * @return array<string, mixed>
	 */
	public function dataviews(): array {
		$definition = [
			'id'            => $this->key,
			'label'         => $this->label,
			'type'          => match ( $this->type ) {
				self::BOOLEAN => 'boolean',
				self::MONEY, self::DECIMAL => 'number',
				self::INTEGER, self::TERM, self::ID, self::IMAGE => 'integer',
				self::TERMS => 'array',
				self::DATETIME => 'datetime',
				default => 'text',
			},
			'enableSorting' => $this->sortable,
			'enableHiding'  => 'name' !== $this->key,
			'readOnly'      => $this->read_only(),
			'filterBy'      => [] === $this->filter_operators ? false : [ 'operators' => $this->filter_operators ],
		];
		if ( self::ENUM === $this->type ) {
			$definition['elements'] = self::elements( $this->choices() + $this->filter_choices );
		}
		return $definition;
	}

	/**
	 * Everything the client needs about the field, and no storage key (SPEC §12.1).
	 *
	 * @return array<string, mixed>
	 */
	public function to_client(): array {
		$client = [
			'key'                => $this->key,
			'label'              => $this->label,
			'type'               => $this->type,
			'readOnly'           => $this->read_only(),
			'nullable'           => $this->nullable,
			'inline'             => $this->inline,
			'editKinds'          => $this->edit_kinds,
			'variations'         => $this->variation_rule,
			'variationNote'      => $this->variation_note,
			'variableParentNote' => $this->variable_parent_note,
			'sortable'           => $this->sortable,
			'defaultColumn'      => $this->default_order > 0,
			'defaultOrder'       => $this->default_order > 0 ? $this->default_order : null,
			'schema'             => $this->rest_schema(),
			'dataviews'          => $this->dataviews(),
		];
		if ( self::ENUM === $this->type ) {
			$client['choices']              = array_map(
				fn( array $element ): array => $element + [
					'hidesProduct' => isset( $this->hiding_choices[ $element['value'] ] ),
					'hidesAs'      => $this->hiding_choices[ $element['value'] ] ?? null,
				],
				self::elements( $this->choices() )
			);
			$client['writableChoices']      = $this->writable_choices();
			$client['variationChoices']     = $this->variation_choices ?? $this->writable_choices();
			$client['variationOnlyChoices'] = $this->variation_only_choices;
		}
		if ( '' !== $this->group ) {
			$client['group'] = $this->group;
		}
		if ( \BulkCatalogueManager\Availability\StatusSwitch::governs( $this ) ) {
			// The global Off/On switch (SPEC §5.5): the screen picks it by this flag, never by its key.
			$client['switchesOffOn'] = true;
		}
		if ( '' !== $this->source ) {
			$client['source'] = $this->source;
		}
		if ( $this->max_length > 0 ) {
			$client['maxLength'] = $this->max_length;
		}
		return $client;
	}

	/**
	 * What the field accepts, as the §4.8 sentence a refusal shows.
	 */
	public function rule_text(): string {
		switch ( $this->type ) {
			case self::TEXT:
				return $this->nullable
					/* translators: 1: a field's name, such as "SKU", 2: the most characters it takes. */
					? sprintf( _n( '%1$s has to be text of up to %2$d character, or left empty.', '%1$s has to be text of up to %2$d characters, or left empty.', $this->max_length, 'bulk-catalogue-manager-for-woocommerce' ), $this->label, $this->max_length )
					/* translators: 1: a field's name, such as "Name", 2: the most characters it takes. */
					: sprintf( _n( '%1$s cannot be empty and has to be up to %2$d character.', '%1$s cannot be empty and has to be up to %2$d characters.', $this->max_length, 'bulk-catalogue-manager-for-woocommerce' ), $this->label, $this->max_length );
			case self::ENUM:
				$labels = array_map( fn( string $value ): string => $this->choices()[ $value ] ?? $value, $this->writable_choices() );
				/* translators: 1: a field's name, such as "Stock status", 2: the choices, separated by commas. */
				return sprintf( __( '%1$s has to be one of: %2$s.', 'bulk-catalogue-manager-for-woocommerce' ), $this->label, implode( ', ', $labels ) );
			case self::BOOLEAN:
				/* translators: %s: a field's name, such as "Featured". */
				return sprintf( __( '%s has to be on or off.', 'bulk-catalogue-manager-for-woocommerce' ), $this->label );
			case self::MONEY:
				/* translators: %s: a field's name, such as "Regular price". */
				return sprintf( __( '%s has to be a price of 0 or more, like 12.50, or left empty.', 'bulk-catalogue-manager-for-woocommerce' ), $this->label );
			case self::DECIMAL:
				/* translators: %s: a field's name, such as "Weight". */
				return sprintf( __( '%s has to be a number of 0 or more, like 1.5, or left empty.', 'bulk-catalogue-manager-for-woocommerce' ), $this->label );
			case self::INTEGER:
				/* translators: %s: a field's name, such as "Stock quantity". */
				return sprintf( __( '%s has to be a whole number. Enter a number like 12.', 'bulk-catalogue-manager-for-woocommerce' ), $this->label );
			case self::TERMS:
				/* translators: 1: a field's name, such as "Categories", 2: the most items allowed. */
				return sprintf( _n( '%1$s has to be a list of up to %2$d that already exists. Choose it from the list.', '%1$s has to be a list of up to %2$d that already exist. Choose them from the list.', Values::MAX_TERMS, 'bulk-catalogue-manager-for-woocommerce' ), $this->label, Values::MAX_TERMS );
			default:
				/* translators: %s: a field's name, such as "Shipping class". */
				return sprintf( __( '%s has to be one that already exists. Choose it from the list.', 'bulk-catalogue-manager-for-woocommerce' ), $this->label );
		}
	}

	/**
	 * Checks that need the object as well as the value: the choices a
	 * variation may take, and SKU uniqueness across products and variations.
	 *
	 * @param object $product A WooCommerce product or variation.
	 * @param mixed  $clean   Canonical value.
	 */
	private function object_refusal( object $product, mixed $clean ): ?string {
		if ( self::ADAPTER_OFF_RECORD === $this->adapter ) {
			$id = (int) self::call( $product, 'get_id', false );
			return '' === $clean || null !== OffRecord::parse( $clean, $id ) ? null : self::off_record_rule();
		}
		$is_variation = self::is_type( $product, 'variation' );
		if ( self::ENUM === $this->type ) {
			$allowed = $is_variation ? ( $this->variation_choices ?? $this->writable_choices() ) : array_diff( $this->writable_choices(), $this->variation_only_choices );
			if ( ! in_array( $clean, $allowed, true ) ) {
				$labels = array_map( fn( string $value ): string => $this->choices()[ $value ] ?? $value, array_values( $allowed ) );
				return $is_variation
					/* translators: 1: a field's name, such as "Status", 2: the choices, separated by commas. */
					? sprintf( __( 'On a variation, %1$s can only be: %2$s.', 'bulk-catalogue-manager-for-woocommerce' ), $this->label, implode( ', ', $labels ) )
					/* translators: 1: a field's name, such as "Tax class", 2: the choices, separated by commas. */
					: sprintf( __( 'On a product, %1$s can only be: %2$s.', 'bulk-catalogue-manager-for-woocommerce' ), $this->label, implode( ', ', $labels ) );
			}
		}
		if ( $this->unique && is_string( $clean ) && '' !== $clean ) {
			$id = (int) self::call( $product, 'get_id', false );
			if ( ! wc_product_has_unique_sku( $id, $clean ) ) {
				return self::clash_reason( $clean );
			}
		}
		return null;
	}

	/**
	 * Why a value is not this product's saved status (SPEC §4.8).
	 */
	private static function off_record_rule(): string {
		return __( 'The saved status before Off could not be read for this product, so it was not changed. Reload the list and try again.', 'bulk-catalogue-manager-for-woocommerce' );
	}

	/**
	 * Text within its length, non-empty unless nullable.
	 *
	 * @param mixed $value Value.
	 */
	private function valid_text( mixed $value ): bool {
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			return false;
		}
		$text = trim( (string) $value );
		if ( '' === $text ) {
			return $this->nullable;
		}
		return 0 === $this->max_length || mb_strlen( $text ) <= $this->max_length;
	}

	/**
	 * Up to MAX_TERMS existing terms of the field's taxonomy.
	 *
	 * @param mixed $value Value.
	 */
	private function valid_terms( mixed $value ): bool {
		$ids = Values::id_list( $value );
		if ( null === $ids || count( $ids ) > Values::MAX_TERMS ) {
			return false;
		}
		foreach ( $ids as $id ) {
			if ( ! term_exists( $id, $this->storage->key() ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Zero (none, or on a variation the same as its product) or an existing term.
	 *
	 * @param mixed $value Value.
	 */
	private function valid_term( mixed $value ): bool {
		$id = Values::integer( $value );
		if ( null === $id || $id < 0 ) {
			return false;
		}
		return 0 === $id || (bool) term_exists( $id, $this->storage->key() );
	}

	/**
	 * A decimal of zero or more. A result below zero is refused, never clamped (SPEC §5.4).
	 *
	 * @param mixed $value Value.
	 */
	private static function non_negative( mixed $value ): bool {
		$decimal = Values::decimal( $value );
		return null !== $decimal && ! str_starts_with( $decimal, '-' );
	}

	/**
	 * Value => label as DataViews elements.
	 *
	 * @param array<string, string> $choices Choices.
	 * @return array<int, array{value: string, label: string}>
	 */
	private static function elements( array $choices ): array {
		$elements = [];
		foreach ( $choices as $value => $label ) {
			$elements[] = [
				'value' => (string) $value,
				'label' => $label,
			];
		}
		return $elements;
	}

	/**
	 * Whether a product object is of a WooCommerce type.
	 *
	 * @param object $product Product.
	 * @param string $type    Product type.
	 */
	private static function is_type( object $product, string $type ): bool {
		return method_exists( $product, 'is_type' ) && true === $product->is_type( $type );
	}

	/**
	 * Call a getter, in edit context when asked.
	 *
	 * @param object $product Product.
	 * @param string $method  Getter.
	 * @param bool   $edit    Whether to pass the 'edit' context.
	 */
	private static function call( object $product, string $method, bool $edit ): mixed {
		return $edit ? $product->{$method}( 'edit' ) : $product->{$method}();
	}

	/**
	 * A refusal with the field's plain sentence and a 400 status.
	 *
	 * @param string $code    `bulkcat_` error code.
	 * @param string $message Plain-English sentence.
	 */
	private static function error( string $code, string $message ): \WP_Error {
		return Refusal::invalid( $code, $message );
	}
}
