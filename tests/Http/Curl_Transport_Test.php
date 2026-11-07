<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Http;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Http\Checksum_Client;
use WP_Doctor\Http\Checksum_Unavailable_Exception;
use WP_Doctor\Http\Curl_Transport;
use WP_Doctor\Http\Stream_Transport;
use WP_Doctor\Http\Transport_Choice;
use WP_Doctor\Http\Unavailable_Transport;

final class Curl_Transport_Test extends TestCase {

	private function skip_without_curl(): void {
		if ( ! Curl_Transport::is_available() ) {
			$this->markTestSkipped( 'The cURL extension is not loaded in this PHP.' );
		}
	}

	private function skip_without_network(): void {
		$enabled = getenv( 'WPD_TEST_NETWORK' );
		if ( false === $enabled || '' === $enabled ) {
			$this->markTestSkipped( 'Set WPD_TEST_NETWORK=1 to contact api.wordpress.org.' );
		}
	}

	public function test_streams_are_chosen_when_allow_url_fopen_is_on_and_streams_speak_https(): void {
		$this->assertInstanceOf( Stream_Transport::class, Transport_Choice::choose( 'ua', true, true, array( 'file', 'https' ) ) );
		$this->assertInstanceOf( Stream_Transport::class, Transport_Choice::choose( 'ua', true, false, array( 'https' ) ) );
	}

	public function test_curl_is_chosen_when_allow_url_fopen_is_off_and_curl_is_loaded(): void {
		$this->assertInstanceOf( Curl_Transport::class, Transport_Choice::choose( 'ua', false, true, array( 'https' ) ) );
	}

	public function test_curl_is_chosen_when_streams_have_no_https_wrapper(): void {
		$this->assertInstanceOf( Curl_Transport::class, Transport_Choice::choose( 'ua', true, true, array( 'file', 'http' ) ) );
	}

	public function test_without_an_https_wrapper_or_curl_every_request_is_refused_naming_both_causes(): void {
		$transport = Transport_Choice::choose( 'ua', true, false, array( 'file', 'http' ) );

		$this->assertInstanceOf( Unavailable_Transport::class, $transport );
		$this->expectException( Checksum_Unavailable_Exception::class );
		$this->expectExceptionMessage( 'no https stream wrapper (the openssl extension is not loaded) and the cURL extension is not loaded' );
		$transport->get( 'https://api.wordpress.org/', 10 );
	}

	public function test_without_either_every_request_is_refused_naming_both_causes(): void {
		$transport = Transport_Choice::choose( 'ua', false, false, array( 'https' ) );

		$this->assertInstanceOf( Unavailable_Transport::class, $transport );
		$this->expectException( Checksum_Unavailable_Exception::class );
		$this->expectExceptionMessage( 'allow_url_fopen is disabled and the cURL extension is not loaded' );
		$transport->get( 'https://api.wordpress.org/', 10 );
	}

	public function test_the_options_allow_https_only_verify_the_certificate_and_do_not_follow_redirects(): void {
		$this->skip_without_curl();

		$options = ( new Curl_Transport( 'wp-doctor-tests' ) )->options( 'https://api.wordpress.org/x' );

		$this->assertSame( CURLPROTO_HTTPS, $options[ CURLOPT_PROTOCOLS ] );
		$this->assertTrue( $options[ CURLOPT_SSL_VERIFYPEER ] );
		$this->assertSame( 2, $options[ CURLOPT_SSL_VERIFYHOST ] );
		$this->assertFalse( $options[ CURLOPT_FOLLOWLOCATION ] );
		$this->assertGreaterThan( 0, $options[ CURLOPT_TIMEOUT ] );
		$this->assertSame( 'wp-doctor-tests', $options[ CURLOPT_USERAGENT ] );
	}

	public function test_a_url_that_is_not_https_is_refused_before_any_request(): void {
		$this->expectException( Checksum_Unavailable_Exception::class );
		$this->expectExceptionMessage( 'Only https URLs are fetched' );
		( new Curl_Transport() )->get( 'http://api.wordpress.org/', 10 );
	}

	public function test_an_unresolvable_host_is_reported_as_a_failed_request(): void {
		$this->skip_without_curl();

		$this->expectException( Checksum_Unavailable_Exception::class );
		$this->expectExceptionMessage( 'The request failed' );
		( new Curl_Transport() )->get( 'https://no-such-host.invalid/', 1000 );
	}

	public function test_the_real_wordpress_org_answers_through_curl(): void {
		$this->skip_without_curl();
		$this->skip_without_network();

		$checksums = ( new Checksum_Client( new Curl_Transport( 'wp-doctor-tests' ) ) )->core_checksums( '6.5.2', 'en_US' );

		$this->assertGreaterThan( 1000, count( $checksums ) );
		$this->assertArrayHasKey( 'wp-login.php', $checksums );
	}

	public function test_a_body_above_the_limit_is_cut_off_while_reading_and_refused(): void {
		$this->skip_without_curl();
		$this->skip_without_network();

		$this->expectException( Checksum_Unavailable_Exception::class );
		$this->expectExceptionMessage( 'larger than 100 bytes' );
		( new Curl_Transport( 'wp-doctor-tests' ) )->get( 'https://api.wordpress.org/core/checksums/1.0/?version=6.5.2&locale=en_US', 100 );
	}

	public function test_curl_is_chosen_when_a_proxy_is_set_since_only_curl_uses_it(): void {
		$this->assertInstanceOf( Curl_Transport::class, Transport_Choice::choose( 'ua', true, true, array( 'https' ), 'https_proxy' ) );
		$this->assertInstanceOf( Stream_Transport::class, Transport_Choice::choose( 'ua', true, false, array( 'https' ), 'https_proxy' ) );
	}

	public function test_a_proxy_that_streams_cannot_use_is_named_when_the_connection_fails(): void {
		$transport = Transport_Choice::choose( 'ua', true, false, array( 'https' ), 'HTTPS_PROXY' );

		try {
			$transport->get( 'https://no-such-host.invalid/', 1000 );
			$this->fail( 'The request to an unreachable host succeeded' );
		} catch ( Checksum_Unavailable_Exception $e ) {
			$this->assertStringStartsWith( 'The request failed: ', $e->getMessage() );
			$this->assertStringEndsWith( '; HTTPS_PROXY is set, but PHP streams do not use it and the cURL extension is not loaded', $e->getMessage() );
		}
	}

	public function test_a_failed_connection_without_a_proxy_does_not_mention_one(): void {
		$transport = Transport_Choice::choose( 'ua', true, false, array( 'https' ) );

		try {
			$transport->get( 'https://no-such-host.invalid/', 1000 );
			$this->fail( 'The request to an unreachable host succeeded' );
		} catch ( Checksum_Unavailable_Exception $e ) {
			$this->assertStringStartsWith( 'The request failed: ', $e->getMessage() );
			$this->assertStringNotContainsString( 'proxy', strtolower( $e->getMessage() ) );
		}
	}

	/**
	 * @dataProvider proxy_environments
	 * @param array<string, string> $env      Environment variables.
	 * @param string|null           $expected The variable that names the proxy, or null.
	 */
	public function test_the_proxy_variables_curl_reads_are_recognised( array $env, ?string $expected ): void {
		$this->assertSame( $expected, Transport_Choice::proxy_variable( $env ) );
	}

	/**
	 * @return array<string, array<int, mixed>>
	 */
	public function proxy_environments(): array {
		return array(
			'lower https' => array( array( 'https_proxy' => 'http://proxy.example.test:3128' ), 'https_proxy' ),
			'upper https' => array( array( 'HTTPS_PROXY' => 'http://proxy.example.test:3128' ), 'HTTPS_PROXY' ),
			'all proxy'   => array( array( 'ALL_PROXY' => 'http://proxy.example.test:3128' ), 'ALL_PROXY' ),
			'empty'       => array( array( 'https_proxy' => '' ), null ),
			'http only'   => array( array( 'http_proxy' => 'http://proxy.example.test:3128' ), null ),
			'nothing'     => array( array(), null ),
		);
	}

	public function test_the_proxy_provider_is_not_empty(): void {
		$this->assertCount( 6, $this->proxy_environments() );
	}

	public function test_an_option_curl_refuses_stops_the_request(): void {
		$this->skip_without_curl();
		$handle = curl_init();
		$this->assertNotFalse( $handle );

		$this->expectException( Checksum_Unavailable_Exception::class );
		$this->expectExceptionMessage( 'cURL refused an option' );
		Curl_Transport::apply_options( $handle, array( CURLOPT_SSLVERSION => 99 ) );
	}
}
