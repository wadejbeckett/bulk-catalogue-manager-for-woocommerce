<?php
/**
 * Native mysqli metadata doubled only at the pure PHP test boundary.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\Data;

use BulkCatalogueManager\Tests\Support\TransactionConnection;

if ( ! function_exists( __NAMESPACE__ . '\\mysqli_errno' ) ) {
	function mysqli_errno( TransactionConnection $connection ): int {
		if ( $connection->closed ) {
			throw new \Error( 'Closed connection' );
		}
		return $connection->errno;
	}
}
if ( ! function_exists( __NAMESPACE__ . '\\mysqli_thread_id' ) ) {
	function mysqli_thread_id( TransactionConnection $connection ): int {
		if ( $connection->closed ) {
			throw new \Error( 'Closed connection' );
		}
		return $connection->thread_id;
	}
}
