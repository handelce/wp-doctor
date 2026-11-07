<?php
/**
 * Tests for Tool_Config.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Cli;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Cli\Config_File_Exception;
use WP_Doctor\Cli\Tool_Config;

final class Tool_Config_Test extends TestCase {

	public function test_the_documented_example_is_accepted(): void {
		$config = Tool_Config::from_json(
			'{"thresholds":{"perf.autoload.size":{"warning":1048576,"critical":3145728}},"ignore":["config.xmlrpc.enabled"],"exclude":["wp-content/cache/**"]}',
			'wp-doctor.json'
		);

		$this->assertSame( array( 'wp-content/cache/**' ), $config->excludes() );
		$this->assertSame( array( 'config.xmlrpc.enabled' ), $config->ignore() );
		$this->assertSame(
			array(
				'perf.autoload.size' => array(
					'warning'  => 1048576,
					'critical' => 3145728,
				),
			),
			$config->thresholds()
		);
		$config->assert_check_ids( array( 'perf.autoload.size', 'config.xmlrpc.enabled' ) );
	}

	public function test_a_leading_byte_order_mark_is_accepted(): void {
		$config = Tool_Config::from_json( "\xef\xbb\xbf" . '{"exclude":["wp-content/cache/**"]}', 'wp-doctor.json' );

		$this->assertSame( array( 'wp-content/cache/**' ), $config->excludes() );
	}

	/**
	 * @dataProvider duplicated_keys
	 * @param string $json Document with a key given twice.
	 * @param string $key  Key the message must name.
	 */
	public function test_a_key_given_twice_is_refused_naming_it( string $json, string $key ): void {
		$this->expectException( Config_File_Exception::class );
		$this->expectExceptionMessage( "Key \"$key\" appears more than once in custom.json" );
		Tool_Config::from_json( $json, 'custom.json' );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function duplicated_keys(): array {
		return array(
			'top level'        => array( '{"ignore":["a.b.c"],"ignore":[]}', 'ignore' ),
			'threshold id'     => array( '{"thresholds":{"perf.autoload.size":{"warning":1},"perf.autoload.size":{"critical":9}}}', 'perf.autoload.size' ),
			'threshold level'  => array( '{"thresholds":{"perf.autoload.size":{"warning":1, "warning" :2}}}', 'warning' ),
			'escaped spelling' => array( '{"exclude":[],"\\u0065xclude":[]}', 'exclude' ),
		);
	}

	public function test_the_duplicated_keys_provider_is_not_empty(): void {
		$this->assertCount( 4, $this->duplicated_keys() );
	}

	public function test_the_same_key_in_sibling_objects_is_not_a_duplicate(): void {
		$config = Tool_Config::from_json( '{"thresholds":{"perf.autoload.size":{"warning":1},"perf.cron.overdue":{"warning":2}},"exclude":["{\\"warning\\":1,\\"warning\\":2}"]}', 'wp-doctor.json' );

		$this->assertSame( 2, count( $config->thresholds() ) );
		$this->assertSame( array( '{"warning":1,"warning":2}' ), $config->excludes() );
	}

	public function test_an_empty_object_is_accepted_and_changes_nothing(): void {
		$config = Tool_Config::from_json( '{}', 'wp-doctor.json' );

		$this->assertSame( array(), $config->excludes() );
		$this->assertSame( array(), $config->ignore() );
		$this->assertSame( array(), $config->thresholds() );
	}

	public function test_an_ignored_id_that_no_check_has_is_refused_naming_the_key_and_file(): void {
		$config = Tool_Config::from_json( '{"ignore":["perf.autolaod.size"]}', 'custom.json' );

		$this->expectException( Config_File_Exception::class );
		$this->expectExceptionMessage( '"perf.autolaod.size" in "ignore" is not a check this version knows (custom.json)' );
		$config->assert_check_ids( array( 'perf.autoload.size' ) );
	}

	public function test_a_threshold_id_that_no_check_has_is_refused(): void {
		$config = Tool_Config::from_json( '{"thresholds":{"perf.nothing.here":{"warning":1}}}', 'custom.json' );

		$this->expectException( Config_File_Exception::class );
		$this->expectExceptionMessage( '"perf.nothing.here" in "thresholds"' );
		$config->assert_check_ids( array( 'perf.autoload.size' ) );
	}

	public function test_the_empty_config_has_no_settings(): void {
		$config = Tool_Config::none();

		$this->assertSame( array(), $config->excludes() );
		$this->assertNull( $config->source() );
	}

	public function test_the_source_is_kept_for_the_report(): void {
		$this->assertSame( 'the scanned site', Tool_Config::from_json( '{}', 'the scanned site' )->source() );
	}

	/**
	 * @dataProvider invalid_documents
	 * @param string $json     Content of the config file.
	 * @param string $fragment Text the message must contain.
	 */
	public function test_an_invalid_document_is_rejected_with_a_reason( string $json, string $fragment ): void {
		try {
			Tool_Config::from_json( $json, 'wp-doctor.json' );
			$this->fail( 'Accepted: ' . $json );
		} catch ( Config_File_Exception $e ) {
			$this->assertStringContainsString( $fragment, $e->getMessage() );
		}
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function invalid_documents(): array {
		return array(
			'not json'                  => array( '{nope', 'not valid JSON' ),
			'empty file'                => array( '', 'not valid JSON' ),
			'a string'                  => array( '"exclude"', 'must be a JSON object' ),
			'a list'                    => array( '["exclude"]', 'must be a JSON object' ),
			'unknown key'               => array( '{"exlcude":["a"]}', 'Unknown key "exlcude"' ),
			'unknown nested key'        => array( '{"thresholds":{"perf.autoload.size":{"warn":1}}}', 'Unknown key "warn"' ),
			'exclude is a string'       => array( '{"exclude":"a/**"}', '"exclude" must be a list' ),
			'exclude holds a number'    => array( '{"exclude":[5]}', '"exclude"' ),
			'exclude holds an empty'    => array( '{"exclude":[""]}', '"exclude"' ),
			'exclude has a nul'         => array( '{"exclude":["a\u0000b"]}', '"exclude"' ),
			'ignore is an object'       => array( '{"ignore":{"a.b":1}}', '"ignore" must be a list' ),
			'ignore is not an id'       => array( '{"ignore":["Not An Id"]}', 'check id' ),
			'threshold id is not an id' => array( '{"thresholds":{"nope":{"warning":1}}}', 'check id' ),
			'threshold not an object'   => array( '{"thresholds":{"perf.a.b":5}}', 'must be an object' ),
			'threshold is a string'     => array( '{"thresholds":{"perf.a.b":{"warning":"1"}}}', 'positive integer' ),
			'threshold is zero'         => array( '{"thresholds":{"perf.a.b":{"warning":0}}}', 'positive integer' ),
			'threshold is negative'     => array( '{"thresholds":{"perf.a.b":{"critical":-1}}}', 'positive integer' ),
			'threshold is a float'      => array( '{"thresholds":{"perf.a.b":{"warning":1.5}}}', 'positive integer' ),
			'warning above critical'    => array( '{"thresholds":{"perf.a.b":{"warning":5,"critical":2}}}', 'below' ),
		);
	}

	public function test_the_invalid_provider_is_not_empty(): void {
		$this->assertGreaterThan( 10, count( $this->invalid_documents() ) );
	}

	public function test_an_error_message_escapes_control_characters_from_the_document(): void {
		try {
			Tool_Config::from_json( '{"bad\u001bkey":1}', 'wp-doctor.json' );
			$this->fail( 'Accepted a document with an unknown key' );
		} catch ( Config_File_Exception $e ) {
			$this->assertStringNotContainsString( "\033", $e->getMessage() );
			$this->assertStringContainsString( '\x1B', $e->getMessage() );
		}
	}

	public function test_an_oversized_document_is_rejected(): void {
		$this->expectException( Config_File_Exception::class );
		Tool_Config::from_json( '{"exclude":["' . str_repeat( 'a', 70000 ) . '"]}', 'wp-doctor.json' );
	}

	/**
	 * @dataProvider unusable_shapes
	 * @param string $json     Document.
	 * @param string $fragment Expected part of the message.
	 */
	public function test_a_shape_that_sets_nothing_or_is_not_an_object_is_refused( string $json, string $fragment ): void {
		$this->expectException( Config_File_Exception::class );
		$this->expectExceptionMessage( $fragment );
		Tool_Config::from_json( $json, 'custom.json' );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function unusable_shapes(): array {
		return array(
			'root list'        => array( '[]', 'custom.json must be a JSON object' ),
			'thresholds null'  => array( '{"thresholds":null}', '"thresholds" must be an object' ),
			'thresholds list'  => array( '{"thresholds":[]}', '"thresholds" must be an object' ),
			'empty levels'     => array( '{"thresholds":{"perf.autoload.size":{}}}', 'The thresholds for "perf.autoload.size" set no level' ),
			'levels as a list' => array( '{"thresholds":{"perf.autoload.size":[]}}', 'The thresholds for "perf.autoload.size" must be an object' ),
		);
	}

	public function test_the_unusable_shapes_provider_is_not_empty(): void {
		$this->assertCount( 5, $this->unusable_shapes() );
	}

	public function test_an_absolute_exclude_in_the_file_is_refused(): void {
		$this->expectException( Config_File_Exception::class );
		$this->expectExceptionMessage( '"/var/www/example/cache" in "exclude" is an absolute path' );
		Tool_Config::from_json( '{"exclude":["/var/www/example/cache"]}', 'custom.json' );
	}
}
