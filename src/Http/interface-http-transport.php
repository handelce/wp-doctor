<?php
/**
 * Fetches a URL.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Http;

/**
 * The only seam through which the tool reaches the network.
 *
 * @since 0.1.0
 */
interface Http_Transport {

	/**
	 * Fetches a URL with GET.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url       HTTPS URL.
	 * @param int    $max_bytes Largest body to accept.
	 * @return string The body.
	 * @throws Checksum_Unavailable_Exception When the request fails or the body is too large.
	 */
	public function get( string $url, int $max_bytes ): string;
}
