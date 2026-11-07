<?php
/**
 * Reads PHP-serialized arrays and scalars without unserialize().
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Util;

use function WP_Doctor\esc_cli;

/**
 * A cursor over data written by serialize(). It accepts arrays, integers,
 * floats, booleans, null and strings, and refuses anything else, so reading
 * site data can never run code. Objects and back-references are refused unless
 * the reader is built to accept objects; then an object is read as a list of
 * property names and values and is never instantiated, and a back-reference
 * (r:N; or R:N;, which serialize() writes for a repeated object or reference)
 * is accepted by skip_value() inside an array or object and never resolved.
 * open_array(), open_plain_array() and read_key() never accept one. Values are
 * walked or skipped, not built, so memory stays small.
 *
 * @since 0.1.0
 */
final class Serialized_Reader {

	private const MAX_DEPTH = 32;

	private const BACK_REFERENCE = '/\G[rR]:[1-9]\d{0,18};/';

	private const SCALAR = '/\G(?:N;|b:[01];|i:[+-]?\d{1,19};|d:(?:[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?|-?INF|NAN);)/';

	/**
	 * Input being read, typically an option value from the site database.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $data;

	/**
	 * Length of the data in bytes.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private $length;

	/**
	 * Offset of the next unread byte.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private $position = 0;

	/**
	 * Arrays and objects currently open.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private $depth = 0;

	/**
	 * Whether objects are read as property lists.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private $allow_objects;

	/**
	 * Wraps serialized data.
	 *
	 * @since 0.1.0
	 *
	 * @param string $data          Output of serialize().
	 * @param bool   $allow_objects Read objects as property lists instead of refusing them.
	 */
	public function __construct( string $data, bool $allow_objects = false ) {
		$this->data          = $data;
		$this->length        = strlen( $data );
		$this->allow_objects = $allow_objects;
	}

	/**
	 * Consumes the start of an array, or of an object when objects are allowed.
	 *
	 * @since 0.1.0
	 *
	 * @return int Number of entries (array items or object properties) it declares.
	 * @throws Malformed_Serialization_Exception When an array does not start here or nesting is too deep.
	 */
	public function open_array(): int {
		return $this->open_container( $this->allow_objects );
	}

	/**
	 * Consumes the start of an array, refusing an object even when objects are allowed.
	 *
	 * @since 0.1.0
	 *
	 * @return int Number of entries it declares.
	 * @throws Malformed_Serialization_Exception When an array does not start here or nesting is too deep.
	 */
	public function open_plain_array(): int {
		return $this->open_container( false );
	}

	/**
	 * Consumes the end of the current array.
	 *
	 * @since 0.1.0
	 *
	 * @throws Malformed_Serialization_Exception When the array does not end here.
	 */
	public function close_array(): void {
		if ( $this->depth < 1 || $this->position >= $this->length || '}' !== $this->data[ $this->position ] ) {
			throw $this->malformed( 'expected the end of an array' );
		}
		++$this->position;
		--$this->depth;
	}

	/**
	 * Consumes an array key.
	 *
	 * @since 0.1.0
	 *
	 * @return int|string The key as PHP stored it: integer or string.
	 * @throws Malformed_Serialization_Exception When the next value is not an integer or a string.
	 */
	public function read_key() {
		if ( 1 === preg_match( '/\Gi:([+-]?\d{1,19});/', $this->data, $match, 0, $this->position ) ) {
			$this->position += strlen( $match[0] );

			return (int) $match[1];
		}

		$string = $this->read_string();
		if ( null === $string ) {
			throw $this->malformed( 'an array key must be an integer or a string' );
		}

		return $string;
	}

	/**
	 * Consumes one value of any accepted type without keeping it. When objects
	 * are allowed, a back-reference inside an array or object is consumed too.
	 *
	 * @since 0.1.0
	 *
	 * @throws Malformed_Serialization_Exception When the value is not an array or scalar.
	 */
	public function skip_value(): void {
		if ( 1 === preg_match( self::SCALAR, $this->data, $match, 0, $this->position ) ) {
			$this->position += strlen( $match[0] );

			return;
		}
		if ( null !== $this->read_string() ) {
			return;
		}
		if ( $this->allow_objects && $this->depth > 0 && 1 === preg_match( self::BACK_REFERENCE, $this->data, $match, 0, $this->position ) ) {
			$this->position += strlen( $match[0] );

			return;
		}

		$entries = $this->open_array();
		for ( $entry = 0; $entry < $entries; $entry++ ) {
			$this->read_key();
			$this->skip_value();
		}
		$this->close_array();
	}

	/**
	 * Requires that every byte has been consumed.
	 *
	 * @since 0.1.0
	 *
	 * @throws Malformed_Serialization_Exception When data remains.
	 */
	public function assert_end(): void {
		if ( $this->position !== $this->length ) {
			throw $this->malformed( 'unexpected data after the value' );
		}
	}

	/**
	 * Consumes a string value, or skips any other value.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null The string, or null when the value was not a string.
	 * @throws Malformed_Serialization_Exception When the value is malformed.
	 */
	public function read_text_or_skip(): ?string {
		$text = $this->read_string();
		if ( null !== $text ) {
			return $text;
		}
		$this->skip_value();

		return null;
	}

	/**
	 * Consumes the start of an array, or of an object when $objects is true.
	 *
	 * @since 0.1.0
	 *
	 * @param bool $objects Whether an object may start here.
	 * @return int Number of entries it declares.
	 * @throws Malformed_Serialization_Exception When neither starts here or nesting is too deep.
	 */
	private function open_container( bool $objects ): int {
		if ( $this->depth >= self::MAX_DEPTH ) {
			throw $this->malformed( 'arrays are nested too deeply' );
		}
		if ( 1 === preg_match( '/\Ga:(\d{1,9}):\{/', $this->data, $match, 0, $this->position ) ) {
			$this->position += strlen( $match[0] );
			++$this->depth;

			return (int) $match[1];
		}
		if ( $objects && 1 === preg_match( '/\GO:(\d{1,9}):"/', $this->data, $match, 0, $this->position ) ) {
			return $this->open_object( strlen( $match[0] ), (int) $match[1] );
		}

		throw $this->malformed( 'expected an array' );
	}

	/**
	 * Consumes the rest of an object header after its class name length.
	 *
	 * @since 0.1.0
	 *
	 * @param int $header_length Bytes already matched, up to the opening quote of the class name.
	 * @param int $name_length   Declared length of the class name.
	 * @return int Number of properties.
	 * @throws Malformed_Serialization_Exception When the header is malformed.
	 */
	private function open_object( int $header_length, int $name_length ): int {
		$name_start = $this->position + $header_length;
		$name       = substr( $this->data, $name_start, $name_length );
		if ( strlen( $name ) !== $name_length || 1 !== preg_match( '/\A[A-Za-z_\x80-\xff\\\\][A-Za-z0-9_\x80-\xff\\\\]*\z/', $name ) ) {
			throw $this->malformed( 'an object has an unreadable class name' );
		}
		if ( 1 !== preg_match( '/\G":(\d{1,9}):\{/', $this->data, $match, 0, $name_start + $name_length ) ) {
			throw $this->malformed( 'an object header is malformed' );
		}
		$this->position = $name_start + $name_length + strlen( $match[0] );
		++$this->depth;

		return (int) $match[1];
	}

	/**
	 * Consumes a string value if one starts here.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null Null when the next value is not a string.
	 * @throws Malformed_Serialization_Exception When a string starts here but is malformed.
	 */
	private function read_string(): ?string {
		if ( 1 !== preg_match( '/\Gs:(\d{1,9}):"/', $this->data, $match, 0, $this->position ) ) {
			return null;
		}
		$start = $this->position + strlen( $match[0] );
		$size  = (int) $match[1];
		if ( '";' !== substr( $this->data, $start + $size, 2 ) ) {
			throw $this->malformed( 'a string does not match its declared length' );
		}
		$this->position = $start + $size + 2;

		return substr( $this->data, $start, $size );
	}

	/**
	 * Byte offset of the next unread character.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function position(): int {
		return $this->position;
	}

	/**
	 * An exception naming the problem and the current offset.
	 *
	 * @since 0.1.0
	 *
	 * @param string $problem What was wrong, worded to follow "Unreadable serialized data:".
	 * @return Malformed_Serialization_Exception
	 */
	private function malformed( string $problem ): Malformed_Serialization_Exception {
		return new Malformed_Serialization_Exception( esc_cli( sprintf( 'Unreadable serialized data: %s at byte %d', $problem, $this->position ) ), $this->position );
	}
}
