<?php
/**
 * Low-level statement execution.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Db;

/**
 * Runs a statement that Read_Only_Connection has already accepted. Nothing but
 * Read_Only_Connection holds a driver.
 *
 * @since 0.1.0
 */
interface Database_Driver {

	/**
	 * Runs one statement and returns its rows.
	 *
	 * @since 0.1.0
	 *
	 * @param string $sql      Statement already accepted by the gate.
	 * @param int    $max_rows Most rows accepted. 0 for a statement that returns none.
	 * @return array<int, array<string, string|null>>
	 * @throws Database_Exception When the statement fails or the result is larger than the limit.
	 */
	public function execute( string $sql, int $max_rows ): array;
}
