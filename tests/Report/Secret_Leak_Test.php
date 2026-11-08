<?php
/**
 * Seeded fake secrets never reach text or JSON output.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Report;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Formatter\Json_Formatter;
use WP_Doctor\Report\Formatter\Markdown_Escape;
use WP_Doctor\Report\Formatter\Markdown_Formatter;
use WP_Doctor\Report\Formatter\Text_Formatter;
use WP_Doctor\Report\Redacted_Report;
use WP_Doctor\Report\Redactor;
use WP_Doctor\Report\Report;
use WP_Doctor\Report\Report_Entry;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;
use WP_Doctor\Site\Config_Parser;
use WP_Doctor\Site\Site_Locator;

final class Secret_Leak_Test extends TestCase {

	/**
	 * Secrets from the plain fixture's wp-config.php.
	 *
	 * @return string[]
	 */
	private function fixture_secrets(): array {
		return array( 'fake-db-password-0001', 'fake-auth-key-0001', 'fake-secure-auth-key-0002', 'fake-auth-salt-0003' );
	}

	/**
	 * A report that mentions every secret in every field and several encodings.
	 *
	 * @return Report
	 */
	private function leaky_report(): Report {
		$mentions = array();
		foreach ( $this->fixture_secrets() as $secret ) {
			$mentions[] = $secret;
			$mentions[] = rawurlencode( $secret );
			$mentions[] = base64_encode( $secret );
			$mentions[] = substr( (string) json_encode( $secret ), 1, -1 );
		}
		$blob = implode( ' | ', $mentions );

		return new Report(
			"0.1.0 $blob",
			'6.5.2',
			array(
				new Report_Entry(
					'a.b',
					'security',
					Result::findings(
						new Finding(
							'a.b',
							Severity::critical(),
							"Title $blob",
							array(
								"key $blob" => "value $blob",
								'url'       => "mysql://user:{$this->fixture_secrets()[0]}@db.example.test/db",
								'names'     => array( "listed $blob", 'plain' ),
								'files'     => array(
									array(
										'path'   => "wp-content/$blob",
										'reason' => "reason $blob",
									),
								),
							),
							"Run: mysql -p{$this->fixture_secrets()[0]}\n$blob"
						)
					)
				),
				new Report_Entry( 'a.c', 'security', Result::skipped( "skipped $blob" ) ),
				new Report_Entry( 'a.d', 'security', Result::error( "error $blob" ) ),
			),
			array( "notice $blob" )
		);
	}

	public function test_the_fixture_really_contains_the_seeded_secrets(): void {
		$site = ( new Site_Locator( new Config_Parser() ) )->locate( dirname( __DIR__ ) . '/fixtures/sites/plain' );

		$read = array();
		foreach ( array( 'DB_PASSWORD', 'AUTH_KEY', 'SECURE_AUTH_KEY', 'AUTH_SALT' ) as $name ) {
			$read[] = $site->config()->value( $name );
		}

		$this->assertSame( $this->fixture_secrets(), $read );
	}

	public function test_no_seeded_secret_appears_in_text_output(): void {
		$site     = ( new Site_Locator( new Config_Parser() ) )->locate( dirname( __DIR__ ) . '/fixtures/sites/plain' );
		$redacted = new Redacted_Report( $this->leaky_report(), Redactor::for_config( $site->config() ) );

		foreach ( array( false, true ) as $colour ) {
			$output = ( new Text_Formatter( $colour ) )->format( $redacted );

			$this->assertStringContainsString( '[redacted]', $output );
			foreach ( $this->fixture_secrets() as $secret ) {
				$this->assertSame( false, strpos( $output, $secret ), 'A seeded secret appears in the text output' );
				$this->assertSame( false, strpos( $output, rawurlencode( $secret ) ), 'An encoded secret appears in the text output' );
				$this->assertSame( false, strpos( $output, base64_encode( $secret ) ), 'A base64 secret appears in the text output' );
			}
		}
	}

	public function test_no_seeded_secret_appears_in_json_output(): void {
		$site     = ( new Site_Locator( new Config_Parser() ) )->locate( dirname( __DIR__ ) . '/fixtures/sites/plain' );
		$redacted = new Redacted_Report( $this->leaky_report(), Redactor::for_config( $site->config() ) );

		$output = ( new Json_Formatter() )->format( $redacted );

		$this->assertStringContainsString( '[redacted]', $output );
		foreach ( $this->fixture_secrets() as $secret ) {
			$this->assertSame( false, strpos( $output, $secret ), 'A seeded secret appears in the JSON output' );
			$this->assertSame( false, strpos( $output, rawurlencode( $secret ) ), 'An encoded secret appears in the JSON output' );
			$this->assertSame( false, strpos( $output, base64_encode( $secret ) ), 'A base64 secret appears in the JSON output' );
			$this->assertSame( false, strpos( $output, substr( (string) json_encode( $secret ), 1, -1 ) ), 'A JSON-escaped secret appears in the JSON output' );
		}
	}

	public function test_no_seeded_secret_appears_in_markdown_output(): void {
		$site     = ( new Site_Locator( new Config_Parser() ) )->locate( dirname( __DIR__ ) . '/fixtures/sites/plain' );
		$redacted = new Redacted_Report( $this->leaky_report(), Redactor::for_config( $site->config() ) );

		$output = ( new Markdown_Formatter() )->format( $redacted );

		$this->assertStringContainsString( 'redacted', $output );
		foreach ( $this->fixture_secrets() as $secret ) {
			foreach ( array( $secret, Markdown_Escape::text( $secret ), rawurlencode( $secret ), base64_encode( $secret ) ) as $form ) {
				$this->assertSame( false, strpos( $output, $form ), 'A seeded secret appears in the Markdown output' );
			}
		}
	}

	public function test_a_redactor_built_without_the_config_would_leak_which_is_why_the_application_must_use_for_config(): void {
		$output = ( new Json_Formatter() )->format( new Redacted_Report( $this->leaky_report(), new Redactor() ) );

		$this->assertNotFalse( strpos( $output, 'fake-db-password-0001' ), 'The control run did not contain the seed' );
	}
}
