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
 * Asks wordpress.org for the checksums of every file in a core release or in
 * a plugin version. The version, locale and slug come from the scanned site,
 * so they are validated before they are put in a URL, and the answer is
 * validated before it is returned.
 *
 * @since 0.1.0
 */
final class Checksum_Client implements Checksum_Source {

	private const ENDPOINT        = 'https://api.wordpress.org/core/checksums/1.0/';
	private const PLUGIN_ENDPOINT = 'https://downloads.wordpress.org/plugin-checksums/';
	private const RELEASES        = 'https://api.wordpress.org/core/stable-check/1.0/';
	private const MAX_RELEASES    = 5000;
	private const MAX_BYTES       = 8388608;
	private const MAX_FILES       = 20000;
	private const HASH_LENGTHS    = array(
		'sha256' => 64,
		'md5'    => 32,
	);

	/**
	 * Fetches the API response.
	 *
	 * @since 0.1.0
	 *
	 * @var Http_Transport
	 */
	private $transport;

	/**
	 * Core checksums already fetched, by version and locale, so that checks
	 * sharing one client ask for a release once.
	 *
	 * @since 0.3.0
	 *
	 * @var array<string, array<string, string>>
	 */
	private $core = array();

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

		if ( isset( $this->core[ "$version $locale" ] ) ) {
			return $this->core[ "$version $locale" ];
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
		$this->core[ "$version $locale" ] = $checksums;

		return $checksums;
	}

	/**
	 * Checksums of the files in one version of a plugin hosted on wordpress.org.
	 *
	 * @since 0.3.0
	 *
	 * @param string $slug    Plugin slug, which is its folder name.
	 * @param string $version Plugin version from its header.
	 * @return array<string, array{sha256: string[], md5: string[]}> Lowercase hashes by path inside the plugin folder.
	 * @throws Checksum_Unavailable_Exception When the checksums cannot be obtained. It is a Not_Published_Exception when wordpress.org has none for that plugin or version.
	 */
	public function plugin_checksums( string $slug, string $version ): array {
		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9_-]{0,199}\z/', $slug ) ) {
			throw new Checksum_Unavailable_Exception( esc_cli( 'The folder name is not a wordpress.org plugin slug: ' . $slug ) );
		}
		if ( 1 !== preg_match( '/^[0-9A-Za-z][0-9A-Za-z.+_-]{0,39}\z/', $version ) ) {
			throw new Checksum_Unavailable_Exception( esc_cli( 'The plugin version is not in a form that can be looked up: ' . $version ) );
		}

		$data = json_decode( $this->transport->get( self::PLUGIN_ENDPOINT . $slug . '/' . rawurlencode( $version ) . '.json', self::MAX_BYTES ), true );
		if ( ! is_array( $data ) || ! isset( $data['files'] ) || ! is_array( $data['files'] ) || array() === $data['files'] ) {
			throw new Checksum_Unavailable_Exception( 'The plugin checksum response holds no list of files' );
		}
		if ( count( $data['files'] ) > self::MAX_FILES ) {
			throw new Checksum_Unavailable_Exception( 'The plugin checksum response lists more files than expected' );
		}

		$checksums = array();
		foreach ( $data['files'] as $path => $entry ) {
			if ( ! is_string( $path ) || ! $this->is_relative_path( $path ) || ! is_array( $entry ) ) {
				throw new Checksum_Unavailable_Exception( 'The plugin checksum response holds an entry that is not a path and its hashes' );
			}
			$hashes = array();
			foreach ( self::HASH_LENGTHS as $algorithm => $length ) {
				$hashes[ $algorithm ] = $this->hashes( $entry[ $algorithm ] ?? array(), $length );
			}
			if ( array() === $hashes['sha256'] && array() === $hashes['md5'] ) {
				throw new Checksum_Unavailable_Exception( 'The plugin checksum response holds a file with no hash' );
			}
			$checksums[ $path ] = $hashes;
		}

		return $checksums;
	}

	/**
	 * Every WordPress release wordpress.org lists, oldest first.
	 *
	 * @since 0.3.0
	 *
	 * @return string[] Versions, such as 6.5.2.
	 * @throws Checksum_Unavailable_Exception When the list cannot be obtained.
	 */
	public function core_releases(): array {
		$data = json_decode( $this->transport->get( self::RELEASES, self::MAX_BYTES ), true );
		if ( ! is_array( $data ) || array() === $data || count( $data ) > self::MAX_RELEASES ) {
			throw new Checksum_Unavailable_Exception( 'The list of WordPress releases was not usable' );
		}
		$releases = array();
		foreach ( array_keys( $data ) as $version ) {
			$version = (string) $version;
			if ( 1 !== preg_match( '/^\d{1,2}\.\d{1,2}(\.\d{1,3}){0,2}\z/', $version ) ) {
				throw new Checksum_Unavailable_Exception( 'The list of WordPress releases holds something that is not a version' );
			}
			$releases[] = $version;
		}
		usort( $releases, 'version_compare' );

		return $releases;
	}

	/**
	 * One algorithm's hashes for a file: a single hash or a list of them.
	 *
	 * @since 0.3.0
	 *
	 * @param mixed $value  Value from the response.
	 * @param int   $length Length of a hash in hex digits.
	 * @return string[] Lowercase hashes.
	 * @throws Checksum_Unavailable_Exception When a value is not a hash of that length.
	 */
	private function hashes( $value, int $length ): array {
		$hashes = array();
		foreach ( is_array( $value ) ? $value : array( $value ) as $hash ) {
			if ( ! is_string( $hash ) || 1 !== preg_match( '/^[0-9a-fA-F]{' . $length . '}\z/', $hash ) ) {
				throw new Checksum_Unavailable_Exception( 'The plugin checksum response holds a hash that is not hex of the right length' );
			}
			$hashes[] = strtolower( $hash );
		}

		return $hashes;
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
