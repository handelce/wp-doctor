<?php
/**
 * Expired transients still stored in the options table.
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

/**
 * Transients delete themselves when read after they expire. Ones that are never
 * read again stay in wp_options and slow down queries on a large table. Only
 * transients stored in the database are counted. With an external object cache
 * they live elsewhere.
 *
 * @since 0.1.0
 */
final class Transients_Expired_Check implements Check {


	/**
	 * Unix time to compare against, or null for the current time.
	 *
	 * @since 0.1.0
	 *
	 * @var int|null
	 */
	private $now;

	/**
	 * Takes the time that decides which transients have expired.
	 *
	 * @since 0.1.0
	 *
	 * @param int|null $now Unix time to treat as now, or null for the clock.
	 */
	public function __construct( ?int $now = null ) {
		$this->now = $now;
	}

	/**
	 * Id that keys the expired-row threshold.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'perf.transients.expired';
	}

	/**
	 * Performance, as the rows slow queries on the options table.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'performance';
	}

	/**
	 * Counts expired transients.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Gives the options table and the row threshold.
	 * @return Result
	 * @throws \UnexpectedValueException When the database returns an unexpected result.
	 */
	public function run( Context $context ): Result {
		$unavailable = $context->site_tables_unavailable();
		if ( null !== $unavailable ) {
			return Result::skipped( $unavailable );
		}

		$table = $context->table( 'options' );
		$now   = $this->now ?? time();
		$rows  = 0;
		$bytes = 0;
		foreach ( array( '_transient_', '_site_transient_' ) as $kind ) {
			$timeout = $kind . 'timeout_';
			$result  = $context->database()->query(
				'SELECT COUNT(*) AS expired_rows, COALESCE(SUM(LENGTH(v.option_value)), 0) AS expired_bytes FROM ' . $table . ' AS t'
				. ' LEFT JOIN ' . $table . ' AS v ON v.option_name = CONCAT(' . "'$kind'" . ', SUBSTRING(t.option_name, ' . ( strlen( $timeout ) + 1 ) . '))'
				. " WHERE t.option_name LIKE '" . str_replace( '_', '!_', $timeout ) . "%' ESCAPE '!'"
				. ' AND CAST(t.option_value AS UNSIGNED) < ' . (int) $now
			);
			if ( 1 !== count( $result ) || ! isset( $result[0]['expired_rows'], $result[0]['expired_bytes'] )
				|| 1 !== preg_match( '/^\d+$/', $result[0]['expired_rows'] ) || 1 !== preg_match( '/^\d+$/', $result[0]['expired_bytes'] ) ) {
				throw new \UnexpectedValueException( 'The expired transients query returned an unexpected result' );
			}
			$rows  += (int) $result[0]['expired_rows'];
			$bytes += (int) $result[0]['expired_bytes'];
		}

		$warning_above = $context->thresholds()->warning( $this->id() );
		if ( $rows <= $warning_above ) {
			return Result::pass();
		}

		return Result::findings(
			new Finding(
				$this->id(),
				Severity::warning(),
				'Many expired transients are still stored in the options table',
				array(
					'expired_transients'  => $rows,
					'expired_value_bytes' => $bytes,
					'warning_above_rows'  => $warning_above,
					'threshold_basis'     => $context->thresholds()->basis( $this->id() ),
				),
				'Delete the expired ones with `wp transient delete --expired` (and `wp transient delete --expired --network` on multisite), '
				. 'or let a cleanup plugin do it. If they come back quickly, find the plugin that creates them. Take a database backup first.'
			)
		);
	}
}
