<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Disk;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Disk\Error_Log_Size_Check;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Temp_Tree;

final class Error_Log_Size_Check_Test extends TestCase {

	private const MIB = 1048576;

	/**
	 * Site holding sparse error_log files at the sizes under test.
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

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Error_Log_Size_Check();

		$this->assertSame( 'disk.errorlog.size', $check->id() );
		$this->assertSame( 'disk', $check->category() );
	}

	public function test_small_logs_and_large_files_with_other_names_pass(): void {
		$this->tree->file( 'error_log', 'small' );
		$this->tree->sparse_file( 'wp-content/other.log', 200 * self::MIB );

		$this->assertTrue( ( new Error_Log_Size_Check() )->run( Context_Factory::for_tree( $this->tree ) )->is_pass() );
	}

	public function test_a_log_of_exactly_fifty_mebibytes_passes(): void {
		$this->tree->sparse_file( 'wp-admin/error_log', 50 * self::MIB );

		$this->assertTrue( ( new Error_Log_Size_Check() )->run( Context_Factory::for_tree( $this->tree ) )->is_pass() );
	}

	public function test_large_logs_anywhere_are_listed_largest_first(): void {
		$this->tree->sparse_file( 'wp-admin/error_log', 60 * self::MIB );
		$this->tree->sparse_file( 'wp-content/plugins/example/deep/error_log', 90 * self::MIB );
		$this->tree->file( 'error_log', 'small' );

		$result = ( new Error_Log_Size_Check() )->run( Context_Factory::for_tree( $this->tree ) );

		$finding  = $result->finding_list()[0];
		$evidence = $finding->evidence();
		$this->assertSame( 'warning', $finding->severity()->name() );
		$this->assertSame( 2, $evidence['large_files'] );
		$this->assertSame( 'wp-content/plugins/example/deep/error_log', $evidence['file_1'] );
		$this->assertSame( 90 * self::MIB, $evidence['file_1_bytes'] );
		$this->assertSame( 'wp-admin/error_log', $evidence['file_2'] );
		$this->assertFalse( $evidence['scan_incomplete'] );
	}

	public function test_a_clean_result_is_a_skip_when_the_scan_stopped_at_a_limit(): void {
		$this->tree->file( 'a.txt' );
		$this->tree->file( 'b.txt' );
		$this->tree->file( 'c.txt' );

		$result = ( new Error_Log_Size_Check() )->run( Context_Factory::for_tree( $this->tree, '', 2 ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'limit', (string) $result->message() );
	}

	public function test_a_finding_found_before_the_limit_says_the_scan_was_incomplete(): void {
		$this->tree->sparse_file( 'error_log', 60 * self::MIB );

		// Entries are read in name order: error_log, wp-config.php, then wp-includes hits the cap.
		$result = ( new Error_Log_Size_Check() )->run( Context_Factory::for_tree( $this->tree, '', 2 ) );

		$this->assertSame( Result::FINDINGS, $result->status() );
		$this->assertTrue( $result->finding_list()[0]->evidence()['scan_incomplete'] );
	}

	public function test_a_second_run_on_the_same_scanner_is_still_a_skip_when_the_walk_stops_at_the_cap(): void {
		$this->tree->file( 'a.txt' );
		$this->tree->file( 'b.txt' );
		$this->tree->file( 'c.txt' );
		$context = Context_Factory::for_tree( $this->tree, '', 2 );

		$first  = ( new Error_Log_Size_Check() )->run( $context );
		$second = ( new Error_Log_Size_Check() )->run( $context );

		$this->assertSame( Result::SKIPPED, $first->status() );
		$this->assertSame( Result::SKIPPED, $second->status() );
		$this->assertStringContainsString( '--max-files', (string) $second->message() );
	}
}
