<?php
/**
 * A statement was refused by the read-only gate.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Db;

/**
 * Indicates a bug in a check, so it extends LogicException and is not caught as a database failure.
 *
 * @since 0.1.0
 */
final class Read_Only_Violation_Exception extends \LogicException {
}
