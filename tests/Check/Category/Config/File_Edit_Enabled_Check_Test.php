<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Config;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Config\File_Edit_Enabled_Check;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Temp_Tree;

final class File_Edit_Enabled_Check_Test extends TestCase {

	/**
	 * Runs the check against a wp-config.php holding the given statements.
	 *
	 * @param string $config wp-config.php statements.
	 * @return Result
	 */
	private function run_with( string $config ): Result {
		$tree = new Temp_Tree();
		try {
			return ( new File_Edit_Enabled_Check() )->run( Context_Factory::for_tree( $tree, $config ) );
		} finally {
			$tree->remove();
		}
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new File_Edit_Enabled_Check();

		$this->assertSame( 'config.fileedit.enabled', $check->id() );
		$this->assertSame( 'config', $check->category() );
	}

	public function test_disallowing_file_edit_passes(): void {
		$this->assertTrue( $this->run_with( "define( 'DISALLOW_FILE_EDIT', true );" )->is_pass() );
	}

	public function test_disallowing_file_mods_also_passes(): void {
		$this->assertTrue( $this->run_with( "define( 'DISALLOW_FILE_MODS', true );" )->is_pass() );
	}

	/**
	 * @dataProvider editor_available
	 * @param string $config wp-config.php statements.
	 */
	public function test_an_editor_that_is_not_disabled_is_an_info_finding_with_the_line_to_add( string $config ): void {
		$finding = $this->run_with( $config )->finding_list()[0];

		$this->assertSame( 'info', $finding->severity()->name() );
		$this->assertStringContainsString( "define( 'DISALLOW_FILE_EDIT', true );", $finding->fix() );
		$this->assertStringContainsString( 'likely', $finding->title() );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function editor_available(): array {
		return array(
			'not set'   => array( '' ),
			'set false' => array( "define( 'DISALLOW_FILE_EDIT', false );" ),
		);
	}

	public function test_the_editor_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->editor_available() );
	}

	public function test_a_value_that_cannot_be_read_is_skipped(): void {
		$result = $this->run_with( "define( 'DISALLOW_FILE_EDIT', getenv( 'X' ) );" );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'DISALLOW_FILE_EDIT', (string) $result->message() );
	}

	public function test_an_editor_setting_not_in_wp_config_is_skipped_when_wp_config_includes_another_file(): void {
		$result = $this->run_with( "define( 'DISALLOW_FILE_EDIT', false );\nrequire __DIR__ . '/hardening.php';" );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'DISALLOW_FILE_MODS', (string) $result->message() );
		$this->assertStringNotContainsString( 'DISALLOW_FILE_EDIT', (string) $result->message() );
	}

	public function test_disallowing_file_edit_passes_even_with_an_include(): void {
		$this->assertTrue( $this->run_with( "define( 'DISALLOW_FILE_EDIT', true );\nrequire __DIR__ . '/hardening.php';" )->is_pass() );
	}
}
