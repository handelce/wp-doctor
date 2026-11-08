<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Report\Formatter;

use JsonSchema\Constraints\Factory;
use JsonSchema\SchemaStorage;
use JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use WP_Doctor\Application;
use WP_Doctor\Check\Check_Registry;
use WP_Doctor\Db\Database_Driver;
use WP_Doctor\Db\Database_Exception;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Formatter\Json_Formatter;
use WP_Doctor\Report\Redacted_Report;
use WP_Doctor\Report\Redactor;
use WP_Doctor\Report\Report;
use WP_Doctor\Report\Report_Entry;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;
use WP_Doctor\Tests\Support\Buffer_Console;
use WP_Doctor\Tests\Support\Sample_Report;

/**
 * Every JSON report must validate against docs/report.schema.json, the schema
 * that version 1 of the document promises.
 */
final class Json_Schema_Test extends TestCase {

	private const SCHEMA_ID = 'https://github.com/handelce/wp-doctor/blob/main/docs/report.schema.json';

	/**
	 * Errors from validating a document against the schema. Empty when it is valid.
	 *
	 * @param string $json Document to validate.
	 * @return string[]
	 */
	private static function violations( string $json ): array {
		$storage = new SchemaStorage();
		$storage->addSchema( self::SCHEMA_ID, json_decode( (string) file_get_contents( dirname( __DIR__, 3 ) . '/docs/report.schema.json' ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the schema from the repository.
		$validator = new Validator( new Factory( $storage ) );
		$document  = json_decode( $json );
		$validator->validate( $document, (object) array( '$ref' => self::SCHEMA_ID ) );
		$errors = array();
		foreach ( $validator->getErrors() as $error ) {
			$errors[] = $error['property'] . ': ' . $error['message'];
		}

		return $errors;
	}

	/**
	 * A report encoded as the JSON formatter writes it.
	 *
	 * @param Report $report Report to encode.
	 * @return string
	 */
	private static function json( Report $report ): string {
		return ( new Json_Formatter() )->format( new Redacted_Report( $report, new Redactor() ) );
	}

	/**
	 * @return array<string, array{0: Report}>
	 */
	public function sample_reports(): array {
		return array(
			'every status and severity' => array( Sample_Report::mixed() ),
			'evidence lists'            => array( Sample_Report::with_lists() ),
			'clean'                     => array( Sample_Report::clean() ),
			'an ignored check'          => array(
				new Report(
					'1.0.0',
					null,
					array( new Report_Entry( 'sec.uploads.php', 'security', Result::findings( new Finding( 'sec.uploads.php', Severity::critical(), 'Shell found', array( 'file' => 'x.php' ), 'Remove it.' ) ) ) ),
					array(),
					array( 'sec.uploads.php' )
				),
			),
		);
	}

	/**
	 * @dataProvider sample_reports
	 * @param Report $report Report to encode.
	 */
	public function test_sample_reports_validate( Report $report ): void {
		$this->assertSame( array(), self::violations( self::json( $report ) ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function fixture_sites(): array {
		$sites = array();
		foreach ( (array) glob( dirname( __DIR__, 2 ) . '/fixtures/sites/*', GLOB_ONLYDIR ) as $site ) {
			$sites[ basename( (string) $site ) ] = array( (string) $site );
		}

		return $sites;
	}

	/**
	 * @dataProvider fixture_sites
	 * @param string $site Fixture site directory.
	 */
	public function test_a_real_scan_of_each_fixture_site_validates( string $site ): void {
		$console = new Buffer_Console();
		$app     = new Application(
			Check_Registry::built_in(),
			$console,
			array(),
			static function (): Database_Driver {
				throw new Database_Exception( 'no database in this test' );
			}
		);

		$code = $app->run( array( 'scan', $site, '--offline', '--format=json' ) );

		if ( '' === $console->out ) {
			$this->assertSame( 3, $code, 'A scan that printed no report must have failed to find a site' );
			$this->assertNotSame( '', $console->err );
			return;
		}
		$this->assertSame( array(), self::violations( $console->out ) );
		$this->assertGreaterThan( 30, count( (array) json_decode( $console->out, true )['checks'] ) );
	}

	public function test_the_providers_have_samples(): void {
		$this->assertCount( 4, $this->sample_reports() );
		$this->assertGreaterThan( 5, count( $this->fixture_sites() ) );
	}

	/**
	 * @return array<string, array{0: callable}>
	 */
	public function broken_documents(): array {
		return array(
			'a nested list in evidence'        => array(
				static function ( array $doc ): array {
					$doc['findings'][0]['evidence']['bad'] = array( array( 'x' ) );
					return $doc;
				},
			),
			'an unknown severity'              => array(
				static function ( array $doc ): array {
					$doc['findings'][0]['severity'] = 'severe';
					return $doc;
				},
			),
			'a skipped check without a reason' => array(
				static function ( array $doc ): array {
					unset( $doc['checks'][3]['reason'] );
					return $doc;
				},
			),
			'an extra top-level key'           => array(
				static function ( array $doc ): array {
					$doc['extra'] = true;
					return $doc;
				},
			),
			'an error without a message'       => array(
				static function ( array $doc ): array {
					unset( $doc['checks'][4]['message'] );
					return $doc;
				},
			),
			'ignored without findings'         => array(
				static function ( array $doc ): array {
					$doc['checks'][0]['ignored'] = true;
					return $doc;
				},
			),
			'schema version 0'                 => array(
				static function ( array $doc ): array {
					$doc['schema_version'] = 0;
					return $doc;
				},
			),
		);
	}

	/**
	 * @dataProvider broken_documents
	 * @param callable $breaker Changes a valid document into one the schema must refuse.
	 */
	public function test_the_schema_refuses_documents_that_break_it( callable $breaker ): void {
		$document = json_decode( self::json( Sample_Report::mixed() ), true );
		$this->assertSame( 'skipped', $document['checks'][3]['status'] );

		$this->assertNotSame( array(), self::violations( (string) json_encode( $breaker( $document ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}
