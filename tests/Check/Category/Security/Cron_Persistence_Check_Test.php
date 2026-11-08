<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Security;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Security\Cron_Persistence_Check;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\File_Modes;
use WP_Doctor\Tests\Support\Temp_Tree;

/**
 * The planted files are inert: they only call add_action() with a hook name.
 */
final class Cron_Persistence_Check_Test extends TestCase {

	private const PLUGINS = 'wp-content/plugins';

	/**
	 * @var Temp_Tree
	 */
	private $tree;

	protected function setUp(): void {
		$this->tree = new Temp_Tree();
		$this->tree->file( 'wp-includes/update.php', "<?php\nadd_action( 'wp_version_check', 'wp_version_check' );\n" );
		$this->tree->file( self::PLUGINS . '/example-forms/example-forms.php', "<?php\n/* Plugin Name: Example Forms */\nadd_action( 'example_forms_daily', 'example_forms_daily' );\nadd_action( 'example_forms_cleanup_' . \$id, 'example_forms_cleanup' );\n" );
	}

	protected function tearDown(): void {
		$this->tree->remove();
	}

	/**
	 * A cron option holding one event for each hook, as WordPress stores it.
	 *
	 * @param string[] $hooks Hook names.
	 * @return string
	 */
	private static function cron( array $hooks ): string {
		$cron = array();
		foreach ( $hooks as $index => $hook ) {
			$cron[ 1700000000 + $index ][ $hook ][ md5( '' ) ] = array(
				'schedule' => 'daily',
				'args'     => array(),
				'interval' => 86400,
			);
		}
		$cron['version'] = 2;

		return serialize( $cron ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Builds the option value WordPress would store.
	}

	/**
	 * Runs the check with the given hooks scheduled.
	 *
	 * @param string[] $hooks Hook names.
	 * @return Result
	 */
	private function run_with( array $hooks ): Result {
		return ( new Cron_Persistence_Check() )->run( Context_Factory::for_tree_with_options( $this->tree, array( 'cron' => self::cron( $hooks ) ) ) );
	}

	/**
	 * The finding with the given severity.
	 *
	 * @param Result $result Check result.
	 * @param string $severity Severity name.
	 * @return Finding
	 */
	private function finding( Result $result, string $severity ): Finding {
		foreach ( $result->finding_list() as $finding ) {
			if ( $severity === $finding->severity()->name() ) {
				return $finding;
			}
		}
		$this->fail( "No $severity finding; status " . $result->status() );
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Cron_Persistence_Check();

		$this->assertSame( 'sec.wpcron.persistence', $check->id() );
		$this->assertSame( 'security', $check->category() );
	}

	public function test_hooks_named_by_core_and_by_a_plugin_exactly_or_by_prefix_pass(): void {
		$this->assertTrue( $this->run_with( array( 'wp_version_check', 'example_forms_daily', 'example_forms_cleanup_42' ) )->is_pass() );
	}

	public function test_a_hook_no_php_names_is_an_info_notice(): void {
		$finding = $this->finding( $this->run_with( array( 'wp_version_check', 'removed_plugin_sync' ) ), 'info' );

		$evidence = $finding->evidence();
		$this->assertStringContainsString( 'likely', $finding->title() );
		$this->assertSame( 2, $evidence['hooks_scheduled'] );
		$this->assertSame( 1, $evidence['hooks_not_named'] );
		$this->assertSame( 'removed_plugin_sync', $evidence['hook_1'] );
		$this->assertSame( 1, $evidence['hook_1_events'] );
		$this->assertStringContainsString( 'wp cron event delete <hook>', $finding->fix() );
	}

	public function test_a_short_prefix_does_not_count_as_naming_a_hook(): void {
		$this->tree->file( self::PLUGINS . '/example-forms/inc/short.php', "<?php\n\$x = 'rm_' . \$id;\n" );

		$evidence = $this->finding( $this->run_with( array( 'rm_job_1' ) ), 'info' )->evidence();

		$this->assertSame( 'rm_job_1', $evidence['hook_1'] );
	}

	public function test_a_hook_named_only_outside_installed_code_is_a_likely_warning_naming_the_file(): void {
		$this->tree->file( 'wp-content/uploads/2026/11/cache.php', "<?php\nadd_action( 'wp_cache_refresh_x', 'x' );\n" );

		$finding = $this->finding( $this->run_with( array( 'wp_version_check', 'wp_cache_refresh_x' ) ), 'warning' );

		$evidence = $finding->evidence();
		$this->assertStringContainsString( 'likely', $finding->title() );
		$this->assertSame( 1, $evidence['hooks'] );
		$this->assertSame( 'wp_cache_refresh_x', $evidence['hook_1'] );
		$this->assertSame( 'wp-content/uploads/2026/11/cache.php', $evidence['hook_1_named_in'] );
		$this->assertStringContainsString( 'treat the site as compromised', $finding->fix() );
	}

	public function test_a_plugin_folder_without_a_plugin_header_does_not_count_as_installed_code(): void {
		$this->tree->file( self::PLUGINS . '/wp-zxq/up.php', "<?php\nadd_action( 'wp_zxq_beacon', 'x' );\n" );

		$evidence = $this->finding( $this->run_with( array( 'wp_zxq_beacon' ) ), 'warning' )->evidence();

		$this->assertSame( self::PLUGINS . '/wp-zxq/up.php', $evidence['hook_1_named_in'] );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function installed_places(): array {
		return array(
			'must-use plugin' => array( 'wp-content/mu-plugins/loader.php' ),
			'drop-in'         => array( 'wp-content/object-cache.php' ),
			'wp-config.php'   => array( 'wp-config.php' ),
			'theme'           => array( 'wp-content/themes/example/functions.php' ),
		);
	}

	/**
	 * @dataProvider installed_places
	 * @param string $path Where the hook is named.
	 */
	public function test_a_hook_named_by_other_code_wordpress_loads_passes( string $path ): void {
		$this->tree->file( 'wp-content/themes/example/style.css', "/*\nTheme Name: Example\n*/\n" );
		$this->tree->file( 'wp-content/themes/example/index.php', "<?php\n" );
		$code = "add_action( 'example_site_job', 'x' );\n";
		if ( 'wp-config.php' === $path ) {
			$this->tree->file( $path, "<?php\n\$table_prefix = 'wptest_';\n" . $code );
		} else {
			$this->tree->file( $path, "<?php\n" . $code );
		}

		$this->assertTrue( $this->run_with( array( 'example_site_job' ) )->is_pass() );
	}

	public function test_the_provider_has_samples(): void {
		$this->assertGreaterThan( 3, count( $this->installed_places() ) );
	}

	public function test_a_hook_named_by_a_plugin_and_elsewhere_passes(): void {
		$this->tree->file( 'wp-content/uploads/notes.php', "<?php\n// 'example_forms_daily'\n" );

		$this->assertTrue( $this->run_with( array( 'example_forms_daily' ) )->is_pass() );
	}

	public function test_an_unreadable_php_file_makes_a_clean_result_a_skip(): void {
		File_Modes::require_enforced_modes();
		$path = $this->tree->file( 'wp-content/uploads/locked.php', "<?php\n" );
		chmod( $path, 0000 );

		$result = $this->run_with( array( 'wp_version_check' ) );

		chmod( $path, 0644 );
		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'could not be read', (string) $result->message() );
	}

	public function test_no_scheduled_events_is_a_skip(): void {
		$this->assertSame( Result::SKIPPED, $this->run_with( array() )->status() );
	}

	public function test_without_a_database_it_is_skipped(): void {
		$this->assertSame( Result::SKIPPED, ( new Cron_Persistence_Check() )->run( Context_Factory::for_tree( $this->tree ) )->status() );
	}
}
