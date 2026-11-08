<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Security;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Security\Outdated_Updates_Check;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Options_Driver;
use WP_Doctor\Tests\Support\Recording_Driver;
use WP_Doctor\Tests\Support\Temp_Tree;
use WP_Doctor\Util\Malformed_Serialization_Exception;

final class Outdated_Updates_Check_Test extends TestCase {

	/**
	 * Serialized update_plugins value, as WordPress writes it.
	 *
	 * @param array<string, string> $pending New version by plugin file.
	 * @return string
	 */
	private function plugins_value( array $pending ): string {
		$update               = new \stdClass();
		$update->last_checked = 1700000000;
		$update->response     = array();
		foreach ( $pending as $file => $version ) {
			$item                      = new \stdClass();
			$item->id                  = 'w.org/plugins/example';
			$item->plugin              = $file;
			$item->new_version         = $version;
			$item->package             = 'https://downloads.example.test/plugin.zip';
			$update->response[ $file ] = $item;
		}
		$update->no_update = array( 'other/other.php' => new \stdClass() );

		return serialize( $update );
	}

	/**
	 * Serialized update_themes value, as WordPress writes it.
	 *
	 * @param array<string, string> $pending New version by theme folder.
	 * @return string
	 */
	private function themes_value( array $pending ): string {
		$update           = new \stdClass();
		$update->response = array();
		foreach ( $pending as $theme => $version ) {
			$update->response[ $theme ] = array(
				'theme'       => $theme,
				'new_version' => $version,
			);
		}

		return serialize( $update );
	}

	/**
	 * Serialized update_core value with the given offers.
	 *
	 * @param array<int, array{0: string, 1: string}> $offers Response and version of each offer.
	 * @return string
	 */
	private function core_offers( array $offers ): string {
		$update          = new \stdClass();
		$update->updates = array();
		foreach ( $offers as $pair ) {
			$offer             = new \stdClass();
			$offer->response   = $pair[0];
			$offer->current    = $pair[1];
			$update->updates[] = $offer;
		}

		return serialize( $update );
	}

	/**
	 * Runs the check on a multisite network whose sitemeta holds the given values.
	 *
	 * @param array<string, string> $values Serialized value by kind: plugins, themes, core.
	 * @param string                $config wp-config.php statements after the MULTISITE define.
	 * @return array{0: Result, 1: Options_Driver}
	 */
	private function run_on_network( array $values, string $config = '' ): array {
		$driver = new Options_Driver();
		foreach ( $values as $kind => $value ) {
			$driver->network_options[ '_site_transient_update_' . $kind ] = $value;
		}
		$tree = new Temp_Tree();
		try {
			return array( ( new Outdated_Updates_Check() )->run( Context_Factory::for_tree_with_driver( $tree, $driver, "define( 'MULTISITE', true );\n$config" ) ), $driver );
		} finally {
			$tree->remove();
		}
	}

	/**
	 * Serialized update_core value, as WordPress writes it.
	 *
	 * @param string[] $responses Response of each offer, in order; versions follow 6.5.3, 6.5.4, and so on.
	 * @return string
	 */
	private function core_value( array $responses ): string {
		$update          = new \stdClass();
		$update->updates = array();
		foreach ( $responses as $index => $response ) {
			$offer             = new \stdClass();
			$offer->response   = $response;
			$offer->current    = '6.5.' . ( 3 + $index );
			$update->updates[] = $offer;
		}

		return serialize( $update );
	}

	/**
	 * Runs the check with stored values for each update option.
	 *
	 * @param array<string, string> $values Serialized value by kind: plugins, themes, core.
	 * @return Result
	 */
	private function run_with( array $values ): Result {
		$driver = new Options_Driver();
		foreach ( $values as $kind => $value ) {
			$driver->options[ '_site_transient_update_' . $kind ] = $value;
		}

		return ( new Outdated_Updates_Check() )->run( Context_Factory::with_driver( $driver ) );
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Outdated_Updates_Check();

		$this->assertSame( 'sec.updates.outdated', $check->id() );
		$this->assertSame( 'security', $check->category() );
	}

	public function test_a_site_with_nothing_pending_passes_after_reading_all_three_kinds(): void {
		$result = $this->run_with(
			array(
				'plugins' => $this->plugins_value( array() ),
				'themes'  => $this->themes_value( array() ),
				'core'    => $this->core_value( array( 'latest' ) ),
			)
		);

		$this->assertTrue( $result->is_pass(), (string) $result->message() );
	}

	public function test_pending_plugins_themes_and_core_are_listed_with_their_new_versions(): void {
		$finding = $this->run_with(
			array(
				'plugins' => $this->plugins_value(
					array(
						'zeta/zeta.php'   => '2.0',
						'alpha/alpha.php' => '1.5.1',
					)
				),
				'themes'  => $this->themes_value( array( 'twentytwentyfour' => '1.3' ) ),
				'core'    => $this->core_value( array( 'upgrade', 'latest' ) ),
			)
		)->finding_list()[0];

		$evidence = $finding->evidence();
		$this->assertSame( 'warning', $finding->severity()->name() );
		$this->assertSame( 2, $evidence['plugin_updates'] );
		$this->assertSame( 1, $evidence['theme_updates'] );
		$this->assertSame( 1, $evidence['core_updates'] );
		$this->assertSame( '6.5.3', $evidence['core_available'] );
		$this->assertSame( 'alpha/alpha.php -> 1.5.1', $evidence['plugins'][0] );
		$this->assertSame( 'zeta/zeta.php -> 2.0', $evidence['plugins'][1] );
		$this->assertSame( 'twentytwentyfour -> 1.3', $evidence['themes'][0] );
		$this->assertNotSame( '', $finding->fix() );
	}

	public function test_core_responses_other_than_upgrade_and_autoupdate_do_not_count(): void {
		$result = $this->run_with(
			array(
				'plugins' => $this->plugins_value( array() ),
				'themes'  => $this->themes_value( array() ),
				'core'    => $this->core_value( array( 'latest', 'development' ) ),
			)
		);

		$this->assertTrue( $result->is_pass() );
	}

	public function test_a_core_offer_waiting_for_the_background_updater_counts_once_per_version(): void {
		$evidence = $this->run_with(
			array(
				'plugins' => $this->plugins_value( array() ),
				'themes'  => $this->themes_value( array() ),
				'core'    => $this->core_offers( array( array( 'upgrade', '6.6.1' ), array( 'autoupdate', '6.6.1' ), array( 'autoupdate', '6.5.5' ) ) ),
			)
		)->finding_list()[0]->evidence();

		$this->assertSame( 2, $evidence['core_updates'] );
		$this->assertSame( '6.6.1, 6.5.5', $evidence['core_available'] );
	}

	public function test_an_offer_that_is_not_newer_than_the_installed_version_does_not_count(): void {
		$result = $this->run_with(
			array(
				'plugins' => $this->plugins_value( array() ),
				'themes'  => $this->themes_value( array() ),
				'core'    => $this->core_offers( array( array( 'autoupdate', '6.5.2' ), array( 'upgrade', '6.4.5' ) ) ),
			)
		);

		$this->assertTrue( $result->is_pass(), (string) $result->message() );
	}

	public function test_a_multisite_network_reads_the_update_data_from_sitemeta_for_the_main_network(): void {
		list( $result, $driver ) = $this->run_on_network(
			array(
				'plugins' => $this->plugins_value( array( 'a/a.php' => '2.0' ) ),
				'themes'  => $this->themes_value( array() ),
				'core'    => $this->core_value( array( 'latest' ) ),
			)
		);

		$evidence = $result->finding_list()[0]->evidence();
		$this->assertSame( 1, $evidence['plugin_updates'] );
		$this->assertSame( 'a/a.php -> 2.0', $evidence['plugins'][0] );
		$queries = array_slice( $driver->statements, 1 );
		$this->assertCount( 6, $queries );
		foreach ( $queries as $sql ) {
			$this->assertStringContainsString( '`wptest_sitemeta`', $sql );
			$this->assertStringContainsString( 'site_id = 1 ', $sql );
		}
	}

	public function test_a_network_id_set_in_wp_config_is_the_one_read(): void {
		list( , $driver ) = $this->run_on_network( array( 'plugins' => $this->plugins_value( array() ) ), "define( 'SITE_ID_CURRENT_SITE', 3 );" );

		$queries = array_slice( $driver->statements, 1 );
		$this->assertCount( 4, $queries );
		foreach ( $queries as $sql ) {
			$this->assertStringContainsString( 'site_id = 3 ', $sql );
		}
	}

	public function test_a_network_id_that_could_not_be_read_is_skipped_with_the_reason(): void {
		list( $result, $driver ) = $this->run_on_network( array(), "define( 'SITE_ID_CURRENT_SITE', getenv( 'EXAMPLE_NETWORK' ) );" );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'SITE_ID_CURRENT_SITE', (string) $result->message() );
		$this->assertSame( array(), array_slice( $driver->statements, 1 ) );
	}

	public function test_a_network_without_update_data_names_sitemeta_in_the_skip(): void {
		list( $result ) = $this->run_on_network( array() );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( '`wptest_sitemeta`', (string) $result->message() );
	}

	public function test_a_site_whose_multisite_setting_is_unknown_names_both_places_when_no_data_is_found(): void {
		$tree = new Temp_Tree();
		try {
			$result = ( new Outdated_Updates_Check() )->run( Context_Factory::for_tree_with_driver( $tree, new Options_Driver(), "define( 'MULTISITE', getenv( 'EXAMPLE_MULTISITE' ) === '1' );" ) );
		} finally {
			$tree->remove();
		}

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'MULTISITE', (string) $result->message() );
		$this->assertStringContainsString( 'sitemeta', (string) $result->message() );
	}

	public function test_a_kind_without_stored_data_is_named_when_something_is_pending(): void {
		$evidence = $this->run_with( array( 'plugins' => $this->plugins_value( array( 'a/a.php' => '2' ) ) ) )->finding_list()[0]->evidence();

		$this->assertSame( 'themes, core', $evidence['no_update_data_for'] );
	}

	public function test_a_kind_without_stored_data_makes_a_clean_result_a_skip(): void {
		$result = $this->run_with( array( 'plugins' => $this->plugins_value( array() ) ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'themes, core', (string) $result->message() );
	}

	public function test_no_stored_update_data_at_all_is_skipped(): void {
		$result = $this->run_with( array() );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'object cache', (string) $result->message() );
	}

	public function test_a_value_that_is_not_a_plain_structure_is_an_error(): void {
		$this->expectException( Malformed_Serialization_Exception::class );
		$this->run_with( array( 'plugins' => 'C:11:"ArrayObject":0:{}' ) );
	}

	public function test_it_is_skipped_without_a_database_or_a_table_prefix(): void {
		$this->assertSame( Result::SKIPPED, ( new Outdated_Updates_Check() )->run( Context_Factory::without_database() )->status() );
		$this->assertSame( Result::SKIPPED, ( new Outdated_Updates_Check() )->run( Context_Factory::with_driver( new Recording_Driver(), 'computed' ) )->status() );
	}
}
