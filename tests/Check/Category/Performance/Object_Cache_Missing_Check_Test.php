<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Performance;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Performance\Object_Cache_Missing_Check;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Temp_Tree;

final class Object_Cache_Missing_Check_Test extends TestCase {

	/**
	 * Site that holds the object-cache.php drop-in only when a test writes it.
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
		$check = new Object_Cache_Missing_Check();

		$this->assertSame( 'perf.objectcache.missing', $check->id() );
		$this->assertSame( 'performance', $check->category() );
	}

	public function test_a_site_with_the_drop_in_passes(): void {
		$this->tree->file( 'wp-content/object-cache.php', '<?php // drop-in' );

		$this->assertTrue( ( new Object_Cache_Missing_Check() )->run( Context_Factory::for_tree( $this->tree ) )->is_pass() );
	}

	public function test_a_site_without_the_drop_in_gets_a_warning_naming_the_expected_file(): void {
		$this->tree->file( 'wp-content/plugins/example/example.php' );

		$finding = ( new Object_Cache_Missing_Check() )->run( Context_Factory::for_tree( $this->tree ) )->finding_list()[0];

		$this->assertSame( 'warning', $finding->severity()->name() );
		$this->assertSame( 'wp-content/object-cache.php', $finding->evidence()['expected_file'] );
		$this->assertNotSame( '', $finding->fix() );
	}

	public function test_a_directory_named_object_cache_php_is_not_a_drop_in(): void {
		$this->tree->file( 'wp-content/object-cache.php/inner.txt' );

		$this->assertSame( 'warning', ( new Object_Cache_Missing_Check() )->run( Context_Factory::for_tree( $this->tree ) )->finding_list()[0]->severity()->name() );
	}

	public function test_a_moved_content_directory_is_skipped(): void {
		$context = Context_Factory::for_tree( $this->tree, "define( 'WP_CONTENT_DIR', '/srv/example/content' );" );

		$this->assertSame( Result::SKIPPED, ( new Object_Cache_Missing_Check() )->run( $context )->status() );
	}

	public function test_a_drop_in_symlinked_inside_the_root_passes(): void {
		$this->tree->file( 'shared/object-cache.php', '<?php // drop-in' );
		$this->tree->link( 'wp-content/object-cache.php', $this->tree->site() . '/shared/object-cache.php' );

		$this->assertTrue( ( new Object_Cache_Missing_Check() )->run( Context_Factory::for_tree( $this->tree ) )->is_pass() );
	}

	public function test_a_drop_in_symlinked_outside_the_root_is_skipped_rather_than_reported_missing(): void {
		file_put_contents( $this->tree->outside() . '/object-cache.php', '<?php // drop-in' );
		$this->tree->link( 'wp-content/object-cache.php', $this->tree->outside() . '/object-cache.php' );

		$result = ( new Object_Cache_Missing_Check() )->run( Context_Factory::for_tree( $this->tree ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'outside the site root', (string) $result->message() );
	}

	public function test_a_broken_drop_in_symlink_is_reported_as_not_loading(): void {
		$this->tree->link( 'wp-content/object-cache.php', $this->tree->site() . '/nothing-here.php' );

		$finding = ( new Object_Cache_Missing_Check() )->run( Context_Factory::for_tree( $this->tree ) )->finding_list()[0];

		$this->assertSame( 'broken symlink', $finding->evidence()['found'] );
	}
}
