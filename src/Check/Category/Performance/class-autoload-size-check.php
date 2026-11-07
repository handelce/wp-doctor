<?php
/**
 * Total size of autoloaded options.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Performance;

use WP_Doctor\Check\Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

use function WP_Doctor\esc_cli;

/**
 * Warns when the autoloaded part of wp_options is large, because WordPress
 * reads all of it on every request.
 *
 * @since 0.1.0
 */
final class Autoload_Size_Check implements Check {


	/**
	 * Id for the autoload total, and the key for its thresholds.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'perf.autoload.size';
	}

	/**
	 * Grouped under performance.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'performance';
	}

	/**
	 * Sums the autoloaded option values.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Gives the options table and the size thresholds.
	 * @return Result
	 * @throws \UnexpectedValueException When the database returns something other than two counts.
	 */
	public function run( Context $context ): Result {
		$unavailable = $context->site_tables_unavailable();
		if ( null !== $unavailable ) {
			return Result::skipped( $unavailable );
		}

		$table = $context->table( 'options' );
		$rows  = $context->database()->query(
			'SELECT COUNT(*) AS option_count, COALESCE(SUM(LENGTH(option_value)), 0) AS total_bytes FROM ' . $table
			. ' WHERE ' . Autoload_Options::CONDITION
		);

		if ( 1 !== count( $rows ) || ! isset( $rows[0]['option_count'], $rows[0]['total_bytes'] )
			|| 1 !== preg_match( '/^\d+$/', $rows[0]['option_count'] ) || 1 !== preg_match( '/^\d+$/', $rows[0]['total_bytes'] ) ) {
			throw new \UnexpectedValueException( 'The autoload totals query returned an unexpected result' );
		}

		$options = (int) $rows[0]['option_count'];
		$bytes   = (int) $rows[0]['total_bytes'];

		if ( 0 === $options ) {
			return Result::skipped( esc_cli( "No autoloaded rows in $table; the table prefix or database may be wrong" ) );
		}
		$warning_above  = $context->thresholds()->warning( $this->id() );
		$critical_above = $context->thresholds()->critical( $this->id() );
		if ( $bytes <= $warning_above ) {
			return Result::pass();
		}

		$severity = $bytes > $critical_above ? Severity::critical() : Severity::warning();

		return Result::findings(
			new Finding(
				$this->id(),
				$severity,
				'Autoloaded options are large; every request loads them',
				array(
					'autoloaded_bytes'     => $bytes,
					'autoloaded_options'   => $options,
					'warning_above_bytes'  => $warning_above,
					'critical_above_bytes' => $critical_above,
					'threshold_basis'      => $context->thresholds()->basis( $this->id() ),
				),
				'See perf.autoload.top for the largest options. For options left by plugins that are no longer used, '
				. 'delete the row; for options that are needed but not on every request, set autoload to no. '
				. 'Take a database backup first.'
			)
		);
	}
}
