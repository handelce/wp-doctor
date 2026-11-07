<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Config;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Config\Writable_Files_Check;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\File_Modes;
use WP_Doctor\Tests\Support\Temp_Tree;

final class Writable_Files_Check_Test extends TestCase {

	/**
	 * Site whose files, directories and links each test gives a mode.
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
	 * @param int $max_files Entry cap for the walk.
	 * @return Result
	 */
	private function run_check( int $max_files = 200000 ): Result {
		return ( new Writable_Files_Check() )->run( Context_Factory::for_tree( $this->tree, '', $max_files ) );
	}

	public function test_file_modes_are_not_judged_on_windows(): void {
		$context = Context_Factory::for_tree_on( $this->tree, 'Windows' );
		$this->tree->file( 'wp-content/index.php', "<?php\n" );
		chmod( $this->tree->site() . '/wp-content/index.php', 0666 );
		chmod( $this->tree->site() . '/wp-config.php', 0666 );

		$result = ( new Writable_Files_Check() )->run( $context );

		$this->assertSame( 'skipped', $result->status() );
		$this->assertStringContainsString( 'Windows', (string) $result->message() );
	}

	public function test_file_modes_are_judged_on_other_systems(): void {
		$context = Context_Factory::for_tree_on( $this->tree, 'Linux' );
		$this->tree->file( 'wp-content/index.php', "<?php\n" );
		chmod( $this->tree->site() . '/wp-content/index.php', 0666 );
		chmod( $this->tree->site() . '/wp-config.php', 0666 );

		$result = ( new Writable_Files_Check() )->run( $context );

		$this->assertSame( 'findings', $result->status() );
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Writable_Files_Check();

		$this->assertSame( 'config.perms.writable', $check->id() );
		$this->assertSame( 'config', $check->category() );
	}

	public function test_files_and_directories_with_ordinary_modes_pass(): void {
		File_Modes::require_unix_modes();
		$this->tree->file( 'index.php' );
		$this->tree->file( 'wp-content/uploads/a.jpg' );
		$this->tree->normalise_permissions();
		chmod( $this->tree->site() . '/index.php', 0664 );

		$this->assertTrue( $this->run_check()->is_pass() );
	}

	public function test_a_symbolic_link_is_not_judged_by_its_own_mode(): void {
		File_Modes::require_unix_modes();
		$this->tree->file( 'real.txt' );
		$this->tree->link( 'alias.txt', $this->tree->site() . '/real.txt' );
		$this->tree->normalise_permissions();
		$link_stat = lstat( $this->tree->site() . '/alias.txt' );
		if ( false === $link_stat || 0 === ( $link_stat['mode'] & 0002 ) ) {
			$this->markTestSkipped( 'Symbolic links on this system do not report a world-writable mode, so the test cannot tell.' );
		}

		$this->assertTrue( $this->run_check()->is_pass() );
	}

	public function test_world_writable_files_and_directories_are_counted_and_listed(): void {
		File_Modes::require_unix_modes();
		$this->tree->file( 'wp-content/cache/page.html' );
		$this->tree->file( 'config.php' );
		$this->tree->file( 'fine.php' );
		$this->tree->normalise_permissions();
		chmod( $this->tree->site() . '/wp-content/cache', 0777 );
		chmod( $this->tree->site() . '/config.php', 0666 );

		$finding = $this->run_check()->finding_list()[0];

		$evidence = $finding->evidence();
		$this->assertSame( 'warning', $finding->severity()->name() );
		$this->assertSame( 1, $evidence['world_writable_files'] );
		$this->assertSame( 1, $evidence['world_writable_directories'] );
		$this->assertSame( 'config.php', $evidence['file_1'] );
		$this->assertSame( 'wp-content/cache', $evidence['file_2'] );
		$this->assertNotSame( '', $finding->fix() );
	}

	public function test_a_group_writable_file_is_not_world_writable(): void {
		File_Modes::require_unix_modes();
		$this->tree->file( 'a.php' );
		$this->tree->normalise_permissions();
		chmod( $this->tree->site() . '/a.php', 0664 );

		$this->assertTrue( $this->run_check()->is_pass() );
	}

	public function test_a_clean_result_is_a_skip_when_the_scan_stopped_at_a_limit(): void {
		$this->tree->file( 'a.txt' );
		$this->tree->file( 'b.txt' );
		$this->tree->file( 'c.txt' );
		$this->tree->normalise_permissions();

		$result = $this->run_check( 2 );

		$this->assertSame( Result::SKIPPED, $result->status() );
	}

	public function test_a_clean_result_is_a_skip_after_an_earlier_check_already_stopped_at_the_cap(): void {
		File_Modes::require_unix_modes();
		$this->tree->file( 'a.txt' );
		$this->tree->file( 'b.txt' );
		$this->tree->file( 'c.txt' );
		$this->tree->normalise_permissions();
		$context = Context_Factory::for_tree( $this->tree, '', 2 );
		$this->assertSame( Result::SKIPPED, ( new \WP_Doctor\Check\Category\Disk\Error_Log_Size_Check() )->run( $context )->status() );

		$result = ( new Writable_Files_Check() )->run( $context );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( '--max-files', (string) $result->message() );
	}
}
