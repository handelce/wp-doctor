<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Disk;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Disk\Debug_Log_Size_Check;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Temp_Tree;

final class Debug_Log_Size_Check_Test extends TestCase {

	private const MIB = 1048576;

	/**
	 * Site holding sparse debug.log files at the sizes under test.
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
	 * Runs the check on the temporary site.
	 *
	 * @param string $config wp-config.php statements.
	 * @return Result
	 */
	private function run_check( string $config = '' ): Result {
		return ( new Debug_Log_Size_Check() )->run( Context_Factory::for_tree( $this->tree, $config ) );
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Debug_Log_Size_Check();

		$this->assertSame( 'disk.debuglog.size', $check->id() );
		$this->assertSame( 'disk', $check->category() );
	}

	public function test_a_site_without_a_debug_log_passes(): void {
		$this->assertTrue( $this->run_check()->is_pass() );
	}

	public function test_a_log_of_exactly_one_hundred_mebibytes_passes(): void {
		$this->tree->sparse_file( 'wp-content/debug.log', 100 * self::MIB );

		$this->assertTrue( $this->run_check()->is_pass() );
	}

	public function test_a_warning_is_raised_above_one_hundred_mebibytes(): void {
		$this->tree->sparse_file( 'wp-content/debug.log', 100 * self::MIB + 1 );

		$finding = $this->run_check()->finding_list()[0];

		$this->assertSame( 'warning', $finding->severity()->name() );
		$this->assertSame( 100 * self::MIB + 1, $finding->evidence()['bytes'] );
		$this->assertSame( 'wp-content/debug.log', $finding->evidence()['path'] );
		$this->assertNotSame( '', $finding->fix() );
	}

	public function test_exactly_one_gibibyte_is_still_a_warning(): void {
		$this->tree->sparse_file( 'wp-content/debug.log', 1024 * self::MIB );

		$this->assertSame( 'warning', $this->run_check()->finding_list()[0]->severity()->name() );
	}

	public function test_a_critical_finding_is_raised_above_one_gibibyte(): void {
		$this->tree->sparse_file( 'wp-content/debug.log', 1024 * self::MIB + 1 );

		$this->assertSame( 'critical', $this->run_check()->finding_list()[0]->severity()->name() );
	}

	public function test_a_custom_log_outside_the_root_is_skipped_with_the_reason(): void {
		$this->tree->sparse_file( 'wp-content/debug.log', 200 * self::MIB );

		$result = $this->run_check( "define( 'WP_DEBUG_LOG', '/var/log/example.log' );" );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'WP_DEBUG_LOG', (string) $result->message() );
		$this->assertStringContainsString( 'outside the site root', (string) $result->message() );
	}

	public function test_a_custom_log_inside_the_root_is_measured_instead_of_the_default(): void {
		$this->tree->sparse_file( 'logs/debug.log', 200 * self::MIB );
		$this->tree->sparse_file( 'wp-content/debug.log', 2048 * self::MIB );

		$finding = $this->run_check( "define( 'WP_DEBUG_LOG', __DIR__ . '/logs/debug.log' );" )->finding_list()[0];

		$this->assertSame( 'logs/debug.log', $finding->evidence()['path'] );
		$this->assertSame( 200 * self::MIB, $finding->evidence()['bytes'] );
		$this->assertSame( 'warning', $finding->severity()->name() );
		$this->assertStringContainsString( 'logs/debug.log', $finding->fix() );
	}

	public function test_a_small_custom_log_inside_the_root_passes(): void {
		$this->tree->file( 'logs/debug.log', 'short' );

		$this->assertTrue( $this->run_check( "define( 'WP_DEBUG_LOG', ABSPATH . 'logs/debug.log' );" )->is_pass() );
	}

	public function test_a_computed_custom_log_path_is_skipped(): void {
		$result = $this->run_check( "define( 'WP_DEBUG_LOG', getenv( 'LOG' ) );" );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'could not be read', (string) $result->message() );
	}

	public function test_a_log_flag_that_is_a_boolean_keeps_the_default_path(): void {
		$this->tree->sparse_file( 'wp-content/debug.log', 200 * self::MIB );

		$this->assertSame( 'wp-content/debug.log', $this->run_check( "define( 'WP_DEBUG_LOG', false );" )->finding_list()[0]->evidence()['path'] );
		$this->assertSame( 'wp-content/debug.log', $this->run_check( "define( 'WP_DEBUG_LOG', true );" )->finding_list()[0]->evidence()['path'] );
	}

	public function test_the_default_log_follows_a_moved_content_directory(): void {
		$this->tree->sparse_file( 'app/debug.log', 200 * self::MIB );

		$this->assertSame( 'app/debug.log', $this->run_check( "define( 'WP_CONTENT_DIR', __DIR__ . '/app' );" )->finding_list()[0]->evidence()['path'] );
	}

	public function test_a_moved_content_directory_is_skipped(): void {
		$result = $this->run_check( "define( 'WP_CONTENT_DIR', '/srv/example/content' );" );

		$this->assertSame( Result::SKIPPED, $result->status() );
	}

	public function test_a_symlinked_log_is_skipped_rather_than_passed_unmeasured(): void {
		$this->tree->sparse_file( 'logs/real.log', 200 * self::MIB );
		$this->tree->link( 'wp-content/debug.log', $this->tree->site() . '/logs/real.log' );

		$result = $this->run_check();

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'symlink', (string) $result->message() );
	}

	public function test_a_log_symlinked_outside_the_root_is_skipped_rather_than_passed(): void {
		file_put_contents( $this->tree->outside() . '/real.log', 'x' );
		$this->tree->link( 'wp-content/debug.log', $this->tree->outside() . '/real.log' );

		$result = $this->run_check();

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'outside the site root', (string) $result->message() );
	}

	public function test_the_fix_quotes_the_log_path_for_the_shell(): void {
		$this->tree->sparse_file( 'logs/my debug;x.log', 200 * self::MIB );

		$finding = $this->run_check( "define( 'WP_DEBUG_LOG', __DIR__ . '/logs/my debug;x.log' );" )->finding_list()[0];

		$this->assertSame( 'logs/my debug;x.log', $finding->evidence()['path'] );
		$this->assertStringContainsString( "`truncate -s 0 'logs/my debug;x.log'`", $finding->fix() );
	}

	public function test_a_debug_log_that_is_a_broken_symlink_is_skipped_not_passed(): void {
		$this->tree->link( 'wp-content/debug.log', $this->tree->site() . '/wp-content/gone.log' );
		$this->assertTrue( is_link( $this->tree->site() . '/wp-content/debug.log' ) );

		$result = ( new Debug_Log_Size_Check() )->run( Context_Factory::for_tree( $this->tree ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'broken symlink', (string) $result->message() );
	}

	public function test_a_debug_log_that_is_a_directory_is_skipped_not_passed(): void {
		mkdir( $this->tree->site() . '/wp-content/debug.log', 0755, true );
		$this->tree->sparse_file( 'wp-content/debug.log/huge', 200 * 1024 * 1024 );
		$this->assertDirectoryExists( $this->tree->site() . '/wp-content/debug.log' );

		$result = ( new Debug_Log_Size_Check() )->run( Context_Factory::for_tree( $this->tree ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'directory', (string) $result->message() );
	}
}
