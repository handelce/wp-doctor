<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Site;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Site\Config_Parser;
use WP_Doctor\Site\Site;

final class Site_Test extends TestCase {

	/**
	 * A site with the given core directory.
	 *
	 * @param string $core_dir Directory holding core, relative to the root.
	 * @return Site
	 */
	private function site( string $core_dir ): Site {
		return new Site( '/srv/example.test', '/srv/example.test/wp-config.php', '6.5.2', ( new Config_Parser() )->parse( "<?php\n" ), $core_dir );
	}

	public function test_a_core_path_is_prefixed_with_the_core_directory(): void {
		$this->assertSame( 'wp/wp-includes/version.php', $this->site( 'wp' )->core_path( 'wp-includes/version.php' ) );
		$this->assertSame( 'a/b/wp-admin', $this->site( 'a/b' )->core_path( 'wp-admin' ) );
		$this->assertSame( 'wp-admin', $this->site( '' )->core_path( 'wp-admin' ) );
	}

	public function test_another_config_keeps_the_core_directory(): void {
		$site = $this->site( 'wp' );

		$this->assertSame( 'wp', $site->with_config( ( new Config_Parser() )->parse( "<?php\n" ) )->core_dir() );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function core_directories_that_are_not_child_paths(): array {
		return array(
			'parent'         => array( '..' ),
			'parent inside'  => array( 'wp/../..' ),
			'current'        => array( '.' ),
			'absolute'       => array( '/wp' ),
			'trailing slash' => array( 'wp/' ),
			'empty segment'  => array( 'a//b' ),
			'backslash'      => array( 'a\\b' ),
			'null byte'      => array( "wp\0" ),
		);
	}

	/**
	 * @dataProvider core_directories_that_are_not_child_paths
	 * @param string $core_dir Rejected core directory.
	 */
	public function test_a_core_directory_that_is_not_a_plain_child_path_is_refused( string $core_dir ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->site( $core_dir );
	}
}
