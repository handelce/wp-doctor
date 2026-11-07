<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Config;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Config\Debug_Enabled_Check;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Temp_Tree;

final class Debug_Enabled_Check_Test extends TestCase {

	/**
	 * Runs the check against a wp-config.php holding the given statements.
	 *
	 * @param string $config wp-config.php statements.
	 * @return Result
	 */
	private function run_with( string $config ): Result {
		$tree = new Temp_Tree();
		try {
			return ( new Debug_Enabled_Check() )->run( Context_Factory::for_tree( $tree, $config ) );
		} finally {
			$tree->remove();
		}
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Debug_Enabled_Check();

		$this->assertSame( 'config.debug.enabled', $check->id() );
		$this->assertSame( 'config', $check->category() );
	}

	/**
	 * @dataProvider debugging_off
	 * @param string $config wp-config.php statements.
	 */
	public function test_a_site_with_wp_debug_off_or_unset_passes_whatever_the_other_two_say( string $config ): void {
		$this->assertTrue( $this->run_with( $config )->is_pass() );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function debugging_off(): array {
		return array(
			'all off'                    => array( "define( 'WP_DEBUG', false );\ndefine( 'WP_DEBUG_LOG', false );" ),
			'nothing set'                => array( '' ),
			'production log, no display' => array( "define( 'WP_DEBUG', false );\ndefine( 'WP_DEBUG_LOG', true );\ndefine( 'WP_DEBUG_DISPLAY', false );" ),
			'log and display, no debug'  => array( "define( 'WP_DEBUG_LOG', '/var/log/example.log' );\ndefine( 'WP_DEBUG_DISPLAY', true );" ),
		);
	}

	public function test_the_debugging_off_provider_is_not_empty(): void {
		$this->assertCount( 4, $this->debugging_off() );
	}

	public function test_wp_debug_on_is_a_warning_with_what_display_and_log_then_do(): void {
		$finding = $this->run_with( "define( 'WP_DEBUG', true );" )->finding_list()[0];

		$this->assertSame( 'warning', $finding->severity()->name() );
		$this->assertStringContainsString( 'likely', $finding->title() );
		$this->assertNotSame( '', $finding->fix() );
		$this->assertSame(
			array(
				'WP_DEBUG'         => 'on',
				'WP_DEBUG_DISPLAY' => 'on, by default when WP_DEBUG is on',
				'WP_DEBUG_LOG'     => 'off',
			),
			$finding->evidence()
		);
	}

	public function test_display_and_log_values_set_in_wp_config_are_shown(): void {
		$evidence = $this->run_with( "define( 'WP_DEBUG', true );\ndefine( 'WP_DEBUG_DISPLAY', false );\ndefine( 'WP_DEBUG_LOG', '/var/log/example.log' );" )->finding_list()[0]->evidence();

		$this->assertSame( 'off', $evidence['WP_DEBUG_DISPLAY'] );
		$this->assertSame( 'on', $evidence['WP_DEBUG_LOG'] );
	}

	public function test_an_unreadable_wp_debug_is_skipped(): void {
		$result = $this->run_with( "define( 'WP_DEBUG', getenv( 'EXAMPLE_DEBUG' ) );\ndefine( 'WP_DEBUG_LOG', true );" );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'WP_DEBUG', (string) $result->message() );
	}

	public function test_wp_debug_missing_is_skipped_when_wp_config_includes_another_file(): void {
		$result = $this->run_with( "define( 'WP_DEBUG_LOG', false );\nrequire __DIR__ . '/debug.php';" );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'WP_DEBUG', (string) $result->message() );
	}

	public function test_wp_debug_on_is_reported_when_wp_config_includes_another_file(): void {
		$evidence = $this->run_with( "define( 'WP_DEBUG', true );\nrequire __DIR__ . '/debug.php';" )->finding_list()[0]->evidence();

		$this->assertSame( 'on', $evidence['WP_DEBUG'] );
		$this->assertSame( 'not read', $evidence['WP_DEBUG_DISPLAY'] );
		$this->assertSame( 'not read', $evidence['WP_DEBUG_LOG'] );
	}
}
