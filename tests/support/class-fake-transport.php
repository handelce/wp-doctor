<?php
/**
 * Transport that returns a canned body and records the request.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Support;

use WP_Doctor\Http\Checksum_Unavailable_Exception;
use WP_Doctor\Http\Http_Transport;

use function WP_Doctor\esc_cli;

final class Fake_Transport implements Http_Transport {

	/**
	 * URLs requested, in order.
	 *
	 * @var string[]
	 */
	public $urls = array();

	/**
	 * Body to return.
	 *
	 * @var string
	 */
	public $body = '';

	/**
	 * Message to fail with instead of returning a body. Null to succeed.
	 *
	 * @var string|null
	 */
	public $failure;

	/**
	 * Records the request and returns the canned body.
	 *
	 * @param string $url       Address requested.
	 * @param int    $max_bytes Largest body the caller accepts. Ignored here.
	 * @return string
	 * @throws Checksum_Unavailable_Exception When a failure is set.
	 */
	public function get( string $url, int $max_bytes ): string {
		$this->urls[] = $url;
		if ( null !== $this->failure ) {
			throw new Checksum_Unavailable_Exception( esc_cli( $this->failure ) );
		}

		return $this->body;
	}
}
