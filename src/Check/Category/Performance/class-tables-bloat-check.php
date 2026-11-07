<?php
/**
 * Large tables that usually hold logs.
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
 * Flags large tables whose names say they hold logs, sessions or queued jobs.
 * The name test is a heuristic, so the finding says "likely". Post revisions
 * live inside the posts table and are not measured here.
 *
 * @since 0.1.0
 */
final class Tables_Bloat_Check implements Check {

	private const NAME_PATTERN = '/(?:^|_)(?:logs?|sessions?)$|^actionscheduler_/i';

	/**
	 * Id that keys the table size threshold.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'perf.tables.bloat';
	}

	/**
	 * Performance, with the largest-tables listing.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'performance';
	}

	/**
	 * Lists large log-like tables.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Gives the table prefix, the table statistics and the size threshold.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$unavailable = $context->site_tables_unavailable();
		if ( null !== $unavailable ) {
			return Result::skipped( $unavailable );
		}

		$prefix = $context->config()->table_prefix();
		$tables = Table_Sizes::largest( $context );
		if ( array() === $tables ) {
			return Result::skipped( esc_cli( "No tables start with the prefix $prefix; the prefix or database may be wrong" ) );
		}

		$larger_than = $context->thresholds()->warning( $this->id() );
		$bloated     = array();
		foreach ( $tables as $table ) {
			$short = substr( $table['name'], strlen( $prefix ) );
			if ( $table['bytes'] > $larger_than && 1 === preg_match( self::NAME_PATTERN, $short ) ) {
				$bloated[] = $table;
			}
		}
		if ( array() === $bloated ) {
			return Result::pass();
		}

		$evidence = array(
			'larger_than_bytes' => $larger_than,
			'threshold_basis'   => $context->thresholds()->basis( $this->id() ),
		);
		foreach ( array_slice( $bloated, 0, 10 ) as $index => $table ) {
			$rank                              = $index + 1;
			$evidence[ "table_$rank" ]         = $table['name'];
			$evidence[ "table_{$rank}_bytes" ] = $table['bytes'];
		}

		return Result::findings(
			new Finding(
				$this->id(),
				Severity::warning(),
				'Tables that likely hold logs or queued jobs have grown very large',
				$evidence,
				'Check which plugin owns each table. Most log and queue plugins have a setting for how long to keep rows; lower it and '
				. 'run their cleanup. Take a database backup before deleting rows.'
			)
		);
	}
}
