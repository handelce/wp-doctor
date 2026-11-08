<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Security;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Security\Unknown_Themes_Check;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\File_Modes;
use WP_Doctor\Tests\Support\Temp_Tree;

/**
 * The planted files are inert: each holds only a comment.
 */
final class Unknown_Themes_Check_Test extends TestCase {

	private const THEMES = 'wp-content/themes';
	private const INERT  = "<?php\n// inert test marker\n";

	/**
	 * @var Temp_Tree
	 */
	private $tree;

	protected function setUp(): void {
		$this->tree = new Temp_Tree();
		$this->tree->file( self::THEMES . '/index.php', "<?php\n// Silence is golden.\n" );
		$this->tree->file( self::THEMES . '/example-classic/style.css', "/*\nTheme Name: Example Classic\n*/\n" );
		$this->tree->file( self::THEMES . '/example-classic/index.php', "<?php\nget_header();\n" );
		$this->tree->file( self::THEMES . '/example-classic/inc/setup.php', "<?php\n" );
		$this->tree->file( self::THEMES . '/example-blocks/style.css', "/*\nTheme Name: Example Blocks\n*/\n" );
		$this->tree->file( self::THEMES . '/example-blocks/templates/index.html', '<!-- wp:post-content /-->' );
		$this->tree->file( self::THEMES . '/example-blocks/functions.php', "<?php\n" );
		$this->tree->file( self::THEMES . '/example-child/style.css', "/*\nTheme Name: Example Child\nTemplate: example-classic\n*/\n" );
		$this->tree->file( self::THEMES . '/example-child/functions.php', "<?php\n" );
		$this->tree->file( self::THEMES . '/example-suite/one/style.css', "/*\nTheme Name: Example One\n*/\n" );
		$this->tree->file( self::THEMES . '/example-suite/one/index.php', "<?php\n" );
		$this->tree->file( self::THEMES . '/example-suite/readme.txt', 'A folder of themes.' );
		$this->tree->file( self::THEMES . '/screenshots/one.png', 'x' );
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
		return ( new Unknown_Themes_Check() )->run( Context_Factory::for_tree( $this->tree, $config ) );
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
		$check = new Unknown_Themes_Check();

		$this->assertSame( 'sec.themes.unknown', $check->id() );
		$this->assertSame( 'security', $check->category() );
	}

	public function test_classic_block_child_and_grouped_themes_pass(): void {
		$this->assertTrue( is_file( $this->tree->site() . '/' . self::THEMES . '/example-suite/one/style.css' ) );

		$this->assertTrue( $this->run_check()->is_pass() );
	}

	public function test_a_folder_of_php_with_no_style_css_is_a_likely_warning(): void {
		$this->tree->file( self::THEMES . '/example-assets/x.php', self::INERT );

		$finding = $this->run_check()->finding_list()[0];

		$evidence = $finding->evidence();
		$this->assertSame( 'warning', $finding->severity()->name() );
		$this->assertStringContainsString( 'likely', $finding->title() );
		$this->assertSame( self::THEMES, $evidence['directory'] );
		$this->assertSame( self::THEMES . '/example-assets', $evidence['entry_1'] );
		$this->assertSame( 'folder with PHP but no style.css', $evidence['entry_1_reason'] );
		$this->assertSame( 1, $evidence['entry_1_php_files'] );
		$this->assertStringContainsString( 'treat the site as compromised', $finding->fix() );
	}

	public function test_a_theme_with_no_template_and_no_parent_is_reported(): void {
		$this->tree->file( self::THEMES . '/example-broken/style.css', "/*\nTheme Name: Example Broken\n*/\n" );
		$this->tree->file( self::THEMES . '/example-broken/functions.php', self::INERT );

		$this->assertSame(
			array( self::THEMES . '/example-broken' => 'theme with PHP but no index.php, templates/index.html or Template header' ),
			$this->flagged()
		);
	}

	public function test_php_in_a_folder_of_themes_outside_its_themes_is_reported(): void {
		$this->tree->file( self::THEMES . '/example-suite/loader.php', self::INERT );

		$this->assertSame( array( self::THEMES . '/example-suite' => 'folder of themes with PHP outside its themes' ), $this->flagged() );
	}

	public function test_a_broken_theme_inside_a_folder_of_themes_is_named(): void {
		$this->tree->file( self::THEMES . '/example-suite/two/style.css', "/*\nTheme Name: Example Two\n*/\n" );
		$this->tree->file( self::THEMES . '/example-suite/two/x.php', self::INERT );

		$this->assertSame(
			array( self::THEMES . '/example-suite' => 'two: theme with PHP but no index.php, templates/index.html or Template header' ),
			$this->flagged()
		);
	}

	public function test_php_files_at_the_top_and_in_unread_folders_are_reported(): void {
		$this->tree->file( self::THEMES . '/x.php', self::INERT );
		$this->tree->file( self::THEMES . '/.cache/y.php', self::INERT );
		$this->tree->file( self::THEMES . '/CVS/z.php', self::INERT );

		$this->assertSame(
			array(
				self::THEMES . '/.cache' => 'folder WordPress does not read for themes',
				self::THEMES . '/CVS'    => 'folder WordPress does not read for themes',
				self::THEMES . '/x.php'  => 'PHP file outside any theme',
			),
			$this->flagged()
		);
	}

	public function test_an_unreadable_stylesheet_leaves_the_theme_unjudged_and_the_result_skipped(): void {
		File_Modes::require_enforced_modes();
		$this->tree->file( self::THEMES . '/example-locked/functions.php', "<?php\n" );
		$path = $this->tree->file( self::THEMES . '/example-locked/style.css', "/*\nTheme Name: Example Locked\nTemplate: example-classic\n*/\n" );
		chmod( $path, 0000 );

		$result = $this->run_check();

		chmod( $path, 0644 );
		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'example-locked/style.css', (string) $result->message() );
	}

	public function test_a_moved_content_directory_outside_the_root_is_a_skip(): void {
		$result = $this->run_check( "define( 'WP_CONTENT_DIR', '/srv/example/content' );\n" );

		$this->assertSame( Result::SKIPPED, $result->status() );
	}

	public function test_a_missing_themes_directory_is_a_skip(): void {
		$this->tree->remove_path( self::THEMES );

		$this->assertSame( Result::SKIPPED, $this->run_check()->status() );
	}
}
