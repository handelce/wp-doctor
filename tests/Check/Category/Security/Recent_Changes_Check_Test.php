<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Security;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Security\Recent_Changes_Check;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Temp_Tree;

/**
 * Files written by a test all share the time of the test, so these cover
 * the paths the check takes before judging; Package_Changes_Test covers the
 * judging with chosen change times.
 */
final class Recent_Changes_Check_Test extends TestCase {

	/**
	 * @var Temp_Tree
	 */
	private $tree;

	protected function setUp(): void {
		$this->tree = new Temp_Tree();
		$this->tree->file( 'wp-login.php', "<?php\n" );
		$this->tree->file( 'wp-content/plugins/example/example.php', "<?php\n/* Plugin Name: Example */\n" );
	}

	protected function tearDown(): void {
		$this->tree->remove();
	}

	/**
	 * Skips a test whose result depends on Unix change times.
	 */
	private static function require_change_times(): void {
		if ( 'Windows' === PHP_OS_FAMILY ) {
			self::markTestSkipped( 'PHP on Windows reports creation times, and the check skips there.' );
		}
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Recent_Changes_Check();

		$this->assertSame( 'sec.recent.changes', $check->id() );
		$this->assertSame( 'security', $check->category() );
	}

	public function test_a_site_written_all_at_once_is_a_skip_rather_than_a_pass(): void {
		self::require_change_times();
		$result = ( new Recent_Changes_Check() )->run( Context_Factory::for_tree( $this->tree ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'within the same hour', (string) $result->message() );
	}

	public function test_it_is_skipped_on_windows_where_the_change_time_is_the_creation_time(): void {
		$result = ( new Recent_Changes_Check() )->run( Context_Factory::for_tree_on( $this->tree, 'Windows' ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'Windows', (string) $result->message() );
	}

	public function test_a_site_without_php_outside_the_cache_is_a_skip(): void {
		self::require_change_times();
		$this->tree->remove_path( 'wp-login.php' );
		$this->tree->remove_path( 'wp-content/plugins' );
		$this->tree->file( 'wp-content/cache/page.php', "<?php\n" );
		$this->tree->file( 'wp-content/plugins/index.php', "<?php\n// Silence is golden.\n" );
		$context = Context_Factory::for_tree( $this->tree );
		$this->tree->remove_path( 'wp-config.php' );
		$this->tree->remove_path( 'wp-includes' );

		$result = ( new Recent_Changes_Check() )->run( $context );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'No PHP files', (string) $result->message() );
	}

	public function test_a_moved_plugins_directory_is_a_skip(): void {
		$result = ( new Recent_Changes_Check() )->run( Context_Factory::for_tree( $this->tree, "define( 'WP_PLUGIN_DIR', '/srv/example/plugins' );\n" ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'WP_PLUGIN_DIR', (string) $result->message() );
	}
}
