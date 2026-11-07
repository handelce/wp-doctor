<?php
/**
 * The largest tables in the database.
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
 * Shows where the space goes. Only tables with the site's prefix are listed.
 *
 * @since 0.1.0
 */
final class Tables_Largest_Check implements Check {

	private const LISTED = 10;

	/**
	 * Id of the largest-tables listing.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'perf.tables.largest';
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
	 * Reports the ten largest tables that carry the site prefix.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Gives the table prefix and the table statistics.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$unavailable = $context->site_tables_unavailable();
		if ( null !== $unavailable ) {
			return Result::skipped( $unavailable );
		}

		$tables = Table_Sizes::largest( $context );
		if ( array() === $tables ) {
			return Result::skipped( esc_cli( 'No tables start with the prefix ' . $context->config()->table_prefix() . '; the prefix or database may be wrong' ) );
		}

		$count    = count( $tables );
		$evidence = array( 'tables_with_prefix' => $count < Table_Sizes::LIMIT ? $count : 'at least ' . $count . '; only the largest ' . Table_Sizes::LIMIT . ' were read' );
		foreach ( array_slice( $tables, 0, self::LISTED ) as $index => $table ) {
			$rank                              = $index + 1;
			$evidence[ "table_$rank" ]         = $table['name'];
			$evidence[ "table_{$rank}_bytes" ] = $table['bytes'];
		}

		return Result::findings(
			new Finding(
				$this->id(),
				Severity::info(),
				'Largest database tables',
				$evidence,
				'Look at the biggest tables first when the database is slow or the backups are large. Tables left by plugins that are '
				. 'no longer installed can be dropped after a backup.'
			)
		);
	}
}
