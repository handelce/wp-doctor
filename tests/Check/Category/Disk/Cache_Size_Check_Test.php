<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Disk;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Disk\Cache_Size_Check;
use WP_Doctor\Check\Thresholds;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\File_Modes;
use WP_Doctor\Tests\Support\Temp_Tree;

final class Cache_Size_Check_Test extends TestCase {

	/**
	 * @var Temp_Tree
	 */
	private $tree;

	protected function setUp(): void {
		$this->tree = new Temp_Tree();
		$this->tree->file( 'wp-content/cache/supercache/example.test/index.html', str_repeat( 'a', 600 ) );
		$this->tree->file( 'wp-content/cache/autoptimize/css/a.css', str_repeat( 'b', 300 ) );
		$this->tree->file( 'wp-content/litespeed/css/b.css', str_repeat( 'c', 50 ) );
	}

	protected function tearDown(): void {
		$this->tree->remove();
	}

	/**
	 * Runs the check with a warning threshold.
	 *
	 * @param int $above Bytes a cache folder must exceed.
	 * @return Result
	 */
	private function run_above( int $above ): Result {
		$context = Context_Factory::for_tree( $this->tree )->with_thresholds( Thresholds::from_config( array( 'disk.cache.size' => array( 'warning' => $above ) ), 'test.json' ) );

		return ( new Cache_Size_Check() )->run( $context );
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Cache_Size_Check();

		$this->assertSame( 'disk.cache.size', $check->id() );
		$this->assertSame( 'disk', $check->category() );
	}

	public function test_a_cache_folder_above_the_threshold_is_a_warning_with_its_largest_folders(): void {
		$finding = $this->run_above( 500 )->finding_list()[0];

		$evidence = $finding->evidence();
		$this->assertSame( 'warning', $finding->severity()->name() );
		$this->assertSame( 500, $evidence['warning_above_bytes'] );
		$this->assertSame( 'wp-content/cache', $evidence['cache_1_path'] );
		$this->assertSame( 900, $evidence['cache_1_bytes'] );
		$this->assertSame( 'wp-content/cache/supercache', $evidence['cache_1_largest_1'] );
		$this->assertSame( 600, $evidence['cache_1_largest_1_bytes'] );
		$this->assertArrayNotHasKey( 'cache_2_path', $evidence );
	}

	public function test_caches_below_the_threshold_pass(): void {
		$this->assertTrue( $this->run_above( 900 )->is_pass() );
	}

	public function test_the_default_threshold_is_one_gib(): void {
		$this->assertSame( 1073741824, Thresholds::defaults()->warning( 'disk.cache.size' ) );
		$this->assertTrue( ( new Cache_Size_Check() )->run( Context_Factory::for_tree( $this->tree ) )->is_pass() );
	}

	public function test_a_symlinked_cache_folder_is_not_measured_and_a_clean_result_is_a_skip(): void {
		$this->tree->link( 'wp-content/wpo-cache', $this->tree->site() . '/wp-content/litespeed' );

		$result = $this->run_above( 900 );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'wp-content/wpo-cache', (string) $result->message() );
	}

	public function test_an_unreadable_cache_folder_makes_a_clean_result_a_skip(): void {
		File_Modes::require_enforced_modes();
		chmod( $this->tree->site() . '/wp-content/litespeed/css', 0000 );

		$result = $this->run_above( 900 );

		chmod( $this->tree->site() . '/wp-content/litespeed/css', 0755 );
		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'wp-content/litespeed', (string) $result->message() );
	}
}
