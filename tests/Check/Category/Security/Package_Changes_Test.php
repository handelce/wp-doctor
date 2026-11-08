<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Security;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Security\Package_Changes;
use WP_Doctor\Fs\File_Info;

final class Package_Changes_Test extends TestCase {

	private const NOW   = 1800000000;
	private const DAY   = 86400;
	private const SINCE = self::NOW - 7 * self::DAY;

	/**
	 * A file with the given change time.
	 *
	 * @param string $path  Path relative to the root.
	 * @param int    $ctime Inode change time.
	 * @return File_Info
	 */
	private static function file( string $path, int $ctime ): File_Info {
		return new File_Info( $path, 10, 1000000000, $ctime, 0644, File_Info::FILE );
	}

	/**
	 * Packages over the default layout, core in the root.
	 *
	 * @param string $core Core directory.
	 * @return Package_Changes
	 */
	private static function packages( string $core = '' ): Package_Changes {
		return new Package_Changes( 'wp-content/plugins', 'wp-content', $core );
	}

	/**
	 * Adds a plugin whose files were all written at one time.
	 *
	 * @param Package_Changes $packages Where to add it.
	 * @param string          $slug     Plugin folder.
	 * @param int             $ctime    When its files were written.
	 * @param int             $count    Number of PHP files.
	 */
	private static function plugin( Package_Changes $packages, string $slug, int $ctime, int $count ): void {
		for ( $n = 0; $n < $count; $n++ ) {
			$packages->add( self::file( "wp-content/plugins/$slug/inc/file-$n.php", $ctime + $n ), true );
		}
	}

	/**
	 * Paths of what written_apart() reports.
	 *
	 * @param Package_Changes $packages Packages to judge.
	 * @return string[]
	 */
	private static function apart( Package_Changes $packages ): array {
		$paths = array();
		foreach ( $packages->written_apart( self::SINCE ) as $row ) {
			$paths[] = $row['file']->path();
		}

		return $paths;
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function package_paths(): array {
		return array(
			'plugin folder'          => array( 'wp-content/plugins/example/inc/a.php', '', 'plugin example' ),
			'single-file plugin'     => array( 'wp-content/plugins/hello.php', '', 'plugin hello.php' ),
			'must-use plugin'        => array( 'wp-content/mu-plugins/loader.php', '', 'must-use plugin loader.php' ),
			'theme'                  => array( 'wp-content/themes/example/functions.php', '', 'theme example' ),
			'core top'               => array( 'wp-login.php', '', 'WordPress core' ),
			'core wp-includes'       => array( 'wp-includes/js/a.js', '', 'WordPress core' ),
			'uploads directory'      => array( 'wp-content/uploads/2026/11/x.php', '', 'directory wp-content/uploads/2026/11' ),
			'core in a subdirectory' => array( 'wp/wp-admin/admin.php', 'wp', 'WordPress core' ),
			'root beside the core'   => array( 'index.php', 'wp', 'the site root' ),
		);
	}

	/**
	 * @dataProvider package_paths
	 * @param string $path     Path relative to the root.
	 * @param string $core     Core directory.
	 * @param string $expected Package label.
	 */
	public function test_a_file_belongs_to_the_package_that_installs_it( string $path, string $core, string $expected ): void {
		$this->assertSame( $expected, self::packages( $core )->package( $path ) );
	}

	public function test_the_provider_has_samples(): void {
		$this->assertGreaterThan( 5, count( $this->package_paths() ) );
	}

	public function test_a_package_updated_as_a_whole_is_not_reported(): void {
		$packages = self::packages();
		self::plugin( $packages, 'old', self::NOW - 400 * self::DAY, 20 );
		self::plugin( $packages, 'updated', self::NOW - self::DAY, 20 );

		$this->assertSame( 40, $packages->judged() );
		$this->assertSame( array(), self::apart( $packages ) );
	}

	public function test_a_recent_file_in_an_old_package_is_reported_with_its_package(): void {
		$packages = self::packages();
		self::plugin( $packages, 'example', self::NOW - 400 * self::DAY, 20 );
		$packages->add( self::file( 'wp-content/plugins/example/inc/cache.php', self::NOW - 3600 ), true );

		$found = $packages->written_apart( self::SINCE );

		$this->assertCount( 1, $found );
		$this->assertSame( 'wp-content/plugins/example/inc/cache.php', $found[0]['file']->path() );
		$this->assertSame( 'plugin example', $found[0]['package'] );
		$this->assertSame( 21, $found[0]['package_files'] );
		$this->assertSame( 0, $found[0]['written_with'] );
	}

	public function test_an_old_change_apart_from_its_package_is_not_recent(): void {
		$packages = self::packages();
		self::plugin( $packages, 'example', self::NOW - 400 * self::DAY, 20 );
		$packages->add( self::file( 'wp-content/plugins/example/inc/cache.php', self::SINCE - 1 ), true );

		$this->assertSame( array(), self::apart( $packages ) );
	}

	public function test_files_that_are_not_judged_still_count_towards_their_package(): void {
		$packages = self::packages();
		$packages->add( self::file( 'wp-content/plugins/hello.php', self::NOW - self::DAY ), true );
		$packages->add( self::file( 'wp-content/plugins/example/example.php', self::NOW - self::DAY ), true );
		for ( $n = 0; $n < 5; $n++ ) {
			$packages->add( self::file( "wp-content/plugins/example/assets/$n.js", self::NOW - self::DAY + 10 ), false );
		}

		$this->assertSame( 2, $packages->judged() );
		$this->assertSame( array( 'wp-content/plugins/hello.php' ), self::apart( $packages ) );
	}

	public function test_half_of_the_other_files_written_within_the_hour_counts_as_a_package_write(): void {
		$packages = self::packages();
		self::plugin( $packages, 'example', self::NOW - 400 * self::DAY, 2 );
		self::plugin( $packages, 'example', self::NOW - self::DAY, 3 );

		$this->assertSame( array(), self::apart( $packages ) );

		$packages->add( self::file( 'wp-content/plugins/example/extra.php', self::NOW - 400 * self::DAY ), true );
		$this->assertCount( 3, self::apart( $packages ) );
	}

	public function test_files_written_within_one_hour_are_written_together(): void {
		$packages = self::packages();
		self::plugin( $packages, 'a', self::NOW, 10 );
		$packages->add( self::file( 'wp-login.php', self::NOW + Package_Changes::WINDOW_SECONDS ), true );

		$this->assertTrue( $packages->written_together() );

		$packages->add( self::file( 'wp-load.php', self::NOW + Package_Changes::WINDOW_SECONDS + 1 ), true );
		$this->assertFalse( $packages->written_together() );
	}

	public function test_reports_are_newest_first(): void {
		$packages = self::packages();
		self::plugin( $packages, 'example', self::NOW - 400 * self::DAY, 20 );
		$packages->add( self::file( 'wp-content/plugins/example/a.php', self::NOW - 5 * self::DAY ), true );
		$packages->add( self::file( 'wp-content/plugins/example/b.php', self::NOW - 2 * self::DAY ), true );

		$this->assertSame( array( 'wp-content/plugins/example/b.php', 'wp-content/plugins/example/a.php' ), self::apart( $packages ) );
	}
}
