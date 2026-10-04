<?php
/**
 * Internal computed values over an already authorized native subject.
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */
declare( strict_types = 1 );
namespace BulkCatalogueManager\External\V2;

use BulkCatalogueManager\Extension\V2\{Subject, TargetRef};
defined( 'ABSPATH' ) || exit;
/** Private-to-core overlay; no provider mutation API or storage writes. */
final class OverlaySubject implements Subject {
	/**
	 * Freeze the current values visible to one evaluated step.
	 * @param Subject $subject Native identity.
	 * @param array $values Authorized field values.
	 */
	public function __construct( private readonly Subject $subject, private readonly array $values ) {}
	/** Native tuple. */
	public function reference(): TargetRef {
		return $this->subject->reference();}
	/** Native parent. */
	public function parent(): ?TargetRef {
		return $this->subject->parent();}
	/**
	 * Current overlay value, never an implicit field read.
	 * @param string $field Registered field key.
	 */
	public function value( string $field ): mixed {
		Rules::ensure( array_key_exists( $field, $this->values ), 'Unloaded field.' );
		return $this->values[ $field ];
	}
}
