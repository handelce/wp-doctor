<?php
/**
 * Raised when a path is not a scannable WordPress site.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Site;

/**
 * The message names what is missing. Application prints it and exits with code 3.
 *
 * @since 0.1.0
 */
final class Site_Not_Found_Exception extends \RuntimeException {
}
