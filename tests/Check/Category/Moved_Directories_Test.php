<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Config\Php_Version_Check;
use WP_Doctor\Check\Category\Performance\Autoload_Orphaned_Check;
use WP_Doctor\Check\Category\Performance\Object_Cache_Missing_Check;
use WP_Doctor\Check\Category\Security\Inactive_Plugins_Check;
use WP_Doctor\Check\Category\Security\Uploads_Php_Check;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Recording_Driver;
use WP_Doctor\Tests\Support\Temp_Tree;

/**
 * Checks that read wp-content follow a moved content or uploads directory,
 * and skip visibly when the plugin directories are moved.
 */
final class Moved_Directories_Test extends TestCase {

	/**
	 * @var Temp_Tree
	 */
	private $tree;

	protected function setUp(): void {
		$this->tree = new Temp_Tree();
	}

	protected function tearDown(): void {
		$this->tree->remove();
	}

	public function test_php_files_in_a_moved_uploads_directory_are_found_and_the_default_location_is_not_read(): void {
		$this->tree->file( 'app/media/2026/planted.php', "<?php\n// inert fixture\n" );
		$this->tree->file( 'wp-content/uploads/2026/ignored.php', "<?php\n// inert fixture in the unused default location\n" );
		$context = Context_Factory::for_tree( $this->tree, "define( 'WP_CONTENT_DIR', __DIR__ . '/app' );\ndefine( 'UPLOADS', 'app/media' );" );

		$result = ( new Uploads_Php_Check() )->run( $context );

		$this->assertSame( Result::FINDINGS, $result->status() );
		$paths = implode( ' ', array_column( $result->finding_list()[0]->evidence()['files'], 'path' ) );
		$this->assertStringContainsString( 'app/media/2026/planted.php', $paths );
		$this->assertStringNotContainsString( 'ignored.php', $paths );
	}

	public function test_the_object_cache_drop_in_is_looked_for_in_the_moved_content_directory(): void {
		$this->tree->file( 'app/object-cache.php', "<?php\n" );
		$context = Context_Factory::for_tree( $this->tree, "define( 'WP_CONTENT_DIR', __DIR__ . '/app' );" );

		$this->assertTrue( ( new Object_Cache_Missing_Check() )->run( $context )->is_pass() );
	}

	public function test_a_content_directory_outside_the_root_still_skips_with_the_reason(): void {
		$context = Context_Factory::for_tree( $this->tree, "define( 'WP_CONTENT_DIR', '/srv/shared/content' );" );

		$result = ( new Uploads_Php_Check() )->run( $context );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'outside the site root', (string) $result->message() );
	}

	public function test_a_moved_plugin_directory_skips_the_plugin_checks_instead_of_reading_the_default(): void {
		$this->tree->file( 'wp-content/plugins/stale/stale.php', "<?php\n/**\n * Plugin Name: Stale\n * Requires PHP: 9.9\n */\n" );
		$driver           = new Recording_Driver();
		$driver->rows_for = array(
			'active_plugins' => array( array( 'option_value' => 'a:0:{}' ) ),
			'LENGTH'         => array( array( 'size_bytes' => '6' ) ),
		);
		$config           = "define( 'WP_PLUGIN_DIR', __DIR__ . '/plugins' );";

		$inactive = ( new Inactive_Plugins_Check() )->run( Context_Factory::for_tree_with_driver( $this->tree, $driver, $config ) );
		$orphaned = ( new Autoload_Orphaned_Check() )->run( Context_Factory::for_tree_with_driver( $this->tree, $driver, $config ) );

		$this->assertSame( Result::SKIPPED, $inactive->status() );
		$this->assertStringContainsString( 'WP_PLUGIN_DIR is set', (string) $inactive->message() );
		$this->assertSame( Result::SKIPPED, $orphaned->status() );
		$this->assertStringContainsString( 'WP_PLUGIN_DIR is set', (string) $orphaned->message() );
	}

	public function test_a_moved_plugin_directory_makes_a_supported_php_version_hint_a_skip_naming_the_unread_plugins(): void {
		$this->tree->file( '.htaccess', "AddHandler application/x-httpd-ea-php82 .php\n" );
		$this->tree->file( 'wp-content/plugins/needs-new/needs-new.php', "<?php\n/**\n * Plugin Name: Needs New\n * Requires PHP: 9.9\n */\n" );
		$context = Context_Factory::for_tree( $this->tree, "define( 'WPMU_PLUGIN_DIR', __DIR__ . '/mu' );" );

		$result = ( new Php_Version_Check( '8.3.0', (int) strtotime( '2026-11-04 12:00:00 UTC' ) ) )->run( $context );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'plugin PHP requirements were not all read', (string) $result->message() );
		$this->assertStringContainsString( 'WPMU_PLUGIN_DIR', (string) $result->message() );
	}
}
