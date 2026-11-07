<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Http;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Http\Checksum_Client;
use WP_Doctor\Http\Checksum_Unavailable_Exception;
use WP_Doctor\Tests\Support\Fake_Transport;

final class Checksum_Client_Test extends TestCase {

	/**
	 * Builds a client over a transport that answers with a body.
	 *
	 * @param string $body Response body.
	 * @return array{0: Checksum_Client, 1: Fake_Transport}
	 */
	private function client_answering( string $body ): array {
		$transport       = new Fake_Transport();
		$transport->body = $body;

		return array( new Checksum_Client( $transport ), $transport );
	}

	public function test_the_checksums_of_a_release_are_returned_by_path_in_lowercase(): void {
		list( $client, $transport ) = $this->client_answering(
			(string) json_encode( // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
				array(
					'checksums' => array(
						'wp-login.php'            => 'ABCDEF0123456789ABCDEF0123456789',
						'wp-includes/version.php' => '0123456789abcdef0123456789abcdef',
					),
				)
			)
		);

		$checksums = $client->core_checksums( '6.5.2', 'en_US' );

		$this->assertSame(
			array(
				'wp-login.php'            => 'abcdef0123456789abcdef0123456789',
				'wp-includes/version.php' => '0123456789abcdef0123456789abcdef',
			),
			$checksums
		);
		$this->assertSame( array( 'https://api.wordpress.org/core/checksums/1.0/?version=6.5.2&locale=en_US' ), $transport->urls );
	}

	/**
	 * @dataProvider acceptable_versions
	 * @param string $version Version from a site.
	 */
	public function test_versions_wordpress_uses_are_looked_up( string $version ): void {
		list( $client, $transport ) = $this->client_answering( '{"checksums":{"a.php":"0123456789abcdef0123456789abcdef"}}' );

		$client->core_checksums( $version, 'de_DE' );

		$this->assertCount( 1, $transport->urls );
		$this->assertStringContainsString( 'version=' . rawurlencode( $version ), $transport->urls[0] );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function acceptable_versions(): array {
		return array(
			'major minor'       => array( '6.5' ),
			'point release'     => array( '6.5.2' ),
			'beta'              => array( '6.6-beta2' ),
			'release candidate' => array( '6.6-RC1' ),
		);
	}

	public function test_the_acceptable_version_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->acceptable_versions() );
	}

	/**
	 * @dataProvider refused_inputs
	 * @param string $version Version from a site.
	 * @param string $locale  Locale from a site.
	 */
	public function test_a_version_or_locale_that_is_not_a_plain_value_is_refused_before_any_request( string $version, string $locale ): void {
		list( $client, $transport ) = $this->client_answering( '{}' );

		try {
			$client->core_checksums( $version, $locale );
			$this->fail( 'No exception' );
		} catch ( Checksum_Unavailable_Exception $e ) {
			$this->assertSame( array(), $transport->urls );
		}
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function refused_inputs(): array {
		return array(
			'url injection in version' => array( '6.5&locale=x', 'en_US' ),
			'path in version'          => array( '../6.5', 'en_US' ),
			'space in version'         => array( '6.5 2', 'en_US' ),
			'empty version'            => array( '', 'en_US' ),
			'text version'             => array( 'latest', 'en_US' ),
			'newline in version'       => array( "6.5\n", 'en_US' ),
			'url injection in locale'  => array( '6.5.2', 'en_US&x=1' ),
			'lowercase region'         => array( '6.5.2', 'en_us' ),
			'empty locale'             => array( '6.5.2', '' ),
			'newline in locale'        => array( '6.5.2', "en_US\n" ),
			'variant with a newline'   => array( '6.5.2', "de_DE_formal\n" ),
			'variant in capitals'      => array( '6.5.2', 'de_DE_FORMAL' ),
			'variant with injection'   => array( '6.5.2', 'de_DE_formal&x=1' ),
			'three parts after region' => array( '6.5.2', 'de_DE_formal_x' ),
		);
	}

	/**
	 * @dataProvider accepted_locales
	 * @param string $locale Locale from a site.
	 */
	public function test_a_locale_with_a_region_or_a_variant_is_looked_up_as_given( string $locale ): void {
		list( $client, $transport ) = $this->client_answering( '{"checksums":{"wp-login.php":"' . str_repeat( 'a', 32 ) . '"}}' );

		$client->core_checksums( '6.5.2', $locale );

		$this->assertSame( array( 'https://api.wordpress.org/core/checksums/1.0/?version=6.5.2&locale=' . $locale ), $transport->urls );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function accepted_locales(): array {
		return array(
			'language and region'   => array( 'pt_BR' ),
			'language only'         => array( 'fr' ),
			'region and variant'    => array( 'de_DE_formal' ),
			'three letter language' => array( 'bel' ),
			'language and variant'  => array( 'art_xemoji' ),
		);
	}

	public function test_the_accepted_locale_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->accepted_locales() );
	}

	public function test_the_refused_input_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->refused_inputs() );
	}

	/**
	 * @dataProvider unusable_responses
	 * @param string $body Response body.
	 */
	public function test_a_response_that_is_not_a_usable_list_of_checksums_is_refused( string $body ): void {
		list( $client ) = $this->client_answering( $body );

		$this->expectException( Checksum_Unavailable_Exception::class );
		$client->core_checksums( '6.5.2', 'en_US' );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function unusable_responses(): array {
		$good = '0123456789abcdef0123456789abcdef';

		return array(
			'not json'            => array( 'not json' ),
			'json scalar'         => array( '5' ),
			'no checksums key'    => array( '{"other":1}' ),
			'checksums false'     => array( '{"checksums":false}' ),
			'empty checksums'     => array( '{"checksums":{}}' ),
			'hash too short'      => array( '{"checksums":{"a.php":"abc"}}' ),
			'hash not hex'        => array( '{"checksums":{"a.php":"zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz"}}' ),
			'hash with a newline' => array( '{"checksums":{"a.php":"' . $good . '\\n"}}' ),
			'hash not a string'   => array( '{"checksums":{"a.php":5}}' ),
			'path traversal'      => array( '{"checksums":{"../wp-config.php":"' . $good . '"}}' ),
			'embedded traversal'  => array( '{"checksums":{"wp-admin/../../x.php":"' . $good . '"}}' ),
			'absolute path'       => array( '{"checksums":{"/etc/passwd":"' . $good . '"}}' ),
			'empty path'          => array( '{"checksums":{"":"' . $good . '"}}' ),
			'nul in path'         => array( '{"checksums":{"a\u0000b.php":"' . $good . '"}}' ),
		);
	}

	public function test_the_unusable_response_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->unusable_responses() );
	}

	public function test_a_response_that_lists_far_too_many_files_is_refused(): void {
		$files = array();
		for ( $i = 0; $i <= 20000; $i++ ) {
			$files[ 'f' . $i . '.php' ] = '0123456789abcdef0123456789abcdef';
		}
		list( $client ) = $this->client_answering( (string) json_encode( array( 'checksums' => $files ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode

		$this->expectException( Checksum_Unavailable_Exception::class );
		$client->core_checksums( '6.5.2', 'en_US' );
	}

	public function test_a_transport_failure_is_passed_on(): void {
		$transport          = new Fake_Transport();
		$transport->failure = 'connection refused';

		$this->expectException( Checksum_Unavailable_Exception::class );
		$this->expectExceptionMessage( 'connection refused' );
		( new Checksum_Client( $transport ) )->core_checksums( '6.5.2', 'en_US' );
	}
}
