<?php
/**
 * PHP settings the site itself sets.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Config;

use WP_Doctor\Check\Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

/**
 * Reads WP_MEMORY_LIMIT and ini_set() calls from wp-config.php, and .user.ini
 * and php.ini from the site root, then judges display_errors, memory_limit and
 * max_execution_time. The web server's own configuration can override all of
 * these and cannot be read from a command-line scan, so findings say "likely".
 *
 * @since 0.1.0
 */
final class Php_Settings_Check implements Check {

	private const LOW_MEMORY_BELOW_BYTES  = 67108864;
	private const LOW_EXECUTION_BELOW_SEC = 30;
	private const INI_READ_BYTES          = 65536;
	private const SETTINGS                = array( 'display_errors', 'memory_limit', 'max_execution_time' );
	private const NOT_EXAMINED            = 'the web server\'s own PHP configuration, which can override these values and cannot be read from a command-line scan';
	private const THRESHOLD_BASIS         = 'default rule of thumb, not measured for this site';

	/**
	 * Id shared by every PHP settings finding.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'config.php.settings';
	}

	/**
	 * Reported under config.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'config';
	}

	/**
	 * Judges the settings the site files set.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Gives wp-config.php values and the INI files in the site root.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$examined = array();
		$not_read = array();
		$findings = array();

		$this->judge_memory_constant( $context, $examined, $not_read, $findings );

		$effective = $this->effective_settings( $context, $not_read );
		foreach ( $effective as $name => $setting ) {
			$examined[] = "$name from {$setting['source']}";
			$finding    = $this->judge_setting( $name, $setting['value'], $setting['source'] );
			if ( null !== $finding ) {
				$findings[] = $finding;
			}
		}

		if ( array() === $examined ) {
			return Result::skipped(
				array() === $not_read
					? 'No PHP settings are set in wp-config.php (WP_MEMORY_LIMIT or ini_set()), .user.ini or php.ini; the web server\'s PHP settings cannot be read from a command-line scan'
					: implode( '; ', $not_read )
			);
		}
		if ( array() !== $findings ) {
			return Result::findings( ...$findings );
		}
		if ( array() !== $not_read ) {
			return Result::skipped( 'Read ' . implode( ', ', $examined ) . ' without findings, but ' . implode( '; ', $not_read ) );
		}

		return Result::pass();
	}

	/**
	 * Judges WP_MEMORY_LIMIT from wp-config.php.
	 *
	 * @since 0.1.0
	 *
	 * @param Context   $context  Source of WP_MEMORY_LIMIT.
	 * @param string[]  $examined Gains an entry when the constant is read.
	 * @param string[]  $not_read Gains an entry when the constant is set but unreadable.
	 * @param Finding[] $findings Gains a finding when the limit is low.
	 */
	private function judge_memory_constant( Context $context, array &$examined, array &$not_read, array &$findings ): void {
		$config = $context->config();
		if ( ! $config->has( 'WP_MEMORY_LIMIT' ) ) {
			if ( array_key_exists( 'WP_MEMORY_LIMIT', $config->unresolved() ) ) {
				$not_read[] = 'WP_MEMORY_LIMIT is set in wp-config.php but its value could not be read (' . $config->unresolved()['WP_MEMORY_LIMIT'] . ')';
			}
			return;
		}

		$raw   = $config->value( 'WP_MEMORY_LIMIT' );
		$bytes = is_string( $raw ) || is_int( $raw ) ? $this->to_bytes( (string) $raw ) : null;
		if ( null === $bytes ) {
			$not_read[] = 'WP_MEMORY_LIMIT in wp-config.php is not a size such as 256M';
			return;
		}
		$examined[] = 'WP_MEMORY_LIMIT from wp-config.php';
		if ( -1 === $bytes || $bytes >= self::LOW_MEMORY_BELOW_BYTES ) {
			return;
		}

		$findings[] = new Finding(
			$this->id(),
			Severity::info(),
			'The WordPress memory limit is likely too low',
			array(
				'setting'         => 'WP_MEMORY_LIMIT',
				'source'          => 'wp-config.php',
				'WP_MEMORY_LIMIT' => (string) $raw,
				'bytes'           => $bytes,
				'low_below_bytes' => self::LOW_MEMORY_BELOW_BYTES,
				'threshold_basis' => self::THRESHOLD_BASIS,
				'not_examined'    => self::NOT_EXAMINED,
			),
			"Raise it in wp-config.php, for example:\ndefine( 'WP_MEMORY_LIMIT', '256M' );\nThe host may cap the value."
		);
	}

	/**
	 * The value that applies for each setting the site files set, with its source.
	 *
	 * An ini_set() call runs after PHP starts, so it wins over .user.ini, which
	 * PHP reads before php.ini in the same directory.
	 *
	 * @since 0.1.0
	 *
	 * @param Context  $context  Gives php.ini and .user.ini in the site root, and the ini_set() calls in wp-config.php.
	 * @param string[] $not_read Gains an entry for each file or call whose value could not be read.
	 * @return array<string, array{value: string, source: string}>
	 */
	private function effective_settings( Context $context, array &$not_read ): array {
		$effective = array();

		foreach ( array( 'php.ini', '.user.ini' ) as $file ) {
			$content = $context->files()->read( $file, self::INI_READ_BYTES );
			if ( null === $content ) {
				continue;
			}
			$values = $this->ini_values( $content );
			if ( null === $values ) {
				$not_read[] = "$file in the site root could not be parsed as an INI file";
				continue;
			}
			foreach ( self::SETTINGS as $name ) {
				if ( isset( $values[ $name ] ) ) {
					$effective[ $name ] = array(
						'value'  => $values[ $name ],
						'source' => $file,
					);
				}
			}
		}

		$config = $context->config();
		foreach ( self::SETTINGS as $name ) {
			if ( $config->has_ini_setting( $name ) ) {
				$value = $config->ini_setting( $name );
				if ( is_bool( $value ) ) {
					$value = $value ? '1' : '0';
				}
				$effective[ $name ] = array(
					'value'  => null === $value ? '' : (string) $value,
					'source' => 'ini_set() in wp-config.php',
				);
			} elseif ( array_key_exists( $name, $config->unresolved_ini_settings() ) ) {
				unset( $effective[ $name ] );
				$not_read[] = "ini_set( '$name' ) in wp-config.php has a value that could not be read (" . $config->unresolved_ini_settings()[ $name ] . ')';
			}
		}

		return $effective;
	}

	/**
	 * Settings from an INI file, sections flattened.
	 *
	 * @since 0.1.0
	 *
	 * @param string $content Up to 64 KB of php.ini or .user.ini.
	 * @return array<string, string>|null Null when the file does not parse.
	 */
	private function ini_values( string $content ): ?array {
		$parsed = @parse_ini_string( $content, true, INI_SCANNER_RAW ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A malformed file is reported through the null return, not a warning in the report.
		if ( ! is_array( $parsed ) ) {
			return null;
		}
		$values = array();
		foreach ( $parsed as $key => $value ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $inner_key => $inner_value ) {
					if ( is_scalar( $inner_value ) ) {
						$values[ (string) $inner_key ] = (string) $inner_value;
					}
				}
			} elseif ( is_scalar( $value ) ) {
				$values[ (string) $key ] = (string) $value;
			}
		}

		return $values;
	}

	/**
	 * A finding for one setting, or null when its value is fine.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name   display_errors, memory_limit or max_execution_time.
	 * @param string $value  Value as written, possibly quoted.
	 * @param string $source File or call that set it, named in the fix text.
	 * @return Finding|null
	 */
	private function judge_setting( string $name, string $value, string $source ): ?Finding {
		$evidence = array(
			'setting'      => $name,
			'value'        => $value,
			'source'       => $source,
			'not_examined' => self::NOT_EXAMINED,
		);
		$trimmed  = trim( $value, " \t\"'" );

		if ( 'display_errors' === $name ) {
			// stderr sends errors to the server or FPM log, not to the page.
			if ( ! in_array( strtolower( $trimmed ), array( '1', 'on', 'true', 'yes', 'stdout' ), true ) ) {
				return null;
			}

			return new Finding(
				$this->id(),
				Severity::warning(),
				'PHP is likely set to display errors to visitors',
				$evidence,
				"Set display_errors to Off in $source, and make sure error_log is set so errors still go to a log. Error output "
				. 'shows file paths and sometimes database details to anyone who triggers an error.'
			);
		}

		if ( 'memory_limit' === $name ) {
			$bytes = $this->to_bytes( $trimmed );
			if ( null === $bytes || -1 === $bytes || $bytes >= self::LOW_MEMORY_BELOW_BYTES ) {
				return null;
			}

			return new Finding(
				$this->id(),
				Severity::info(),
				'The PHP memory limit is likely too low',
				array_merge(
					$evidence,
					array(
						'bytes'           => $bytes,
						'low_below_bytes' => self::LOW_MEMORY_BELOW_BYTES,
						'threshold_basis' => self::THRESHOLD_BASIS,
					)
				),
				"Raise memory_limit in $source, for example to 256M. WordPress raises it to WP_MEMORY_LIMIT when that is higher, so set both."
			);
		}

		if ( 1 !== preg_match( '/^\d+$/', $trimmed ) ) {
			return null;
		}
		$seconds = (int) $trimmed;
		if ( 0 === $seconds || $seconds >= self::LOW_EXECUTION_BELOW_SEC ) {
			return null;
		}

		return new Finding(
			$this->id(),
			Severity::info(),
			'The PHP execution time limit is likely too low',
			array_merge(
				$evidence,
				array(
					'seconds'           => $seconds,
					'low_below_seconds' => self::LOW_EXECUTION_BELOW_SEC,
					'threshold_basis'   => self::THRESHOLD_BASIS,
				)
			),
			"Raise max_execution_time in $source to at least 30, PHP's default. Updates, imports and backups stop part-way when it is lower."
		);
	}

	/**
	 * Converts a PHP size such as 256M to bytes.
	 *
	 * @since 0.1.0
	 *
	 * @param string $size Value such as 256M, 512K or -1.
	 * @return int|null Bytes, -1 for unlimited, or null when it is not a size.
	 */
	private function to_bytes( string $size ): ?int {
		if ( 1 !== preg_match( '/^\s*(-1|\d+)\s*([kmg]?)b?\s*$/i', $size, $match ) ) {
			return null;
		}
		$number     = (int) $match[1];
		$multiplier = array(
			''  => 1,
			'k' => 1024,
			'm' => 1048576,
			'g' => 1073741824,
		);

		return -1 === $number ? -1 : $number * $multiplier[ strtolower( $match[2] ) ];
	}
}
