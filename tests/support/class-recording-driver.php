<?php
/**
 * Database driver that records statements instead of running them.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Support;

use WP_Doctor\Db\Database_Driver;

final class Recording_Driver implements Database_Driver {

	/**
	 * Every statement received, in order.
	 *
	 * @var string[]
	 */
	public $statements = array();

	/**
	 * Row limits received with each statement.
	 *
	 * @var int[]
	 */
	public $limits = array();

	/**
	 * Rows returned for every query.
	 *
	 * @var array<int, array<string, string|null>>
	 */
	public $rows = array();

	/**
	 * Rows returned instead of $rows when the statement contains the key.
	 *
	 * @var array<string, array<int, array<string, string|null>>>
	 */
	public $rows_for = array();

	/**
	 * Records the statement and returns the canned rows.
	 *
	 * @param string $sql      SQL sent through the connection.
	 * @param int    $max_rows Row limit the connection passed.
	 * @return array<int, array<string, string|null>>
	 */
	public function execute( string $sql, int $max_rows ): array {
		$this->statements[] = $sql;
		$this->limits[]     = $max_rows;

		foreach ( $this->rows_for as $fragment => $rows ) {
			if ( false !== strpos( $sql, (string) $fragment ) ) {
				return $rows;
			}
		}

		return $this->rows;
	}
}
