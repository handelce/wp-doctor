<?php
/**
 * A database server past the end of its support.
 *
 * @since 0.2.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Config;

use WP_Doctor\Check\Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

use function WP_Doctor\esc_cli;

/**
 * Compares the server's release series with the end-of-support dates that
 * MySQL and MariaDB publish. Where a vendor gives only a month or a quarter,
 * the date is its last day. A series the vendor says has ended without a date
 * is marked ENDED. A series older than every one in its table has ended too;
 * a series that is newer, or missing between them, is not judged. A host or a
 * fork may keep patching an old series, so findings say "likely".
 *
 * @since 0.2.0
 */
final class Db_Version_Check implements Check {

	private const ENDED = 'ended';

	/**
	 * End of support by MySQL series: the end of extended support for long-term
	 * series, from mysql.com; Innovation series end when the next one ships.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	private const MYSQL = array(
		'4.0' => '2008-12-31',
		'4.1' => '2009-12-31',
		'5.0' => '2012-01-09',
		'5.1' => '2013-12-31',
		'5.5' => '2018-12-31',
		'5.6' => '2021-02-01',
		'5.7' => '2023-10-25',
		'8.0' => '2026-04-21',
		'8.1' => self::ENDED,
		'8.2' => self::ENDED,
		'8.3' => self::ENDED,
		'8.4' => '2032-04-30',
		'9.0' => self::ENDED,
		'9.1' => self::ENDED,
		'9.2' => self::ENDED,
		'9.3' => self::ENDED,
		'9.4' => self::ENDED,
		'9.5' => self::ENDED,
		'9.6' => self::ENDED,
		'9.7' => '2034-04-21',
	);

	/**
	 * End of community maintenance by MariaDB series, from mariadb.org and the
	 * release notes on mariadb.com.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, string>
	 */
	private const MARIADB = array(
		'10.0'  => '2019-03-31',
		'10.1'  => '2020-10-17',
		'10.2'  => '2022-05-23',
		'10.3'  => '2023-05-25',
		'10.4'  => '2024-06-18',
		'10.5'  => '2025-06-24',
		'10.6'  => '2026-07-06',
		'10.7'  => '2023-02-28',
		'10.8'  => '2023-05-31',
		'10.9'  => '2023-08-31',
		'10.10' => '2023-11-30',
		'10.11' => '2028-02-16',
		'11.0'  => '2024-06-30',
		'11.1'  => '2024-08-31',
		'11.2'  => '2024-11-30',
		'11.4'  => '2029-05-29',
		'11.5'  => self::ENDED,
		'11.6'  => self::ENDED,
		'11.7'  => self::ENDED,
		'11.8'  => '2028-06-04',
		'12.3'  => '2029-06-12',
		'13.0'  => '2026-12-31',
		'13.1'  => '2027-03-31',
	);

	/**
	 * Unix time to compare against. Null for the clock.
	 *
	 * @since 0.2.0
	 *
	 * @var int|null
	 */
	private $now;

	/**
	 * Takes the time that decides which series have ended.
	 *
	 * @since 0.2.0
	 *
	 * @param int|null $now Unix time to treat as now, or null for the clock.
	 */
	public function __construct( ?int $now = null ) {
		$this->now = $now;
	}

	/**
	 * Id of the database version finding.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'config.db.version';
	}

	/**
	 * Grouped under config, with the PHP version.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'config';
	}

	/**
	 * Reads the server version and looks up its series.
	 *
	 * @since 0.2.0
	 *
	 * @param Context $context Gives the database.
	 * @return Result
	 * @throws \UnexpectedValueException When the database returns an unexpected result.
	 */
	public function run( Context $context ): Result {
		$unavailable = $context->database_unavailable();
		if ( null !== $unavailable ) {
			return Result::skipped( $unavailable );
		}
		$rows = $context->database()->query( 'SELECT VERSION() AS version' );
		if ( 1 !== count( $rows ) || ! isset( $rows[0]['version'] ) ) {
			throw new \UnexpectedValueException( 'The server version query returned an unexpected result' );
		}
		$version = $rows[0]['version'];
		$mariadb = false !== stripos( $version, 'mariadb' );
		$product = $mariadb ? 'MariaDB' : 'MySQL';
		$pattern = $mariadb ? '/^(?:5\.5\.5-)?(\d+)\.(\d+)(?:[.-]|$)/' : '/^(\d+)\.(\d+)(?:[.-]|$)/';
		if ( 1 !== preg_match( $pattern, $version, $match ) ) {
			return Result::skipped( esc_cli( "The server version \"$version\" could not be read as a release series" ) );
		}
		$series = $match[1] . '.' . $match[2];
		$table  = $mariadb ? self::MARIADB : self::MYSQL;
		$ended  = $this->end_of( $series, $table );
		if ( null === $ended ) {
			return Result::skipped( "The end of support for $product $series is not in the table wp-doctor carries, so it was not judged" );
		}
		$today = gmdate( 'Y-m-d', $this->now ?? time() );
		if ( self::ENDED !== $ended && $ended >= $today ) {
			return Result::pass();
		}

		return Result::findings(
			new Finding(
				$this->id(),
				Severity::warning(),
				"$product $series likely no longer receives security fixes",
				array(
					'server_version'  => $version,
					'series'          => "$product $series",
					'support_ended'   => self::ENDED === $ended ? 'yes, on a date the vendor does not give' : $ended,
					'patched_by_host' => 'not visible: a host or a fork may still patch this series',
				),
				'Ask your host to move the database to a supported series, such as MySQL 8.4 or MariaDB 11.8, or upgrade it yourself '
				. 'after a backup. Check that your plugins support the new version first; most work with both.'
			)
		);
	}

	/**
	 * End of support for a series. Null when the table cannot say.
	 *
	 * @since 0.2.0
	 *
	 * @param string                $series Major and minor version, such as 10.6.
	 * @param array<string, string> $table  End dates or ENDED by series, oldest first.
	 * @return string|null
	 */
	private function end_of( string $series, array $table ): ?string {
		if ( isset( $table[ $series ] ) ) {
			return $table[ $series ];
		}
		$known = array_keys( $table );

		return version_compare( $series, (string) $known[0], '<' ) ? self::ENDED : null;
	}
}
