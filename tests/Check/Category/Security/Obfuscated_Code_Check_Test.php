<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Security;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Security\Obfuscated_Code_Check;
use WP_Doctor\Check\Runs_Without_Config;
use WP_Doctor\Fs\File_Scanner;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Temp_Tree;

/**
 * The planted code is inert: the payload decodes to "// inert test marker".
 */
final class Obfuscated_Code_Check_Test extends TestCase {

	private const PLANTED = "<?php\n@eval( base64_decode( 'Ly8gaW5lcnQgdGVzdCBtYXJrZXI=' ) );\n";

	/**
	 * @var Temp_Tree
	 */
	private $tree;

	protected function setUp(): void {
		$this->tree = new Temp_Tree();
		$this->tree->file( 'wp-content/plugins/example/example.php', "<?php\neval( '?>' . file_get_contents( __DIR__ . '/view.php' ) );\n" );
	}

	protected function tearDown(): void {
		$this->tree->remove();
	}

	/**
	 * Runs the check over the tree.
	 *
	 * @param string[] $exclude Exclude globs.
	 * @param int      $max_files Entry cap for the walk.
	 * @return Result
	 */
	private function run_check( array $exclude = array(), int $max_files = File_Scanner::DEFAULT_MAX_FILES ): Result {
		return ( new Obfuscated_Code_Check() )->run( Context_Factory::for_tree( $this->tree, '', $max_files, $exclude ) );
	}

	public function test_it_has_an_id_and_a_category_and_runs_without_the_config(): void {
		$check = new Obfuscated_Code_Check();

		$this->assertSame( 'sec.code.obfuscated', $check->id() );
		$this->assertSame( 'security', $check->category() );
		$this->assertInstanceOf( Runs_Without_Config::class, $check );
	}

	public function test_a_site_whose_eval_runs_only_local_templates_passes(): void {
		$this->assertTrue( $this->run_check()->is_pass() );
	}

	public function test_a_planted_decoder_in_a_plugin_is_a_likely_warning_with_the_file_and_line(): void {
		$this->tree->file( 'wp-content/plugins/example/inc/cache.php', self::PLANTED );

		$finding = $this->run_check()->finding_list()[0];

		$evidence = $finding->evidence();
		$this->assertSame( 'warning', $finding->severity()->name() );
		$this->assertStringContainsString( 'likely', $finding->title() );
		$this->assertSame( 4, $evidence['php_files_examined'] );
		$this->assertSame( 1, $evidence['files_flagged'] );
		$this->assertFalse( $evidence['scan_incomplete'] );
		$this->assertSame( 'wp-content/plugins/example/inc/cache.php', $evidence['file_1'] );
		$this->assertSame( 2, $evidence['file_1_line'] );
		$this->assertSame( 'eval() of base64_decode()', $evidence['file_1_code'] );
		$this->assertStringContainsString( 'clean copy', $finding->fix() );
	}

	public function test_code_injected_into_wp_config_is_reported(): void {
		$this->tree->file( 'wp-config.php', "<?php\n\$table_prefix = 'wptest_';\n" . substr( self::PLANTED, 6 ) );

		$this->assertSame( 'wp-config.php', $this->run_check()->finding_list()[0]->evidence()['file_1'] );
	}

	public function test_files_are_listed_in_path_order_with_a_count_of_further_hits(): void {
		$this->tree->file( 'b.php', self::PLANTED . "eval( gzinflate( base64_decode( '09dXyMxLLSpRKEktLlHITSzKTi0CAA==' ) ) );\n" );
		$this->tree->file( 'a.php', self::PLANTED );

		$evidence = $this->run_check()->finding_list()[0]->evidence();

		$this->assertSame( 'a.php', $evidence['file_1'] );
		$this->assertArrayNotHasKey( 'file_1_more', $evidence );
		$this->assertSame( 'b.php', $evidence['file_2'] );
		$this->assertSame( 1, $evidence['file_2_more'] );
	}

	public function test_a_clean_scan_that_did_not_see_every_file_is_a_skip_with_the_reason(): void {
		$this->tree->sparse_file( 'wp-content/plugins/example/big.php', File_Scanner::DEFAULT_MAX_BYTES + 1 );

		$result = $this->run_check();

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'read only in part', (string) $result->message() );
	}

	public function test_a_finding_from_an_incomplete_scan_says_what_was_not_examined(): void {
		$this->tree->file( 'a.php', self::PLANTED );
		$this->tree->sparse_file( 'wp-content/plugins/example/big.php', File_Scanner::DEFAULT_MAX_BYTES + 1 );

		$evidence = $this->run_check()->finding_list()[0]->evidence();

		$this->assertTrue( $evidence['scan_incomplete'] );
		$this->assertStringContainsString( 'big.php', $evidence['not_examined'] );
	}

	public function test_a_site_with_every_file_excluded_has_nothing_to_examine(): void {
		$result = $this->run_check( array( '**' ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'nothing to examine', (string) $result->message() );
	}
}
