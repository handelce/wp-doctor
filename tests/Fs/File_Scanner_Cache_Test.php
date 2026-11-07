<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Fs;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Fs\File_Scanner;
use WP_Doctor\Fs\Walk;
use WP_Doctor\Tests\Support\Temp_Tree;

final class File_Scanner_Cache_Test extends TestCase {

	/**
	 * @var Temp_Tree
	 */
	private $tree;

	protected function setUp(): void {
		$this->tree = new Temp_Tree();
		$this->tree->file( 'index.php' );
		$this->tree->file( 'wp-content/uploads/2026/a.jpg' );
		$this->tree->file( 'wp-content/uploads/2026/b.jpg' );
		$this->tree->file( 'wp-content/plugins/alpha/alpha.php' );
		$this->tree->file( 'wp-includes/version.php' );
	}

	protected function tearDown(): void {
		$this->tree->remove();
	}

	/**
	 * Paths a walk yields, in order.
	 *
	 * @param Walk $walk A walk.
	 * @return string[]
	 */
	private function paths( Walk $walk ): array {
		$paths = array();
		foreach ( $walk as $entry ) {
			$paths[] = $entry->path();
		}

		return $paths;
	}

	public function test_a_later_walk_replays_the_first_complete_full_walk_without_reading_the_disk(): void {
		$scanner = new File_Scanner( $this->tree->site() );
		$first   = $this->paths( $scanner->walk() );
		$this->tree->file( 'added-after-the-first-walk.txt' );

		$this->assertContains( 'wp-content/uploads/2026/a.jpg', $first );
		$this->assertSame( $first, $this->paths( $scanner->walk() ) );
		$this->assertNotContains( 'added-after-the-first-walk.txt', $this->paths( $scanner->walk() ) );
	}

	/**
	 * @dataProvider subtree_walks
	 * @param string $relative  Directory to walk.
	 * @param bool   $recursive Whether to descend.
	 */
	public function test_a_replayed_subtree_walk_matches_a_fresh_one_in_content_and_order( string $relative, bool $recursive ): void {
		$cached = new File_Scanner( $this->tree->site() );
		$this->paths( $cached->walk() );

		$expected = $this->paths( ( new File_Scanner( $this->tree->site(), File_Scanner::DEFAULT_MAX_FILES, File_Scanner::DEFAULT_MAX_BYTES, array(), PHP_OS_FAMILY, 0 ) )->walk( $relative, $recursive ) );

		$this->assertNotEmpty( $expected );
		$this->assertSame( $expected, $this->paths( $cached->walk( $relative, $recursive ) ) );
	}

	/**
	 * @return array<string, array<int, mixed>>
	 */
	public function subtree_walks(): array {
		return array(
			'root, one level'      => array( '', false ),
			'uploads, recursive'   => array( 'wp-content/uploads', true ),
			'content, one level'   => array( 'wp-content', false ),
			'a plugin, recursive'  => array( 'wp-content/plugins/alpha', true ),
			'trailing slash given' => array( 'wp-content/uploads/', true ),
		);
	}

	public function test_the_subtree_walks_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->subtree_walks() );
	}

	public function test_a_directory_name_that_only_starts_like_another_is_not_mixed_in(): void {
		$this->tree->file( 'wp-content-old/stale.zip' );
		$scanner = new File_Scanner( $this->tree->site() );
		$this->paths( $scanner->walk() );

		$this->assertNotContains( 'wp-content-old/stale.zip', $this->paths( $scanner->walk( 'wp-content' ) ) );
	}

	public function test_a_missing_directory_is_still_refused_after_the_cache_is_filled(): void {
		$scanner = new File_Scanner( $this->tree->site() );
		$this->paths( $scanner->walk() );

		$this->expectException( \WP_Doctor\Fs\Path_Unavailable_Exception::class );
		$scanner->walk( 'no-such-directory' );
	}

	public function test_an_abandoned_walk_a_capped_walk_and_a_walk_above_the_limit_are_not_kept(): void {
		$abandoned = new File_Scanner( $this->tree->site() );
		$abandoned->walk()->getIterator()->current();
		$this->tree->file( 'later-one.txt' );
		$this->assertContains( 'later-one.txt', $this->paths( $abandoned->walk() ) );

		$capped = new File_Scanner( $this->tree->site(), 2 );
		$this->paths( $capped->walk() );
		$this->tree->file( 'later-two.txt' );
		$this->assertCount( 2, $this->paths( $capped->walk() ) );
		$this->assertNotEmpty( $capped->notices() );

		$large = new File_Scanner( $this->tree->site(), File_Scanner::DEFAULT_MAX_FILES, File_Scanner::DEFAULT_MAX_BYTES, array(), PHP_OS_FAMILY, 3 );
		$this->paths( $large->walk() );
		$this->tree->file( 'later-three.txt' );
		$this->assertContains( 'later-three.txt', $this->paths( $large->walk() ) );
	}

	public function test_a_replayed_walk_of_a_directory_with_an_unreadable_subdirectory_is_incomplete(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Permissions are not enforced for root.' );
		}
		$this->tree->file( 'wp-content/uploads/zz/shell.php' );
		chmod( $this->tree->site() . '/wp-content/uploads/zz', 0000 );
		$scanner = new File_Scanner( $this->tree->site() );
		$first   = $scanner->walk();
		$this->paths( $first );
		$this->assertFalse( $first->is_complete() );

		$uploads = $scanner->walk( 'wp-content/uploads' );
		$this->assertContains( 'wp-content/uploads/2026/a.jpg', $this->paths( $uploads ) );
		$this->assertFalse( $uploads->is_complete() );
		$this->assertStringContainsString( 'could not be read', (string) $uploads->incomplete_reason() );

		$plugins = $scanner->walk( 'wp-content/plugins' );
		$this->assertContains( 'wp-content/plugins/alpha/alpha.php', $this->paths( $plugins ) );
		$this->assertTrue( $plugins->is_complete() );

		$one_level = $scanner->walk( 'wp-content/uploads', false );
		$this->assertContains( 'wp-content/uploads/zz', $this->paths( $one_level ) );
		$this->assertTrue( $one_level->is_complete() );
	}

	public function test_a_replayed_walk_of_a_directory_with_a_symlink_leaving_the_root_is_incomplete(): void {
		$this->tree->link( 'wp-content/uploads/2026/elsewhere.php', $this->tree->outside() );
		$scanner = new File_Scanner( $this->tree->site() );
		$this->paths( $scanner->walk() );

		$uploads = $scanner->walk( 'wp-content/uploads' );
		$this->assertContains( 'wp-content/uploads/2026/a.jpg', $this->paths( $uploads ) );
		$this->assertSame( array( 'wp-content/uploads/2026/elsewhere.php' ), $uploads->skipped_links() );
		$this->assertNotNull( $uploads->incomplete_reason() );
		$this->assertNull( $uploads->listing_gap() );
	}

	public function test_a_directory_reached_through_a_symlink_is_walked_afresh_after_the_cache_is_filled(): void {
		$this->tree->file( 'shared/uploads/evil.php' );
		$this->tree->link( 'wp-content/linked-uploads', $this->tree->site() . '/shared/uploads' );
		$scanner = new File_Scanner( $this->tree->site() );
		$this->paths( $scanner->walk() );

		$this->assertSame( array( 'wp-content/linked-uploads/evil.php' ), $this->paths( $scanner->walk( 'wp-content/linked-uploads' ) ) );
	}

	/**
	 * @dataProvider non_canonical_spellings
	 * @param string $relative A spelling of shared/uploads.
	 */
	public function test_a_non_canonical_spelling_of_a_directory_is_walked_afresh_after_the_cache_is_filled( string $relative ): void {
		$this->tree->file( 'shared/uploads/evil.php' );
		$scanner = new File_Scanner( $this->tree->site() );
		$this->paths( $scanner->walk() );

		$paths = $this->paths( $scanner->walk( $relative ) );

		$this->assertCount( 1, $paths );
		$this->assertStringEndsWith( 'evil.php', $paths[0] );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function non_canonical_spellings(): array {
		return array(
			'leading dot'  => array( './shared/uploads' ),
			'double slash' => array( 'shared//uploads' ),
			'inner dot'    => array( 'shared/./uploads' ),
		);
	}

	public function test_the_non_canonical_spellings_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->non_canonical_spellings() );
	}
}
