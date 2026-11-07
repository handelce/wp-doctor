<?php
/**
 * A database connection or query failed.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Db;

/**
 * The message never contains the password.
 *
 * @since 0.1.0
 */
final class Database_Exception extends \RuntimeException {
}
