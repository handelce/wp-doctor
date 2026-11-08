<?php
/**
 * A lookup that wordpress.org answered with "not found".
 *
 * @since 0.3.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Http;

/**
 * The server answered 404: it publishes nothing at that address, which for
 * plugin checksums means the plugin or that version is not on wordpress.org.
 *
 * @since 0.3.0
 */
final class Not_Published_Exception extends Checksum_Unavailable_Exception {
}
