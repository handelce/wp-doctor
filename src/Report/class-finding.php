<?php
/**
 * A problem reported by a check.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Report;

use function WP_Doctor\esc_cli;

/**
 * Immutable. Every field is required, so a finding without evidence or a fix
 * cannot exist.
 *
 * @since 0.1.0
 */
final class Finding {

	/**
	 * Check id, such as perf.autoload.size.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $id;

	/**
	 * How urgent the finding is.
	 *
	 * @since 0.1.0
	 *
	 * @var Severity
	 */
	private $severity;

	/**
	 * One-line plain-language title.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $title;

	/**
	 * Ordered key/value pairs that triggered the finding. A value is a scalar,
	 * a list of scalars, or a list of objects whose values are scalars.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, mixed>
	 */
	private $evidence;

	/**
	 * Recommended fix, possibly a copyable command.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $fix;

	/**
	 * Validates and stores every field.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $id       Id of the check that raised it.
	 * @param Severity             $severity How urgent the finding is.
	 * @param string               $title    One-line plain-language title.
	 * @param array<string, mixed> $evidence Ordered key/value pairs that triggered the finding. Must not be empty. A value is a scalar, a list of scalars, or a list of objects with string keys and scalar values.
	 * @param string               $fix      Recommended fix, possibly a copyable command.
	 * @throws \InvalidArgumentException When a text field is blank or the evidence is empty or malformed.
	 */
	public function __construct( string $id, Severity $severity, string $title, array $evidence, string $fix ) {
		self::require_text( 'id', $id );
		self::require_text( 'title', $title );
		self::require_text( 'fix', $fix );

		if ( array() === $evidence ) {
			throw new \InvalidArgumentException( esc_cli( "Finding $id has no evidence" ) );
		}

		foreach ( $evidence as $key => $value ) {
			if ( ! is_string( $key ) || '' === $key ) {
				throw new \InvalidArgumentException( esc_cli( "Finding $id has an evidence entry without a string key" ) );
			}
			if ( ! self::is_evidence_value( $value ) ) {
				throw new \InvalidArgumentException( esc_cli( "Finding $id evidence \"$key\" is not a scalar, a list of scalars, or a list of objects of scalars" ) );
			}
		}

		$this->id       = $id;
		$this->severity = $severity;
		$this->title    = $title;
		$this->evidence = $evidence;
		$this->fix      = $fix;
	}

	/**
	 * Id of the check that raised the finding.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * How urgent the finding is.
	 *
	 * @since 0.1.0
	 *
	 * @return Severity
	 */
	public function severity(): Severity {
		return $this->severity;
	}

	/**
	 * One-line plain-language title.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function title(): string {
		return $this->title;
	}

	/**
	 * Evidence in the order it was given.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public function evidence(): array {
		return $this->evidence;
	}

	/**
	 * Recommended fix, possibly a copyable command.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function fix(): string {
		return $this->fix;
	}

	/**
	 * Whether a value can be evidence: a scalar, or a list holding only
	 * scalars or only objects whose keys are names and whose values are scalars.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Evidence value.
	 * @return bool
	 */
	public static function is_evidence_value( $value ): bool {
		if ( is_scalar( $value ) ) {
			return true;
		}
		if ( ! is_array( $value ) ) {
			return false;
		}
		if ( array() === $value ) {
			return true;
		}
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			return false;
		}
		$scalars = 0;
		foreach ( $value as $item ) {
			if ( is_scalar( $item ) ) {
				++$scalars;
			} elseif ( ! self::is_evidence_object( $item ) ) {
				return false;
			}
		}

		return 0 === $scalars || count( $value ) === $scalars;
	}

	/**
	 * Whether a list item is an object of named scalar fields.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $item List item.
	 * @return bool
	 */
	private static function is_evidence_object( $item ): bool {
		if ( ! is_array( $item ) || array() === $item ) {
			return false;
		}
		foreach ( $item as $field => $field_value ) {
			if ( ! is_string( $field ) || '' === $field || ! is_scalar( $field_value ) ) {
				return false;
			}
		}

		return true;
	}


	/**
	 * Rejects a blank text field.
	 *
	 * @since 0.1.0
	 *
	 * @param string $field Field name, for the message.
	 * @param string $value Field value.
	 * @throws \InvalidArgumentException When the value is blank.
	 */
	private static function require_text( string $field, string $value ): void {
		if ( '' === trim( $value ) ) {
			throw new \InvalidArgumentException( esc_cli( "Finding $field must not be empty" ) );
		}
	}
}
