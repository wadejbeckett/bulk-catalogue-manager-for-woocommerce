<?php
/**
 * `POST /bulkcat/v1/preview` (SPEC §4.4, §9.1, §12.1): what a change would do,
 * computed by Engine\Preview without writing or saving anything. The body
 * carries the operation list (`operations`, `[ { action, args } ]`), the
 * selection — an `ids` list or the signed `token` from `POST /selection`,
 * exactly one of them, taken with the same arguments as `POST /batches` —
 * and `sample_size`: 25 (the default), 100, or `all` for every row up to
 * 1,000. "Also apply to variations" (T69) is a step argument inside
 * `operations` (`args.apply_to_variations`), so it needs no route argument
 * of its own; the answer counts the variations it adds (`totals.by_type`,
 * `summary`, `selection.variations_added`).
 *
 * It precedes a write, so it needs the write capability (Access::can_write);
 * each object is checked for the current user as the writer would, and an
 * object the user may not edit is shown only as skipped with its reason. An
 * operation list the registry refuses is 400 with the registry's code.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Rest;

use BulkCatalogueManager\Engine\Preview;
use BulkCatalogueManager\Requests\BatchIntent;
use BulkCatalogueManager\Requests\RequestService;
use BulkCatalogueManager\Selection\Selection;
use BulkCatalogueManager\Selection\SelectionToken;

defined( 'ABSPATH' ) || exit;

/**
 * The preview route.
 *
 * Every preview is exact (T83): an `ids` list (sorted, each once) or a
 * `v2.` snapshot token from the durable selection routes is resolved to its
 * exact typed members and expanded once (Engine\ExactScope), members that
 * are missing or changed in kind stay in the scope as unavailable, and the
 * answer carries the `scope_hash` and normalised `operations` a durable
 * Apply's intent needs, with a `hash` bound to the scope and to the
 * credential as sent (a snapshot's current authorization epoch). A 1.0
 * selection token (the transient kind) is refused with the reselect
 * refusal; it never turns back into a filter.
 */
final class PreviewController implements ReadController {
	/**
	 * The route, under Access::REST_NAMESPACE.
	 */
	public const ROUTE = '/preview';

	/**
	 * The response schema's title.
	 */
	public const TITLE = 'bulkcat-preview';

	/**
	 * The `sample_size` value asking for every row up to Preview::SAMPLE_ALL.
	 */
	public const ALL = 'all';

	/**
	 * The preview.
	 *
	 * @var Preview|null
	 */
	private ?Preview $preview;

	/**
	 * Build a controller.
	 *
	 * @param Preview|null $preview The preview; built when first needed by default.
	 */
	public function __construct( ?Preview $preview = null ) {
		$this->preview = $preview;
	}

	/**
	 * Add the route through the registrar.
	 *
	 * @param Routes $routes The registrar.
	 */
	public function register_routes( Routes $routes ): void {
		$routes->add(
			self::ROUTE,
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ Access::class, 'can_write' ],
					'args'                => self::args(),
				],
				'schema' => [ $this, 'get_item_schema' ],
			]
		);
	}

	/**
	 * The preview's answer. The first seven keys are the 1.0 shape and keep
	 * their meaning; `unavailable_count`, `scope_hash` and `operations` were
	 * added for exact selections (T83) and follow them, so a client reading
	 * the old keys is unaffected.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		$object  = [ 'type' => 'object' ];
		$objects = [
			'type'  => 'array',
			'items' => $object,
		];
		return [
			'$schema'    => RouteSchema::DIALECT,
			'title'      => self::TITLE,
			'type'       => 'object',
			'properties' => [
				'totals'            => $object,
				'summary'           => [ 'type' => 'string' ],
				'sample'            => $objects,
				'warnings'          => $object,
				'unreadable'        => [
					'description' => __( 'Chosen products that cannot be changed now, by id; at most 1,000 are listed.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'array',
					'items'       => [ 'type' => 'integer' ],
				],
				'selection'         => $object,
				'hash'              => [
					'description' => __( 'The preview\'s hash, which applying the change must send back.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'string',
				],
				'unavailable_count' => [
					'description' => __( 'How many chosen products cannot be changed now, including any the list leaves out.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => 'integer',
				],
				'scope_hash'        => [
					'description' => __( 'The signature of the exact products and variations this change covers; null for a preview that is not exact.', 'bulk-catalogue-manager-for-woocommerce' ),
					'type'        => [ 'string', 'null' ],
				],
				'operations'        => [
					'description' => __( 'The change as it will be recorded, with every default written out.', 'bulk-catalogue-manager-for-woocommerce' ),
				] + $objects,
			],
		];
	}

	/**
	 * The route's arguments: the batch route's `operations`, `ids` and
	 * `token`, and the sample size.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function args(): array {
		$sizes  = [ ...Preview::SAMPLE_SIZES, self::ALL ];
		$schema = [
			'description' => __( 'How many rows of the preview to return: 25, 100, or all for every row up to 1,000.', 'bulk-catalogue-manager-for-woocommerce' ),
			'type'        => [ 'integer', 'string' ],
			'enum'        => $sizes,
			'default'     => Preview::DEFAULT_SAMPLE,
		];

		$schema['validate_callback'] = static function ( mixed $value, mixed $request = null, string $param = '' ) use ( $schema ): bool|\WP_Error {
			unset( $request );
			return rest_validate_value_from_schema( $value, $schema, $param );
		};
		$schema['sanitize_callback'] = static fn( mixed $value ): int => self::ALL === $value ? Preview::SAMPLE_ALL : (int) $value;

		$args                           = array_intersect_key( BatchesController::args(), array_flip( [ 'operations', 'ids', 'token' ] ) );
		$args['operations']['required'] = true;
		return $args + [ 'sample_size' => $schema ];
	}

	/**
	 * The preview of the posted change.
	 *
	 * @param \WP_REST_Request $request The request, its arguments validated and sanitised by core.
	 */
	public function create_item( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$ids   = $request->get_param( 'ids' );
		$token = $request->get_param( 'token' );
		if ( ( null === $ids ) === ( null === $token ) ) {
			return Refusal::invalid(
				BatchesController::SELECTION_REQUIRED,
				__( 'This preview does not say which products to change. Choose the products again and try again.', 'bulk-catalogue-manager-for-woocommerce' )
			);
		}
		$size = (int) ( $request->get_param( 'sample_size' ) ?? Preview::DEFAULT_SAMPLE );
		if ( null !== $token ) {
			if ( ! is_string( $token ) || ! str_starts_with( $token, 'v2.' ) ) {
				// A 1.0 transient token: choose the products again (T83).
				return SelectionToken::refusal();
			}
			$result = ( new RequestService() )->preview(
				[
					'token'       => $token,
					'operations'  => $request->get_param( 'operations' ),
					'sample_size' => $size,
				]
			);
			return $result instanceof \WP_Error ? $result : self::respond( $result );
		}
		try {
			$selection = Selection::of_ids( BatchIntent::ids( (array) $ids ) );
		} catch ( \InvalidArgumentException $e ) {
			return Refusal::invalid(
				'bulkcat_invalid_value',
				__( 'These products could not be selected. Choose the products again and try again.', 'bulk-catalogue-manager-for-woocommerce' ),
				[ 'details' => $e->getMessage() ]
			);
		}
		$this->preview ??= new Preview();
		$result          = $this->preview->run( $selection, $request->get_param( 'operations' ), $size, '', null, true );
		return $result instanceof \WP_Error ? $result : self::respond( $result );
	}

	/**
	 * The preview as a response; maps keyed by reason stay JSON objects when empty.
	 *
	 * @param array<string, mixed> $result The preview.
	 */
	private static function respond( array $result ): \WP_REST_Response {
		$result['totals']['would_fail'] = (object) $result['totals']['would_fail'];
		return new \WP_REST_Response( $result, 200 );
	}
}
