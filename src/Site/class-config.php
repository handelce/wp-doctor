<?php
/**
 * Values read statically from wp-config.php.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Site;

use function WP_Doctor\esc_cli;

/**
 * Literal constants and the table prefix, plus a record of everything that
 * could not be read, so a check can skip with a precise reason.
 *
 * @since 0.1.0
 */
final class Config {

	/**
	 * Name that stands for $table_prefix in skip_reason().
	 *
	 * @since 0.1.0
	 */
	public const TABLE_PREFIX = '$table_prefix';

	/**
	 * Constants defined with a literal value.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string|int|float|bool|null>
	 */
	private $constants;

	/**
	 * Constants that are defined but could not be read, with the reason.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private $unresolved;

	/**
	 * Usable table prefix, when it was read.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private $table_prefix;

	/**
	 * Why the table prefix was assigned but is not usable. Null when it was read or never assigned.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private $table_prefix_problem;

	/**
	 * Number of define() calls whose constant name is not a literal.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private $computed_name_defines;

	/**
	 * Number of eval() calls, and of include or require statements other than
	 * wp-settings.php: code the parser does not read, which may define constants.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private $unread_code;

	/**
	 * Constants defined as a path built from __DIR__, dirname( __FILE__ ) or
	 * ABSPATH and one string literal: name to base and suffix.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, array{base: string, suffix: string}>
	 */
	private $path_constants;

	/**
	 * PHP settings set with a literal ini_set() call, by setting name.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string|int|float|bool|null>
	 */
	private $ini_settings;

	/**
	 * PHP settings passed to ini_set() whose value could not be read, with the reason.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, string>
	 */
	private $unresolved_ini_settings;

	/**
	 * Why wp-config.php could not be read. Null when it was read.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private $unreadable_reason = null;

	/**
	 * Holds what Config_Parser read.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string|int|float|bool|null>          $constants             Literal constant values by name.
	 * @param array<string, string>                              $unresolved             Reason each unreadable constant could not be read.
	 * @param string|null                                        $table_prefix           Usable prefix, or null when it was not read.
	 * @param string|null                                        $table_prefix_problem   Why the assigned prefix is unusable. Null when it was read or never assigned.
	 * @param int                                                $computed_name_defines  Number of define() calls whose name is not a literal.
	 * @param int                                                $unread_code            Number of eval() calls and of includes other than wp-settings.php.
	 * @param array<string, string|int|float|bool|null>          $ini_settings           Settings from literal ini_set() calls.
	 * @param array<string, string>                              $unresolved_ini_settings ini_set() settings that could not be read, with reasons.
	 * @param array<string, array{base: string, suffix: string}> $path_constants Constants defined as a base directory plus a literal suffix.
	 */
	public function __construct(
		array $constants,
		array $unresolved,
		?string $table_prefix,
		?string $table_prefix_problem,
		int $computed_name_defines,
		int $unread_code,
		array $ini_settings = array(),
		array $unresolved_ini_settings = array(),
		array $path_constants = array()
	) {
		$this->constants               = $constants;
		$this->unresolved              = $unresolved;
		$this->table_prefix            = $table_prefix;
		$this->table_prefix_problem    = $table_prefix_problem;
		$this->computed_name_defines   = $computed_name_defines;
		$this->unread_code             = $unread_code;
		$this->ini_settings            = $ini_settings;
		$this->unresolved_ini_settings = $unresolved_ini_settings;
		$this->path_constants          = $path_constants;
	}

	/**
	 * A config with nothing in it, for a wp-config.php that could not be read.
	 *
	 * @since 0.1.0
	 *
	 * @param string $reason Why the file could not be read.
	 * @return Config
	 */
	public static function unreadable( string $reason ): Config {
		$config                    = new self( array(), array(), null, null, 0, 0 );
		$config->unreadable_reason = $reason;

		return $config;
	}

	/**
	 * Why wp-config.php could not be read, or null when it was read.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null
	 */
	public function unreadable_reason(): ?string {
		return $this->unreadable_reason;
	}

	/**
	 * A constant defined as __DIR__, dirname( __FILE__ ) or ABSPATH joined with one literal.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Constant name.
	 * @return array{base: string, suffix: string}|null The base (`__DIR__` or `ABSPATH`) and the literal. Null when not defined that way.
	 */
	public function path_constant( string $name ): ?array {
		return $this->path_constants[ $name ] ?? null;
	}

	/**
	 * Whether the constant is defined in any form this parser reads, or is defined but unreadable.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Constant name.
	 * @return bool
	 */
	public function is_defined( string $name ): bool {
		return array_key_exists( $name, $this->constants ) || array_key_exists( $name, $this->unresolved ) || array_key_exists( $name, $this->path_constants );
	}

	/**
	 * Whether a PHP setting was set with a literal ini_set() call.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Setting name, such as display_errors.
	 * @return bool
	 */
	public function has_ini_setting( string $name ): bool {
		return array_key_exists( $name, $this->ini_settings );
	}

	/**
	 * Value of a PHP setting set with ini_set(). Call has_ini_setting() first.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Setting name.
	 * @return string|int|float|bool|null
	 * @throws \LogicException When the setting was not read.
	 */
	public function ini_setting( string $name ) {
		if ( ! array_key_exists( $name, $this->ini_settings ) ) {
			throw new \LogicException( esc_cli( "ini_set() value for $name was not read" ) );
		}

		return $this->ini_settings[ $name ];
	}

	/**
	 * Settings passed to ini_set() whose value could not be read, with the reason.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	public function unresolved_ini_settings(): array {
		return $this->unresolved_ini_settings;
	}

	/**
	 * Whether the constant was defined with a literal value.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Constant name.
	 * @return bool
	 */
	public function has( string $name ): bool {
		return array_key_exists( $name, $this->constants );
	}

	/**
	 * Value of a constant defined with a literal.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Constant name.
	 * @return string|int|float|bool|null
	 * @throws \OutOfBoundsException When the constant was not read. Call skip_reason() first.
	 */
	public function value( string $name ) {
		if ( ! $this->has( $name ) ) {
			throw new \OutOfBoundsException( esc_cli( "$name was not read from wp-config.php" ) );
		}

		return $this->constants[ $name ];
	}

	/**
	 * Every constant read as a literal.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string|int|float|bool|null>
	 */
	public function constants(): array {
		return $this->constants;
	}

	/**
	 * Constants that are defined but not readable, with the reason.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, string>
	 */
	public function unresolved(): array {
		return $this->unresolved;
	}

	/**
	 * A copy that uses the given table prefix instead of the one read from wp-config.php.
	 *
	 * @since 0.1.0
	 *
	 * @param string $prefix Letters, digits and underscore only.
	 * @return Config
	 * @throws \InvalidArgumentException When the prefix is empty or has other characters.
	 */
	public function with_table_prefix( string $prefix ): Config {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+\z/', $prefix ) ) {
			throw new \InvalidArgumentException( 'A table prefix may contain only letters, digits and underscore, and cannot be empty' );
		}

		$copy                       = clone $this;
		$copy->table_prefix         = $prefix;
		$copy->table_prefix_problem = null;

		return $copy;
	}

	/**
	 * The table prefix, restricted to letters, digits and underscore so it is safe in an identifier.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 * @throws \OutOfBoundsException When the prefix was not read. Call skip_reason() first.
	 */
	public function table_prefix(): string {
		if ( null === $this->table_prefix ) {
			throw new \OutOfBoundsException( '$table_prefix was not read from wp-config.php' );
		}

		return $this->table_prefix;
	}

	/**
	 * Number of define() calls whose constant name is not a literal.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function computed_name_defines(): int {
		return $this->computed_name_defines;
	}

	/**
	 * Number of eval() calls, and of include or require statements other than
	 * wp-settings.php: code the parser does not read, which may define constants.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function unread_code(): int {
		return $this->unread_code;
	}

	/**
	 * Why a check that needs these values cannot run.
	 *
	 * @since 0.1.0
	 *
	 * @param string ...$needs Constant names, and TABLE_PREFIX for the table prefix.
	 * @return string|null The skip reason, or null when every value was read.
	 */
	public function skip_reason( string ...$needs ): ?string {
		$missing = array();
		foreach ( $needs as $name ) {
			$problem = $this->problem_with( $name );
			if ( null !== $problem ) {
				$missing[] = "$name ($problem)";
			}
		}

		if ( array() === $missing ) {
			return null;
		}

		return 'wp-config.php values unavailable: ' . implode( ', ', $missing );
	}

	/**
	 * Why one needed value is not available.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Constant name or TABLE_PREFIX.
	 * @return string|null Null when the value was read.
	 */
	private function problem_with( string $name ): ?string {
		if ( self::TABLE_PREFIX === $name ) {
			if ( null !== $this->table_prefix ) {
				return null;
			}
			return $this->table_prefix_problem ?? $this->not_defined();
		}

		if ( $this->has( $name ) ) {
			return null;
		}

		return $this->unresolved[ $name ] ?? $this->not_defined();
	}

	/**
	 * The "not defined" explanation, with anything in wp-config.php that might hide the value.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	private function not_defined(): string {
		$notes = array();
		if ( $this->computed_name_defines > 0 ) {
			$notes[] = sprintf(
				'wp-config.php has %d define() %s with %s',
				$this->computed_name_defines,
				1 === $this->computed_name_defines ? 'call' : 'calls',
				1 === $this->computed_name_defines ? 'a computed name' : 'computed names'
			);
		}
		if ( $this->unread_code > 0 ) {
			$notes[] = sprintf( '%d %s not read', $this->unread_code, 1 === $this->unread_code ? 'include' : 'includes' );
		}

		return implode( '; ', array_merge( array( 'not defined' ), $notes ) );
	}
}
