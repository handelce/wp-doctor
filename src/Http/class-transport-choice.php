<?php
/**
 * Picks how the tool reaches the network.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Http;

/**
 * PHP streams when allow_url_fopen is on and streams can open https URLs,
 * otherwise cURL when the extension is loaded, and otherwise a transport that
 * refuses every request with the reason, so the checksum check skips saying why.
 *
 * @since 0.1.0
 */
final class Transport_Choice {

	/**
	 * The transport for this PHP.
	 *
	 * @since 0.1.0
	 *
	 * @param string $user_agent User agent to send.
	 * @return Http_Transport
	 */
	public static function for_this_php( string $user_agent ): Http_Transport {
		return self::choose( $user_agent, Stream_Transport::url_fopen_enabled(), Curl_Transport::is_available(), stream_get_wrappers(), (string) self::proxy_variable( getenv() ) );
	}

	/**
	 * The transport for the given capabilities.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $user_agent      User agent to send.
	 * @param bool     $url_fopen       Whether allow_url_fopen is on.
	 * @param bool     $curl            Whether the cURL extension is loaded.
	 * @param string[] $stream_wrappers Registered stream wrappers, as stream_get_wrappers() returns them.
	 * @param string   $proxy_variable  The environment variable that names a proxy for https, which only cURL uses, or ''.
	 * @return Http_Transport
	 */
	public static function choose( string $user_agent, bool $url_fopen, bool $curl, array $stream_wrappers, string $proxy_variable = '' ): Http_Transport {
		$https_streams = in_array( 'https', $stream_wrappers, true );
		if ( '' !== $proxy_variable && $curl ) {
			return new Curl_Transport( $user_agent );
		}
		if ( $url_fopen && $https_streams ) {
			return new Stream_Transport( $user_agent, $proxy_variable );
		}
		if ( $curl ) {
			return new Curl_Transport( $user_agent );
		}

		$streams = $url_fopen ? 'PHP has no https stream wrapper (the openssl extension is not loaded)' : 'allow_url_fopen is disabled';

		return new Unavailable_Transport( $streams . ' and the cURL extension is not loaded in this PHP, so wordpress.org cannot be reached' );
	}

	/**
	 * The variable that names a proxy for https requests, as libcurl reads
	 * them: https_proxy, HTTPS_PROXY, all_proxy or ALL_PROXY.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, string> $env Environment variables by name.
	 * @return string|null The first one set, or null.
	 */
	public static function proxy_variable( array $env ): ?string {
		foreach ( array( 'https_proxy', 'HTTPS_PROXY', 'all_proxy', 'ALL_PROXY' ) as $name ) {
			if ( isset( $env[ $name ] ) && '' !== trim( $env[ $name ] ) ) {
				return $name;
			}
		}

		return null;
	}
}
