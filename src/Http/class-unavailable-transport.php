<?php
/**
 * A transport for a PHP that cannot make HTTPS requests.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Http;

use function WP_Doctor\esc_cli;

/**
 * Refuses every request with the reason it was built with.
 *
 * @since 0.1.0
 */
final class Unavailable_Transport implements Http_Transport {

	/**
	 * Why no request can be made.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $reason;

	/**
	 * Stores the reason given with every refusal.
	 *
	 * @since 0.1.0
	 *
	 * @param string $reason Why no request can be made.
	 */
	public function __construct( string $reason ) {
		$this->reason = $reason;
	}

	// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- The interface returns a string; this implementation always throws.
	/**
	 * Refuses the request.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url       URL that was asked for.
	 * @param int    $max_bytes Largest body to accept.
	 * @return string Never returned, since this transport always throws.
	 * @throws Checksum_Unavailable_Exception Always.
	 */
	public function get( string $url, int $max_bytes ): string {
		throw new Checksum_Unavailable_Exception( esc_cli( $this->reason ) );
	}
	// phpcs:enable
}
