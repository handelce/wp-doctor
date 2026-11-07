<?php
/**
 * Sizes of the site's database tables.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Performance;

use WP_Doctor\Check\Context;

/**
 * Reads data plus index size for every table that carries the site's table
 * prefix, from the server's table statistics. The figures are estimates for
 * some storage engines.
 *
 * @since 0.1.0
 */
final class Table_Sizes {

	public const LIMIT = 200;

	/**
	 * The largest tables of the site, biggest first.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Gives the table prefix and the database. The site tables must be available.
	 * @return array<int, array{name: string, bytes: int}>
	 * @throws \UnexpectedValueException When a returned row is malformed.
	 */
	public static function largest( Context $context ): array {
		$prefix = $context->config()->table_prefix();
		$rows   = $context->database()->query(
			'SELECT table_name AS name, (COALESCE(data_length, 0) + COALESCE(index_length, 0)) AS size_bytes'
			. ' FROM information_schema.tables WHERE table_schema = DATABASE()'
			. ' AND LEFT(table_name, ' . strlen( $prefix ) . ") = '$prefix'"
			. ' ORDER BY size_bytes DESC, name ASC LIMIT ' . self::LIMIT
		);

		$tables = array();
		foreach ( $rows as $row ) {
			if ( ! isset( $row['name'], $row['size_bytes'] ) || 1 !== preg_match( '/^\d+(\.\d+)?$/', $row['size_bytes'] ) ) {
				throw new \UnexpectedValueException( 'The table size query returned an unexpected row' );
			}
			$tables[] = array(
				'name'  => $row['name'],
				'bytes' => (int) $row['size_bytes'],
			);
		}

		return $tables;
	}
}
