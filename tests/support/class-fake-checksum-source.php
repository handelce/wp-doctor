<?php
/**
 * Checksum source with canned answers.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Support;

use WP_Doctor\Http\Checksum_Source;
use WP_Doctor\Http\Checksum_Unavailable_Exception;

use function WP_Doctor\esc_cli;

final class Fake_Checksum_Source implements Checksum_Source {

	/**
	 * Checksums to return.
	 *
	 * @var array<string, string>
	 */
	public $checksums = array();

	/**
	 * Message to fail with. Null to succeed.
	 *
	 * @var string|null
	 */
	public $failure;

	/**
	 * Version and locale of each request.
	 *
	 * @var array<int, array{0: string, 1: string}>
	 */
	public $requests = array();

	/**
	 * Returns the canned checksums.
	 *
	 * @param string $version WordPress version asked for.
	 * @param string $locale  Package locale asked for.
	 * @return array<string, string>
	 * @throws Checksum_Unavailable_Exception When a failure is set.
	 */
	public function core_checksums( string $version, string $locale ): array {
		$this->requests[] = array( $version, $locale );
		if ( null !== $this->failure ) {
			throw new Checksum_Unavailable_Exception( esc_cli( $this->failure ) );
		}

		return $this->checksums;
	}
}
