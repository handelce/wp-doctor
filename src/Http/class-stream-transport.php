<?php
/**
 * HTTPS GET over PHP streams.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Http;

use function WP_Doctor\esc_cli;

/**
 * Verifies the server certificate, does not follow redirects, and stops
 * reading at the size limit. Needs allow_url_fopen. Curl_Transport covers PHPs
 * without it.
 *
 * @since 0.1.0
 */
final class Stream_Transport implements Http_Transport {

	private const TIMEOUT_SECONDS = 15;
	private const CHUNK_BYTES     = 8192;

	/**
	 * User agent sent with each request.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $user_agent;

	/**
	 * The environment variable that names a proxy these streams do not use, or ''.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $ignored_proxy;

	/**
	 * Sets the user agent named in each request's headers.
	 *
	 * @since 0.1.0
	 *
	 * @param string $user_agent    User agent to send.
	 * @param string $ignored_proxy Variable naming a proxy that cURL would use and streams cannot, or ''. A failed connection names it.
	 */
	public function __construct( string $user_agent = 'wp-doctor', string $ignored_proxy = '' ) {
		$this->user_agent    = $user_agent;
		$this->ignored_proxy = $ignored_proxy;
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
		if ( ! self::url_fopen_enabled() ) {
			throw new Checksum_Unavailable_Exception( 'allow_url_fopen is disabled in this PHP, so wordpress.org cannot be reached' );
		}

		$deadline = microtime( true ) + self::TIMEOUT_SECONDS;
		$context  = stream_context_create( $this->stream_options() );

		error_clear_last();
		$handle = @fopen( $url, 'rb', false, $context ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A failed connection is reported as an exception below. This reads a URL, not a file.
		if ( false === $handle ) {
			$error = error_get_last();
			$proxy = '' === $this->ignored_proxy ? '' : "; $this->ignored_proxy is set, but PHP streams do not use it and the cURL extension is not loaded";
			throw new Checksum_Unavailable_Exception( esc_cli( 'The request failed: ' . ( is_array( $error ) ? $error['message'] : 'unknown error' ) . $proxy ) );
		}

		try {
			$status = $this->status_of( stream_get_meta_data( $handle ) );
			$body   = self::read_body(
				$handle,
				$max_bytes,
				$deadline,
				static function (): float {
					return microtime( true );
				}
			);
		} finally {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes a read stream.
		}

		return self::accepted_body( $status, $body, $max_bytes );
	}

	/**
	 * Reads up to one byte more than the limit before a deadline. The stream's
	 * own timeout applies to each read, so a server that sends a byte every few
	 * seconds would never trip it; the deadline bounds the whole body.
	 *
	 * @since 0.1.0
	 *
	 * @param resource $handle    Open stream.
	 * @param int      $max_bytes Largest body to accept.
	 * @param float    $deadline  Time, in seconds as microtime( true ) gives it, by which reading must finish.
	 * @param callable $now       Returns the current time in the same unit.
	 * @return string The body read, at most $max_bytes + 1 bytes.
	 * @throws Checksum_Unavailable_Exception When the deadline passes.
	 */
	public static function read_body( $handle, int $max_bytes, float $deadline, callable $now ): string {
		$late = 'The response did not finish within ' . self::TIMEOUT_SECONDS . ' seconds';
		$body = '';
		$read = 0;
		while ( ! feof( $handle ) && $read <= $max_bytes ) {
			$left = $deadline - $now();
			if ( $left <= 0 ) {
				throw new Checksum_Unavailable_Exception( esc_cli( $late ) );
			}
			stream_set_timeout( $handle, max( 1, (int) ceil( $left ) ) );
			$chunk = (string) fread( $handle, min( self::CHUNK_BYTES, $max_bytes + 1 - $read ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reads a response stream.
			if ( '' === $chunk && ! empty( stream_get_meta_data( $handle )['timed_out'] ) ) {
				throw new Checksum_Unavailable_Exception( esc_cli( $late ) );
			}
			$body .= $chunk;
			$read += strlen( $chunk );
		}

		return $body;
	}

	/**
	 * Whether this PHP lets streams open URLs.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public static function url_fopen_enabled(): bool {
		$value = strtolower( (string) ini_get( 'allow_url_fopen' ) );

		return in_array( $value, array( '1', 'on', 'true', 'yes' ), true );
	}

	/**
	 * Stream context options for a request. ignore_errors keeps the stream open
	 * on an error status, so accepted_body() can refuse it with the code.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function stream_options(): array {
		return array(
			'http' => array(
				'method'          => 'GET',
				'timeout'         => self::TIMEOUT_SECONDS,
				'follow_location' => 0,
				'ignore_errors'   => true,
				'header'          => 'User-Agent: ' . $this->user_agent . "\r\nAccept: application/json\r\n",
			),
			'ssl'  => array(
				'verify_peer'      => true,
				'verify_peer_name' => true,
			),
		);
	}

	/**
	 * Accepts a response only when it is a complete 200 answer within the size limit.
	 *
	 * @since 0.1.0
	 *
	 * @param int          $status    HTTP status code.
	 * @param string|false $body      Body read, up to one byte more than the limit.
	 * @param int          $max_bytes Largest body to accept.
	 * @return string The body.
	 * @throws Checksum_Unavailable_Exception When the answer is not acceptable.
	 */
	public static function accepted_body( int $status, $body, int $max_bytes ): string {
		if ( 200 !== $status ) {
			throw new Checksum_Unavailable_Exception( esc_cli( "The server answered with status $status" ) );
		}
		if ( ! is_string( $body ) || '' === $body || strlen( $body ) > $max_bytes ) {
			throw new Checksum_Unavailable_Exception( esc_cli( "The response was empty or larger than $max_bytes bytes" ) );
		}

		return $body;
	}

	/**
	 * Reads the HTTP status code from stream metadata.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $meta Result of stream_get_meta_data().
	 * @return int Status code, or 0 when it cannot be read.
	 */
	private function status_of( array $meta ): int {
		$headers = isset( $meta['wrapper_data'] ) && is_array( $meta['wrapper_data'] ) ? $meta['wrapper_data'] : array();
		$status  = 0;
		foreach ( $headers as $line ) {
			if ( is_string( $line ) && 1 === preg_match( '#^HTTP/\S+\s+(\d{3})#', $line, $match ) ) {
				$status = (int) $match[1];
			}
		}

		return $status;
	}
}
