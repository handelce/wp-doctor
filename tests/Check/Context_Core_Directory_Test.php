<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Temp_Tree;

final class Context_Core_Directory_Test extends TestCase {

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

	public function test_the_default_content_directory_is_inside_the_core_directory(): void {
		$context = Context_Factory::for_core_in( $this->tree, 'wp' );

		$this->assertSame( 'wp', $context->site()->core_dir() );
		$this->assertNull( $context->content_dir_unavailable() );
		$this->assertSame( 'wp/wp-content', $context->content_dir() );
		$this->assertSame( 'wp/wp-content/uploads', $context->uploads_dir() );
		$this->assertSame( 'wp/wp-content/plugins', $context->plugins_dir() );
	}

	public function test_a_content_directory_beside_the_core_directory_is_followed(): void {
		$context = Context_Factory::for_core_in( $this->tree, 'wp', "define( 'WP_CONTENT_DIR', __DIR__ . '/wp-content' );\n" );

		$this->assertSame( 'wp-content', $context->content_dir() );
		$this->assertSame( 'wp-content/uploads', $context->uploads_dir() );
	}

	public function test_a_content_directory_built_on_abspath_is_inside_the_core_directory(): void {
		$context = Context_Factory::for_core_in( $this->tree, 'wp', "define( 'WP_CONTENT_DIR', ABSPATH . 'content' );\n" );

		$this->assertSame( 'wp/content', $context->content_dir() );
	}

	public function test_uploads_is_read_relative_to_the_core_directory(): void {
		$context = Context_Factory::for_core_in( $this->tree, 'wp', "define( 'UPLOADS', 'files/' );\n" );

		$this->assertSame( 'wp/files', $context->uploads_dir() );
	}

	public function test_uploads_naming_the_core_directory_itself_is_refused(): void {
		$context = Context_Factory::for_core_in( $this->tree, 'wp', "define( 'UPLOADS', '/' );\n" );

		$this->assertNotNull( $context->uploads_dir_unavailable() );
	}

	public function test_a_relative_upload_path_option_is_read_relative_to_the_core_directory(): void {
		$context = Context_Factory::for_core_in_with_options( $this->tree, 'wp', array( 'upload_path' => 'media' ) );

		$this->assertSame( 'wp/media', $context->uploads_dir() );
		$this->assertNull( $context->uploads_dir_unconfirmed() );
	}

	public function test_an_absolute_upload_path_in_the_web_root_is_followed(): void {
		$context = Context_Factory::for_core_in_with_options( $this->tree, 'wp', array( 'upload_path' => $this->tree->site() . '/media' ) );

		$this->assertSame( 'media', $context->uploads_dir() );
	}
}
