<?php
/**
 * Site tables that use the MyISAM storage engine.
 *
 * @since 0.2.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Performance;

use WP_Doctor\Check\Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Check\Evidence;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

use function WP_Doctor\esc_cli;

/**
 * MyISAM locks a whole table for each write and has no transactions or crash
 * recovery, so on a busy site writes queue behind each other and a crash can
 * leave a table to be repaired. WordPress has created InnoDB tables by
 * default for years; MyISAM ones are usually left from an old install or an
 * import. Only base tables with the site's prefix are read.
 *
 * @since 0.2.0
 */
final class Tables_Engine_Check implements Check {

	private const MAX_TABLES = 10000;

	/**
	 * Id of the MyISAM table notice.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'perf.tables.engine';
	}

	/**
	 * Grouped under performance.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'performance';
	}

	/**
	 * Reads the engine of every table with the site's prefix.
	 *
	 * @since 0.2.0
	 *
	 * @param Context $context Gives the table prefix and the database.
	 * @return Result
	 * @throws \UnexpectedValueException When the database returns an unexpected result.
	 */
	public function run( Context $context ): Result {
		$unavailable = $context->site_tables_unavailable();
		if ( null !== $unavailable ) {
			return Result::skipped( $unavailable );
		}

		$prefix = $context->config()->table_prefix();
		$rows   = $context->database()->query(
			'SELECT table_name AS name, engine AS engine FROM information_schema.tables WHERE table_schema = DATABASE()'
			. " AND table_type = 'BASE TABLE' AND LEFT(table_name, " . strlen( $prefix ) . ") = '$prefix'"
			. ' ORDER BY name ASC LIMIT ' . ( self::MAX_TABLES + 1 )
		);
		if ( array() === $rows ) {
			return Result::skipped( esc_cli( 'No tables start with the prefix ' . $prefix . '; the prefix or database may be wrong' ) );
		}
		if ( count( $rows ) > self::MAX_TABLES ) {
			return Result::skipped( 'More than ' . self::MAX_TABLES . ' tables carry the site prefix, so their engines were not read' );
		}

		$myisam = array();
		foreach ( $rows as $row ) {
			if ( ! isset( $row['name'] ) || ! array_key_exists( 'engine', $row ) ) {
				throw new \UnexpectedValueException( 'The table engine query returned an unexpected row' );
			}
			if ( null !== $row['engine'] && 0 === strcasecmp( $row['engine'], 'MyISAM' ) ) {
				$myisam[] = $row['name'];
			}
		}
		if ( array() === $myisam ) {
			return Result::pass();
		}

		return Result::findings(
			new Finding(
				$this->id(),
				Severity::info(),
				'Tables use the MyISAM storage engine',
				array_merge(
					array(
						'tables_with_prefix' => count( $rows ),
						'myisam_tables'      => count( $myisam ),
					),
					array( 'tables' => Evidence::listed( $myisam ) )
				),
				'Take a database backup, then convert each table to InnoDB, for example '
				. '`wp db query "ALTER TABLE ' . $myisam[0] . ' ENGINE=InnoDB"`. Converting a large table rewrites it and '
				. 'locks it while it runs, so do it when the site is quiet.'
			)
		);
	}
}
