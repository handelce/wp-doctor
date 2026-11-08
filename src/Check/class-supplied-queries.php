<?php
/**
 * SQL statements the user gave with --queries.
 *
 * @since 0.3.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check;

use function WP_Doctor\esc_cli;

/**
 * One statement per line of the file, keyed by its line number. Blank lines
 * and lines starting with # are left out, and a trailing semicolon is
 * dropped. The statements are not checked here: each still goes through the
 * read-only gate when a check runs it.
 *
 * @since 0.3.0
 */
final class Supplied_Queries {

	public const MAX_BYTES   = 1048576;
	public const MAX_QUERIES = 500;

	/**
	 * Statements by line number, from 1.
	 *
	 * @since 0.3.0
	 *
	 * @var array<int, string>
	 */
	private $queries;

	/**
	 * Holds statements already split into lines.
	 *
	 * @since 0.3.0
	 *
	 * @param array<int, string> $queries Statements by line number.
	 */
	private function __construct( array $queries ) {
		$this->queries = $queries;
	}

	/**
	 * Reads the text of a queries file.
	 *
	 * @since 0.3.0
	 *
	 * @param string $text File content.
	 * @return self
	 * @throws \InvalidArgumentException When the file is too large, holds no statement, or holds too many.
	 */
	public static function from_text( string $text ): self {
		if ( strlen( $text ) > self::MAX_BYTES ) {
			throw new \InvalidArgumentException( esc_cli( 'the file is larger than ' . self::MAX_BYTES . ' bytes' ) );
		}
		$queries = array();
		$lines   = preg_split( '/\r\n|\r|\n/', $text );
		foreach ( false === $lines ? array() : $lines as $index => $line ) {
			$line = rtrim( trim( $line ), ';' );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			$queries[ $index + 1 ] = rtrim( $line );
		}
		if ( array() === $queries ) {
			throw new \InvalidArgumentException( 'it holds no statement; give one SELECT per line' );
		}
		if ( count( $queries ) > self::MAX_QUERIES ) {
			throw new \InvalidArgumentException( esc_cli( 'it holds more than ' . self::MAX_QUERIES . ' statements' ) );
		}

		return new self( $queries );
	}

	/**
	 * Statements by line number.
	 *
	 * @since 0.3.0
	 *
	 * @return array<int, string>
	 */
	public function all(): array {
		return $this->queries;
	}
}
