<?php
/**
 * A driver that knows which server it is connected to.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Db;

/**
 * Reports the server version without sending a statement.
 *
 * @since 0.1.0
 */
interface Versioned_Driver extends Database_Driver {

	/**
	 * The version string the server announced when the connection opened.
	 *
	 * @since 0.1.0
	 *
	 * @return string For example 8.0.36 or 10.11.6-MariaDB.
	 */
	public function server_version(): string;
}
