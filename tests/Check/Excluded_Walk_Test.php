<?php
/**
 * Tests that a check whose walk examined nothing does not pass.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Check;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Config\Exposed_Files_Check;
use WP_Doctor\Check\Category\Config\Writable_Files_Check;
use WP_Doctor\Check\Category\Disk\Error_Log_Size_Check;
use WP_Doctor\Check\Category\Disk\Leftover_Files_Check;
use WP_Doctor\Check\Category\Security\Uploads_Disguised_Check;
use WP_Doctor\Check\Category\Security\Uploads_Php_Check;
use WP_Doctor\Check\Check;
use WP_Doctor\Fs\File_Scanner;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Temp_Tree;

final class Excluded_Walk_Test extends TestCase {

	/**
	 * Site with a file of every kind the walking checks look for.
	 *
	 * @var Temp_Tree
	 */
	private $tree;

	protected function setUp(): void {
		$this->tree = new Temp_Tree();
		$this->tree->file( '.env', 'APP_KEY=example' );
		$this->tree->file( 'backup.sql', 'CREATE TABLE example (id INT);' );
		$this->tree->file( 'error_log', 'PHP Notice: example' );
		$this->tree->file( 'wp-content/uploads/2024/photo.jpg', 'jpeg' );
		$this->tree->file( 'wp-content/uploads/2024/shell.php', '<?php echo 1;' );
	}

	protected function tearDown(): void {
		$this->tree->remove();
	}

	/**
	 * @dataProvider walking_checks
	 * @param Check $check Check that walks the site.
	 */
	public function test_a_check_whose_whole_walk_is_excluded_skips( Check $check ): void {
		$context = Context_Factory::for_tree( $this->tree, '', File_Scanner::DEFAULT_MAX_FILES, array( '**' ) );
		$this->assertNotEmpty( iterator_to_array( ( new File_Scanner( $this->tree->site() ) )->walk(), false ) );

		$result = $check->run( $context );

		$this->assertSame( Result::SKIPPED, $result->status(), $check->id() . ' did not skip: ' . (string) $result->message() );
		$this->assertStringContainsString( 'exclude', (string) $result->message() );
	}

	/**
	 * @dataProvider uploads_checks
	 * @param Check $check Check that walks the uploads directory.
	 */
	public function test_an_uploads_check_on_an_excluded_uploads_directory_skips( Check $check ): void {
		$context = Context_Factory::for_tree( $this->tree, '', File_Scanner::DEFAULT_MAX_FILES, array( 'wp-content/uploads' ) );
		$this->assertFileExists( $this->tree->site() . '/wp-content/uploads/2024/shell.php' );

		$result = $check->run( $context );

		$this->assertSame( Result::SKIPPED, $result->status(), $check->id() . ' did not skip' );
		$this->assertStringContainsString( 'excluded', (string) $result->message() );
	}

	/**
	 * @return array<string, array<int, Check>>
	 */
	public function walking_checks(): array {
		return array(
			'exposed files'    => array( new Exposed_Files_Check() ),
			'writable files'   => array( new Writable_Files_Check() ),
			'error log size'   => array( new Error_Log_Size_Check() ),
			'leftover files'   => array( new Leftover_Files_Check() ),
			'uploads php'      => array( new Uploads_Php_Check() ),
			'uploads disguise' => array( new Uploads_Disguised_Check() ),
		);
	}

	/**
	 * @return array<string, array<int, Check>>
	 */
	public function uploads_checks(): array {
		return array(
			'uploads php'      => array( new Uploads_Php_Check() ),
			'uploads disguise' => array( new Uploads_Disguised_Check() ),
		);
	}

	public function test_the_providers_are_not_empty(): void {
		$this->assertCount( 6, $this->walking_checks() );
		$this->assertCount( 2, $this->uploads_checks() );
	}

	/**
	 * @dataProvider site_wide_checks
	 * @param Check $check Check that walks the whole site for names.
	 */
	public function test_a_symlink_leaving_the_root_is_named_in_the_skip_with_how_to_accept_it( Check $check ): void {
		$this->tree->remove_path( 'backup.sql' );
		$this->tree->remove_path( 'error_log' );
		$this->tree->link( 'wp-content/shared', $this->tree->outside() );
		$this->assertTrue( is_link( $this->tree->site() . '/wp-content/shared' ) );

		$result = $check->run( Context_Factory::for_tree( $this->tree ) );

		$this->assertSame( Result::SKIPPED, $result->status(), $check->id() );
		$this->assertStringContainsString( 'wp-content/shared', (string) $result->message() );
		$this->assertStringContainsString( '--exclude', (string) $result->message() );
	}

	/**
	 * @dataProvider site_wide_checks
	 * @param Check $check Check that walks the whole site for names.
	 */
	public function test_an_excluded_symlink_leaving_the_root_lets_a_clean_site_pass( Check $check ): void {
		$this->tree->remove_path( 'backup.sql' );
		$this->tree->remove_path( 'error_log' );
		$this->tree->link( 'wp-content/shared', $this->tree->outside() );

		$result = $check->run( Context_Factory::for_tree( $this->tree, '', File_Scanner::DEFAULT_MAX_FILES, array( 'wp-content/shared' ) ) );

		$this->assertTrue( $result->is_pass(), $check->id() . ': ' . (string) $result->message() );
	}

	/**
	 * @return array<string, array<int, Check>>
	 */
	public function site_wide_checks(): array {
		return array(
			'error log size' => array( new Error_Log_Size_Check() ),
			'leftover files' => array( new Leftover_Files_Check() ),
		);
	}

	public function test_the_site_wide_checks_provider_is_not_empty(): void {
		$this->assertCount( 2, $this->site_wide_checks() );
	}
}
