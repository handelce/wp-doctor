<?php
/**
 * Core checksums from the wordpress.org API.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Http;

use function WP_Doctor\esc_cli;

/**
 * Asks api.wordpress.org for the MD5 of every file in a release. The version
 * and locale come from the scanned site, so they are validated before they are
 * put in a URL, and the answer is validated before it is returned.
 *
 * @since 0.1.0
 */
final class Checksum_Client implements Checksum_Source {

	private const ENDPOINT  = 'https://api.wordpress.org/core/checksums/1.0/';
	private const MAX_BYTES = 8388608;
	private const MAX_FILES = 20000;

	/**
	 * Fetches the API response.
	 *
	 * @since 0.1.0
	 *
	 * @var Http_Transport
	 */
	private $transport;

	/**
	 * Takes the transport used for every request to the API.
	 *
	 * @since 0.1.0
	 *
	 * @param Http_Transport $transport How to fetch a URL.
	 */
	public function __construct( Http_Transport $transport ) {
		$this->transport = $transport;
	}

	/**
	 * MD5 checksums of the files in a WordPress release.
	 *
	 * @since 0.1.0
	 *
	 * @param string $version WordPress version, such as 6.5.2.
	 * @param string $locale  Locale, such as en_US.
	 * @return array<string, string> Lowercase MD5 by path relative to the site root.
	 * @throws Checksum_Unavailable_Exception When the checksums cannot be obtained.
	 */
	public function core_checksums( string $version, string $locale ): array {
		if ( 1 !== preg_match( '/^\d{1,2}\.\d{1,2}(\.\d{1,3})?(-[A-Za-z0-9.]{1,20})?\z/', $version ) ) {
			throw new Checksum_Unavailable_Exception( esc_cli( 'The WordPress version is not in a form that can be looked up: ' . $version ) );
		}
		if ( 1 !== preg_match( '/^[a-z]{2,3}(_[A-Z]{2})?(_[a-z0-9]{3,10})?\z/', $locale ) ) {
			throw new Checksum_Unavailable_Exception( esc_cli( 'The locale is not in a form that can be looked up: ' . $locale ) );
		}

		$body = $this->transport->get( self::ENDPOINT . '?version=' . rawurlencode( $version ) . '&locale=' . rawurlencode( $locale ), self::MAX_BYTES );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			throw new Checksum_Unavailable_Exception( 'The checksum response was not valid JSON' );
		}
		if ( ! isset( $data['checksums'] ) || ! is_array( $data['checksums'] ) || array() === $data['checksums'] ) {
			throw new Checksum_Unavailable_Exception( esc_cli( "wordpress.org publishes no checksums for version $version, locale $locale" ) );
		}
		if ( count( $data['checksums'] ) > self::MAX_FILES ) {
			throw new Checksum_Unavailable_Exception( 'The checksum response lists more files than expected' );
		}

		$checksums = array();
		foreach ( $data['checksums'] as $path => $hash ) {
			if ( ! is_string( $path ) || ! $this->is_relative_path( $path ) || ! is_string( $hash ) || 1 !== preg_match( '/^[0-9a-fA-F]{32}\z/', $hash ) ) {
				throw new Checksum_Unavailable_Exception( 'The checksum response holds an entry that is not a path and an MD5' );
			}
			$checksums[ $path ] = strtolower( $hash );
		}

		return $checksums;
	}

	/**
	 * Whether a path is a plain path below the site root.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Path from the response.
	 * @return bool
	 */
	private function is_relative_path( string $path ): bool {
		return '' !== $path && '/' !== $path[0] && false === strpos( $path, "\0" ) && ! in_array( '..', explode( '/', $path ), true );
	}
}
