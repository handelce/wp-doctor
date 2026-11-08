<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Security;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Security\Core_Checksums_Check;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Fake_Checksum_Source;
use WP_Doctor\Tests\Support\Temp_Tree;

final class Core_Checksums_Subdirectory_Test extends TestCase {

	/**
	 * Web root whose core is in wp/.
	 *
	 * @var Temp_Tree
	 */
	private $tree;

	/**
	 * Checksums the fake source returns, keyed as the release lists them.
	 *
	 * @var Fake_Checksum_Source
	 */
	private $source;

	protected function setUp(): void {
		$this->tree   = new Temp_Tree();
		$this->source = new Fake_Checksum_Source();
		$this->core_file( 'index.php', "<?php\nrequire __DIR__ . '/wp-blog-header.php';\n" );
		$this->core_file( 'wp-includes/version.php', "<?php\n\$wp_version = '6.5.2';\n" );
		$this->core_file( 'wp-login.php', '<?php // login' );
		$this->core_file( 'wp-admin/index.php', '<?php // admin' );
		$this->core_file( 'wp-includes/functions.php', '<?php // functions' );
		$this->tree->file( 'wp-content/themes/a/style.css', '/* theme */' );
	}

	protected function tearDown(): void {
		$this->tree->remove();
	}

	/**
	 * Writes a file into wp/ and records its checksum under the release path.
	 *
	 * @param string $path    Path relative to the core directory.
	 * @param string $content File content.
	 */
	private function core_file( string $path, string $content ): void {
		$this->tree->file( "wp/$path", $content );
		$this->source->checksums[ $path ] = md5( $content );
	}

	/**
	 * Runs the check over the web root with the fake source.
	 *
	 * @return Result
	 */
	private function run_check(): Result {
		$context = Context_Factory::for_core_in( $this->tree, 'wp', "define( 'WP_CONTENT_DIR', __DIR__ . '/wp-content' );\n" );
		$this->assertSame( 'wp', $context->site()->core_dir(), 'The tree was not located as a site with core in wp/' );

		return ( new Core_Checksums_Check() )->run( $context->with_checksums( $this->source ) );
	}

	public function test_core_files_in_the_subdirectory_that_match_the_release_pass_beside_the_loader_in_the_web_root(): void {
		$result = $this->run_check();

		$this->assertSame( array(), $result->finding_list() );
		$this->assertSame( 'pass', $result->status() );
		$this->assertSame( array( '6.5.2' ), array_column( $this->source->requests, 0 ) );
	}

	public function test_a_modified_core_file_is_named_with_its_path_from_the_web_root(): void {
		$this->tree->file( 'wp/wp-includes/functions.php', '<?php // functions with an injected line' );

		$evidence = $this->run_check()->finding_list()[0]->evidence();

		$this->assertSame( 1, $evidence['modified_files'] );
		$this->assertSame( 'wp/wp-includes/functions.php', $evidence['modified_1'] );
	}

	public function test_a_missing_core_file_is_named_with_its_path_from_the_web_root(): void {
		$this->tree->remove_path( 'wp/wp-login.php' );

		$evidence = $this->run_check()->finding_list()[0]->evidence();

		$this->assertSame( 1, $evidence['missing_files'] );
		$this->assertSame( 'wp/wp-login.php', $evidence['missing_1'] );
	}

	public function test_a_file_added_to_a_core_directory_in_the_subdirectory_is_critical(): void {
		$this->tree->file( 'wp/wp-includes/class-planted.php', '<?php // inert test marker' );

		$finding = $this->run_check()->finding_list()[0];

		$this->assertSame( 'critical', $finding->severity()->name() );
		$this->assertSame( 'wp/wp-includes/class-planted.php', $finding->evidence()['unknown_1'] );
	}

	public function test_unknown_php_at_the_top_of_the_core_directory_and_of_the_web_root_are_both_warnings(): void {
		$this->tree->file( 'wp/planted.php', '<?php // inert test marker' );
		$this->tree->file( 'other.php', '<?php // inert test marker' );

		$finding = $this->run_check()->finding_list()[0];

		$this->assertSame( 'warning', $finding->severity()->name() );
		$this->assertSame( 2, $finding->evidence()['unknown_php_in_root'] );
		$this->assertSame( 'other.php', $finding->evidence()['unknown_1'] );
		$this->assertSame( 'wp/planted.php', $finding->evidence()['unknown_2'] );
	}

	public function test_index_php_in_the_core_directory_is_still_compared(): void {
		$this->tree->file( 'wp/index.php', '<?php // changed' );

		$this->assertSame( 'wp/index.php', $this->run_check()->finding_list()[0]->evidence()['modified_1'] );
	}
}
