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
use WP_Doctor\Http\Not_Published_Exception;

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
	 * Plugin checksums by "slug/version".
	 *
	 * @var array<string, array<string, array{sha256: string[], md5: string[]}>>
	 */
	public $plugins = array();

	/**
	 * Failure messages by plugin slug, thrown as an unavailable lookup.
	 *
	 * @var array<string, string>
	 */
	public $plugin_failures = array();

	/**
	 * Every plugin lookup made, as "slug/version".
	 *
	 * @var string[]
	 */
	public $plugin_requests = array();

	/**
	 * Releases returned by core_releases(), oldest first.
	 *
	 * @var string[]
	 */
	public $releases = array();

	/**
	 * Message core_releases() fails with. Null to answer.
	 *
	 * @var string|null
	 */
	public $releases_failure;

	/**
	 * Core checksums by version, used instead of $checksums when the version is a key.
	 *
	 * @var array<string, array<string, string>>
	 */
	public $checksums_by_version = array();

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

		return $this->checksums_by_version[ $version ] ?? $this->checksums;
	}

	/**
	 * Returns the canned list of releases.
	 *
	 * @return string[]
	 * @throws Checksum_Unavailable_Exception When a failure is set.
	 */
	public function core_releases(): array {
		$failure = $this->releases_failure ?? $this->failure;
		if ( null !== $failure ) {
			throw new Checksum_Unavailable_Exception( esc_cli( $failure ) );
		}

		return $this->releases;
	}

	/**
	 * Returns the canned checksums for "slug/version", or answers as wordpress.org
	 * does for a plugin it does not host.
	 *
	 * @param string $slug    Plugin slug asked for.
	 * @param string $version Plugin version asked for.
	 * @return array<string, array{sha256: string[], md5: string[]}>
	 * @throws Checksum_Unavailable_Exception When a failure is set for the slug.
	 * @throws Not_Published_Exception When no checksums are canned for it.
	 */
	public function plugin_checksums( string $slug, string $version ): array {
		$this->plugin_requests[] = "$slug/$version";
		if ( isset( $this->plugin_failures[ $slug ] ) ) {
			throw new Checksum_Unavailable_Exception( esc_cli( $this->plugin_failures[ $slug ] ) );
		}
		if ( ! isset( $this->plugins[ "$slug/$version" ] ) ) {
			throw new Not_Published_Exception( 'The server answered with status 404: nothing is published there' );
		}

		return $this->plugins[ "$slug/$version" ];
	}
}
