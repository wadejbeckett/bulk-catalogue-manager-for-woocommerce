<?php
/**
 * A row source over a callable with RowSource::page()'s signature, so a
 * controller can adapt an existing read in a line and a test can count calls.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Export;

defined( 'ABSPATH' ) || exit;

/**
 * Callable row source.
 */
final class CallableRowSource implements RowSource {
	/**
	 * The page reader.
	 *
	 * @var callable(array<string, mixed>, int, int): array{items: list<array<string, mixed>>, total: int}
	 */
	private $reader;

	/**
	 * Build over a page reader.
	 *
	 * @param callable $reader Takes ( params, page, per_page ), returns [ 'items' => rows, 'total' => n ].
	 */
	public function __construct( callable $reader ) {
		$this->reader = $reader;
	}

	/**
	 * One page, as the reader returns it, checked for shape.
	 *
	 * @param array<string, mixed> $params   Parameters, without paging.
	 * @param int                  $page     Page, from 1.
	 * @param int                  $per_page Rows per page.
	 * @return array{items: list<array<string, mixed>>, total: int}
	 * @throws \UnexpectedValueException When the reader returns something else.
	 */
	public function page( array $params, int $page, int $per_page ): array {
		$result = ( $this->reader )( $params, $page, $per_page );
		if ( ! is_array( $result ) || ! isset( $result['items'] ) || ! is_array( $result['items'] ) || ! is_int( $result['total'] ?? null ) ) {
			throw new \UnexpectedValueException( 'A row source page must be [ items, total ].' );
		}
		return [
			'items' => array_values( $result['items'] ),
			'total' => $result['total'],
		];
	}
}
