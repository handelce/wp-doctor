<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Http;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Http\Checksum_Client;
use WP_Doctor\Http\Checksum_Unavailable_Exception;
use WP_Doctor\Http\Stream_Transport;

final class Stream_Transport_Test extends TestCase {

	/**
	 * @dataProvider urls_that_are_not_https
	 * @param string $url Address with a scheme other than https.
	 */
	public function test_only_https_urls_are_fetched( string $url ): void {
		$this->expectException( Checksum_Unavailable_Exception::class );
		( new Stream_Transport() )->get( $url, 1000 );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function urls_that_are_not_https(): array {
		return array(
			'http'       => array( 'http://api.wordpress.org/core/checksums/1.0/' ),
			'file'       => array( 'file:///etc/passwd' ),
			'php filter' => array( 'php://filter/resource=/etc/passwd' ),
			'data'       => array( 'data://text/plain;base64,SGVsbG8=' ),
			'empty'      => array( '' ),
		);
	}

	public function test_the_url_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->urls_that_are_not_https() );
	}

	public function test_a_host_that_cannot_be_reached_is_an_exception_and_prints_no_php_warning(): void {
		$this->expectException( Checksum_Unavailable_Exception::class );
		( new Stream_Transport() )->get( 'https://no-such-host.invalid/', 1000 );
	}

	public function test_a_failed_request_names_its_own_error_not_an_earlier_silenced_one(): void {
		@trigger_error( 'earlier unrelated warning', E_USER_WARNING ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
		$this->assertSame( 'earlier unrelated warning', error_get_last()['message'] ?? null );

		try {
			( new Stream_Transport() )->get( 'https://no-such-host.invalid/', 1000 );
			$this->fail( 'The request to an unreachable host succeeded' );
		} catch ( Checksum_Unavailable_Exception $e ) {
			$this->assertStringStartsWith( 'The request failed: ', $e->getMessage() );
			$this->assertStringNotContainsString( 'earlier unrelated', $e->getMessage() );
			$this->assertStringContainsString( 'no-such-host.invalid', $e->getMessage() );
		}
	}

	public function test_requests_verify_the_certificate_do_not_follow_redirects_and_identify_the_tool(): void {
		$options = ( new Stream_Transport( 'wp-doctor-tests' ) )->stream_options();

		$this->assertTrue( $options['ssl']['verify_peer'] );
		$this->assertTrue( $options['ssl']['verify_peer_name'] );
		$this->assertSame( 0, $options['http']['follow_location'] );
		$this->assertSame( 'GET', $options['http']['method'] );
		$this->assertStringContainsString( 'User-Agent: wp-doctor-tests', $options['http']['header'] );
	}

	public function test_a_complete_answer_within_the_limit_is_accepted(): void {
		$this->assertSame( 'abc', Stream_Transport::accepted_body( 200, 'abc', 3 ) );
	}

	/**
	 * @dataProvider unacceptable_answers
	 * @param int          $status HTTP status.
	 * @param string|false $body   Body read.
	 */
	public function test_an_answer_that_is_not_a_complete_200_within_the_limit_is_refused( int $status, $body ): void {
		$this->expectException( Checksum_Unavailable_Exception::class );
		Stream_Transport::accepted_body( $status, $body, 3 );
	}

	/**
	 * @return array<string, array<int, mixed>>
	 */
	public function unacceptable_answers(): array {
		return array(
			'not found'        => array( 404, 'abc' ),
			'server error'     => array( 500, 'abc' ),
			'redirect'         => array( 301, 'abc' ),
			'no status'        => array( 0, 'abc' ),
			'one byte too big' => array( 200, 'abcd' ),
			'empty'            => array( 200, '' ),
			'read failed'      => array( 200, false ),
		);
	}

	public function test_the_unacceptable_answer_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->unacceptable_answers() );
	}

	public function test_the_real_wordpress_org_answers_for_a_known_release(): void {
		$enabled = getenv( 'WPD_TEST_NETWORK' );
		if ( false === $enabled || '' === $enabled ) {
			$this->markTestSkipped( 'Set WPD_TEST_NETWORK=1 to contact api.wordpress.org.' );
		}

		$checksums = ( new Checksum_Client( new Stream_Transport( 'wp-doctor-tests' ) ) )->core_checksums( '6.5.2', 'en_US' );

		$this->assertGreaterThan( 1000, count( $checksums ) );
		$this->assertArrayHasKey( 'wp-login.php', $checksums );
		$this->assertArrayHasKey( 'wp-includes/version.php', $checksums );
	}

	public function test_a_body_that_arrives_too_slowly_is_refused_at_the_deadline(): void {
		$handle = fopen( 'php://temp', 'w+b' );
		$this->assertNotFalse( $handle );
		fwrite( $handle, str_repeat( 'x', 40000 ) );
		rewind( $handle );
		$clock = 0.0;
		$now   = static function () use ( &$clock ): float {
			$clock += 6.0;
			return $clock;
		};

		try {
			$this->expectException( Checksum_Unavailable_Exception::class );
			$this->expectExceptionMessage( 'did not finish within 15 seconds' );
			Stream_Transport::read_body( $handle, 100000, 15.0, $now );
		} finally {
			fclose( $handle );
		}
	}

	public function test_a_body_read_in_time_is_returned_whole(): void {
		$handle = fopen( 'php://temp', 'w+b' );
		$this->assertNotFalse( $handle );
		fwrite( $handle, str_repeat( 'y', 20000 ) );
		rewind( $handle );

		$body = Stream_Transport::read_body(
			$handle,
			100000,
			15.0,
			static function (): float {
				return 1.0;
			}
		);
		fclose( $handle );

		$this->assertSame( 20000, strlen( (string) $body ) );
	}

	public function test_reading_stops_one_byte_past_the_limit(): void {
		$handle = fopen( 'php://temp', 'w+b' );
		$this->assertNotFalse( $handle );
		fwrite( $handle, str_repeat( 'z', 50000 ) );
		rewind( $handle );

		$body = Stream_Transport::read_body(
			$handle,
			100,
			15.0,
			static function (): float {
				return 1.0;
			}
		);
		fclose( $handle );

		$this->assertSame( 101, strlen( (string) $body ) );
	}
}
