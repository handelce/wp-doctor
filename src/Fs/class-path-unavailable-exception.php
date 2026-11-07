<?php
/**
 * A path cannot be scanned.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Fs;

/**
 * The path is missing, not a directory, excluded, or outside the site root.
 *
 * @since 0.1.0
 */
final class Path_Unavailable_Exception extends \RuntimeException {
}
