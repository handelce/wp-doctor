<?php
/**
 * Limits the checks compare against.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check;

use function WP_Doctor\esc_cli;

/**
 * The info, warning and critical limits for the checks that have them, either the
 * built-in defaults or values from wp-doctor.json. A check asks for its own
 * id with its default, so a missing setting always falls back to the default.
 *
 * @since 0.1.0
 */
final class Thresholds {

	public const INFO     = 'info';
	public const WARNING  = 'warning';
	public const CRITICAL = 'critical';

	private const DEFAULT_BASIS = 'default rule of thumb, not measured for this site';

	/**
	 * Levels each check lets wp-doctor.json set, with the unit of the number
	 * and the built-in limit. Checks read their limits from here, so this is
	 * the only place a default is written.
	 *
	 * @since 0.1.0
	 */
	private const CONFIGURABLE = array(
		'perf.autoload.size'      => array(
			self::WARNING  => array( 'bytes of autoloaded options', 1048576 ),
			self::CRITICAL => array( 'bytes of autoloaded options', 3145728 ),
		),
		'perf.autoload.orphaned'  => array( self::WARNING => array( 'bytes an orphaned option must exceed', 10240 ) ),
		'perf.tables.bloat'       => array( self::WARNING => array( 'bytes a log or session table must exceed', 104857600 ) ),
		'perf.transients.expired' => array( self::WARNING => array( 'expired transient rows', 5000 ) ),
		'perf.cron.duplicates'    => array( self::CRITICAL => array( 'copies of one event', 50 ) ),
		'perf.cron.overdue'       => array( self::WARNING => array( 'seconds an event is late', 3600 ) ),
		'disk.debuglog.size'      => array(
			self::WARNING  => array( 'bytes', 104857600 ),
			self::CRITICAL => array( 'bytes', 1073741824 ),
		),
		'disk.errorlog.size'      => array( self::WARNING => array( 'bytes', 52428800 ) ),
		'disk.cache.size'         => array( self::WARNING => array( 'bytes in one cache folder', 1073741824 ) ),
		'perf.revisions.count'    => array( self::WARNING => array( 'revision rows', 10000 ) ),
		'sec.users.admins'        => array( self::CRITICAL => array( 'administrators registered within 24 hours', 3 ) ),
		'sec.recent.changes'      => array( self::INFO => array( 'days a changed PHP file counts as recent', 7 ) ),
	);

	/**
	 * Configured limits by check id and level.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, array<string, int>>
	 */
	private $levels;

	/**
	 * Config file the limits came from. Null for the defaults.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private $source;

	/**
	 * Callers use defaults() or from_config().
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array<string, int>> $levels Limits by check id and level.
	 * @param string|null                       $source Config file name, or null for the defaults.
	 */
	private function __construct( array $levels, ?string $source ) {
		$this->levels = $levels;
		$this->source = $source;
	}

	/**
	 * The built-in limits only.
	 *
	 * @since 0.1.0
	 *
	 * @return self
	 */
	public static function defaults(): self {
		return new self( array(), null );
	}

	/**
	 * Limits from a config file.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, array<string, int>> $levels Limits by check id and level, as Tool_Config validated them.
	 * @param string                            $source Config file name, for the evidence.
	 * @return self
	 * @throws \InvalidArgumentException When a check or level cannot be configured.
	 */
	public static function from_config( array $levels, string $source ): self {
		foreach ( $levels as $id => $by_level ) {
			$problem = self::unsupported( (string) $id, array_keys( $by_level ) );
			if ( null !== $problem ) {
				throw new \InvalidArgumentException( esc_cli( $problem ) );
			}
		}
		$thresholds = new self( $levels, $source );
		foreach ( array_keys( $levels ) as $id ) {
			$id = (string) $id;
			if ( ! isset( self::CONFIGURABLE[ $id ][ self::WARNING ], self::CONFIGURABLE[ $id ][ self::CRITICAL ] ) ) {
				continue;
			}
			$warning  = $thresholds->warning( $id );
			$critical = $thresholds->critical( $id );
			if ( $warning >= $critical ) {
				throw new \InvalidArgumentException(
					esc_cli(
						"The warning threshold for \"$id\" ($warning" . $thresholds->origin( $id, self::WARNING )
						. ") must be below its critical threshold ($critical" . $thresholds->origin( $id, self::CRITICAL ) . ')'
					)
				);
			}
		}

		return $thresholds;
	}

	/**
	 * Where one level's limit comes from, as a suffix for a message.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id    Check id.
	 * @param string $level WARNING or CRITICAL.
	 * @return string
	 */
	private function origin( string $id, string $level ): string {
		return isset( $this->levels[ $id ][ $level ] ) ? '' : ', the built-in default';
	}

	/**
	 * Reason wp-doctor.json may not set these levels for a check, or null when it may.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $id     Check id from the config file.
	 * @param string[] $levels Levels the config file sets, such as warning.
	 * @return string|null
	 */
	public static function unsupported( string $id, array $levels ): ?string {
		if ( ! isset( self::CONFIGURABLE[ $id ] ) ) {
			return "\"$id\" has no configurable thresholds; configurable checks: " . implode( ', ', array_keys( self::CONFIGURABLE ) );
		}
		foreach ( $levels as $level ) {
			if ( ! isset( self::CONFIGURABLE[ $id ][ $level ] ) ) {
				return "\"$id\" has no $level threshold; configurable: " . implode( ', ', array_keys( self::CONFIGURABLE[ $id ] ) );
			}
		}

		return null;
	}

	/**
	 * Every configurable check with the unit of each level.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array<string, string>>
	 */
	public static function configurable(): array {
		$units = array();
		foreach ( self::CONFIGURABLE as $id => $levels ) {
			foreach ( $levels as $level => $entry ) {
				$units[ $id ][ $level ] = $entry[0];
			}
		}

		return $units;
	}

	/**
	 * The info limit for a check: the configured one, else the built-in one.
	 *
	 * @since 0.2.0
	 *
	 * @param string $check_id Check id.
	 * @return int
	 * @throws \LogicException When the check has no info level.
	 */
	public function info( string $check_id ): int {
		return $this->limit( $check_id, self::INFO );
	}

	/**
	 * The warning limit for a check: the configured one, else the built-in one.
	 *
	 * @since 0.1.0
	 *
	 * @param string $check_id Check id.
	 * @return int
	 * @throws \LogicException When the check has no warning level.
	 */
	public function warning( string $check_id ): int {
		return $this->limit( $check_id, self::WARNING );
	}

	/**
	 * The critical limit for a check: the configured one, else the built-in one.
	 *
	 * @since 0.1.0
	 *
	 * @param string $check_id Check id.
	 * @return int
	 * @throws \LogicException When the check has no critical level.
	 */
	public function critical( string $check_id ): int {
		return $this->limit( $check_id, self::CRITICAL );
	}

	/**
	 * Where a check's limits come from, for the finding's evidence. When the
	 * file sets only one of a check's two levels, the other is named as the default.
	 *
	 * @since 0.1.0
	 *
	 * @param string $check_id Check id.
	 * @return string
	 */
	public function basis( string $check_id ): string {
		if ( ! isset( $this->levels[ $check_id ] ) || null === $this->source ) {
			return self::DEFAULT_BASIS;
		}
		$defaults = array_diff( array_keys( self::CONFIGURABLE[ $check_id ] ?? array() ), array_keys( $this->levels[ $check_id ] ) );
		if ( array() === $defaults ) {
			return 'set in ' . $this->source;
		}

		return implode( ' and ', array_keys( $this->levels[ $check_id ] ) ) . ' set in ' . $this->source . '; '
			. implode( ' and ', $defaults ) . ' is the ' . self::DEFAULT_BASIS;
	}

	/**
	 * One level's limit.
	 *
	 * @since 0.1.0
	 *
	 * @param string $check_id Check id.
	 * @param string $level    INFO, WARNING or CRITICAL.
	 * @return int
	 * @throws \LogicException When the check has no such level.
	 */
	private function limit( string $check_id, string $level ): int {
		if ( ! isset( self::CONFIGURABLE[ $check_id ][ $level ] ) ) {
			throw new \LogicException( esc_cli( "\"$check_id\" has no $level threshold" ) );
		}

		return $this->levels[ $check_id ][ $level ] ?? self::CONFIGURABLE[ $check_id ][ $level ][1];
	}

	/**
	 * Check ids with configured limits.
	 *
	 * @since 0.1.0
	 *
	 * @return string[]
	 */
	public function configured_ids(): array {
		return array_map( 'strval', array_keys( $this->levels ) );
	}
}
