<?php
/**
 * Severity of a finding.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Report;

use function WP_Doctor\esc_cli;

/**
 * One of critical, warning or info, ordered by rank.
 *
 * @since 0.1.0
 */
final class Severity {

	private const RANKS = array(
		'info'     => 1,
		'warning'  => 2,
		'critical' => 3,
	);

	/**
	 * Lowercase name, a key of RANKS.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Use the named constructors.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name A key of RANKS.
	 */
	private function __construct( string $name ) {
		$this->name = $name;
	}

	/**
	 * Compromise indicators and conditions that break or endanger the site.
	 *
	 * @since 0.1.0
	 *
	 * @return self
	 */
	public static function critical(): self {
		return new self( 'critical' );
	}

	/**
	 * Problems worth fixing soon.
	 *
	 * @since 0.1.0
	 *
	 * @return self
	 */
	public static function warning(): self {
		return new self( 'warning' );
	}

	/**
	 * Observations that need no action.
	 *
	 * @since 0.1.0
	 *
	 * @return self
	 */
	public static function info(): self {
		return new self( 'info' );
	}

	/**
	 * Looks up a severity by the name used in config and JSON output.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name One of critical, warning or info.
	 * @return self
	 * @throws \InvalidArgumentException When the name is not a severity.
	 */
	public static function from_name( string $name ): self {
		if ( ! isset( self::RANKS[ $name ] ) ) {
			throw new \InvalidArgumentException(
				esc_cli( sprintf( 'Unknown severity "%s"; expected one of: %s', $name, implode( ', ', array_keys( self::RANKS ) ) ) )
			);
		}

		return new self( $name );
	}

	/**
	 * Lowercase name.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Sort key: higher is more severe.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function rank(): int {
		return self::RANKS[ $this->name ];
	}

	/**
	 * Whether both severities have the same name.
	 *
	 * @since 0.1.0
	 *
	 * @param self $other Severity to compare with.
	 * @return bool
	 */
	public function equals( self $other ): bool {
		return $this->name === $other->name;
	}
}
