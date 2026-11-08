<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Config\Db_Version_Check;
use WP_Doctor\Check\Category\Performance\Cron_Overdue_Check;
use WP_Doctor\Check\Category\Performance\Revisions_Count_Check;
use WP_Doctor\Check\Category\Performance\Tables_Engine_Check;
use WP_Doctor\Check\Category\Performance\Tables_Largest_Check;
use WP_Doctor\Check\Category\Performance\Transients_Expired_Check;
use WP_Doctor\Check\Category\Security\Admin_Users_Check;
use WP_Doctor\Check\Category\Security\Injected_Content_Check;
use WP_Doctor\Check\Category\Security\Outdated_Updates_Check;
use WP_Doctor\Check\Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Check\Thresholds;
use WP_Doctor\Db\Read_Only_Connection;
use WP_Doctor\Fs\File_Scanner;
use WP_Doctor\Report\Result;
use WP_Doctor\Site\Config_Parser;
use WP_Doctor\Site\Site_Locator;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Temp_Tree;
use WP_Doctor\Tests\Support\Test_Database;

/**
 * Runs checks against a real database server. Needs WPD_TEST_DB_HOST.
 */
final class Database_Checks_Integration_Test extends TestCase {

	/**
	 * Runs a check against a database loaded from a seed file.
	 *
	 * @param Check           $check      Check to run.
	 * @param string          $seed       File name under tests/fixtures/sql.
	 * @param Thresholds|null $thresholds Limits to use instead of the defaults.
	 * @return Result
	 */
	private function run_against( Check $check, string $seed, ?Thresholds $thresholds = null ): Result {
		$database = Test_Database::seeded( $seed );
		try {
			$site    = ( new Site_Locator( new Config_Parser() ) )->locate( dirname( __DIR__ ) . '/fixtures/sites/plain' );
			$context = Context::with_database( $site, new File_Scanner( $site->root() ), new Read_Only_Connection( $database->driver() ) );
			if ( null !== $thresholds ) {
				$context = $context->with_thresholds( $thresholds );
			}

			return $check->run( $context );
		} finally {
			$database->drop();
		}
	}

	public function test_a_multisite_network_reads_its_own_update_data_from_sitemeta(): void {
		$database = Test_Database::seeded( 'network-updates.sql' );
		$tree     = new Temp_Tree();
		try {
			$result = ( new Outdated_Updates_Check() )->run( Context_Factory::for_tree_with_driver( $tree, $database->driver(), "define( 'MULTISITE', true );" ) );
		} finally {
			$tree->remove();
			$database->drop();
		}

		$evidence = $result->finding_list()[0]->evidence();
		$this->assertSame( 1, $evidence['plugin_updates'] );
		$this->assertSame( 'alpha/alpha.php -> 1.5.1', $evidence['plugin_1'] );
		$this->assertSame( 0, $evidence['theme_updates'] );
		$this->assertSame( '6.5.5', $evidence['core_available'] );
	}

	public function test_ten_thousand_expired_transients_are_a_warning_and_fresh_ones_are_not_counted(): void {
		$result = $this->run_against( new Transients_Expired_Check( 1700000000 ), 'transients-many-expired.sql' );

		$evidence = $result->finding_list()[0]->evidence();
		$this->assertSame( 10000, $evidence['expired_transients'] );
		$this->assertSame( 10000 * strlen( 'cached' ), $evidence['expired_value_bytes'] );
	}

	public function test_two_hundred_expired_transients_of_both_kinds_pass(): void {
		$result = $this->run_against( new Transients_Expired_Check( 1700000000 ), 'transients-few-expired.sql' );

		$this->assertTrue( $result->is_pass(), (string) $result->message() );
	}

	public function test_the_expiry_clock_decides_what_is_expired(): void {
		$result = $this->run_against( new Transients_Expired_Check( 1500000000 ), 'transients-many-expired.sql' );

		$this->assertTrue( $result->is_pass() );
	}

	public function test_a_schedule_far_in_the_past_is_overdue(): void {
		$result = $this->run_against( new Cron_Overdue_Check( 1700000000 + 100 * 3600 ), 'cron-runaway.sql' );

		$evidence = $result->finding_list()[0]->evidence();
		$this->assertSame( 61, $evidence['overdue_events'] );
		$this->assertSame( 'example_cleanup', $evidence['hook_1'] );
		$this->assertSame( 60, $evidence['hook_1_overdue'] );
	}

	public function test_a_schedule_that_is_not_yet_due_passes(): void {
		$result = $this->run_against( new Cron_Overdue_Check( 1699990000 ), 'cron-runaway.sql' );

		$this->assertTrue( $result->is_pass() );
	}

	public function test_tables_are_listed_largest_first_and_other_applications_tables_are_left_out(): void {
		$result = $this->run_against( new Tables_Largest_Check(), 'tables-sizes.sql' );

		$evidence = $result->finding_list()[0]->evidence();
		$this->assertSame( 3, $evidence['tables_with_prefix'] );
		$this->assertSame( 'wptest_postmeta', $evidence['table_1'] );
		$this->assertSame( 'wptest_posts', $evidence['table_2'] );
		$this->assertSame( 'wptest_security_log', $evidence['table_3'] );
		$this->assertGreaterThan( $evidence['table_2_bytes'], $evidence['table_1_bytes'] );
	}

	public function test_revisions_are_counted_by_type_and_grouped_by_their_post(): void {
		$result = $this->run_against( new Revisions_Count_Check(), 'posts-revisions.sql', Thresholds::from_config( array( 'perf.revisions.count' => array( 'warning' => 5 ) ), 'test.json' ) );

		$evidence = $result->finding_list()[0]->evidence();
		$this->assertSame( 6, $evidence['revisions'] );
		$this->assertSame( 2, $evidence['posts_with_revisions'] );
		$this->assertSame( array( 2, 4, 1, 2 ), array( $evidence['post_1'], $evidence['post_1_revisions'], $evidence['post_2'], $evidence['post_2_revisions'] ) );
	}

	public function test_myisam_base_tables_with_the_prefix_are_listed(): void {
		$result = $this->run_against( new Tables_Engine_Check(), 'tables-engines.sql' );

		$evidence = $result->finding_list()[0]->evidence();
		$this->assertSame( 3, $evidence['tables_with_prefix'] );
		$this->assertSame( 2, $evidence['myisam_tables'] );
		$this->assertSame( array( 'wptest_old_log', 'wptest_terms' ), array( $evidence['table_1'], $evidence['table_2'] ) );
	}

	public function test_the_real_server_version_is_read_and_judged_by_its_series(): void {
		$database = Test_Database::seeded( 'tables-engines.sql' );
		try {
			$version = $database->driver()->server_version();
			$result  = $this->run_against( new Db_Version_Check(), 'tables-engines.sql' );
		} finally {
			$database->drop();
		}

		$this->assertSame( 1, preg_match( '/^(?:5\.5\.5-)?(\d+\.\d+)\.\d+/', $version, $match ), $version );
		$this->assertContains( $result->status(), array( Result::PASS, Result::FINDINGS ), (string) $result->message() );
		if ( Result::FINDINGS === $result->status() ) {
			$this->assertStringEndsWith( $match[1], $result->finding_list()[0]->evidence()['series'] );
		}
	}

	public function test_injected_scripts_and_recent_defacement_are_found_in_published_posts_only(): void {
		$result = $this->run_against( new Injected_Content_Check( 1794096000 ), 'posts-injected.sql' );

		$by = array();
		foreach ( $result->finding_list() as $finding ) {
			$by[ $finding->severity()->name() ] = $finding->evidence();
		}
		$this->assertSame( array( 'warning', 'info' ), array_keys( $by ) );
		$this->assertSame( 2, $by['warning']['posts_with_scripts'] );
		$this->assertSame( array( 2, 'script from cdn.bad.invalid' ), array( $by['warning']['post_1'], $by['warning']['post_1_scripts'] ) );
		$this->assertArrayNotHasKey( 'post_2', $by['warning'] );
		$this->assertSame( 1, $by['info']['posts_flagged'] );
		$this->assertSame( 5, $by['info']['post_1'] );
	}

	public function test_administrators_are_found_through_the_prefixed_capabilities_key_and_judged_with_the_bundled_indicators(): void {
		$result = $this->run_against( new Admin_Users_Check(), 'users-admins.sql' );

		$by = array();
		foreach ( $result->finding_list() as $finding ) {
			$by[ (string) $finding->evidence()['rule'] ] = $finding;
		}
		$this->assertSame( array( 'administrator_list', 'registration_cluster', 'name_indicator', 'email_address' ), array_keys( $by ) );

		$list = $by['administrator_list']->evidence();
		$this->assertSame( 5, $list['administrators'] );
		$this->assertSame( 'owner', $list['admin_1'] );
		$this->assertSame( '2020-01-01 10:00:00', $list['admin_1_registered'] );
		$this->assertSame( 2, $list['admin_1_posts'] );
		$this->assertSame( 'new_three', $list['admin_4'] );
		$this->assertSame( 0, $list['admin_4_posts'] );

		$cluster = $by['registration_cluster']->evidence();
		$this->assertSame( 'critical', $by['registration_cluster']->severity()->name() );
		$this->assertSame( 3, $cluster['cluster_size'] );
		$this->assertSame( 'new_one', $cluster['admin_1'] );

		$name = $by['name_indicator']->evidence();
		$this->assertSame( 'critical', $by['name_indicator']->severity()->name() );
		$this->assertSame( 1, $name['matches'] );
		$this->assertSame( 'wpenginer', $name['admin_1'] );
		$this->assertStringStartsWith( 'https://www.wordfence.com/', (string) $name['admin_1_source'] );

		$email = $by['email_address']->evidence();
		$this->assertSame( 'warning', $by['email_address']->severity()->name() );
		$this->assertSame( 5, $email['matches'] );
		$this->assertSame( 'placeholder email domain', $email['admin_1_reason'] );
	}

	public function test_pending_updates_are_read_from_the_stored_wordpress_objects(): void {
		$result = $this->run_against( new Outdated_Updates_Check(), 'options-updates.sql' );

		$evidence = $result->finding_list()[0]->evidence();
		$this->assertSame( 2, $evidence['plugin_updates'] );
		$this->assertSame( 1, $evidence['theme_updates'] );
		$this->assertSame( '6.5.3', $evidence['core_available'] );
		$this->assertSame( 'alpha/alpha.php -> 1.5.1', $evidence['plugin_1'] );
		$this->assertSame( 'twentytwentyfour -> 1.3', $evidence['theme_1'] );
	}

	public function test_stored_update_data_with_nothing_pending_passes(): void {
		$result = $this->run_against( new Outdated_Updates_Check(), 'options-no-updates.sql' );

		$this->assertTrue( $result->is_pass(), (string) $result->message() );
	}
}
