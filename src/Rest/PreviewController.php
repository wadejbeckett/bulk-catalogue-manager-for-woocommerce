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
 * each object is checked for the current user as the writer would. An
 * operation list the registry refuses is 400 with the registry's code.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Rest;

use BulkCatalogueManager\Engine\Preview;
use BulkCatalogueManager\Selection\Selection;
use BulkCatalogueManager\Selection\SelectionToken;

defined( 'ABSPATH' ) || exit;

/**
 * The preview route.
 */
final class PreviewController implements Controller {
	/**
	 * The route, under Access::REST_NAMESPACE.
	 */
	public const ROUTE = '/preview';

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
			]
		);
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

		return array_intersect_key( BatchesController::args(), array_flip( [ 'operations', 'ids', 'token' ] ) ) + [ 'sample_size' => $schema ];
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
		if ( null !== $token ) {
			$selection = SelectionToken::parse( $token, get_current_user_id() );
		} else {
			try {
				$selection = Selection::of_ids( (array) $ids );
			} catch ( \InvalidArgumentException $e ) {
				$selection = Refusal::invalid(
					'bulkcat_invalid_value',
					__( 'These products could not be selected. Choose the products again and try again.', 'bulk-catalogue-manager-for-woocommerce' ),
					[ 'details' => $e->getMessage() ]
				);
			}
		}
		if ( $selection instanceof \WP_Error ) {
			return $selection;
		}

		$this->preview ??= new Preview();
		$result          = $this->preview->run(
			$selection,
			$request->get_param( 'operations' ),
			(int) ( $request->get_param( 'sample_size' ) ?? Preview::DEFAULT_SAMPLE ),
			null === $token ? '' : (string) $token
		);
		if ( $result instanceof \WP_Error ) {
			return $result;
		}
		// Maps keyed by reason or kind stay JSON objects when empty.
		$result['totals']['would_fail'] = (object) $result['totals']['would_fail'];
		return new \WP_REST_Response( $result, 200 );
	}
}
