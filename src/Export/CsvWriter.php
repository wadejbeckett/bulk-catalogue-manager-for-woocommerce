<?php
/**
 * The plugin's one CSV writer (SPEC §10, §11, §13). The catalogue export,
 * the preview sample download, a batch's changes and the audit list all
 * write through it, so there is no second CSV implementation.
 *
 * Output is UTF-8 with a byte-order mark, so a spreadsheet reads accents
 * correctly; every field is quoted, a quote doubled inside it; lines end
 * with CRLF (RFC 4180), and a line break inside a value stays inside its
 * quotes. The separator is a comma or a semicolon, from the CSV separator
 * setting. A value that begins, after any leading spaces, with `=`, `+`,
 * `-`, `@`, a tab or a carriage return gets an apostrophe in front, so a
 * spreadsheet shows it as text rather than running it as a formula.
 *
 * Lines go straight to a stream as they are written, so a caller writing
 * page by page keeps memory flat.
 *
 * @package BulkCatalogueManager
 * @license GPL-3.0-or-later
 */

declare( strict_types = 1 );

namespace BulkCatalogueManager\Export;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- The writer streams to php://output or a caller's stream; WP_Filesystem writes whole files and cannot stream a response.

/**
 * CSV writer.
 */
final class CsvWriter {
	/**
	 * The UTF-8 byte-order mark, written once at the start.
	 */
	public const BOM = "\xEF\xBB\xBF";

	/**
	 * Line ending.
	 */
	public const EOL = "\r\n";

	/**
	 * The separators the CSV separator setting offers.
	 */
	public const SEPARATORS = [ ',', ';' ];

	/**
	 * Characters that make a spreadsheet read a cell as a formula when they start it.
	 */
	public const FORMULA_STARTS = [ '=', '+', '-', '@', "\t", "\r" ];

	/**
	 * Written in front of a value that would start a formula.
	 */
	public const GUARD = "'";

	/**
	 * The stream written to.
	 *
	 * @var resource
	 */
	private $stream;

	/**
	 * Lines written, header included.
	 *
	 * @var int
	 */
	private int $lines = 0;

	/**
	 * Start a CSV on a stream: writes the byte-order mark.
	 *
	 * @param resource $stream    Writable stream, such as php://output or php://memory.
	 * @param string   $separator "," or ";".
	 * @throws \InvalidArgumentException When the stream is not a resource or the separator is not offered.
	 */
	public function __construct( $stream, private readonly string $separator = ',' ) {
		if ( ! is_resource( $stream ) ) {
			throw new \InvalidArgumentException( 'The CSV writer needs a writable stream.' );
		}
		if ( ! in_array( $separator, self::SEPARATORS, true ) ) {
			throw new \InvalidArgumentException( 'The CSV separator must be a comma or a semicolon.' );
		}
		$this->stream = $stream;
		$this->put( self::BOM );
	}

	/**
	 * A writer on the response body.
	 *
	 * @param string $separator "," or ";".
	 * @throws \RuntimeException When the output stream cannot be opened.
	 */
	public static function to_output( string $separator = ',' ): self {
		$stream = fopen( 'php://output', 'wb' );
		if ( false === $stream ) {
			throw new \RuntimeException( 'The output stream could not be opened.' );
		}
		return new self( $stream, $separator );
	}

	/**
	 * Write one line of cells, in order.
	 *
	 * @param array<int|string, mixed> $cells Cells; keys are ignored.
	 */
	public function row( array $cells ): void {
		$this->put( self::line( $cells, $this->separator ) );
		++$this->lines;
	}

	/**
	 * Write one line whose first cell, when it is plain ASCII letters, is
	 * written without quotes; every other cell as row() writes it. For the
	 * first line of a file meant for WooCommerce's product importer: PHP's
	 * CSV reader, which the importer uses, treats a field as quoted only when
	 * a quote is its first byte, so after the byte-order mark a quoted first
	 * heading would be read with its quotes and not be mapped (ticket 10).
	 * A cell of letters only needs no quoting under RFC 4180 and cannot
	 * start a formula.
	 *
	 * @param array<int|string, mixed> $cells Cells; keys are ignored.
	 */
	public function row_bare_first( array $cells ): void {
		$cells = array_values( $cells );
		$first = self::text( $cells[0] ?? null );
		if ( 1 !== preg_match( '/^[A-Za-z]+$/D', $first ) ) {
			$this->row( $cells );
			return;
		}
		$rest = array_slice( $cells, 1 );
		$this->put( $first . ( [] === $rest ? self::EOL : $this->separator . self::line( $rest, $this->separator ) ) );
		++$this->lines;
	}

	/**
	 * Write many lines.
	 *
	 * @param iterable<array<int|string, mixed>> $rows Rows of cells.
	 */
	public function rows( iterable $rows ): void {
		foreach ( $rows as $cells ) {
			$this->row( $cells );
		}
	}

	/**
	 * Push what has been written on to the reader.
	 */
	public function flush(): void {
		fflush( $this->stream );
	}

	/**
	 * Lines written so far, header included.
	 */
	public function lines(): int {
		return $this->lines;
	}

	/**
	 * The separator in use.
	 */
	public function separator(): string {
		return $this->separator;
	}

	/**
	 * One CSV line: each cell guarded and quoted, joined by the separator, ending CRLF.
	 *
	 * @param array<int|string, mixed> $cells     Cells.
	 * @param string                   $separator "," or ";".
	 */
	public static function line( array $cells, string $separator ): string {
		$quoted = [];
		foreach ( $cells as $cell ) {
			$quoted[] = '"' . str_replace( '"', '""', self::guard( self::text( $cell ) ) ) . '"';
		}
		return implode( $separator, $quoted ) . self::EOL;
	}

	/**
	 * A cell's text. Null and '' are empty, distinct from 0; true and false
	 * are Yes and No; a list is its items joined by a comma and a space;
	 * text is made valid UTF-8.
	 *
	 * @param mixed $value Cell value.
	 */
	public static function text( mixed $value ): string {
		if ( null === $value ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? __( 'Yes', 'bulk-catalogue-manager-for-woocommerce' ) : __( 'No', 'bulk-catalogue-manager-for-woocommerce' );
		}
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( [ self::class, 'text' ], $value ) );
		}
		if ( is_float( $value ) ) {
			return rtrim( rtrim( sprintf( '%.10F', $value ), '0' ), '.' );
		}
		if ( is_scalar( $value ) || $value instanceof \Stringable ) {
			return mb_scrub( (string) $value, 'UTF-8' );
		}
		return '';
	}

	/**
	 * A value with an apostrophe in front when it would start a formula:
	 * its first character after any leading spaces is one of FORMULA_STARTS.
	 *
	 * @param string $value Cell text.
	 */
	public static function guard( string $value ): string {
		$probe = ltrim( $value, ' ' );
		if ( '' !== $probe && in_array( $probe[0], self::FORMULA_STARTS, true ) ) {
			return self::GUARD . $value;
		}
		return $value;
	}

	/**
	 * Write bytes to the stream.
	 *
	 * @param string $bytes Bytes.
	 * @throws \RuntimeException When the stream refuses them.
	 */
	private function put( string $bytes ): void {
		if ( false === fwrite( $this->stream, $bytes ) ) {
			throw new \RuntimeException( 'The CSV could not be written.' );
		}
	}
}
