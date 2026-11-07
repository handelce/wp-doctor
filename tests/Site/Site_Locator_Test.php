<?php
/**
 * Tests for Site_Locator.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Site;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Site\Config;
use WP_Doctor\Site\Config_Parser;
use WP_Doctor\Site\Site_Locator;
use WP_Doctor\Site\Site_Not_Found_Exception;
use WP_Doctor\Tests\Support\File_Modes;
use WP_Doctor\Tests\Support\Temp_Tree;

final class Site_Locator_Test extends TestCase {

	private function fixture( string $name ): string {
		return dirname( __DIR__ ) . '/fixtures/sites/' . $name;
	}

	private function locator(): Site_Locator {
		return new Site_Locator( new Config_Parser() );
	}

	public function test_a_site_with_its_config_in_the_root_is_located(): void {
		$site = $this->locator()->locate( $this->fixture( 'plain' ) );

		$this->assertSame( realpath( $this->fixture( 'plain' ) ), $site->root() );
		$this->assertSame( realpath( $this->fixture( 'plain' ) ) . '/wp-config.php', $site->config_path() );
		$this->assertSame( '6.5.2', $site->wp_version() );
	}

	public function test_the_located_config_holds_the_fixture_values(): void {
		$config = $this->locator()->locate( $this->fixture( 'plain' ) )->config();

		$this->assertSame( 'example_db', $config->value( 'DB_NAME' ) );
		$this->assertSame( 'wptest_', $config->table_prefix() );
		$this->assertTrue( 'fake-db-password-0001' === $config->value( 'DB_PASSWORD' ), 'DB_PASSWORD did not match the fixture' );
		$this->assertSame( array(), $config->unresolved() );
		$this->assertSame(
			array(
				'base'   => '__DIR__',
				'suffix' => '/',
			),
			$config->path_constant( 'ABSPATH' )
		);
	}

	public function test_a_config_one_directory_above_the_core_is_found(): void {
		$site = $this->locator()->locate( $this->fixture( 'config-above/public' ) );

		$this->assertSame( realpath( $this->fixture( 'config-above' ) ) . '/wp-config.php', $site->config_path() );
		$this->assertSame( realpath( $this->fixture( 'config-above/public' ) ), $site->root() );
	}

	public function test_a_relative_path_with_dot_segments_is_resolved(): void {
		$site = $this->locator()->locate( $this->fixture( 'plain' ) . '/wp-content/..' );

		$this->assertSame( realpath( $this->fixture( 'plain' ) ), $site->root() );
	}

	/**
	 * @dataProvider unlocatable_paths
	 * @param string $path     Path relative to the fixtures, or absolute.
	 * @param string $fragment Text the message must contain.
	 */
	public function test_a_path_that_is_not_a_wordpress_site_is_rejected_with_a_reason( string $path, string $fragment ): void {
		$full = 0 === strpos( $path, '/' ) ? $path : $this->fixture( $path );

		try {
			$this->locator()->locate( $full );
			$this->fail( "$path was located" );
		} catch ( Site_Not_Found_Exception $e ) {
			$this->assertStringContainsString( $fragment, $e->getMessage() );
		}
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function unlocatable_paths(): array {
		return array(
			'missing path'    => array( '/nonexistent/example.test', 'does not exist' ),
			'not a WordPress' => array( 'not-wordpress', 'wp-includes/version.php' ),
			'a file'          => array( 'not-wordpress/index.html', 'not a directory' ),
			'no wp-config'    => array( 'no-config', 'wp-config.php' ),
		);
	}

	public function test_the_unlocatable_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->unlocatable_paths() );
	}

	public function test_computed_values_in_the_fixture_are_recorded_not_resolved(): void {
		$config = $this->locator()->locate( $this->fixture( 'computed' ) )->config();

		$this->assertSame( 'example_user', $config->value( 'DB_USER' ) );
		foreach ( array( 'DB_NAME', 'DB_PASSWORD', 'DB_HOST', 'WP_DEBUG' ) as $name ) {
			$this->assertFalse( $config->has( $name ), "$name was resolved" );
			$this->assertArrayHasKey( $name, $config->unresolved() );
		}
		$this->assertFalse( $config->has( 'WP_CONTENT_DIR' ) );
		$this->assertSame( '/content', $config->path_constant( 'WP_CONTENT_DIR' )['suffix'] );
		$this->assertSame( 1, $config->computed_name_defines() );
		$this->assertSame( 1, $config->unread_code() );
		$this->assertNotNull( $config->skip_reason( Config::TABLE_PREFIX ) );
	}

	public function test_locating_a_site_does_not_execute_its_config(): void {
		$contents = (string) file_get_contents( $this->fixture( 'computed' ) . '/wp-config.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertStringContainsString( 'wpd_canary_executed', $contents, 'The fixture lost its canary' );

		$this->locator()->locate( $this->fixture( 'computed' ) );

		$this->assertArrayNotHasKey( 'wpd_canary_executed', $GLOBALS );
	}

	public function test_an_oversized_config_is_located_with_a_reason_instead_of_values(): void {
		$dir = sys_get_temp_dir() . '/wpd-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir . '/wp-includes', 0777, true );
		file_put_contents( $dir . '/wp-includes/version.php', "<?php \$wp_version = '6.5.2';" );
		file_put_contents( $dir . '/wp-config.php', '<?php ' . str_repeat( '/' . str_repeat( 'x', 1000 ) . "\n", 1100 ) );

		try {
			$site = $this->locator()->locate( $dir );

			$this->assertStringContainsString( 'larger than', (string) $site->config()->unreadable_reason() );
			$this->assertSame( array(), $site->config()->constants() );
		} finally {
			unlink( $dir . '/wp-config.php' );
			unlink( $dir . '/wp-includes/version.php' );
			rmdir( $dir . '/wp-includes' );
			rmdir( $dir );
		}
	}

	public function test_the_version_is_null_when_it_cannot_be_read(): void {
		$dir = sys_get_temp_dir() . '/wpd-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dir . '/wp-includes', 0777, true );
		file_put_contents( $dir . '/wp-includes/version.php', '<?php // no version here' );
		file_put_contents( $dir . '/wp-config.php', '<?php $table_prefix = "wp_";' );

		try {
			$this->assertNull( $this->locator()->locate( $dir )->wp_version() );
		} finally {
			unlink( $dir . '/wp-config.php' );
			unlink( $dir . '/wp-includes/version.php' );
			rmdir( $dir . '/wp-includes' );
			rmdir( $dir );
		}
	}

	public function test_a_version_file_that_is_a_symlink_out_of_the_root_is_not_read(): void {
		$tree = new Temp_Tree();
		try {
			$tree->file( 'wp-config.php', "<?php\n\$table_prefix = 'wptest_';\n" );
			$outside = $tree->outside();
			file_put_contents( "$outside/version.php", "<?php\n\$wp_version = '9.9.9';\n" );
			mkdir( $tree->site() . '/wp-includes' );
			$tree->link( 'wp-includes/version.php', "$outside/version.php" );

			$site = $this->locator()->locate( $tree->site() );

			$this->assertNull( $site->wp_version(), 'The version was read through a symlink that leaves the root' );
		} finally {
			$tree->remove();
		}
	}

	public function test_core_in_a_subdirectory_is_rejected_with_the_directory_to_scan_instead(): void {
		$tree = new Temp_Tree();
		try {
			$tree->file( 'wp-config.php', "<?php\n\$table_prefix = 'wptest_';\n" );
			$tree->file( 'index.php', "<?php\ndefine( 'WP_USE_THEMES', true );\nrequire __DIR__ . '/wp/wp-blog-header.php';\n" );
			$tree->file( 'wp/wp-includes/version.php', "<?php\n\$wp_version = '6.5.2';\n" );

			try {
				$this->locator()->locate( $tree->site() );
				$this->fail( 'A web root with core in a subdirectory was located as a site' );
			} catch ( Site_Not_Found_Exception $e ) {
				$this->assertStringContainsString( 'subdirectory wp', $e->getMessage() );
				$this->assertStringContainsString( $tree->site() . '/wp', $e->getMessage() );
				$this->assertStringContainsString( 'wp-content will skip', $e->getMessage() );
			}
		} finally {
			$tree->remove();
		}
	}

	public function test_a_single_child_holding_core_is_named_even_without_the_index_hint(): void {
		$tree = new Temp_Tree();
		try {
			$tree->file( 'wp-config.php', "<?php\n\$table_prefix = 'wptest_';\n" );
			$tree->file( 'core/wp-includes/version.php', "<?php\n\$wp_version = '6.5.2';\n" );
			$tree->file( 'wp-content/uploads/a.txt' );

			$this->expectException( Site_Not_Found_Exception::class );
			$this->expectExceptionMessage( 'subdirectory core' );
			$this->locator()->locate( $tree->site() );
		} finally {
			$tree->remove();
		}
	}

	public function test_a_root_without_core_or_a_single_core_child_keeps_the_plain_message(): void {
		$tree = new Temp_Tree();
		try {
			$tree->file( 'wp-config.php', "<?php\n\$table_prefix = 'wptest_';\n" );
			$tree->file( 'a/wp-includes/version.php', "<?php\n" );
			$tree->file( 'b/wp-includes/version.php', "<?php\n" );

			$this->expectException( Site_Not_Found_Exception::class );
			$this->expectExceptionMessage( 'wp-includes/version.php is missing' );
			$this->locator()->locate( $tree->site() );
		} finally {
			$tree->remove();
		}
	}

	public function test_a_config_the_scanning_account_cannot_read_is_located_with_a_reason(): void {
		File_Modes::require_enforced_modes();
		$tree = new Temp_Tree();
		try {
			$tree->file( 'wp-includes/version.php', "<?php\n\$wp_version = '6.5.2';\n" );
			$config = $tree->file( 'wp-config.php', "<?php\ndefine( 'DB_NAME', 'example' );\n" );
			chmod( $config, 0000 );

			$site = $this->locator()->locate( $tree->site() );

			$this->assertStringContainsString( 'could not be read', (string) $site->config()->unreadable_reason() );
			$this->assertFalse( $site->config()->has( 'DB_NAME' ) );
			$this->assertSame( '6.5.2', $site->wp_version() );
		} finally {
			chmod( $tree->site() . '/wp-config.php', 0644 );
			$tree->remove();
		}
	}
}
