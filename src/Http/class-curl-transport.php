<?php
/**
 * HTTPS GET through the cURL extension.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Http;

use function WP_Doctor\esc_cli;

/**
 * Used when allow_url_fopen is off. Applies the same guards as
 * Stream_Transport: HTTPS only, certificate and host name verified, no
 * redirects, a timeout, and a size limit enforced while reading, so an
 * oversized body is never held in memory.
 *
 * @since 0.1.0
 */
final class Curl_Transport implements Http_Transport {

	private const TIMEOUT_SECONDS = 15;

	/**
	 * User agent sent with each request.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $user_agent;

	/**
	 * Sets the User-Agent header sent with each request.
	 *
	 * @since 0.1.0
	 *
	 * @param string $user_agent User agent to send.
	 */
	public function __construct( string $user_agent = 'wp-doctor' ) {
		$this->user_agent = $user_agent;
	}

	/**
	 * Sets every option, or stops: a request without one of its guards, such
	 * as the https-only protocol list, must not be sent.
	 *
	 * @since 0.1.0
	 *
	 * @param resource|object   $handle  Handle from curl_init().
	 * @param array<int, mixed> $options Options by CURLOPT_ constant.
	 * @throws Checksum_Unavailable_Exception When cURL refuses an option.
	 */
	public static function apply_options( $handle, array $options ): void {
		// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged -- wp-doctor runs without WordPress; a refused option is reported below.
		if ( false === @curl_setopt_array( $handle, $options ) ) {
			throw new Checksum_Unavailable_Exception( esc_cli( 'cURL refused an option this request needs: ' . curl_error( $handle ) ) );
		}
		// phpcs:enable
	}

	/**
	 * Whether this PHP has the cURL extension.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return function_exists( 'curl_init' );
	}

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
	public function get( string $url, int $max_bytes ): string {
		if ( 0 !== strpos( $url, 'https://' ) ) {
			throw new Checksum_Unavailable_Exception( 'Only https URLs are fetched' );
		}
		if ( ! self::is_available() ) {
			throw new Checksum_Unavailable_Exception( 'The cURL extension is not loaded in this PHP' );
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions -- wp-doctor runs without WordPress, so wp_remote_get() does not exist.
		$handle = curl_init();
		if ( false === $handle ) {
			throw new Checksum_Unavailable_Exception( 'cURL could not start a request' );
		}

		$body     = '';
		$too_long = false;
		self::apply_options(
			$handle,
			$this->options( $url ) + array(
				CURLOPT_WRITEFUNCTION => static function ( $curl, string $chunk ) use ( &$body, &$too_long, $max_bytes ): int {
					if ( strlen( $body ) + strlen( $chunk ) > $max_bytes ) {
						$too_long = true;
						return 0;
					}
					$body .= $chunk;
					return strlen( $chunk );
				},
			)
		);

		$ok     = curl_exec( $handle );
		$status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
		$error  = curl_error( $handle );
		curl_close( $handle );
		// phpcs:enable

		if ( $too_long ) {
			throw new Checksum_Unavailable_Exception( esc_cli( "The response was empty or larger than $max_bytes bytes" ) );
		}
		if ( false === $ok ) {
			throw new Checksum_Unavailable_Exception( esc_cli( 'The request failed: ' . ( '' === $error ? 'unknown error' : $error ) ) );
		}

		return Stream_Transport::accepted_body( $status, $body, $max_bytes );
	}

	/**
	 * Options for a cURL GET of $url: HTTPS only, certificate and host verified,
	 * no redirects, and a timeout.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url HTTPS URL.
	 * @return array<int, mixed>
	 */
	public function options( string $url ): array {
		return array(
			CURLOPT_URL            => $url,
			CURLOPT_HTTPGET        => true,
			CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_SSL_VERIFYPEER => true,
			CURLOPT_SSL_VERIFYHOST => 2,
			CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
			CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
			CURLOPT_USERAGENT      => $this->user_agent,
			CURLOPT_HTTPHEADER     => array( 'Accept: application/json' ),
		);
	}
}
