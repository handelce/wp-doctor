<?php
/**
 * Tests for normalise_separators() and real_path().
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests;

use PHPUnit\Framework\TestCase;

use function WP_Doctor\normalise_separators;
use function WP_Doctor\real_path;

final class Real_Path_Test extends TestCase {

	public function test_backslashes_become_slashes_where_the_separator_is_a_backslash(): void {
		$this->assertSame( 'C:/sites/example.test/wp-content', normalise_separators( 'C:\\sites\\example.test\\wp-content', '\\' ) );
	}

	public function test_a_path_is_unchanged_where_the_separator_is_a_slash(): void {
		$this->assertSame( '/srv/a\\b', normalise_separators( '/srv/a\\b', '/' ) );
	}

	public function test_a_path_that_exists_is_resolved(): void {
		$resolved = real_path( __DIR__ . '/../tests' );

		$this->assertIsString( $resolved );
		$this->assertSame( normalise_separators( (string) realpath( __DIR__ ) ), $resolved );
	}

	public function test_a_path_that_does_not_exist_is_false(): void {
		$this->assertFalse( real_path( __DIR__ . '/missing-directory' ) );
	}
}
