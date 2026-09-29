<?php
/**
 * The check a provider passes when it registers (docs/EXTENSIONS.md §3.2,
 * §3.4, §5.2): everything it says about itself is read once, through the
 * guard, and checked; the first problem refuses the whole provider with
 * `bulkcat_provider_incompatible`. Labels and descriptions longer than their
 * limits are trimmed with an ellipsis rather than refused; identifiers must
 * match their patterns.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\External;

use BulkCatalogueManager\Extension\ActionDescriptor;
use BulkCatalogueManager\Extension\ChooserGroup;
use BulkCatalogueManager\Extension\Contract;
use BulkCatalogueManager\Extension\ExternalAction;
use BulkCatalogueManager\Extension\ExternalField;
use BulkCatalogueManager\Extension\Provider;
use BulkCatalogueManager\Fields\Field;
use BulkCatalogueManager\Operations\Group;

defined( 'ABSPATH' ) || exit;

/**
 * Checks one provider.
 */
final class Inspector {
	/**
	 * A provider id: three to twenty lower-case letters and digits, starting with a letter.
	 */
	public const PROVIDER_ID = '/^[a-z][a-z0-9]{2,19}$/';

	/**
	 * Most fields a provider declares.
	 */
	public const MAX_FIELDS = 5;

	/**
	 * Most actions a provider offers.
	 */
	public const MAX_ACTIONS = 20;

	/**
	 * Most chooser groups of a provider's own.
	 */
	public const MAX_GROUPS = 2;

	/**
	 * Most choices one argument offers.
	 */
	public const MAX_CHOICES = 500;

	/**
	 * Most items one multichoice argument may choose.
	 */
	public const MAX_ITEMS = 100;

	/**
	 * Longest field key, action id or group id (the journal's `prop` column).
	 */
	public const MAX_KEY = 64;

	/**
	 * The argument kinds a provider may declare.
	 */
	public const KINDS = [ 'boolean', 'text', 'integer', 'decimal', 'choice', 'multichoice' ];

	/**
	 * Argument names the core adds itself.
	 */
	public const RESERVED = [ 'field', 'apply_to_variations', 'skip_already' ];

	/**
	 * The keys an argument spec may carry.
	 */
	private const SPEC_KEYS = [ 'type', 'label', 'rule', 'required', 'default', 'choices', 'min', 'max', 'exclusive_min', 'exclusive_max', 'max_length', 'allow_empty', 'min_items', 'max_items' ];

	/**
	 * Check a provider: what it registered as, or the refusal naming the first problem.
	 *
	 * @param Provider  $provider The provider.
	 * @param list<string> $taken Provider ids already registered.
	 */
	public static function inspect( Provider $provider, array $taken ): Registered|\WP_Error {
		$id = '';
		try {
			$id       = Guard::run( '', 'id', static fn(): mixed => $provider->id() );
			$id       = is_string( $id ) ? $id : '';
			$contract = Guard::run( $id, 'contract', static fn(): mixed => $provider->contract() );
			if ( ! is_int( $contract ) || ! in_array( $contract, Contract::SUPPORTED, true ) ) {
				return self::refuse(
					sprintf(
						/* translators: %s: the contract versions this plugin accepts, such as "1". */
						__( 'it was written for a version of the add-on contract this plugin does not support (this plugin supports version %s).', 'bulk-catalogue-manager-for-woocommerce' ),
						implode( ', ', Contract::SUPPORTED )
					)
				);
			}
			if ( 1 !== preg_match( self::PROVIDER_ID, $id ) ) {
				return self::refuse( __( 'its id must be three to twenty lower-case letters and digits, starting with a letter.', 'bulk-catalogue-manager-for-woocommerce' ) );
			}
			if ( in_array( $id, $taken, true ) ) {
				return self::refuse(
					sprintf(
						/* translators: %s: an add-on id, such as "example". */
						__( 'another add-on is already registered with the id "%s".', 'bulk-catalogue-manager-for-woocommerce' ),
						$id
					)
				);
			}
			$label   = Text::plain( Guard::run( $id, 'label', static fn(): mixed => $provider->label() ), Text::LABEL );
			$version = Text::plain( Guard::run( $id, 'version', static fn(): mixed => $provider->version() ), 40 );
			if ( '' === $label ) {
				return self::refuse( __( 'it has no name.', 'bulk-catalogue-manager-for-woocommerce' ) );
			}
			$fields   = Guard::run( $id, 'fields', static fn(): mixed => $provider->fields() );
			$groups   = Guard::run( $id, 'groups', static fn(): mixed => $provider->groups() );
			$declared = Guard::run( $id, 'actions', static fn(): mixed => $provider->actions() );
			return self::check( $provider, $id, $label, $version, $contract, $fields, $groups, $declared );
		} catch ( ProviderFailure $e ) {
			return self::refuse( __( 'one of its methods failed while it was being read. The WooCommerce log has the details.', 'bulk-catalogue-manager-for-woocommerce' ) );
		}
	}

	/**
	 * Check what the provider declared.
	 *
	 * @param Provider $provider The provider.
	 * @param string   $id       Its id.
	 * @param string   $label    Its label.
	 * @param string   $version  Its version.
	 * @param int      $contract Its contract version.
	 * @param mixed    $fields   What fields() returned.
	 * @param mixed    $groups   What groups() returned.
	 * @param mixed    $declared What actions() returned.
	 * @throws ProviderFailure When a method throws.
	 */
	private static function check( Provider $provider, string $id, string $label, string $version, int $contract, mixed $fields, mixed $groups, mixed $declared ): Registered|\WP_Error {
		if ( ! is_array( $fields ) || [] === $fields || ! array_is_list( $fields ) ) {
			return self::refuse( __( 'it declares no values of its own.', 'bulk-catalogue-manager-for-woocommerce' ) );
		}
		if ( count( $fields ) > self::MAX_FIELDS ) {
			return self::refuse( self::limit( __( 'values', 'bulk-catalogue-manager-for-woocommerce' ), self::MAX_FIELDS ) );
		}
		$by_key = [];
		$meta   = [];
		foreach ( $fields as $field ) {
			if ( ! $field instanceof ExternalField ) {
				return self::refuse( __( 'one of its values is not an external field.', 'bulk-catalogue-manager-for-woocommerce' ) );
			}
			$key = Guard::run( $id, 'field key', static fn(): mixed => $field->key() );
			if ( ! is_string( $key ) || ! self::prefixed( $key, $id ) ) {
				return self::refuse( self::bad_id( __( 'value', 'bulk-catalogue-manager-for-woocommerce' ), is_string( $key ) ? $key : '', $id ) );
			}
			if ( isset( $by_key[ $key ] ) ) {
				return self::refuse( self::twice( __( 'value', 'bulk-catalogue-manager-for-woocommerce' ), $key ) );
			}
			$rule = Guard::run( $id, 'variation_rule', static fn(): mixed => $field->variation_rule() );
			if ( ! in_array( $rule, [ ExternalField::PARENT_ONLY, ExternalField::PER_VARIATION ], true ) ) {
				return self::refuse(
					sprintf(
						/* translators: %s: a value's key, such as "x_example_labels". */
						__( 'its value "%s" must belong either to the product or to each variation.', 'bulk-catalogue-manager-for-woocommerce' ),
						$key
					)
				);
			}
			$format = Guard::run( $id, 'format', static fn(): mixed => $field->format() );
			if ( ! is_int( $format ) || $format < 1 ) {
				return self::refuse(
					sprintf(
						/* translators: %s: a value's key, such as "x_example_labels". */
						__( 'its value "%s" must give a format version of 1 or more.', 'bulk-catalogue-manager-for-woocommerce' ),
						$key
					)
				);
			}
			$field_label = Text::plain( Guard::run( $id, 'field label', static fn(): mixed => $field->label() ), Text::LABEL );
			if ( '' === $field_label ) {
				return self::refuse( self::unnamed( $key ) );
			}
			$by_key[ $key ] = $field;
			$meta[ $key ]   = [
				'key'    => $key,
				'label'  => $field_label,
				'rule'   => $rule,
				'format' => $format,
			];
		}

		if ( ! is_array( $groups ) || ! array_is_list( $groups ) ) {
			return self::refuse( __( 'its chooser groups could not be read.', 'bulk-catalogue-manager-for-woocommerce' ) );
		}
		if ( count( $groups ) > self::MAX_GROUPS ) {
			return self::refuse( self::limit( __( 'chooser groups', 'bulk-catalogue-manager-for-woocommerce' ), self::MAX_GROUPS ) );
		}
		$group_list = [];
		foreach ( $groups as $group ) {
			if ( ! $group instanceof ChooserGroup || ! self::prefixed( $group->id(), $id ) ) {
				return self::refuse( self::bad_id( __( 'chooser group', 'bulk-catalogue-manager-for-woocommerce' ), $group instanceof ChooserGroup ? $group->id() : '', $id ) );
			}
			if ( in_array( $group->id(), array_column( $group_list, 'id' ), true ) ) {
				return self::refuse( self::twice( __( 'chooser group', 'bulk-catalogue-manager-for-woocommerce' ), $group->id() ) );
			}
			$group_label = Text::plain( $group->label(), Text::LABEL );
			if ( '' === $group_label ) {
				return self::refuse( self::unnamed( $group->id() ) );
			}
			$group_list[] = [
				'id'    => $group->id(),
				'label' => $group_label,
			];
		}

		if ( ! is_array( $declared ) || [] === $declared || ! array_is_list( $declared ) ) {
			return self::refuse( __( 'it offers no actions.', 'bulk-catalogue-manager-for-woocommerce' ) );
		}
		if ( count( $declared ) > self::MAX_ACTIONS ) {
			return self::refuse( self::limit( __( 'actions', 'bulk-catalogue-manager-for-woocommerce' ), self::MAX_ACTIONS ) );
		}
		$actions   = [];
		$described = [];
		foreach ( $declared as $action ) {
			if ( ! $action instanceof ExternalAction ) {
				return self::refuse( __( 'one of its actions is not an external action.', 'bulk-catalogue-manager-for-woocommerce' ) );
			}
			$descriptor = Guard::run( $id, 'describe', static fn(): mixed => $action->describe() );
			if ( ! $descriptor instanceof ActionDescriptor ) {
				return self::refuse( __( 'one of its actions does not describe itself.', 'bulk-catalogue-manager-for-woocommerce' ) );
			}
			$kept = self::action( $descriptor, $id, $by_key, $meta, array_column( $group_list, 'id' ) );
			if ( is_string( $kept ) ) {
				return self::refuse( $kept );
			}
			if ( isset( $described[ $kept['id'] ] ) ) {
				return self::refuse( self::twice( __( 'action', 'bulk-catalogue-manager-for-woocommerce' ), $kept['id'] ) );
			}
			$actions[ $kept['id'] ]   = $action;
			$described[ $kept['id'] ] = $kept;
		}
		return new Registered( $provider, $id, $label, $version, $contract, $by_key, $meta, $actions, $described, $group_list );
	}

	/**
	 * One action checked and trimmed, or the sentence naming its problem.
	 *
	 * @param ActionDescriptor                                                            $descriptor What it described.
	 * @param string                                                                      $id         The provider id.
	 * @param array<string, ExternalField>                                                $fields     The provider's fields.
	 * @param array<string, array{key: string, label: string, rule: string, format: int}> $meta       Its fields as kept.
	 * @param list<string>                                                                $groups     Its chooser group ids.
	 * @return array<string, mixed>|string
	 */
	private static function action( ActionDescriptor $descriptor, string $id, array $fields, array $meta, array $groups ): array|string {
		$action_id = $descriptor->id();
		if ( ! self::prefixed( $action_id, $id ) ) {
			return self::bad_id( __( 'action', 'bulk-catalogue-manager-for-woocommerce' ), $action_id, $id );
		}
		if ( ! isset( $fields[ $descriptor->field() ] ) ) {
			return sprintf(
				/* translators: 1: an action id, such as "x_example_add"; 2: a value's key, such as "x_example_labels". */
				__( 'its action "%1$s" changes "%2$s", which is not one of its own values.', 'bulk-catalogue-manager-for-woocommerce' ),
				$action_id,
				$descriptor->field()
			);
		}
		if ( ! in_array( $descriptor->group(), Group::ALL, true ) && ! in_array( $descriptor->group(), $groups, true ) ) {
			return sprintf(
				/* translators: 1: an action id, such as "x_example_add"; 2: a chooser group id. */
				__( 'its action "%1$s" sits in the chooser group "%2$s", which does not exist.', 'bulk-catalogue-manager-for-woocommerce' ),
				$action_id,
				$descriptor->group()
			);
		}
		if ( ! in_array( $descriptor->edit_kind(), Field::EDIT_KINDS, true ) ) {
			return sprintf(
				/* translators: 1: an action id, such as "x_example_add"; 2: an edit kind it named. */
				__( 'its action "%1$s" names the edit kind "%2$s", which does not exist.', 'bulk-catalogue-manager-for-woocommerce' ),
				$action_id,
				$descriptor->edit_kind()
			);
		}
		$capability = trim( $descriptor->capability() );
		if ( '' === $capability ) {
			return sprintf(
				/* translators: %s: an action id, such as "x_example_add". */
				__( 'its action "%s" names no capability a user needs to use it.', 'bulk-catalogue-manager-for-woocommerce' ),
				$action_id
			);
		}
		$label       = Text::plain( $descriptor->label(), Text::LABEL );
		$description = Text::plain( $descriptor->description(), Text::DESCRIPTION );
		if ( '' === $label || '' === $description ) {
			return self::unnamed( $action_id );
		}
		$arguments = [];
		foreach ( $descriptor->arguments() as $name => $spec ) {
			$checked = self::spec( $action_id, $name, $spec );
			if ( is_string( $checked ) ) {
				return $checked;
			}
			$arguments[ $name ] = $checked;
		}
		return [
			'id'             => $action_id,
			'field'          => $descriptor->field(),
			'group'          => $descriptor->group(),
			'label'          => $label,
			'description'    => $description,
			'edit_kind'      => $descriptor->edit_kind(),
			'arguments'      => $arguments,
			'capability'     => $capability,
			'rule'           => $meta[ $descriptor->field() ]['rule'],
			'always_confirm' => $descriptor->always_confirm(),
			'confirm_reason' => Text::plain( $descriptor->confirm_reason(), Text::DESCRIPTION ),
		];
	}

	/**
	 * One argument spec checked and trimmed, or the sentence naming its problem.
	 *
	 * @param string $action The action id.
	 * @param mixed  $name   The argument name.
	 * @param mixed  $spec   Its spec.
	 * @return array<string, mixed>|string
	 */
	private static function spec( string $action, mixed $name, mixed $spec ): array|string {
		$problem = static fn( string $what ): string => sprintf(
			/* translators: 1: an action id, such as "x_example_add"; 2: an argument name; 3: what is wrong with it. */
			__( 'its action "%1$s" has an argument "%2$s" that %3$s', 'bulk-catalogue-manager-for-woocommerce' ),
			$action,
			is_string( $name ) ? $name : '',
			$what
		);
		if ( ! is_string( $name ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{0,39}$/', $name ) ) {
			return $problem( __( 'is not a lower-case name.', 'bulk-catalogue-manager-for-woocommerce' ) );
		}
		if ( in_array( $name, self::RESERVED, true ) ) {
			return $problem( __( 'uses a name the core adds itself.', 'bulk-catalogue-manager-for-woocommerce' ) );
		}
		if ( ! is_array( $spec ) || [] !== array_diff( array_keys( $spec ), self::SPEC_KEYS ) ) {
			return $problem( __( 'has a spec the core cannot read.', 'bulk-catalogue-manager-for-woocommerce' ) );
		}
		$type = $spec['type'] ?? null;
		if ( ! in_array( $type, self::KINDS, true ) ) {
			return $problem( __( 'is of a kind the core does not support.', 'bulk-catalogue-manager-for-woocommerce' ) );
		}
		$label = Text::plain( $spec['label'] ?? '', Text::LABEL );
		$rule  = Text::plain( $spec['rule'] ?? '', Text::DESCRIPTION );
		if ( '' === $label || '' === $rule ) {
			return $problem( __( 'has no label or no rule sentence.', 'bulk-catalogue-manager-for-woocommerce' ) );
		}
		$kept = [
			'type'  => $type,
			'label' => $label,
			'rule'  => $rule,
		];
		foreach ( [ 'required', 'allow_empty', 'exclusive_min', 'exclusive_max' ] as $flag ) {
			if ( array_key_exists( $flag, $spec ) ) {
				if ( ! is_bool( $spec[ $flag ] ) ) {
					return $problem( __( 'has a switch that is not true or false.', 'bulk-catalogue-manager-for-woocommerce' ) );
				}
				$kept[ $flag ] = $spec[ $flag ];
			}
		}
		foreach ( [ 'min', 'max' ] as $bound ) {
			if ( array_key_exists( $bound, $spec ) ) {
				if ( ! is_int( $spec[ $bound ] ) && ! is_float( $spec[ $bound ] ) ) {
					return $problem( __( 'has a bound that is not a number.', 'bulk-catalogue-manager-for-woocommerce' ) );
				}
				$kept[ $bound ] = $spec[ $bound ];
			}
		}
		foreach ( [ 'max_length', 'min_items', 'max_items' ] as $count ) {
			if ( array_key_exists( $count, $spec ) ) {
				if ( ! is_int( $spec[ $count ] ) || $spec[ $count ] < 0 ) {
					return $problem( __( 'has a length or item count that is not a whole number.', 'bulk-catalogue-manager-for-woocommerce' ) );
				}
				$kept[ $count ] = $spec[ $count ];
			}
		}
		if ( in_array( $type, [ 'choice', 'multichoice' ], true ) ) {
			$choices = $spec['choices'] ?? null;
			if ( ! is_array( $choices ) || [] === $choices ) {
				return $problem( __( 'offers no choices.', 'bulk-catalogue-manager-for-woocommerce' ) );
			}
			if ( count( $choices ) > self::MAX_CHOICES ) {
				return $problem(
					sprintf(
						/* translators: %d: the most choices one argument may offer, 500. */
						__( 'offers more than %d choices.', 'bulk-catalogue-manager-for-woocommerce' ),
						self::MAX_CHOICES
					)
				);
			}
			$kept['choices'] = [];
			foreach ( $choices as $value => $choice_label ) {
				$value = (string) $value;
				if ( '' === $value || strlen( $value ) > 64 || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
					return $problem( __( 'offers a choice whose value is empty, too long or not plain text.', 'bulk-catalogue-manager-for-woocommerce' ) );
				}
				$kept['choices'][ $value ] = Text::plain( $choice_label, Text::LABEL );
			}
			if ( 'multichoice' === $type ) {
				$max = $kept['max_items'] ?? min( self::MAX_ITEMS, count( $kept['choices'] ) );
				if ( $max > self::MAX_ITEMS || $max < 1 || ( $kept['min_items'] ?? 0 ) > $max ) {
					return $problem(
						sprintf(
							/* translators: %d: the most items one argument may choose, 100. */
							__( 'lets people choose more than %d items, or fewer than it requires.', 'bulk-catalogue-manager-for-woocommerce' ),
							self::MAX_ITEMS
						)
					);
				}
				$kept['max_items'] = $max;
				$kept['min_items'] = $kept['min_items'] ?? ( ! empty( $kept['required'] ) ? 1 : 0 );
			}
		} elseif ( array_key_exists( 'choices', $spec ) ) {
			return $problem( __( 'offers choices without being a choice.', 'bulk-catalogue-manager-for-woocommerce' ) );
		}
		if ( array_key_exists( 'default', $spec ) ) {
			if ( null === Arguments::normalise( $spec['default'], $kept ) ) {
				return $problem( __( 'has a default its own rule refuses.', 'bulk-catalogue-manager-for-woocommerce' ) );
			}
			$kept['default'] = Arguments::normalise( $spec['default'], $kept );
		}
		return $kept;
	}

	/**
	 * Whether an identifier is `x_<provider id>_<name>`, at most MAX_KEY characters.
	 *
	 * @param string $identifier The identifier.
	 * @param string $id         The provider id.
	 */
	public static function prefixed( string $identifier, string $id ): bool {
		return strlen( $identifier ) <= self::MAX_KEY && 1 === preg_match( '/^x_' . preg_quote( $id, '/' ) . '_[a-z0-9][a-z0-9_]*$/', $identifier );
	}

	/**
	 * The refusal of the whole provider.
	 *
	 * @param string $problem What is wrong, as the end of a sentence.
	 */
	private static function refuse( string $problem ): \WP_Error {
		return Refusals::incompatible( $problem );
	}

	/**
	 * The problem of an identifier without the provider's prefix.
	 *
	 * @param string $what       What it identifies.
	 * @param string $identifier The identifier.
	 * @param string $id         The provider id.
	 */
	private static function bad_id( string $what, string $identifier, string $id ): string {
		return sprintf(
			/* translators: 1: what the identifier names, such as "action"; 2: the identifier; 3: the prefix it needs, such as "x_example_". */
			__( 'its %1$s "%2$s" must be named "%3$s" followed by lower-case letters, digits or underscores, at most 64 characters in all.', 'bulk-catalogue-manager-for-woocommerce' ),
			$what,
			$identifier,
			'x_' . $id . '_'
		);
	}

	/**
	 * The problem of an identifier declared twice.
	 *
	 * @param string $what       What it identifies.
	 * @param string $identifier The identifier.
	 */
	private static function twice( string $what, string $identifier ): string {
		return sprintf(
			/* translators: 1: what the identifier names, such as "action"; 2: the identifier. */
			__( 'its %1$s "%2$s" is declared twice.', 'bulk-catalogue-manager-for-woocommerce' ),
			$what,
			$identifier
		);
	}

	/**
	 * The problem of something without a name.
	 *
	 * @param string $identifier Its identifier.
	 */
	private static function unnamed( string $identifier ): string {
		return sprintf(
			/* translators: %s: an identifier, such as "x_example_add". */
			__( '"%s" has no name or no description.', 'bulk-catalogue-manager-for-woocommerce' ),
			$identifier
		);
	}

	/**
	 * The problem of a list above its limit.
	 *
	 * @param string $what  What is listed.
	 * @param int    $limit The limit.
	 */
	private static function limit( string $what, int $limit ): string {
		return sprintf(
			/* translators: 1: what is listed, such as "actions"; 2: the most allowed, such as 20. */
			__( 'it declares more %1$s than the %2$d allowed.', 'bulk-catalogue-manager-for-woocommerce' ),
			$what,
			$limit
		);
	}
}
