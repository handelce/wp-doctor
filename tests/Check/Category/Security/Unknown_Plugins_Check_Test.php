<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Security;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Security\Unknown_Plugins_Check;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\File_Modes;
use WP_Doctor\Tests\Support\Temp_Tree;

/**
 * The planted files are inert: each holds only a comment.
 */
final class Unknown_Plugins_Check_Test extends TestCase {

	private const PLUGINS = 'wp-content/plugins';
	private const INERT   = "<?php\n// inert test marker\n";

	/**
	 * @var Temp_Tree
	 */
	private $tree;

	protected function setUp(): void {
		$this->tree = new Temp_Tree();
		$this->tree->file( self::PLUGINS . '/index.php', "<?php\n// Silence is golden.\n" );
		$this->tree->file( self::PLUGINS . '/hello.php', "<?php\n/*\nPlugin Name: Hello Example\n*/\n" );
		$this->tree->file( self::PLUGINS . '/example-forms/wp-example-forms.php', "<?php\n/**\n * Plugin Name: Example Forms\n */\n" );
		$this->tree->file( self::PLUGINS . '/example-forms/includes/form.php', "<?php\nfunction example_form() {}\n" );
		$this->tree->file( self::PLUGINS . '/example-forms/uninstall.php', "<?php\n" );
		$this->tree->file( self::PLUGINS . '/fonts-only/font.woff2', 'x' );
	}

	protected function tearDown(): void {
		$this->tree->remove();
	}

	/**
	 * Runs the check over the tree.
	 *
	 * @param string $config Statements for wp-config.php.
	 * @return Result
	 */
	private function run_check( string $config = '' ): Result {
		return ( new Unknown_Plugins_Check() )->run( Context_Factory::for_tree( $this->tree, $config ) );
	}

	/**
	 * Evidence of the single finding, keyed path => reason.
	 *
	 * @return array<string, string>
	 */
	private function flagged(): array {
		$findings = $this->run_check()->finding_list();
		$this->assertCount( 1, $findings );
		$evidence = $findings[0]->evidence();
		$flagged  = array();
		for ( $rank = 1; isset( $evidence[ "entry_$rank" ] ); $rank++ ) {
			$flagged[ $evidence[ "entry_$rank" ] ] = $evidence[ "entry_{$rank}_reason" ];
		}
		$this->assertSame( $evidence['flagged'], count( $flagged ) );

		return $flagged;
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Unknown_Plugins_Check();

		$this->assertSame( 'sec.plugins.unknown', $check->id() );
		$this->assertSame( 'security', $check->category() );
	}

	public function test_plugins_whose_main_file_has_a_header_pass_whatever_it_is_named(): void {
		$this->assertTrue( is_file( $this->tree->site() . '/' . self::PLUGINS . '/example-forms/wp-example-forms.php' ) );

		$this->assertTrue( $this->run_check()->is_pass() );
	}

	public function test_a_folder_of_php_with_no_plugin_header_is_a_likely_warning(): void {
		$this->tree->file( self::PLUGINS . '/wp-zxq/up.php', self::INERT );

		$finding = $this->run_check()->finding_list()[0];

		$evidence = $finding->evidence();
		$this->assertSame( 'warning', $finding->severity()->name() );
		$this->assertStringContainsString( 'likely', $finding->title() );
		$this->assertSame( self::PLUGINS, $evidence['directory'] );
		$this->assertSame( 5, $evidence['entries_listed'] );
		$this->assertFalse( $evidence['scan_incomplete'] );
		$this->assertSame( self::PLUGINS . '/wp-zxq', $evidence['entry_1'] );
		$this->assertSame( 'folder with PHP but no file with a Plugin Name header', $evidence['entry_1_reason'] );
		$this->assertSame( 1, $evidence['entry_1_php_files'] );
		$this->assertRegExp( '/^\d{4}-\d\d-\d\d \d\d:\d\d UTC$/', $evidence['entry_1_php_newest'] );
		$this->assertStringContainsString( 'treat the site as compromised', $finding->fix() );
	}

	public function test_a_header_only_in_a_subfolder_does_not_make_a_plugin(): void {
		$this->tree->file( self::PLUGINS . '/example-tools/example-tools/example-tools.php', "<?php\n/* Plugin Name: Example Tools */\n" );

		$this->assertSame( array( self::PLUGINS . '/example-tools' => 'folder with PHP but no file with a Plugin Name header' ), $this->flagged() );
	}

	public function test_a_header_in_a_file_whose_name_begins_with_a_dot_is_not_read(): void {
		$this->tree->file( self::PLUGINS . '/example-hidden/.loader.php', "<?php\n/* Plugin Name: Example Hidden */\n" );

		$this->assertSame( array( self::PLUGINS . '/example-hidden' => 'folder with PHP but no file with a Plugin Name header' ), $this->flagged() );
	}

	public function test_a_folder_whose_name_begins_with_a_dot_and_holds_php_is_reported(): void {
		$this->tree->file( self::PLUGINS . '/.cache/deep/x.php', self::INERT );
		$this->tree->file( self::PLUGINS . '/.git/HEAD', 'ref: refs/heads/main' );

		$this->assertSame( array( self::PLUGINS . '/.cache' => 'folder whose name begins with a dot, which WordPress does not read' ), $this->flagged() );
	}

	public function test_php_files_at_the_top_without_a_header_are_reported_but_the_empty_index_is_not(): void {
		$this->tree->file( self::PLUGINS . '/stray.php', self::INERT );
		$this->tree->file( self::PLUGINS . '/shell.phtml', self::INERT );
		$this->tree->file( self::PLUGINS . '/readme.txt', 'Plugin Name: not PHP' );

		$this->assertSame(
			array(
				self::PLUGINS . '/shell.phtml' => 'PHP file with no Plugin Name header',
				self::PLUGINS . '/stray.php'   => 'PHP file with no Plugin Name header',
			),
			$this->flagged()
		);
	}

	public function test_an_index_php_holding_code_is_reported(): void {
		$this->tree->file( self::PLUGINS . '/index.php', self::INERT . "echo 'x';\n" );

		$this->assertSame( array( self::PLUGINS . '/index.php' => 'PHP file with no Plugin Name header' ), $this->flagged() );
	}

	public function test_a_folder_with_an_unreadable_candidate_file_is_not_judged_and_the_result_is_skipped(): void {
		File_Modes::require_enforced_modes();
		$path = $this->tree->file( self::PLUGINS . '/example-locked/example-locked.php', "<?php\n/* Plugin Name: Example Locked */\n" );
		chmod( $path, 0000 );

		$result = $this->run_check();

		chmod( $path, 0644 );
		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'example-locked/example-locked.php', (string) $result->message() );
	}

	public function test_a_symlinked_plugin_folder_makes_a_clean_result_a_skip(): void {
		$this->tree->link( self::PLUGINS . '/example-linked', $this->tree->outside() );

		$result = $this->run_check();

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'example-linked', (string) $result->message() );
	}

	public function test_a_moved_plugins_directory_is_a_skip(): void {
		$result = $this->run_check( "define( 'WP_PLUGIN_DIR', '/srv/example/plugins' );\n" );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'WP_PLUGIN_DIR', (string) $result->message() );
	}

	public function test_a_missing_plugins_directory_is_a_skip(): void {
		$this->tree->remove_path( self::PLUGINS );

		$this->assertSame( Result::SKIPPED, $this->run_check()->status() );
	}
}
