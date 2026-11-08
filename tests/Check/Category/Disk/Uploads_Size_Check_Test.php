<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Disk;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Disk\Uploads_Size_Check;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Temp_Tree;

final class Uploads_Size_Check_Test extends TestCase {

	private const UPLOADS = 'wp-content/uploads';

	/**
	 * @var Temp_Tree
	 */
	private $tree;

	protected function setUp(): void {
		$this->tree = new Temp_Tree();
		$this->tree->file( self::UPLOADS . '/2025/01/photo.jpg', str_repeat( 'a', 300 ) );
		$this->tree->file( self::UPLOADS . '/2025/02/photo.jpg', str_repeat( 'b', 200 ) );
		$this->tree->file( self::UPLOADS . '/2026/03/photo.jpg', str_repeat( 'c', 100 ) );
		$this->tree->file( self::UPLOADS . '/elementor/css/post-1.css', str_repeat( 'd', 800 ) );
		$this->tree->file( self::UPLOADS . '/index.php', '<?php' );
	}

	protected function tearDown(): void {
		$this->tree->remove();
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Uploads_Size_Check();

		$this->assertSame( 'disk.uploads.size', $check->id() );
		$this->assertSame( 'disk', $check->category() );
	}

	public function test_the_total_and_the_largest_folders_are_listed(): void {
		$finding = ( new Uploads_Size_Check() )->run( Context_Factory::for_tree_with_options( $this->tree ) )->finding_list()[0];

		$evidence = $finding->evidence();
		$this->assertSame( 'info', $finding->severity()->name() );
		$this->assertSame( self::UPLOADS, $evidence['directory'] );
		$this->assertSame( 1405, $evidence['bytes'] );
		$this->assertSame( 5, $evidence['files'] );
		$this->assertFalse( $evidence['scan_incomplete'] );
		$this->assertArrayNotHasKey( 'location_unconfirmed', $evidence );
		$this->assertSame(
			array( self::UPLOADS . '/elementor', 800, self::UPLOADS . '/2025', 500, self::UPLOADS . '/2026', 100, self::UPLOADS . '/index.php', 5 ),
			array( $evidence['largest'][0]['path'], $evidence['largest'][0]['bytes'], $evidence['largest'][1]['path'], $evidence['largest'][1]['bytes'], $evidence['largest'][2]['path'], $evidence['largest'][2]['bytes'], $evidence['largest'][3]['path'], $evidence['largest'][3]['bytes'] )
		);
	}

	public function test_an_unconfirmed_location_is_said_in_the_evidence(): void {
		$evidence = ( new Uploads_Size_Check() )->run( Context_Factory::for_tree( $this->tree ) )->finding_list()[0]->evidence();

		$this->assertArrayHasKey( 'location_unconfirmed', $evidence );
	}

	public function test_a_symlink_leaving_the_root_makes_the_total_incomplete(): void {
		$this->tree->link( self::UPLOADS . '/shared', $this->tree->outside() );

		$evidence = ( new Uploads_Size_Check() )->run( Context_Factory::for_tree_with_options( $this->tree ) )->finding_list()[0]->evidence();

		$this->assertTrue( $evidence['scan_incomplete'] );
		$this->assertStringContainsString( self::UPLOADS . '/shared', $evidence['not_counted'] );
	}

	public function test_a_missing_uploads_directory_is_a_skip(): void {
		$this->tree->remove_path( self::UPLOADS );

		$this->assertSame( Result::SKIPPED, ( new Uploads_Size_Check() )->run( Context_Factory::for_tree_with_options( $this->tree ) )->status() );
	}
}
