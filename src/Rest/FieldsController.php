<?php
/**
 * `GET /bcm/v1/fields` (SPEC §5.1, §12.1): the field registry as the client
 * reads it, so the client hard-codes no field list. Each item carries the
 * key, label, type, edit kinds, variation rule and notes, choices, REST
 * schema fragment and DataViews definition, and no storage key.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Rest;

use BulkCatalogueManager\Fields\Registry;

defined( 'ABSPATH' ) || exit;

/**
 * The fields route.
 */
final class FieldsController implements ReadController {
	/**
	 * The route, under Access::REST_NAMESPACE.
	 */
	public const ROUTE = '/fields';

	/**
	 * Build over a registry.
	 *
	 * @param Registry $registry Field registry.
	 */
	public function __construct( private readonly Registry $registry ) {}

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
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ Access::class, 'can_read' ],
					'args'                => [],
				],
				'schema' => [ $this, 'get_item_schema' ],
			]
		);
	}

	/**
	 * Every field's client definition, in registry order.
	 *
	 * @param mixed $request The REST request; unused.
	 */
	public function get_items( mixed $request = null ): \WP_REST_Response {
		unset( $request );
		return new \WP_REST_Response( $this->registry->to_client(), 200 );
	}

	/**
	 * The schema of one item of the list.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		$string_list = [
			'type'  => 'array',
			'items' => [ 'type' => 'string' ],
		];
		return [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'bcm-field',
			'type'       => 'object',
			'properties' => [
				'key'                  => [ 'type' => 'string' ],
				'label'                => [ 'type' => 'string' ],
				'type'                 => [ 'type' => 'string' ],
				'readOnly'             => [ 'type' => 'boolean' ],
				'nullable'             => [ 'type' => 'boolean' ],
				'inline'               => [ 'type' => 'boolean' ],
				'editKinds'            => $string_list,
				'variations'           => [ 'type' => 'string' ],
				'variationNote'        => [ 'type' => 'string' ],
				'variableParentNote'   => [ 'type' => 'string' ],
				'sortable'             => [ 'type' => 'boolean' ],
				'schema'               => [ 'type' => 'object' ],
				'dataviews'            => [ 'type' => 'object' ],
				'choices'              => [ 'type' => 'array' ],
				'writableChoices'      => $string_list,
				'variationChoices'     => $string_list,
				'variationOnlyChoices' => $string_list,
				'source'               => [ 'type' => 'string' ],
				'maxLength'            => [ 'type' => 'integer' ],
			],
		];
	}
}
