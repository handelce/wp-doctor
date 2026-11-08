<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Security;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Security\Plugin_Checksums_Check;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Fake_Checksum_Source;
use WP_Doctor\Tests\Support\File_Modes;
use WP_Doctor\Tests\Support\Temp_Tree;

/**
 * The planted change is inert: it adds only a comment.
 */
final class Plugin_Checksums_Check_Test extends TestCase {

	private const PLUGINS = 'wp-content/plugins';
	private const MAIN    = "<?php\n/*\n * Plugin Name: Example Forms\n * Version: 2.1.0\n */\n";
	private const HELPER  = "<?php\nfunction example_forms_render() {}\n";
	private const HELLO   = "<?php\n/*\nPlugin Name: Hello Dolly\nVersion: 1.7.2\n*/\n";

	/**
	 * @var Temp_Tree
	 */
	private $tree;

	/**
	 * @var Fake_Checksum_Source
	 */
	private $source;

	protected function setUp(): void {
		$this->tree   = new Temp_Tree();
		$this->source = new Fake_Checksum_Source();
		$this->tree->file( self::PLUGINS . '/index.php', "<?php\n// Silence is golden.\n" );
		$this->tree->file( self::PLUGINS . '/example-forms/example-forms.php', self::MAIN );
		$this->tree->file( self::PLUGINS . '/example-forms/inc/render.php', self::HELPER );
		$this->tree->file( self::PLUGINS . '/example-forms/readme.txt', "=== Example Forms ===\nEdited by the site owner.\n" );
		$this->source->plugins['example-forms/2.1.0'] = array(
			'example-forms.php' => self::hashes( self::MAIN ),
			'inc/render.php'    => array(
				'sha256' => array(),
				'md5'    => array( md5( self::HELPER ) ),
			),
			'readme.txt'        => self::hashes( 'the published readme' ),
		);
	}

	protected function tearDown(): void {
		$this->tree->remove();
	}

	/**
	 * Hashes of a text as wordpress.org lists them.
	 *
	 * @param string $text File content.
	 * @return array{sha256: string[], md5: string[]}
	 */
	private static function hashes( string $text ): array {
		return array(
			'sha256' => array( hash( 'sha256', $text ) ),
			'md5'    => array( md5( $text ) ),
		);
	}

	/**
	 * Runs the check with the fake source.
	 *
	 * @return Result
	 */
	private function run_check(): Result {
		return ( new Plugin_Checksums_Check() )->run( Context_Factory::for_tree( $this->tree )->with_checksums( $this->source ) );
	}

	/**
	 * Path and problem of each file in the finding.
	 *
	 * @return array<string, string>
	 */
	private function problems(): array {
		$evidence = $this->run_check()->finding_list()[0]->evidence();
		$problems = array();
		foreach ( $evidence['files'] as $file ) {
			$problems[ $file['path'] ] = $file['problem'];
		}
		$this->assertNotSame( array(), $problems );

		return $problems;
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Plugin_Checksums_Check();

		$this->assertSame( 'sec.plugins.checksums', $check->id() );
		$this->assertSame( 'security', $check->category() );
	}

	public function test_a_plugin_matching_its_release_passes_with_an_edited_readme_and_the_version_from_its_header(): void {
		$this->assertTrue( $this->run_check()->is_pass() );
		$this->assertSame( array( 'example-forms/2.1.0' ), $this->source->plugin_requests );
	}

	public function test_a_changed_file_and_an_added_file_are_a_warning(): void {
		$this->tree->file( self::PLUGINS . '/example-forms/inc/render.php', self::HELPER . "// inert test marker\n" );
		$this->tree->file( self::PLUGINS . '/example-forms/inc/cache.php', "<?php\n// inert test marker\n" );

		$finding = $this->run_check()->finding_list()[0];

		$evidence = $finding->evidence();
		$this->assertSame( 'warning', $finding->severity()->name() );
		$this->assertSame( 1, $evidence['plugins_checked'] );
		$this->assertSame( 1, $evidence['files_changed'] );
		$this->assertSame( 1, $evidence['files_added'] );
		$this->assertSame( 'example-forms 2.1.0', $evidence['files'][0]['plugin'] );
		$this->assertSame(
			array(
				self::PLUGINS . '/example-forms/inc/cache.php'  => 'added',
				self::PLUGINS . '/example-forms/inc/render.php' => 'changed',
			),
			$this->problems()
		);
		$this->assertStringContainsString( 'wp plugin install <slug> --version=<version> --force', $finding->fix() );
	}

	public function test_any_of_several_published_hashes_is_accepted(): void {
		$this->source->plugins['example-forms/2.1.0']['inc/render.php']['md5'] = array( str_repeat( 'a', 32 ), md5( self::HELPER ) );

		$this->assertTrue( $this->run_check()->is_pass() );
	}

	public function test_a_plugin_wordpress_org_does_not_publish_makes_a_clean_result_a_skip_naming_it(): void {
		$this->tree->file( self::PLUGINS . '/example-premium/example-premium.php', "<?php\n/* Plugin Name: Example Premium\nVersion: 3.0 */\n" );

		$result = $this->run_check();

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( '1 plugin(s) checked', (string) $result->message() );
		$this->assertStringContainsString( 'example-premium', (string) $result->message() );
	}

	public function test_plugins_that_were_not_checked_are_listed_beside_a_finding(): void {
		$this->tree->file( self::PLUGINS . '/example-forms/inc/render.php', self::HELPER . "// inert test marker\n" );
		$this->tree->file( self::PLUGINS . '/example-premium/example-premium.php', "<?php\n/* Plugin Name: Example Premium\nVersion: 3.0 */\n" );
		$this->tree->file( self::PLUGINS . '/example-noversion/example-noversion.php', "<?php\n/* Plugin Name: Example No Version */\n" );
		$this->tree->file( self::PLUGINS . '/single.php', "<?php\n/* Plugin Name: Single */\n" );
		$this->source->plugin_failures['example-down'] = 'The request failed: timed out';
		$this->tree->file( self::PLUGINS . '/example-down/example-down.php', "<?php\n/* Plugin Name: Example Down\nVersion: 1.0 */\n" );

		$evidence = $this->run_check()->finding_list()[0]->evidence();

		$this->assertSame( 4, $evidence['plugins_not_checked'] );
		$this->assertTrue( $evidence['scan_incomplete'] );
		$listed = $evidence['not_checked'];
		$this->assertSame( array( 'example-down', 'example-noversion', 'example-premium', 'single.php' ), array_column( $listed, 'plugin' ) );
		$this->assertStringStartsWith( 'the checksums could not be fetched', $listed[0]['reason'] );
		$this->assertSame( 'no plugin file with a Version header', $listed[1]['reason'] );
		$this->assertStringStartsWith( 'version 3.0 is not published on wordpress.org', $listed[2]['reason'] );
		$this->assertStringStartsWith( 'a single-file plugin', $listed[3]['reason'] );
	}

	public function test_hello_dolly_is_compared_with_the_copy_in_the_installed_release(): void {
		$this->tree->file( self::PLUGINS . '/hello.php', self::HELLO );
		$this->source->checksums = array( 'wp-content/plugins/hello.php' => md5( self::HELLO ) );

		$this->assertTrue( $this->run_check()->is_pass() );
	}

	public function test_hello_dolly_left_by_an_earlier_release_is_accepted(): void {
		$this->tree->file( self::PLUGINS . '/hello.php', self::HELLO );
		$this->source->releases             = array( '6.4.5', '6.5.1', '6.5.2', '6.5.3' );
		$this->source->checksums            = array( 'wp-content/plugins/hello.php' => str_repeat( 'a', 32 ) );
		$this->source->checksums_by_version = array( '6.4.5' => array( 'wp-content/plugins/hello.php' => md5( self::HELLO ) ) );

		$this->assertTrue( $this->run_check()->is_pass() );
		$this->assertSame( array( '6.5.2', '6.4.5' ), array_column( $this->source->requests, 0 ) );
	}

	public function test_hello_dolly_matching_no_release_is_reported_as_changed(): void {
		$this->tree->file( self::PLUGINS . '/hello.php', self::HELLO . "// inert test marker\n" );
		$this->source->releases  = array( '6.4.5', '6.5.2' );
		$this->source->checksums = array( 'wp-content/plugins/hello.php' => md5( self::HELLO ) );

		$this->assertSame( array( self::PLUGINS . '/hello.php' => 'changed' ), $this->problems() );
	}

	public function test_hello_dolly_is_not_judged_changed_when_earlier_releases_could_not_be_fetched(): void {
		$this->tree->file( self::PLUGINS . '/hello.php', self::HELLO );
		$this->source->checksums        = array( 'wp-content/plugins/hello.php' => str_repeat( 'a', 32 ) );
		$this->source->releases_failure = 'The request failed: no route to host';

		$result = $this->run_check();

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'hello.php', (string) $result->message() );
	}

	public function test_an_unreadable_plugin_file_makes_a_clean_result_a_skip(): void {
		File_Modes::require_enforced_modes();
		$path = $this->tree->site() . '/' . self::PLUGINS . '/example-forms/inc/render.php';
		chmod( $path, 0000 );

		$result = $this->run_check();

		chmod( $path, 0644 );
		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'example-forms/inc/render.php', (string) $result->message() );
	}

	public function test_offline_it_is_skipped(): void {
		$result = ( new Plugin_Checksums_Check() )->run( Context_Factory::for_tree( $this->tree )->with_checksums( null, '--offline was given' ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertSame( '--offline was given', $result->message() );
	}

	public function test_a_site_without_plugins_is_a_skip(): void {
		$this->tree->remove_path( self::PLUGINS . '/example-forms' );

		$this->assertSame( Result::SKIPPED, $this->run_check()->status() );
	}
}
