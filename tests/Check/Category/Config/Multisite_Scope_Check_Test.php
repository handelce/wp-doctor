<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Config;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Config\Multisite_Scope_Check;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Temp_Tree;

final class Multisite_Scope_Check_Test extends TestCase {

	/**
	 * Runs the check against a wp-config.php holding the given statements.
	 *
	 * @param string $config wp-config.php statements.
	 * @return Result
	 */
	private function run_with( string $config ): Result {
		$tree = new Temp_Tree();
		try {
			return ( new Multisite_Scope_Check() )->run( Context_Factory::for_tree( $tree, $config ) );
		} finally {
			$tree->remove();
		}
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Multisite_Scope_Check();

		$this->assertSame( 'config.multisite.scope', $check->id() );
		$this->assertSame( 'config', $check->category() );
	}

	public function test_a_single_site_passes(): void {
		$this->assertTrue( $this->run_with( "define( 'WP_DEBUG', false );" )->is_pass() );
	}

	public function test_multisite_switched_off_passes(): void {
		$this->assertTrue( $this->run_with( "define( 'MULTISITE', false );" )->is_pass() );
	}

	public function test_a_network_is_reported_as_scanned_by_its_main_site_only(): void {
		$result = $this->run_with( "define( 'MULTISITE', true );\ndefine( 'SUBDOMAIN_INSTALL', false );" );

		$findings = $result->finding_list();
		$this->assertCount( 1, $findings, 'A network must produce one finding' );
		$this->assertSame( 'info', $findings[0]->severity()->name() );
		$this->assertSame( 'main site only', $findings[0]->evidence()['sites_scanned'] );
		$this->assertSame( 'false', $findings[0]->evidence()['SUBDOMAIN_INSTALL'] );
		$this->assertSame( 'wptest_', $findings[0]->evidence()['main_site_table_prefix'] );
		$this->assertNotSame( '', $findings[0]->fix() );
	}

	public function test_a_network_whose_subdomain_setting_is_not_read_says_so(): void {
		$findings = $this->run_with( "define( 'MULTISITE', true );" )->finding_list();

		$this->assertCount( 1, $findings );
		$this->assertSame( 'not read from wp-config.php', $findings[0]->evidence()['SUBDOMAIN_INSTALL'] );
	}

	public function test_a_computed_multisite_value_is_skipped_with_the_reason(): void {
		$result = $this->run_with( "define( 'MULTISITE', getenv( 'X' ) );" );

		$this->assertSame( 'skipped', $result->status() );
		$this->assertStringContainsString( 'MULTISITE', (string) $result->message() );
	}

	public function test_multisite_not_in_wp_config_is_skipped_when_wp_config_includes_another_file(): void {
		$result = $this->run_with( "require __DIR__ . '/network.php';" );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'MULTISITE', (string) $result->message() );
	}

	public function test_multisite_switched_off_in_wp_config_passes_even_with_an_include(): void {
		$this->assertTrue( $this->run_with( "define( 'MULTISITE', false );\nrequire __DIR__ . '/network.php';" )->is_pass() );
	}
}
