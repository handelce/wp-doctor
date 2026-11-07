<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Performance;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Performance\Autoload_Orphaned_Check;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Recording_Driver;
use WP_Doctor\Tests\Support\Temp_Tree;

final class Autoload_Orphaned_Check_Test extends TestCase {

	/**
	 * Site with three plugins, one theme and one must-use plugin installed.
	 *
	 * @var Temp_Tree
	 */
	private $tree;

	protected function setUp(): void {
		$this->tree = new Temp_Tree();
		$this->tree->file( 'wp-content/plugins/woocommerce/woocommerce.php' );
		$this->tree->file( 'wp-content/plugins/contact-form-7/wp-contact-form-7.php' );
		$this->tree->file( 'wp-content/plugins/hello.php' );
		$this->tree->file( 'wp-content/themes/twentytwentyfour/style.css' );
		$this->tree->file( 'wp-content/mu-plugins/loader.php' );
	}

	protected function tearDown(): void {
		$this->tree->remove();
	}

	/**
	 * Runs the check against large autoloaded options with the given names.
	 *
	 * @param string[] $names Option names, largest first.
	 * @return array{0: Result, 1: Recording_Driver}
	 */
	private function run_with_options( array $names ): array {
		$driver       = new Recording_Driver();
		$driver->rows = array();
		foreach ( $names as $index => $name ) {
			$driver->rows[] = array(
				'option_name' => $name,
				'size_bytes'  => (string) ( 900000 - $index * 1000 ),
			);
		}

		return array( ( new Autoload_Orphaned_Check() )->run( Context_Factory::for_tree_with_driver( $this->tree, $driver ) ), $driver );
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Autoload_Orphaned_Check();

		$this->assertSame( 'perf.autoload.orphaned', $check->id() );
		$this->assertSame( 'performance', $check->category() );
	}

	public function test_the_query_asks_for_large_autoloaded_options_only(): void {
		list( , $driver ) = $this->run_with_options( array( 'woocommerce_settings' ) );

		$sql = $driver->statements[1];
		$this->assertStringContainsString( "autoload IN ('yes', 'on', 'auto', 'auto-on')", $sql );
		$this->assertStringContainsString( 'LENGTH(option_value) > 10240', $sql );
		$this->assertStringContainsString( 'ORDER BY size_bytes DESC', $sql );
		$this->assertStringContainsString( '`wptest_options`', $sql );
	}

	public function test_options_that_match_core_or_an_installed_extension_pass(): void {
		list( $result ) = $this->run_with_options(
			array(
				'woocommerce_cart_hash',
				'wp_user_roles',
				'widget_text',
				'theme_mods_twentytwentyfour',
				'twentytwentyfour_options',
				'contact_form_7_data',
				'hello_cache',
				'loader_state',
				'cron',
				'sidebars_widgets',
				'_transient_example',
				'ab_short',
			)
		);

		$this->assertTrue( $result->is_pass() );
	}

	public function test_a_large_option_with_no_matching_extension_is_a_warning_naming_it(): void {
		list( $result ) = $this->run_with_options( array( 'woocommerce_cart_hash', 'removedplugin_cache', 'gone-plugin-data', 'cron' ) );

		$finding  = $result->finding_list()[0];
		$evidence = $finding->evidence();
		$this->assertSame( 'warning', $finding->severity()->name() );
		$this->assertStringContainsString( 'likely', $finding->title() );
		$this->assertSame( 4, $evidence['large_options_examined'] );
		$this->assertArrayNotHasKey( 'large_autoloaded_options', $evidence );
		$this->assertSame( 'removedplugin_cache', $evidence['option_1'] );
		$this->assertSame( 899000, $evidence['option_1_bytes'] );
		$this->assertSame( 'gone-plugin-data', $evidence['option_2'] );
		$this->assertArrayNotHasKey( 'option_3', $evidence );
		$this->assertStringContainsString( 'default', (string) $evidence['threshold_basis'] );
		$this->assertNotSame( '', $finding->fix() );
	}

	public function test_a_full_sample_says_that_smaller_large_options_were_not_examined(): void {
		$names = array();
		for ( $i = 1; $i <= 50; $i++ ) {
			$names[] = "removedplugin_part_$i";
		}

		list( $result ) = $this->run_with_options( $names );

		$evidence = $result->finding_list()[0]->evidence();
		$this->assertSame( 'the largest 50; any smaller ones above the threshold were not examined', $evidence['large_options_examined'] );
		$this->assertSame( 'removedplugin_part_1', $evidence['option_1'] );
	}

	public function test_an_option_starting_with_user_is_not_assumed_to_belong_to_core(): void {
		list( $result ) = $this->run_with_options( array( 'user_import_cache' ) );

		$this->assertSame( Result::FINDINGS, $result->status() );
	}

	public function test_a_prefix_is_matched_against_the_start_of_a_folder_name_either_way(): void {
		list( $result ) = $this->run_with_options( array( 'contact_anything', 'woocommerce_gateway_stripe_settings', 'hellodolly_cache' ) );

		$this->assertTrue( $result->is_pass() );
	}

	public function test_a_short_installed_name_does_not_claim_every_option(): void {
		$this->tree->file( 'wp-content/plugins/ab/ab.php' );

		list( $result ) = $this->run_with_options( array( 'abandoned_cache' ) );

		$this->assertSame( Result::FINDINGS, $result->status() );
	}

	public function test_installed_names_are_compared_without_regard_to_case(): void {
		$this->tree->file( 'wp-content/plugins/MixedCasePlugin/main.php' );

		list( $result ) = $this->run_with_options( array( 'mixedcaseplugin_settings' ) );

		$this->assertTrue( $result->is_pass() );
	}

	public function test_no_large_options_passes_after_the_query_ran(): void {
		list( $result, $driver ) = $this->run_with_options( array() );

		$this->assertTrue( $result->is_pass() );
		$this->assertCount( 2, $driver->statements );
		$this->assertStringContainsString( 'LENGTH(option_value) > 10240', $driver->statements[1] );
	}

	public function test_a_row_without_a_size_is_an_error(): void {
		$driver       = new Recording_Driver();
		$driver->rows = array( array( 'option_name' => 'example_cache' ) );

		$this->expectException( \UnexpectedValueException::class );
		( new Autoload_Orphaned_Check() )->run( Context_Factory::for_tree_with_driver( $this->tree, $driver ) );
	}

	public function test_a_missing_plugins_directory_is_skipped(): void {
		$tree = new Temp_Tree();
		try {
			$result = ( new Autoload_Orphaned_Check() )->run( Context_Factory::for_tree_with_driver( $tree, new Recording_Driver() ) );
		} finally {
			$tree->remove();
		}

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'wp-content/plugins', (string) $result->message() );
	}

	public function test_it_is_skipped_without_a_database_or_a_moved_content_directory(): void {
		$this->assertSame( Result::SKIPPED, ( new Autoload_Orphaned_Check() )->run( Context_Factory::for_tree( $this->tree ) )->status() );
		$moved = new Temp_Tree();
		try {
			$result = ( new Autoload_Orphaned_Check() )->run( Context_Factory::for_tree_with_driver( $moved, new Recording_Driver(), "define( 'WP_CONTENT_DIR', '/srv/x' );" ) );
		} finally {
			$moved->remove();
		}
		$this->assertSame( Result::SKIPPED, $result->status() );
	}

	public function test_options_of_plugins_and_themes_symlinked_from_outside_the_root_are_not_orphans(): void {
		mkdir( $this->tree->outside() . '/sharedplugin' );
		mkdir( $this->tree->outside() . '/sharedtheme' );
		$this->tree->link( 'wp-content/plugins/sharedplugin', $this->tree->outside() . '/sharedplugin' );
		$this->tree->link( 'wp-content/themes/sharedtheme', $this->tree->outside() . '/sharedtheme' );
		$this->assertTrue( is_link( $this->tree->site() . '/wp-content/plugins/sharedplugin' ) );

		list( $result ) = $this->run_with_options( array( 'sharedplugin_cache', 'sharedtheme_mods' ) );

		$this->assertTrue( $result->is_pass() );
	}

	public function test_a_plugins_directory_that_could_not_be_listed_fully_is_skipped(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Permissions are not enforced for root.' );
		}
		$driver       = new Recording_Driver();
		$driver->rows = array(
			array(
				'option_name' => 'gone_plugin_data',
				'size_bytes'  => '900000',
			),
		);
		$context      = Context_Factory::for_tree_with_driver( $this->tree, $driver );
		chmod( $this->tree->site() . '/wp-content/plugins', 0000 );

		$result = ( new Autoload_Orphaned_Check() )->run( $context );
		chmod( $this->tree->site() . '/wp-content/plugins', 0755 );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'could not be read', (string) $result->message() );
	}

	public function test_the_role_option_under_a_custom_table_prefix_belongs_to_core(): void {
		$driver       = new Recording_Driver();
		$driver->rows = array(
			array(
				'option_name' => 'wpx3_user_roles',
				'size_bytes'  => '40000',
			),
			array(
				'option_name' => 'disallowed_keys',
				'size_bytes'  => '30000',
			),
			array(
				'option_name' => 'moderation_keys',
				'size_bytes'  => '20000',
			),
		);

		$result = ( new Autoload_Orphaned_Check() )->run( Context_Factory::for_tree_with_driver( $this->tree, $driver, "\$table_prefix = 'wpx3_';" ) );

		$this->assertStringContainsString( '`wpx3_options`', $driver->statements[1] );
		$this->assertTrue( $result->is_pass(), 'A core option was reported as orphaned' );
	}

	public function test_a_themes_directory_that_leaves_the_root_is_skipped(): void {
		$this->tree->remove_path( 'wp-content/themes' );
		$this->tree->link( 'wp-content/themes', $this->tree->outside() );
		$this->assertTrue( is_link( $this->tree->site() . '/wp-content/themes' ) );

		list( $result ) = $this->run_with_options( array( 'twentytwentyfour_options' ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'themes', (string) $result->message() );
	}

	public function test_a_site_without_a_must_use_plugins_directory_is_still_examined(): void {
		$this->tree->remove_path( 'wp-content/mu-plugins' );
		$this->assertFalse( is_dir( $this->tree->site() . '/wp-content/mu-plugins' ) );

		list( $result ) = $this->run_with_options( array( 'abandoned_plugin_cache' ) );

		$this->assertSame( Result::FINDINGS, $result->status() );
	}
}
