<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Security;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Security\Inactive_Plugins_Check;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\File_Modes;
use WP_Doctor\Tests\Support\Recording_Driver;
use WP_Doctor\Tests\Support\Temp_Tree;
use WP_Doctor\Util\Malformed_Serialization_Exception;

final class Inactive_Plugins_Check_Test extends TestCase {

	/**
	 * Site whose plugins directory holds the plugins on disk.
	 *
	 * @var Temp_Tree
	 */
	private $tree;

	protected function setUp(): void {
		$this->tree = new Temp_Tree();
	}

	protected function tearDown(): void {
		$this->tree->remove();
	}

	/**
	 * Runs the check with a given active_plugins value.
	 *
	 * @param string $value  Serialized active_plugins option.
	 * @param string $config wp-config.php statements.
	 * @return Result
	 */
	private function run_with_active( string $value, string $config = '' ): Result {
		$driver           = new Recording_Driver();
		$driver->rows_for = array(
			'LENGTH(option_value)' => array( array( 'size_bytes' => (string) strlen( $value ) ) ),
			'SELECT option_value'  => array( array( 'option_value' => $value ) ),
		);

		return ( new Inactive_Plugins_Check() )->run( Context_Factory::for_tree_with_driver( $this->tree, $driver, $config ) );
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Inactive_Plugins_Check();

		$this->assertSame( 'sec.plugins.inactive', $check->id() );
		$this->assertSame( 'security', $check->category() );
	}

	public function test_plugins_that_are_all_active_pass(): void {
		$this->tree->file( 'wp-content/plugins/akismet/akismet.php' );
		$this->tree->file( 'wp-content/plugins/hello.php' );
		$this->tree->file( 'wp-content/plugins/index.php' );

		$result = $this->run_with_active( serialize( array( 'akismet/akismet.php', 'hello.php' ) ) );

		$this->assertTrue( $result->is_pass() );
	}

	public function test_hidden_folders_and_files_are_not_plugins(): void {
		$this->tree->file( 'wp-content/plugins/akismet/akismet.php' );
		$this->tree->file( 'wp-content/plugins/.git/HEAD' );
		$this->tree->file( 'wp-content/plugins/.hidden.php' );
		$this->tree->file( 'wp-content/plugins/gone/gone.php' );

		$result = $this->run_with_active( serialize( array( 'akismet/akismet.php' ) ) );

		$evidence = $result->finding_list()[0]->evidence();
		$this->assertContains( 'gone', $evidence['plugins'] );
		$this->assertNotContains( '.git', $evidence['plugins'] );
		$this->assertNotContains( '.hidden.php', $evidence['plugins'] );
	}

	public function test_plugins_on_disk_that_are_not_active_are_listed_by_name(): void {
		$this->tree->file( 'wp-content/plugins/akismet/akismet.php' );
		$this->tree->file( 'wp-content/plugins/old-plugin/old.php' );
		$this->tree->file( 'wp-content/plugins/another/another.php' );
		$this->tree->file( 'wp-content/plugins/hello.php' );
		$this->tree->file( 'wp-content/plugins/index.php' );
		$this->tree->file( 'wp-content/plugins/readme.txt' );

		$finding = $this->run_with_active( serialize( array( 'akismet/akismet.php' ) ) )->finding_list()[0];

		$evidence = $finding->evidence();
		$this->assertSame( 'info', $finding->severity()->name() );
		$this->assertSame( 4, $evidence['plugins_on_disk'] );
		$this->assertSame( 3, $evidence['inactive'] );
		$this->assertSame( 'another', $evidence['plugins'][0] );
		$this->assertSame( 'hello.php', $evidence['plugins'][1] );
		$this->assertSame( 'old-plugin', $evidence['plugins'][2] );
		$this->assertNotSame( '', $finding->fix() );
	}

	public function test_an_active_entry_that_is_not_on_disk_is_ignored(): void {
		$this->tree->file( 'wp-content/plugins/akismet/akismet.php' );

		$this->assertTrue( $this->run_with_active( serialize( array( 'akismet/akismet.php', 'removed/removed.php' ) ) )->is_pass() );
	}

	public function test_an_empty_active_list_makes_every_plugin_inactive(): void {
		$this->tree->file( 'wp-content/plugins/akismet/akismet.php' );

		$this->assertSame( 1, $this->run_with_active( serialize( array() ) )->finding_list()[0]->evidence()['inactive'] );
	}

	public function test_a_multisite_network_is_skipped(): void {
		$result = $this->run_with_active( serialize( array() ), "define( 'MULTISITE', true );" );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'multisite', (string) $result->message() );
	}

	public function test_a_missing_plugins_directory_is_skipped(): void {
		$result = $this->run_with_active( serialize( array() ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'wp-content/plugins', (string) $result->message() );
	}

	public function test_a_missing_active_plugins_option_is_skipped(): void {
		$this->tree->file( 'wp-content/plugins/akismet/akismet.php' );

		$result = ( new Inactive_Plugins_Check() )->run( Context_Factory::for_tree_with_driver( $this->tree, new Recording_Driver() ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
	}

	public function test_an_active_plugins_value_with_an_object_is_an_error(): void {
		$this->tree->file( 'wp-content/plugins/akismet/akismet.php' );

		$this->expectException( Malformed_Serialization_Exception::class );
		$this->run_with_active( 'a:1:{i:0;O:8:"stdClass":0:{}}' );
	}

	public function test_it_is_skipped_without_a_database(): void {
		$context = Context_Factory::for_tree( $this->tree );

		$this->assertSame( Result::SKIPPED, ( new Inactive_Plugins_Check() )->run( $context )->status() );
	}

	public function test_a_multisite_setting_that_could_not_be_read_is_skipped(): void {
		$this->tree->file( 'wp-content/plugins/akismet/akismet.php' );

		$result = $this->run_with_active( serialize( array() ), "define( 'MULTISITE', getenv( 'EXAMPLE_MULTISITE' ) );" );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'MULTISITE', (string) $result->message() );
	}

	public function test_an_undefined_multisite_setting_is_skipped_when_wp_config_includes_other_files(): void {
		$this->tree->file( 'wp-content/plugins/akismet/akismet.php' );
		$this->tree->file( 'wp-config.php', "<?php\nrequire __DIR__ . '/wp-config-local.php';\n\$table_prefix = 'wptest_';\n" );

		$result = $this->run_with_active( serialize( array() ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'MULTISITE', (string) $result->message() );
		$this->assertStringContainsString( 'include', (string) $result->message() );
	}

	public function test_a_multisite_setting_of_false_is_not_a_network(): void {
		$this->tree->file( 'wp-content/plugins/akismet/akismet.php' );

		$result = $this->run_with_active( serialize( array() ), "define( 'MULTISITE', false );" );

		$this->assertSame( 'akismet', $result->finding_list()[0]->evidence()['plugins'][0] );
	}

	public function test_symlinked_plugin_folders_are_listed_by_name_without_being_followed(): void {
		$this->tree->file( 'wp-content/plugins/akismet/akismet.php' );
		$this->tree->file( 'shared/inside-plugin/inside-plugin.php' );
		mkdir( $this->tree->outside() . '/outside-plugin' );
		$this->tree->link( 'wp-content/plugins/inside-plugin', $this->tree->site() . '/shared/inside-plugin' );
		$this->tree->link( 'wp-content/plugins/outside-plugin', $this->tree->outside() . '/outside-plugin' );

		$evidence = $this->run_with_active( serialize( array( 'akismet/akismet.php' ) ) )->finding_list()[0]->evidence();

		$this->assertSame( 3, $evidence['plugins_on_disk'] );
		$this->assertSame( 'inside-plugin', $evidence['plugins'][0] );
		$this->assertSame( 'outside-plugin', $evidence['plugins'][1] );
	}

	public function test_a_plugins_directory_that_could_not_be_listed_is_skipped_rather_than_passed(): void {
		File_Modes::require_enforced_modes();
		$this->tree->file( 'wp-content/plugins/akismet/akismet.php' );
		$this->tree->file( 'wp-config.php', "<?php\n\$table_prefix = 'wptest_';\n" );
		chmod( $this->tree->site() . '/wp-content/plugins', 0000 );

		$result = $this->run_with_active( serialize( array( 'akismet/akismet.php' ) ) );
		chmod( $this->tree->site() . '/wp-content/plugins', 0755 );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'could not be read', (string) $result->message() );
	}
}
