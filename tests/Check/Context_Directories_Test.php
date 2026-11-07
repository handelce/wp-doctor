<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check;

use WP_Doctor\Site\Config;
use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Context;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Temp_Tree;

final class Context_Directories_Test extends TestCase {

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

	/**
	 * A context over a site whose wp-config.php holds the given statements.
	 *
	 * @param string $config PHP statements.
	 * @return Context
	 */
	private function context( string $config ): Context {
		$this->tree->file( 'wp-config.php', "<?php\n\$table_prefix = 'wptest_';\n" . $config );

		return Context_Factory::for_tree( $this->tree );
	}

	public function test_the_defaults_apply_when_nothing_moves_a_directory(): void {
		$context = $this->context( '' );

		$this->assertNull( $context->content_dir_unavailable() );
		$this->assertSame( 'wp-content', $context->content_dir() );
		$this->assertSame( 'wp-content/uploads', $context->uploads_dir() );
		$this->assertSame( 'wp-content/plugins', $context->plugins_dir() );
	}

	/**
	 * @dataProvider moved_content_directories
	 * @param string $define   The define statement.
	 * @param string $expected Directory relative to the root.
	 */
	public function test_a_content_directory_inside_the_root_is_followed( string $define, string $expected ): void {
		$context = $this->context( $define );

		$this->assertNull( $context->content_dir_unavailable() );
		$this->assertSame( $expected, $context->content_dir() );
		$this->assertSame( "$expected/uploads", $context->uploads_dir() );
		$this->assertSame( "$expected/plugins", $context->plugins_dir() );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function moved_content_directories(): array {
		return array(
			'__DIR__'          => array( "define( 'WP_CONTENT_DIR', __DIR__ . '/app' );", 'app' ),
			'dirname __FILE__' => array( "define( 'WP_CONTENT_DIR', dirname( __FILE__ ) . '/app/content/' );", 'app/content' ),
			'ABSPATH'          => array( "define( 'WP_CONTENT_DIR', ABSPATH . 'content' );", 'content' ),
		);
	}

	public function test_the_moved_content_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->moved_content_directories() );
	}

	public function test_an_absolute_literal_inside_the_root_is_followed(): void {
		$context = $this->context( "define( 'WP_CONTENT_DIR', '" . $this->tree->site() . "/app' );" );

		$this->assertSame( 'app', $context->content_dir() );
	}

	public function test_a_content_directory_outside_the_root_is_unavailable_with_the_reason(): void {
		$context = $this->context( "define( 'WP_CONTENT_DIR', dirname( __DIR__ ) . '/shared' );" );
		$literal = $this->context( "define( 'WP_CONTENT_DIR', '/srv/elsewhere/content' );" );

		$this->assertStringContainsString( 'could not be read', (string) $context->content_dir_unavailable() );
		$this->assertStringContainsString( 'outside the site root', (string) $literal->content_dir_unavailable() );
		$this->assertStringContainsString( 'outside the site root', (string) $literal->uploads_dir_unavailable() );
		$this->assertStringContainsString( 'outside the site root', (string) $literal->plugins_dir_unavailable() );
	}

	public function test_a_parent_segment_or_the_root_itself_is_unavailable(): void {
		$parent = $this->context( "define( 'WP_CONTENT_DIR', __DIR__ . '/../content' );" );
		$root   = $this->context( "define( 'WP_CONTENT_DIR', __DIR__ . '/' );" );

		$this->assertStringContainsString( 'parent-directory', (string) $parent->content_dir_unavailable() );
		$this->assertStringContainsString( 'site root itself', (string) $root->content_dir_unavailable() );
	}

	public function test_a_computed_content_directory_is_unavailable_with_the_parser_reason(): void {
		$context = $this->context( "define( 'WP_CONTENT_DIR', getenv( 'CONTENT' ) );" );

		$this->assertSame( 'WP_CONTENT_DIR is set in wp-config.php but could not be read (computed value)', $context->content_dir_unavailable() );
		$this->expectException( \LogicException::class );
		$context->content_dir();
	}

	public function test_a_literal_uploads_constant_is_relative_to_the_root(): void {
		$context = $this->context( "define( 'UPLOADS', 'files/' );" );
		$moved   = $this->context( "define( 'WP_CONTENT_DIR', __DIR__ . '/app' );\ndefine( 'UPLOADS', 'app/media' );" );

		$this->assertSame( 'files', $context->uploads_dir() );
		$this->assertSame( 'wp-content', $context->content_dir() );
		$this->assertSame( 'app/media', $moved->uploads_dir() );
	}

	public function test_an_unreadable_or_escaping_uploads_constant_is_unavailable(): void {
		$computed = $this->context( "define( 'UPLOADS', getenv( 'U' ) );" );
		$path     = $this->context( "define( 'UPLOADS', __DIR__ . '/files' );" );
		$parent   = $this->context( "define( 'UPLOADS', '../files' );" );

		$this->assertStringContainsString( 'could not be read (computed value)', (string) $computed->uploads_dir_unavailable() );
		$this->assertStringContainsString( 'built from a path', (string) $path->uploads_dir_unavailable() );
		$this->assertStringContainsString( 'parent-directory', (string) $parent->uploads_dir_unavailable() );
		$this->assertNull( $parent->content_dir_unavailable() );
	}

	public function test_a_moved_plugin_directory_makes_the_plugin_directories_unavailable_but_not_content(): void {
		$plugin = $this->context( "define( 'WP_PLUGIN_DIR', __DIR__ . '/plugins' );" );
		$mu     = $this->context( "define( 'WPMU_PLUGIN_DIR', getenv( 'MU' ) );" );

		$this->assertStringContainsString( 'WP_PLUGIN_DIR is set', (string) $plugin->plugins_dir_unavailable() );
		$this->assertStringContainsString( 'WPMU_PLUGIN_DIR is set', (string) $mu->plugins_dir_unavailable() );
		$this->assertNull( $plugin->content_dir_unavailable() );
		$this->assertNull( $plugin->uploads_dir_unavailable() );
		$this->expectException( \LogicException::class );
		$plugin->plugins_dir();
	}

	public function test_the_debug_log_defaults_to_the_content_directory_and_follows_a_literal_inside_the_root(): void {
		$default = $this->context( "define( 'WP_DEBUG_LOG', true );" );
		$moved   = $this->context( "define( 'WP_CONTENT_DIR', __DIR__ . '/app' );" );
		$custom  = $this->context( "define( 'WP_DEBUG_LOG', '" . $this->tree->site() . "/logs/wp.log' );" );
		$outside = $this->context( "define( 'WP_DEBUG_LOG', '/var/log/wp.log' );" );

		$this->assertSame( 'wp-content/debug.log', $default->debug_log_path() );
		$this->assertSame( 'app/debug.log', $moved->debug_log_path() );
		$this->assertSame( 'logs/wp.log', $custom->debug_log_path() );
		$this->assertStringContainsString( 'outside the site root', (string) $outside->debug_log_unavailable() );
		$this->expectException( \LogicException::class );
		$outside->debug_log_path();
	}

	public function test_no_directory_is_assumed_when_wp_config_could_not_be_read(): void {
		$context = $this->context( '' );
		$site    = $context->site()->with_config( Config::unreadable( 'wp-config.php could not be read in this test' ) );
		$context = Context::without_database( $site, $context->files(), 'no database in this test' );

		$reasons = array(
			'content' => $context->content_dir_unavailable(),
			'uploads' => $context->uploads_dir_unavailable(),
			'plugins' => $context->plugins_dir_unavailable(),
			'log'     => $context->debug_log_unavailable(),
		);

		$this->assertCount( 4, $reasons );
		foreach ( $reasons as $directory => $reason ) {
			$this->assertStringContainsString( 'could not be read in this test', (string) $reason, $directory );
		}
	}

	/**
	 * A context whose options table holds the given upload_path.
	 *
	 * @param string $upload_path Value of the upload_path option.
	 * @param string $config      PHP statements for wp-config.php.
	 * @return Context
	 */
	private function context_with_upload_path( string $upload_path, string $config = '' ): Context {
		$this->tree->file( 'wp-config.php', "<?php\n\$table_prefix = 'wptest_';\n" . $config );

		return Context_Factory::for_tree_with_options( $this->tree, array( 'upload_path' => $upload_path ) );
	}

	public function test_a_relative_upload_path_option_moves_the_uploads_directory(): void {
		$context = $this->context_with_upload_path( 'wp-content/files' );

		$this->assertSame( 'wp-content/files', $context->uploads_dir() );
		$this->assertNull( $context->uploads_dir_unconfirmed() );
	}

	public function test_an_absolute_upload_path_inside_the_root_is_followed(): void {
		$context = $this->context_with_upload_path( $this->tree->site() . '/media/' );

		$this->assertSame( 'media', $context->uploads_dir() );
	}

	public function test_an_upload_path_outside_the_root_makes_uploads_unavailable(): void {
		$context = $this->context_with_upload_path( '/srv/shared/uploads' );

		$this->assertStringContainsString( 'upload_path', (string) $context->uploads_dir_unavailable() );
	}

	public function test_an_empty_upload_path_keeps_the_default_and_is_confirmed(): void {
		$context = $this->context_with_upload_path( '  ' );

		$this->assertSame( 'wp-content/uploads', $context->uploads_dir() );
		$this->assertNull( $context->uploads_dir_unconfirmed() );
	}

	public function test_the_uploads_constant_wins_over_the_upload_path_option(): void {
		$context = $this->context_with_upload_path( 'wp-content/files', "define( 'UPLOADS', 'media' );\n" );

		$this->assertSame( 'media', $context->uploads_dir() );
		$this->assertNull( $context->uploads_dir_unconfirmed() );
	}

	public function test_without_a_database_the_default_uploads_directory_is_unconfirmed(): void {
		$context = $this->context( '' );

		$this->assertSame( 'wp-content/uploads', $context->uploads_dir() );
		$this->assertStringContainsString( 'upload_path', (string) $context->uploads_dir_unconfirmed() );
	}

	public function test_a_literal_content_directory_written_through_a_symlinked_root_is_followed(): void {
		$alias = $this->tree->outside() . '/public_html';
		symlink( $this->tree->site(), $alias );
		$this->tree->file( 'wp-content/object-cache.php', '<?php' );
		$this->assertSame( realpath( $this->tree->site() ), realpath( $alias ) );

		$context = $this->context( "define( 'WP_CONTENT_DIR', '$alias/wp-content' );\n" );

		$this->assertNull( $context->content_dir_unavailable() );
		$this->assertSame( 'wp-content', $context->content_dir() );
	}
}
