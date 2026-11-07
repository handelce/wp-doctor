<?php
/**
 * Tests for File_Scanner.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Fs;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Fs\File_Info;
use WP_Doctor\Fs\File_Scanner;
use WP_Doctor\Fs\File_Too_Large_Exception;
use WP_Doctor\Fs\Path_Unavailable_Exception;
use WP_Doctor\Fs\Walk;
use WP_Doctor\Tests\Support\Temp_Tree;

final class File_Scanner_Test extends TestCase {

	/**
	 * Tree built for the current test.
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

	/**
	 * Relative paths of the files a walk yields.
	 *
	 * @param File_Scanner $scanner  Scanner to walk with.
	 * @param string       $relative Start directory.
	 * @return string[]
	 */
	private function walked_files( File_Scanner $scanner, string $relative = '' ): array {
		$paths = array();
		foreach ( $scanner->walk( $relative ) as $info ) {
			if ( $info->is_file() ) {
				$paths[] = $info->path();
			}
		}

		return $paths;
	}

	public function test_a_walk_lists_files_recursively_in_sorted_order(): void {
		$this->tree->file( 'b.txt' );
		$this->tree->file( 'a.txt' );
		$this->tree->file( 'wp-content/uploads/2026/x.jpg', '12345' );

		$scanner = new File_Scanner( $this->tree->site() );

		$this->assertSame( array( 'a.txt', 'b.txt', 'wp-content/uploads/2026/x.jpg' ), $this->walked_files( $scanner ) );
	}

	public function test_a_walk_can_start_in_a_subdirectory(): void {
		$this->tree->file( 'a.txt' );
		$this->tree->file( 'wp-content/uploads/x.jpg' );

		$scanner = new File_Scanner( $this->tree->site() );

		$this->assertSame( array( 'wp-content/uploads/x.jpg' ), $this->walked_files( $scanner, 'wp-content/uploads' ) );
	}

	public function test_an_entry_reports_size_type_and_permissions(): void {
		$path = $this->tree->file( 'dir/file.txt', '12345' );
		chmod( $path, 0640 );

		$entries = iterator_to_array( ( new File_Scanner( $this->tree->site() ) )->walk(), false );

		$this->assertCount( 2, $entries );
		$this->assertTrue( $entries[0]->is_dir() );
		$this->assertSame( 'dir', $entries[0]->path() );
		$this->assertTrue( $entries[1]->is_file() );
		$this->assertSame( 5, $entries[1]->size() );
		$this->assertSame( 0640, $entries[1]->mode() );
		$this->assertGreaterThan( 0, $entries[1]->mtime() );
	}

	public function test_a_non_recursive_walk_lists_one_level(): void {
		$this->tree->file( 'a.txt' );
		$this->tree->file( 'sub/b.txt' );

		$paths = array();
		foreach ( ( new File_Scanner( $this->tree->site() ) )->walk( '', false ) as $info ) {
			$paths[] = $info->path();
		}

		$this->assertSame( array( 'a.txt', 'sub' ), $paths );
	}

	public function test_the_entry_cap_stops_a_walk_and_is_reported(): void {
		for ( $i = 1; $i <= 6; $i++ ) {
			$this->tree->file( "f$i.txt" );
		}
		$scanner = new File_Scanner( $this->tree->site(), 4 );

		$this->assertCount( 4, $this->walked_files( $scanner ) );
		$this->assertCount( 1, $scanner->notices() );
		$this->assertStringContainsString( '4', $scanner->notices()[0] );
	}

	public function test_the_cap_applies_to_each_walk_separately(): void {
		for ( $i = 1; $i <= 3; $i++ ) {
			$this->tree->file( "f$i.txt" );
		}
		$scanner = new File_Scanner( $this->tree->site(), 3 );

		$this->assertCount( 3, $this->walked_files( $scanner ) );
		$this->assertCount( 3, $this->walked_files( $scanner ) );
		$this->assertSame( array(), $scanner->notices() );
	}

	public function test_a_symlink_leaving_the_root_is_neither_listed_nor_followed(): void {
		$secret = $this->tree->outside() . '/secret.txt';
		file_put_contents( $secret, 'outside' );
		mkdir( $this->tree->outside() . '/dir' );
		file_put_contents( $this->tree->outside() . '/dir/inner.txt', 'outside' );
		$this->tree->file( 'kept.txt' );
		$this->tree->link( 'escape-file', $secret );
		$this->tree->link( 'escape-dir', $this->tree->outside() . '/dir' );
		$this->assertTrue( is_link( $this->tree->site() . '/escape-file' ), 'The test links were not created' );

		$scanner = new File_Scanner( $this->tree->site() );

		$this->assertSame( array( 'kept.txt' ), $this->walked_files( $scanner ) );
		$this->assertNull( $scanner->read( 'escape-file' ) );
		$this->assertNull( $scanner->stat( 'escape-file' ) );
		$this->assertNull( $scanner->hash( 'escape-file' ) );
		$this->assertNull( $scanner->read( 'escape-dir/inner.txt' ) );
		$this->assertNotEmpty( $scanner->notices() );
		$this->assertStringContainsString( 'symlink', implode( ' ', $scanner->notices() ) );
	}

	public function test_a_symlink_inside_the_root_is_listed_but_a_linked_directory_is_not_descended(): void {
		$this->tree->file( 'real/inner.txt', 'inside' );
		$this->tree->file( 'target.txt', 'inside' );
		$this->tree->link( 'file-link', $this->tree->site() . '/target.txt' );
		$this->tree->link( 'dir-link', $this->tree->site() . '/real' );

		$scanner = new File_Scanner( $this->tree->site() );
		$links   = array();
		$files   = array();
		foreach ( $scanner->walk() as $info ) {
			if ( $info->is_link() ) {
				$links[] = $info->path();
			}
			if ( $info->is_file() ) {
				$files[] = $info->path();
			}
		}

		$this->assertSame( array( 'dir-link', 'file-link' ), $links );
		$this->assertSame( array( 'real/inner.txt', 'target.txt' ), $files );
		$this->assertSame( 'inside', $scanner->read( 'file-link' ) );
	}

	public function test_a_broken_symlink_is_skipped_and_reported(): void {
		$this->tree->file( 'kept.txt' );
		$this->tree->link( 'dangling', $this->tree->site() . '/missing' );

		$scanner = new File_Scanner( $this->tree->site() );

		$this->assertSame( array( 'kept.txt' ), $this->walked_files( $scanner ) );
		$this->assertNotEmpty( $scanner->notices() );
	}

	/**
	 * @dataProvider escaping_paths
	 * @param string $relative Path that tries to leave the root.
	 */
	public function test_a_path_that_leaves_the_root_cannot_be_read( string $relative ): void {
		file_put_contents( $this->tree->outside() . '/secret.txt', 'outside' );
		$this->tree->file( 'inside.txt' );
		$scanner = new File_Scanner( $this->tree->site() );

		$this->assertNull( $scanner->read( $relative ), $relative );
		$this->assertNull( $scanner->stat( $relative ), $relative );
		$this->assertNull( $scanner->hash( $relative ), $relative );
		$this->assertSame( 'x', $scanner->read( 'inside.txt' ) );
		$this->assertSame( array(), $scanner->notices(), 'A traversal attempt was reported as a symlink' );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function escaping_paths(): array {
		return array(
			'parent traversal'        => array( '../outside/secret.txt' ),
			'nested traversal'        => array( 'a/../../outside/secret.txt' ),
			'traversal in the middle' => array( 'inside.txt/../../outside/secret.txt' ),
			'absolute path'           => array( '/etc/hosts' ),
			'nul byte'                => array( "inside.txt\0.php" ),
		);
	}

	public function test_the_escaping_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->escaping_paths() );
	}

	public function test_a_missing_file_reads_as_null(): void {
		$scanner = new File_Scanner( $this->tree->site() );

		$this->assertNull( $scanner->read( 'nope.txt' ) );
		$this->assertNull( $scanner->stat( 'nope.txt' ) );
		$this->assertNull( $scanner->hash( 'nope.txt' ) );
	}

	public function test_a_directory_cannot_be_read_as_a_file(): void {
		$this->tree->file( 'dir/a.txt' );

		$this->assertNull( ( new File_Scanner( $this->tree->site() ) )->read( 'dir' ) );
	}

	public function test_a_read_stops_at_the_byte_cap_and_says_so(): void {
		$this->tree->file( 'big.log', str_repeat( 'a', 100 ) );
		$scanner = new File_Scanner( $this->tree->site(), 100, 40 );

		$this->assertSame( str_repeat( 'a', 40 ), $scanner->read( 'big.log' ) );
		$this->assertNotEmpty( $scanner->notices() );
		$this->assertStringContainsString( '40', implode( ' ', $scanner->notices() ) );
	}

	public function test_a_requested_prefix_is_not_reported_as_truncation(): void {
		$this->tree->file( 'big.log', str_repeat( 'a', 100 ) );
		$scanner = new File_Scanner( $this->tree->site(), 100, 40 );

		$this->assertSame( 'aaaaa', $scanner->read( 'big.log', 5 ) );
		$this->assertSame( array(), $scanner->notices() );
	}

	public function test_a_requested_length_cannot_exceed_the_byte_cap(): void {
		$this->tree->file( 'big.log', str_repeat( 'a', 100 ) );
		$scanner = new File_Scanner( $this->tree->site(), 100, 40 );

		$this->assertSame( 40, strlen( (string) $scanner->read( 'big.log', 1000 ) ) );
	}

	public function test_a_negative_length_is_rejected(): void {
		$this->tree->file( 'a.txt' );

		$this->expectException( \InvalidArgumentException::class );
		( new File_Scanner( $this->tree->site() ) )->read( 'a.txt', -1 );
	}

	public function test_hashes_match_the_file_content(): void {
		$path    = $this->tree->file( 'a.txt', 'hello' );
		$scanner = new File_Scanner( $this->tree->site() );

		$this->assertSame( md5_file( $path ), $scanner->hash( 'a.txt' ) );
		$this->assertSame( hash( 'sha256', 'hello' ), $scanner->hash( 'a.txt', 'sha256' ) );
		$this->assertSame( 32, strlen( (string) $scanner->hash( 'a.txt' ) ) );
	}

	public function test_an_unknown_hash_algorithm_is_rejected(): void {
		$this->tree->file( 'a.txt' );

		$this->expectException( \InvalidArgumentException::class );
		( new File_Scanner( $this->tree->site() ) )->hash( 'a.txt', 'crc32' );
	}

	public function test_excluded_paths_are_invisible(): void {
		$this->tree->file( 'wp-content/cache/a/b.html' );
		$this->tree->file( 'wp-content/uploads/x.jpg' );
		$this->tree->file( 'notes.tmp' );
		$this->tree->file( 'sub/notes.tmp' );
		$scanner = new File_Scanner( $this->tree->site(), 1000, 1000, array( 'wp-content/cache/**', '**/*.tmp' ) );

		$this->assertSame( array( 'wp-content/uploads/x.jpg' ), $this->walked_files( $scanner ) );
		$this->assertNull( $scanner->read( 'wp-content/cache/a/b.html' ) );
		$this->assertNull( $scanner->stat( 'sub/notes.tmp' ) );
	}

	public function test_a_single_star_does_not_cross_directories(): void {
		$this->tree->file( 'a.tmp' );
		$this->tree->file( 'sub/b.tmp' );
		$scanner = new File_Scanner( $this->tree->site(), 1000, 1000, array( '*.tmp' ) );

		$this->assertSame( array( 'sub/b.tmp' ), $this->walked_files( $scanner ) );
	}

	/**
	 * @dataProvider unavailable_directories
	 * @param string $relative Directory to walk.
	 */
	public function test_walking_a_directory_that_is_not_available_is_an_error( string $relative ): void {
		$this->tree->file( 'a.txt' );
		$this->tree->link( 'escape', $this->tree->outside() );
		$scanner = new File_Scanner( $this->tree->site() );

		$this->expectException( Path_Unavailable_Exception::class );
		iterator_to_array( $scanner->walk( $relative ) );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function unavailable_directories(): array {
		return array(
			'missing'          => array( 'nope' ),
			'a file'           => array( 'a.txt' ),
			'outside via link' => array( 'escape' ),
			'traversal'        => array( '../outside' ),
		);
	}

	public function test_the_unavailable_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->unavailable_directories() );
	}

	public function test_an_unreadable_subdirectory_is_skipped_and_reported(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Permissions are not enforced for root.' );
		}
		$this->tree->file( 'open/a.txt' );
		$this->tree->file( 'closed/b.txt' );
		chmod( $this->tree->site() . '/closed', 0000 );
		$scanner = new File_Scanner( $this->tree->site() );

		$this->assertSame( array( 'open/a.txt' ), $this->walked_files( $scanner ) );
		$this->assertStringContainsString( 'unreadable', implode( ' ', $scanner->notices() ) );
	}

	public function test_the_root_must_exist(): void {
		$this->expectException( \InvalidArgumentException::class );
		new File_Scanner( $this->tree->site() . '/missing' );
	}

	public function test_the_scanner_exposes_no_method_that_writes(): void {
		$allowed = array( '__construct', 'walk', 'stat', 'presence', 'read', 'hash', 'notices', 'root', 'has_unix_permissions' );
		$methods = array();
		foreach ( ( new \ReflectionClass( File_Scanner::class ) )->getMethods( \ReflectionMethod::IS_PUBLIC ) as $method ) {
			$methods[] = $method->getName();
		}

		$this->assertNotEmpty( $methods );
		$this->assertSame( array(), array_values( array_diff( $methods, $allowed ) ) );
	}

	public function test_file_info_is_a_value_object(): void {
		$info = new File_Info( 'a/b.txt', 3, 100, 0644, File_Info::FILE );

		$this->assertTrue( $info->is_file() );
		$this->assertFalse( $info->is_dir() );
		$this->assertFalse( $info->is_link() );
		$this->assertSame( 'txt', $info->extension() );
		$this->assertSame( 'b.txt', $info->name() );
	}

	/**
	 * Runs a walk to the end.
	 *
	 * @param Walk $walk Walk to finish.
	 * @return Walk The same walk.
	 */
	private function finish( Walk $walk ): Walk {
		foreach ( $walk as $entry ) {
			$this->assertInstanceOf( File_Info::class, $entry );
		}

		return $walk;
	}

	public function test_a_walk_that_saw_everything_says_it_is_complete(): void {
		$this->tree->file( 'a/b.txt' );
		$walk = ( new File_Scanner( $this->tree->site() ) )->walk();

		$this->assertCount( 2, iterator_to_array( $walk, false ) );
		$this->assertTrue( $walk->is_complete() );
		$this->assertNull( $walk->incomplete_reason() );
	}

	public function test_a_capped_walk_says_it_is_incomplete_every_time_it_runs(): void {
		for ( $i = 1; $i <= 4; $i++ ) {
			$this->tree->file( "f$i.txt" );
		}
		$scanner = new File_Scanner( $this->tree->site(), 3 );

		$first  = $this->finish( $scanner->walk() );
		$second = $this->finish( $scanner->walk() );

		$this->assertFalse( $first->is_complete() );
		$this->assertFalse( $second->is_complete() );
		$this->assertStringContainsString( '--max-files', (string) $second->incomplete_reason() );
	}

	public function test_a_walk_cannot_say_whether_it_is_complete_before_its_last_entry(): void {
		$this->tree->file( 'a.txt' );
		$this->tree->file( 'b.txt' );
		$walk = ( new File_Scanner( $this->tree->site() ) )->walk();
		$this->assertTrue( $walk->getIterator()->valid() );

		$this->expectException( \LogicException::class );
		$walk->is_complete();
	}

	public function test_a_walk_can_be_iterated_only_once(): void {
		$this->tree->file( 'a.txt' );
		$walk = $this->finish( ( new File_Scanner( $this->tree->site() ) )->walk() );

		$this->expectException( \LogicException::class );
		$walk->getIterator();
	}

	public function test_a_symlink_leaving_the_root_makes_the_walk_of_its_directory_incomplete(): void {
		$this->tree->file( 'plugins/kept/kept.php' );
		$this->tree->link( 'plugins/shared-plugin', $this->tree->outside() );
		$walk = $this->finish( ( new File_Scanner( $this->tree->site() ) )->walk( 'plugins', false ) );

		$this->assertSame( array( 'plugins/shared-plugin' ), $walk->skipped_links() );
		$this->assertStringContainsString( 'symlink', (string) $walk->incomplete_reason() );
		$this->assertNull( $walk->listing_gap() );
	}

	public function test_an_unreadable_directory_makes_the_walk_incomplete(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Permissions are not enforced for root.' );
		}
		$this->tree->file( 'open/a.txt' );
		$this->tree->file( 'closed/b.txt' );
		chmod( $this->tree->site() . '/closed', 0000 );
		$scanner = new File_Scanner( $this->tree->site() );

		$walk = $this->finish( $scanner->walk() );
		$open = $this->finish( $scanner->walk( 'open' ) );

		$this->assertNotNull( $walk->listing_gap() );
		$this->assertTrue( $open->is_complete() );
	}

	public function test_files_below_an_excluded_directory_cannot_be_reached_by_path(): void {
		$this->tree->file( 'cache/page.html' );
		$scanner = new File_Scanner( $this->tree->site(), File_Scanner::DEFAULT_MAX_FILES, File_Scanner::DEFAULT_MAX_BYTES, array( 'cache' ) );

		$this->assertTrue( is_file( $this->tree->site() . '/cache/page.html' ) );
		$this->assertNull( $scanner->stat( 'cache/page.html' ) );
		$this->assertNull( $scanner->read( 'cache/page.html' ) );
		$this->assertSame( File_Scanner::EXCLUDED, $scanner->presence( 'cache/page.html' ) );
	}

	public function test_presence_tells_a_missing_file_from_one_that_was_not_examined(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Permissions are not enforced for root.' );
		}
		$this->tree->file( 'here.txt' );
		$this->tree->file( 'skipped/file.txt' );
		$this->tree->file( 'closed/file.txt' );
		file_put_contents( $this->tree->outside() . '/file.txt', 'outside' );
		$this->tree->link( 'leaves', $this->tree->outside() );
		$this->tree->link( 'leaves.txt', $this->tree->outside() . '/file.txt' );
		$this->tree->link( 'broken.txt', $this->tree->site() . '/nothing-here' );
		chmod( $this->tree->site() . '/closed', 0000 );
		$scanner = new File_Scanner( $this->tree->site(), File_Scanner::DEFAULT_MAX_FILES, File_Scanner::DEFAULT_MAX_BYTES, array( 'skipped/**' ) );

		$this->assertSame( File_Scanner::PRESENT, $scanner->presence( 'here.txt' ) );
		$this->assertSame( File_Scanner::ABSENT, $scanner->presence( 'gone.txt' ) );
		$this->assertSame( File_Scanner::ABSENT, $scanner->presence( 'gone/deeper/file.txt' ) );
		$this->assertSame( File_Scanner::EXCLUDED, $scanner->presence( 'skipped/file.txt' ) );
		$this->assertSame( File_Scanner::OUTSIDE_ROOT, $scanner->presence( 'leaves.txt' ) );
		$this->assertSame( File_Scanner::OUTSIDE_ROOT, $scanner->presence( 'leaves/file.txt' ) );
		$this->assertSame( File_Scanner::OUTSIDE_ROOT, $scanner->presence( 'leaves/gone.txt' ) );
		$this->assertSame( File_Scanner::OUTSIDE_ROOT, $scanner->presence( '../outside/file.txt' ) );
		$this->assertSame( File_Scanner::BROKEN_LINK, $scanner->presence( 'broken.txt' ) );
		$this->assertSame( File_Scanner::UNREADABLE, $scanner->presence( 'closed/file.txt' ) );
	}

	public function test_a_file_above_the_hash_limit_is_refused(): void {
		$this->tree->sparse_file( 'huge.bin', File_Scanner::MAX_HASH_BYTES + 1 );
		$this->tree->sparse_file( 'limit.bin', File_Scanner::MAX_HASH_BYTES );
		$scanner = new File_Scanner( $this->tree->site() );

		$this->assertSame( 32, strlen( (string) $scanner->hash( 'limit.bin' ) ) );
		$this->expectException( File_Too_Large_Exception::class );
		$scanner->hash( 'huge.bin' );
	}

	public function test_an_empty_file_hashes_like_empty_content(): void {
		$this->tree->file( 'empty.txt', '' );

		$this->assertSame( md5( '' ), ( new File_Scanner( $this->tree->site() ) )->hash( 'empty.txt' ) );
	}

	public function test_a_path_that_no_longer_resolves_to_itself_is_not_opened(): void {
		$this->tree->file( 'real.txt', 'inside' );
		$this->tree->link( 'swapped.txt', $this->tree->site() . '/real.txt' );
		$scanner = new File_Scanner( $this->tree->site() );
		$open    = new \ReflectionMethod( File_Scanner::class, 'open' );
		$open->setAccessible( true );

		$handle = $open->invoke( $scanner, $scanner->root() . '/real.txt' );
		$this->assertIsResource( $handle );
		fclose( $handle );
		$this->assertNull( $open->invoke( $scanner, $scanner->root() . '/swapped.txt' ) );
	}

	public function test_a_walk_whose_every_entry_is_excluded_is_incomplete(): void {
		$this->tree->file( 'wp-config.php', '<?php' );
		$this->tree->file( 'wp-content/uploads/photo.jpg' );
		$scanner = new File_Scanner( $this->tree->site(), File_Scanner::DEFAULT_MAX_FILES, File_Scanner::DEFAULT_MAX_BYTES, array( '**' ) );

		$walk = $scanner->walk();
		$seen = iterator_to_array( $walk, false );

		$this->assertFileExists( $this->tree->site() . '/wp-config.php' );
		$this->assertSame( array(), $seen );
		$this->assertStringContainsString( 'excluded', (string) $walk->incomplete_reason() );
		$this->assertStringContainsString( 'excluded', (string) $walk->listing_gap() );
	}

	public function test_an_empty_directory_gives_an_incomplete_walk_but_no_listing_gap(): void {
		mkdir( $this->tree->site() . '/empty' );
		$this->assertDirectoryExists( $this->tree->site() . '/empty' );
		$scanner = new File_Scanner( $this->tree->site() );

		$walk = $scanner->walk( 'empty' );
		$this->assertSame( array(), iterator_to_array( $walk, false ) );

		$this->assertStringContainsString( 'nothing to examine', (string) $walk->incomplete_reason() );
		$this->assertNull( $walk->listing_gap() );
		$this->assertSame( 0, $walk->entries_seen() );
	}

	public function test_a_partly_excluded_walk_is_complete(): void {
		$this->tree->file( 'keep/a.txt' );
		$this->tree->file( 'cache/b.html' );
		$scanner = new File_Scanner( $this->tree->site(), File_Scanner::DEFAULT_MAX_FILES, File_Scanner::DEFAULT_MAX_BYTES, array( 'cache/**', 'cache' ) );

		$walk = $scanner->walk();
		$this->assertContains(
			'keep/a.txt',
			array_map(
				static function ( $info ) {
					return $info->path();
				},
				iterator_to_array( $walk, false )
			)
		);

		$this->assertNull( $walk->incomplete_reason() );
	}

	public function test_walking_a_directory_that_is_itself_excluded_says_so(): void {
		$this->tree->file( 'wp-content/uploads/photo.jpg' );
		$scanner = new File_Scanner( $this->tree->site(), File_Scanner::DEFAULT_MAX_FILES, File_Scanner::DEFAULT_MAX_BYTES, array( 'wp-content/uploads' ) );

		$this->expectException( Path_Unavailable_Exception::class );
		$this->expectExceptionMessage( 'excluded' );
		$scanner->walk( 'wp-content/uploads' );
	}

	public function test_a_replayed_walk_whose_every_entry_was_excluded_is_still_incomplete(): void {
		$this->tree->file( 'a/one.txt' );
		$this->tree->file( 'b/two.txt' );
		$scanner = new File_Scanner( $this->tree->site(), File_Scanner::DEFAULT_MAX_FILES, File_Scanner::DEFAULT_MAX_BYTES, array( 'b/two.txt' ) );
		$full    = $scanner->walk();
		$this->assertNotEmpty( iterator_to_array( $full, false ) );

		$replayed = $scanner->walk( 'b' );
		$this->assertSame( array(), iterator_to_array( $replayed, false ) );

		$this->assertStringContainsString( 'excluded', (string) $replayed->incomplete_reason() );
	}
}
