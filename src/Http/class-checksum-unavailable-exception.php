<?php
/**
 * Checksums could not be obtained.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Http;

/**
 * The network is down, the answer was not usable, or the input was refused.
 *
 * @since 0.1.0
 */
final class Checksum_Unavailable_Exception extends \RuntimeException {
}
