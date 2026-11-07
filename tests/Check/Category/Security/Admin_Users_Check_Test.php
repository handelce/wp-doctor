<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Security;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Security\Admin_Users_Check;
use WP_Doctor\Check\Category\Security\Rogue_Admin_Indicators;
use WP_Doctor\Check\Thresholds;
use WP_Doctor\Db\Database_Driver;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Recording_Driver;

final class Admin_Users_Check_Test extends TestCase {

	/**
	 * Indicators for these tests: invented names, so the data file is not under test here.
	 *
	 * @return Rogue_Admin_Indicators
	 */
	private function indicators(): Rogue_Admin_Indicators {
		return new Rogue_Admin_Indicators(
			array(
				array(
					'login'    => 'rogue_tool',
					'match'    => 'exact',
					'signal'   => 'strong',
					'campaign' => 'Invented campaign A',
					'source'   => 'https://reports.example.test/a',
				),
				array(
					'login'    => 'xq_',
					'match'    => 'prefix',
					'signal'   => 'strong',
					'campaign' => 'Invented campaign B',
					'source'   => 'https://reports.example.test/b',
				),
				array(
					'login'    => 'backupadmin',
					'match'    => 'exact',
					'signal'   => 'soft',
					'email'    => 'backupadmin@reports.example.test',
					'campaign' => 'Invented campaign C',
					'source'   => 'https://reports.example.test/c',
				),
				array(
					'login'    => 'help',
					'match'    => 'exact',
					'signal'   => 'soft',
					'campaign' => 'Invented campaign D',
					'source'   => 'https://reports.example.test/d',
				),
			),
			array( 'hostbot' ),
			array( 'example.com' ),
			array( 'invalid' ),
			array( 'tempbox.test' )
		);
	}

	/**
	 * A database holding users and post counts that answers the administrator
	 * query in its ORDER BY and LIMIT, and records every statement. It does not
	 * apply the WHERE clause: every user it holds is returned as an
	 * administrator. The capability filter is proven against real servers by
	 * Database_Checks_Integration_Test with users-admins.sql.
	 *
	 * @return Database_Driver
	 */
	private function users_database(): Database_Driver {
		return new class() implements Database_Driver {

			/**
			 * Every statement received, in order.
			 *
			 * @var string[]
			 */
			public $statements = array();

			/**
			 * User rows, in insertion order.
			 *
			 * @var array<int, array<string, string>>
			 */
			public $users = array();

			/**
			 * Post count rows.
			 *
			 * @var array<int, array<string, string>>
			 */
			public $posts = array();

			/**
			 * Answers the users and posts queries.
			 *
			 * @param string $sql      Statement.
			 * @param int    $max_rows Row limit.
			 * @return array<int, array<string, string>>
			 */
			public function execute( string $sql, int $max_rows ): array {
				$this->statements[] = $sql;
				if ( false !== strpos( $sql, '`wptest_posts`' ) ) {
					return $this->posts;
				}
				if ( false === strpos( $sql, '`wptest_users`' ) ) {
					return array();
				}
				if ( 1 !== preg_match( '/ORDER BY u\.user_registered (ASC|DESC), u\.ID (ASC|DESC) LIMIT (\d+)$/', $sql, $order ) ) {
					throw new \LogicException( 'The administrator query no longer orders by registration and id with a limit' );
				}
				$rows = $this->users;
				usort(
					$rows,
					static function ( array $a, array $b ) use ( $order ): int {
						$by_date = strcmp( $a['registered'], $b['registered'] );
						if ( 'DESC' === $order[1] ) {
							$by_date = -$by_date;
						}
						$by_id = (int) $a['user_id'] <=> (int) $b['user_id'];
						if ( 'DESC' === $order[2] ) {
							$by_id = -$by_id;
						}

						return 0 !== $by_date ? $by_date : $by_id;
					}
				);

				return array_slice( $rows, 0, (int) $order[3] );
			}
		};
	}

	/**
	 * Runs the check against a database that reports the given administrators.
	 *
	 * Each admin is login, registration date, and optional email, display name, url and post count.
	 * The users query is answered as MySQL would: sorted by its ORDER BY and cut to its LIMIT.
	 *
	 * @param array<int, array<int, string|int>> $admins     Accounts.
	 * @param Rogue_Admin_Indicators|null       $indicators Indicators to judge by. Null for the invented test set.
	 * @return array{0: Result, 1: Database_Driver}
	 */
	private function run_with_admins( array $admins, ?Rogue_Admin_Indicators $indicators = null ): array {
		$driver = $this->users_database();
		foreach ( $admins as $index => $admin ) {
			$id              = (string) ( $index + 1 );
			$driver->users[] = array(
				'user_id'      => $id,
				'login'        => (string) $admin[0],
				'email'        => (string) ( $admin[2] ?? $admin[0] . '@example.test' ),
				'registered'   => (string) $admin[1],
				'display_name' => (string) ( $admin[3] ?? ucfirst( (string) $admin[0] ) . ' Person' ),
				'url'          => (string) ( $admin[4] ?? 'https://example.test' ),
			);
			if ( ( $admin[5] ?? 0 ) > 0 ) {
				$driver->posts[] = array(
					'user_id' => $id,
					'posts'   => (string) $admin[5],
				);
			}
		}

		return array( ( new Admin_Users_Check( $indicators ?? $this->indicators() ) )->run( Context_Factory::with_driver( $driver ) ), $driver );
	}

	/**
	 * Findings keyed by the rule in their evidence.
	 *
	 * @param Result $result Result with findings.
	 * @return array<string, Finding>
	 */
	private function by_rule( Result $result ): array {
		$by = array();
		foreach ( $result->finding_list() as $finding ) {
			$rule = (string) $finding->evidence()['rule'];
			$this->assertArrayNotHasKey( $rule, $by, 'Each rule produces at most one finding' );
			$by[ $rule ] = $finding;
		}
		$this->assertNotEmpty( $by );

		return $by;
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Admin_Users_Check();

		$this->assertSame( 'sec.users.admins', $check->id() );
		$this->assertSame( 'security', $check->category() );
	}

	public function test_the_query_joins_users_to_the_capabilities_of_the_sites_prefix(): void {
		list( , $driver ) = $this->run_with_admins( array( array( 'owner', '2020-01-01 10:00:00' ) ) );

		$sql = $driver->statements[1];
		$this->assertStringContainsString( '`wptest_users`', $sql );
		$this->assertStringContainsString( '`wptest_usermeta`', $sql );
		$this->assertStringContainsString( "m.meta_key = 'wptest_capabilities'", $sql );
		$this->assertStringContainsString( 'LIKE \'%"administrator"%\'', $sql );
		$this->assertStringContainsString( 'display_name', $sql );
	}

	public function test_post_counts_are_read_for_the_listed_administrators_only(): void {
		list( , $driver ) = $this->run_with_admins(
			array(
				array( 'owner', '2020-01-01 10:00:00' ),
				array( 'second', '2021-01-01 10:00:00' ),
			)
		);

		$sql = $driver->statements[2];
		$this->assertStringContainsString( '`wptest_posts`', $sql );
		$this->assertStringContainsString( 'post_author IN (1,2)', $sql );
		$this->assertStringContainsString( 'GROUP BY post_author', $sql );
	}

	public function test_accounts_spread_over_time_are_an_info_finding_with_dates_and_post_counts(): void {
		list( $result ) = $this->run_with_admins(
			array(
				array( 'owner', '2020-01-01 10:00:00', null, null, null, 12 ),
				array( 'editor_lead', '2021-06-01 10:00:00' ),
				array( 'agency', '2023-02-01 10:00:00' ),
			)
		);

		$findings = $this->by_rule( $result );
		$this->assertSame( array( 'administrator_list' ), array_keys( $findings ) );
		$this->assertSame( 'info', $findings['administrator_list']->severity()->name() );
		$evidence = $findings['administrator_list']->evidence();
		$this->assertSame( 3, $evidence['administrators'] );
		$this->assertSame( 'owner', $evidence['admin_1'] );
		$this->assertSame( '2020-01-01 10:00:00', $evidence['admin_1_registered'] );
		$this->assertSame( 'owner@example.test', $evidence['admin_1_email'] );
		$this->assertSame( 12, $evidence['admin_1_posts'] );
		$this->assertSame( 0, $evidence['admin_2_posts'] );
		$this->assertFalse( $evidence['list_truncated'] );
		$this->assertNotSame( '', $findings['administrator_list']->fix() );
	}

	public function test_three_accounts_created_within_a_day_are_also_a_critical_finding(): void {
		list( $result ) = $this->run_with_admins(
			array(
				array( 'owner', '2020-01-01 10:00:00' ),
				array( 'new_one', '2024-05-01 01:00:00' ),
				array( 'new_two', '2024-05-01 02:30:00' ),
				array( 'new_three', '2024-05-02 01:00:00' ),
			)
		);

		$finding = $this->by_rule( $result )['registration_cluster'];
		$this->assertSame( 'critical', $finding->severity()->name() );
		$this->assertStringContainsString( 'likely', $finding->title() );
		$evidence = $finding->evidence();
		$this->assertSame( 3, $evidence['cluster_size'] );
		$this->assertSame( 'new_one', $evidence['admin_1'] );
		$this->assertSame( 'new_three', $evidence['admin_3'] );
		$this->assertArrayNotHasKey( 'admin_4', $evidence );
		$this->assertStringContainsString( 'default', (string) $evidence['threshold_basis'] );
	}

	public function test_two_accounts_in_a_day_and_three_just_over_a_day_are_not_a_cluster(): void {
		list( $two )    = $this->run_with_admins(
			array(
				array( 'a', '2024-05-01 01:00:00' ),
				array( 'b', '2024-05-01 02:00:00' ),
			)
		);
		list( $spread ) = $this->run_with_admins(
			array(
				array( 'a', '2024-05-01 00:00:00' ),
				array( 'b', '2024-05-01 12:00:00' ),
				array( 'c', '2024-05-02 00:00:01' ),
			)
		);

		$this->assertArrayNotHasKey( 'registration_cluster', $this->by_rule( $two ) );
		$this->assertArrayNotHasKey( 'registration_cluster', $this->by_rule( $spread ) );
	}

	public function test_three_accounts_exactly_a_day_apart_in_total_are_a_cluster(): void {
		list( $result ) = $this->run_with_admins(
			array(
				array( 'a', '2024-05-01 00:00:00' ),
				array( 'b', '2024-05-01 12:00:00' ),
				array( 'c', '2024-05-02 00:00:00' ),
			)
		);

		$this->assertArrayHasKey( 'registration_cluster', $this->by_rule( $result ) );
	}

	public function test_an_account_with_an_invalid_date_is_listed_as_unknown_and_never_clustered(): void {
		list( $result ) = $this->run_with_admins(
			array(
				array( 'a', '0000-00-00 00:00:00' ),
				array( 'b', '0000-00-00 00:00:00' ),
				array( 'c', '0000-00-00 00:00:00' ),
			)
		);

		$findings = $this->by_rule( $result );
		$this->assertArrayNotHasKey( 'registration_cluster', $findings );
		$this->assertSame( 'unknown', $findings['administrator_list']->evidence()['admin_1_registered'] );
	}

	public function test_more_administrators_than_the_limit_says_the_list_is_truncated(): void {
		$admins = array();
		for ( $i = 0; $i < 201; $i++ ) {
			$admins[] = array( 'admin' . $i, gmdate( 'Y-m-d H:i:s', 1600000000 + $i * 200000 ) );
		}

		list( $result ) = $this->run_with_admins( $admins );

		$evidence = $this->by_rule( $result )['administrator_list']->evidence();
		$this->assertTrue( $evidence['list_truncated'] );
		$this->assertSame( 200, $evidence['administrators'] );
		$this->assertArrayNotHasKey( 'admin_21', $evidence );
	}

	public function test_exactly_a_full_page_of_administrators_is_not_called_truncated(): void {
		$admins = array();
		for ( $i = 0; $i < 200; $i++ ) {
			$admins[] = array( 'admin' . $i, gmdate( 'Y-m-d H:i:s', 1600000000 + $i * 200000 ) );
		}

		list( $result ) = $this->run_with_admins( $admins );

		$evidence = $this->by_rule( $result )['administrator_list']->evidence();
		$this->assertSame( 200, $evidence['administrators'] );
		$this->assertFalse( $evidence['list_truncated'] );
	}

	public function test_past_the_limit_the_newest_administrators_are_the_ones_judged(): void {
		$admins = array();
		for ( $i = 0; $i < 250; $i++ ) {
			$admins[] = array( 'admin' . $i, gmdate( 'Y-m-d H:i:s', 1600000000 + $i * 200000 ), null, null, null, 1 );
		}
		$admins[] = array( 'new_one', '2030-05-01 01:00:00' );
		$admins[] = array( 'rogue_tool', '2030-05-01 02:00:00' );
		$admins[] = array( 'new_two', '2030-05-01 03:00:00' );

		list( $result ) = $this->run_with_admins( $admins );

		$findings = $this->by_rule( $result );
		$list     = $findings['administrator_list']->evidence();
		$this->assertSame( 200, $list['administrators'] );
		$this->assertTrue( $list['list_truncated'] );
		$this->assertSame( 'admin53', $list['admin_1'] );
		$this->assertSame( 'rogue_tool', $findings['name_indicator']->evidence()['admin_1'] );
		$cluster = $findings['registration_cluster']->evidence();
		$this->assertSame( 3, $cluster['cluster_size'] );
		$this->assertSame( 'new_one', $cluster['admin_1'] );
		$this->assertSame( 'new_two', $cluster['admin_3'] );
	}

	public function test_the_odd_one_out_on_a_numeric_email_domain_names_that_account(): void {
		list( $result ) = $this->run_with_admins(
			array(
				array( 'owner', '2020-01-01 10:00:00', 'owner@example.test', null, null, 3 ),
				array( 'staff', '2021-01-01 10:00:00', 'staff@example.test', null, null, 1 ),
				array( 'newcomer', '2024-01-01 10:00:00', 'me@123' ),
			)
		);

		$evidence = $this->by_rule( $result )['odd_one_out']->evidence();
		$this->assertSame( 'newcomer', $evidence['admin_1'] );
		$this->assertSame( 'only administrator on 123, newest registration, no posts', $evidence['admin_1_reason'] );
	}

	public function test_the_odd_one_out_is_found_when_the_shared_domain_is_numeric(): void {
		list( $result ) = $this->run_with_admins(
			array(
				array( 'owner', '2020-01-01 10:00:00', 'owner@10', null, null, 3 ),
				array( 'staff', '2021-01-01 10:00:00', 'staff@10', null, null, 1 ),
				array( 'newcomer', '2024-01-01 10:00:00', 'me@elsewhere-example.test' ),
			)
		);

		$this->assertSame( 'newcomer', $this->by_rule( $result )['odd_one_out']->evidence()['admin_1'] );
	}

	public function test_a_login_equal_to_a_published_indicator_is_critical_and_names_the_report(): void {
		list( $result ) = $this->run_with_admins(
			array(
				array( 'owner', '2020-01-01 10:00:00', null, null, null, 3 ),
				array( 'Rogue_Tool', '2024-05-01 01:00:00' ),
			)
		);

		$finding = $this->by_rule( $result )['name_indicator'];
		$this->assertSame( 'critical', $finding->severity()->name() );
		$this->assertStringContainsString( 'likely', $finding->title() );
		$evidence = $finding->evidence();
		$this->assertSame( 1, $evidence['matches'] );
		$this->assertSame( 'Rogue_Tool', $evidence['admin_1'] );
		$this->assertSame( 'login equals rogue_tool', $evidence['admin_1_reason'] );
		$this->assertSame( 'Invented campaign A', $evidence['admin_1_campaign'] );
		$this->assertSame( 'https://reports.example.test/a', $evidence['admin_1_source'] );
		$this->assertStringContainsString( 'likely', (string) $evidence['indicator_basis'] );
		$this->assertStringContainsString( 'compromised', $finding->fix() );
	}

	public function test_a_login_with_a_published_prefix_is_critical(): void {
		list( $result ) = $this->run_with_admins(
			array(
				array( 'owner', '2020-01-01 10:00:00', null, null, null, 3 ),
				array( 'xq_7f3a', '2024-05-01 01:00:00' ),
			)
		);

		$evidence = $this->by_rule( $result )['name_indicator']->evidence();
		$this->assertSame( 'xq_7f3a', $evidence['admin_1'] );
		$this->assertSame( 'login starts with xq_', $evidence['admin_1_reason'] );
	}

	public function test_an_allowlisted_login_is_listed_but_never_judged(): void {
		list( $result ) = $this->run_with_admins(
			array(
				array( 'owner', '2020-01-01 10:00:00', null, null, null, 3 ),
				array( 'second', '2021-01-01 10:00:00', null, null, null, 1 ),
				array( 'hostbot', '2024-05-01 01:00:00', 'hostbot@tempbox.test', 'hostbot', '' ),
			)
		);

		$findings = $this->by_rule( $result );
		$this->assertSame( array( 'administrator_list' ), array_keys( $findings ) );
		$this->assertSame( 'hostbot', $findings['administrator_list']->evidence()['admin_3'] );
	}

	public function test_a_soft_indicator_with_the_documented_email_is_critical(): void {
		list( $result ) = $this->run_with_admins(
			array(
				array( 'owner', '2020-01-01 10:00:00', null, null, null, 3 ),
				array( 'backupadmin', '2021-01-01 10:00:00', 'BackupAdmin@reports.example.test', 'Backup Admin', 'https://example.test', 5 ),
			)
		);

		$findings = $this->by_rule( $result );
		$this->assertArrayNotHasKey( 'soft_indicator', $findings );
		$evidence = $findings['name_indicator']->evidence();
		$this->assertSame( 'backupadmin', $evidence['admin_1'] );
		$this->assertStringContainsString( 'email matches the report', (string) $evidence['admin_1_reason'] );
	}

	public function test_a_soft_indicator_needs_both_the_newest_registration_and_no_posts(): void {
		list( $with_posts ) = $this->run_with_admins(
			array(
				array( 'owner', '2020-01-01 10:00:00', null, null, null, 3 ),
				array( 'help', '2024-01-01 10:00:00', 'help@example.test', 'help', '', 4 ),
			)
		);
		list( $older )      = $this->run_with_admins(
			array(
				array( 'owner', '2020-01-01 10:00:00', null, null, null, 3 ),
				array( 'help', '2021-01-01 10:00:00', 'help@example.test', 'help', '' ),
				array( 'later', '2022-01-01 10:00:00', null, null, null, 2 ),
			)
		);
		list( $both )       = $this->run_with_admins(
			array(
				array( 'owner', '2020-01-01 10:00:00', null, null, null, 3 ),
				array( 'help', '2024-01-01 10:00:00', 'help@example.test', 'Help Desk', 'https://example.test' ),
			)
		);

		$this->assertArrayNotHasKey( 'soft_indicator', $this->by_rule( $with_posts ) );
		$this->assertArrayNotHasKey( 'soft_indicator', $this->by_rule( $older ) );
		$finding = $this->by_rule( $both )['soft_indicator'];
		$this->assertSame( 'warning', $finding->severity()->name() );
		$this->assertStringContainsString( 'likely', $finding->title() );
		$evidence = $finding->evidence();
		$this->assertSame( 'help', $evidence['admin_1'] );
		$this->assertSame( 'login equals help; newest registration, no posts', $evidence['admin_1_reason'] );
		$this->assertSame( 'https://reports.example.test/d', $evidence['admin_1_source'] );
	}

	public function test_the_wordpress_defaults_for_display_name_and_website_are_not_signals(): void {
		list( $result ) = $this->run_with_admins(
			array(
				array( 'owner', '2020-01-01 10:00:00', null, null, null, 3 ),
				array( 'help', '2021-01-01 10:00:00', 'help@example.test', 'help', '', 0 ),
				array( 'later', '2022-01-01 10:00:00', null, null, null, 2 ),
			)
		);

		$findings = $this->by_rule( $result );
		$this->assertSame( 'help', $findings['administrator_list']->evidence()['admin_2'] );
		$this->assertArrayNotHasKey( 'soft_indicator', $findings );
	}

	public function test_an_empty_or_invalid_email_is_a_warning(): void {
		list( $result ) = $this->run_with_admins(
			array(
				array( 'owner', '2020-01-01 10:00:00', null, null, null, 3 ),
				array( 'blank', '2021-01-01 10:00:00', '' ),
				array( 'broken', '2022-01-01 10:00:00', 'not-an-address' ),
			)
		);

		$finding = $this->by_rule( $result )['email_address'];
		$this->assertSame( 'warning', $finding->severity()->name() );
		$this->assertStringContainsString( 'likely', $finding->title() );
		$evidence = $finding->evidence();
		$this->assertSame( 2, $evidence['matches'] );
		$this->assertSame( 'blank', $evidence['admin_1'] );
		$this->assertSame( 'no email address', $evidence['admin_1_reason'] );
		$this->assertSame( 'broken', $evidence['admin_2'] );
		$this->assertSame( 'not a valid email address', $evidence['admin_2_reason'] );
	}

	public function test_a_placeholder_email_domain_is_a_warning_and_a_dotless_one_is_not_an_address(): void {
		list( $result ) = $this->run_with_admins(
			array(
				array( 'owner', '2020-01-01 10:00:00', null, null, null, 3 ),
				array( 'seeded', '2021-01-01 10:00:00', 'seeded@Example.COM' ),
				array( 'dotless', '2022-01-01 10:00:00', 'root@localhost' ),
				array( 'reserved', '2023-01-01 10:00:00', 'who@host.invalid' ),
			)
		);

		$evidence = $this->by_rule( $result )['email_address']->evidence();
		$this->assertSame( 3, $evidence['matches'] );
		$this->assertSame( 'placeholder email domain', $evidence['admin_1_reason'] );
		$this->assertSame( 'not a valid email address', $evidence['admin_2_reason'] );
		$this->assertSame( 'placeholder email domain', $evidence['admin_3_reason'] );
	}

	public function test_a_disposable_email_domain_is_a_warning_including_its_subdomains(): void {
		list( $result ) = $this->run_with_admins(
			array(
				array( 'owner', '2020-01-01 10:00:00', null, null, null, 3 ),
				array( 'burner', '2021-01-01 10:00:00', 'burner@tempbox.test' ),
				array( 'nested', '2022-01-01 10:00:00', 'nested@mx1.tempbox.test' ),
			)
		);

		$evidence = $this->by_rule( $result )['email_address']->evidence();
		$this->assertSame( 2, $evidence['matches'] );
		$this->assertSame( 'disposable email domain', $evidence['admin_1_reason'] );
		$this->assertSame( 'disposable email domain', $evidence['admin_2_reason'] );
	}

	public function test_ordinary_email_addresses_raise_nothing(): void {
		list( $result ) = $this->run_with_admins(
			array(
				array( 'owner', '2020-01-01 10:00:00', 'owner@example.test', null, null, 3 ),
				array( 'agency', '2021-01-01 10:00:00', 'team@agency-example.test', null, null, 1 ),
			)
		);

		$this->assertSame( array( 'administrator_list' ), array_keys( $this->by_rule( $result ) ) );
	}

	public function test_the_odd_one_out_is_an_info_finding(): void {
		list( $result ) = $this->run_with_admins(
			array(
				array( 'owner', '2020-01-01 10:00:00', 'owner@example.test', null, null, 3 ),
				array( 'staff', '2021-01-01 10:00:00', 'staff@example.test', null, null, 1 ),
				array( 'newcomer', '2024-01-01 10:00:00', 'me@elsewhere-example.test' ),
			)
		);

		$finding = $this->by_rule( $result )['odd_one_out'];
		$this->assertSame( 'info', $finding->severity()->name() );
		$this->assertStringContainsString( 'likely', $finding->title() );
		$evidence = $finding->evidence();
		$this->assertSame( 'newcomer', $evidence['admin_1'] );
		$this->assertSame( 'only administrator on elsewhere-example.test, newest registration, no posts', $evidence['admin_1_reason'] );
	}

	public function test_the_odd_one_out_needs_three_admins_the_newest_registration_and_no_posts(): void {
		list( $two_admins )    = $this->run_with_admins(
			array(
				array( 'owner', '2020-01-01 10:00:00', 'owner@example.test', null, null, 3 ),
				array( 'newcomer', '2024-01-01 10:00:00', 'me@elsewhere-example.test' ),
			)
		);
		list( $not_newest )    = $this->run_with_admins(
			array(
				array( 'owner', '2020-01-01 10:00:00', 'owner@example.test', null, null, 3 ),
				array( 'contractor', '2021-01-01 10:00:00', 'me@elsewhere-example.test' ),
				array( 'staff', '2024-01-01 10:00:00', 'staff@example.test', null, null, 1 ),
			)
		);
		list( $has_posts )     = $this->run_with_admins(
			array(
				array( 'owner', '2020-01-01 10:00:00', 'owner@example.test', null, null, 3 ),
				array( 'staff', '2021-01-01 10:00:00', 'staff@example.test', null, null, 1 ),
				array( 'author', '2024-01-01 10:00:00', 'me@elsewhere-example.test', null, null, 7 ),
			)
		);
		list( $three_domains ) = $this->run_with_admins(
			array(
				array( 'owner', '2020-01-01 10:00:00', 'owner@example.test', null, null, 3 ),
				array( 'staff', '2021-01-01 10:00:00', 'staff@other-example.test', null, null, 1 ),
				array( 'newcomer', '2024-01-01 10:00:00', 'me@elsewhere-example.test' ),
			)
		);

		foreach ( array( $two_admins, $not_newest, $has_posts, $three_domains ) as $result ) {
			$this->assertArrayNotHasKey( 'odd_one_out', $this->by_rule( $result ) );
		}
	}

	public function test_no_administrators_is_skipped_not_passed(): void {
		list( $result ) = $this->run_with_admins( array() );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'wptest_users', (string) $result->message() );
	}

	public function test_a_row_without_a_login_is_an_error(): void {
		$driver       = new Recording_Driver();
		$driver->rows = array(
			array(
				'user_id'    => '1',
				'registered' => '2020-01-01 00:00:00',
			),
		);

		$this->expectException( \UnexpectedValueException::class );
		( new Admin_Users_Check( $this->indicators() ) )->run( Context_Factory::with_driver( $driver ) );
	}

	public function test_it_is_skipped_without_a_database_or_a_table_prefix(): void {
		$this->assertSame( Result::SKIPPED, ( new Admin_Users_Check() )->run( Context_Factory::without_database() )->status() );
		$this->assertSame( Result::SKIPPED, ( new Admin_Users_Check() )->run( Context_Factory::with_driver( new Recording_Driver(), 'computed' ) )->status() );
	}

	public function test_the_bundled_indicators_are_used_when_none_are_given(): void {
		$driver           = new Recording_Driver();
		$driver->rows_for = array(
			'`wptest_users`' => array(
				array(
					'user_id'      => '1',
					'login'        => 'owner',
					'email'        => 'owner@agency-example.org',
					'registered'   => '2020-01-01 10:00:00',
					'display_name' => 'Owner',
					'url'          => '',
				),
				array(
					'user_id'      => '2',
					'login'        => 'wpenginer',
					'email'        => 'x@agency-example.org',
					'registered'   => '2024-01-01 10:00:00',
					'display_name' => 'wpenginer',
					'url'          => '',
				),
			),
			'`wptest_posts`' => array(),
		);

		$result = ( new Admin_Users_Check() )->run( Context_Factory::with_driver( $driver ) );

		$evidence = $this->by_rule( $result )['name_indicator']->evidence();
		$this->assertSame( 'wpenginer', $evidence['admin_1'] );
		$this->assertStringStartsWith( 'https://www.wordfence.com/', (string) $evidence['admin_1_source'] );
	}

	public function test_the_cluster_size_can_be_lowered_by_configuration(): void {
		$driver           = new Recording_Driver();
		$driver->rows_for = array(
			'`wptest_users`' => array(
				array(
					'user_id'      => '1',
					'login'        => 'a',
					'email'        => 'a@example.test',
					'registered'   => '2024-05-01 01:00:00',
					'display_name' => 'A Person',
					'url'          => '',
				),
				array(
					'user_id'      => '2',
					'login'        => 'b',
					'email'        => 'b@example.test',
					'registered'   => '2024-05-01 02:00:00',
					'display_name' => 'B Person',
					'url'          => '',
				),
			),
			'`wptest_posts`' => array(),
		);
		$context          = Context_Factory::with_driver( $driver )->with_thresholds( Thresholds::from_config( array( 'sec.users.admins' => array( 'critical' => 2 ) ), 'custom.json' ) );

		$findings = $this->by_rule( ( new Admin_Users_Check( $this->indicators() ) )->run( $context ) );

		$this->assertSame( 2, $findings['registration_cluster']->evidence()['cluster_size'] );
		$this->assertStringStartsWith( '2 or more within', (string) $findings['registration_cluster']->evidence()['cluster_rule'] );
		$this->assertSame( 'set in custom.json', $findings['registration_cluster']->evidence()['threshold_basis'] );
	}

	public function test_a_real_person_whose_login_starts_with_xtw_is_not_judged_by_the_bundled_data(): void {
		list( $result ) = $this->run_with_admins(
			array(
				array( 'owner', '2020-01-01 10:00:00', null, null, null, 3 ),
				array( 'xtwang', '2024-05-01 10:00:00', 'xtwang@example.test', 'Xiaotian Wang', 'https://example.test', 12 ),
			),
			Rogue_Admin_Indicators::bundled()
		);

		$findings = $this->by_rule( $result );
		$this->assertSame( 'xtwang', $findings['administrator_list']->evidence()['admin_2'] );
		$this->assertArrayNotHasKey( 'name_indicator', $findings );
		$this->assertArrayNotHasKey( 'soft_indicator', $findings );
	}

	public function test_a_script_made_xtw_account_is_a_warning_from_the_bundled_data(): void {
		list( $result ) = $this->run_with_admins(
			array(
				array( 'owner', '2020-01-01 10:00:00', null, null, null, 3 ),
				array( 'xtw4a1f9c', '2024-05-01 10:00:00', 'xtw4a1f9c@example.test', 'xtw4a1f9c', '' ),
			),
			Rogue_Admin_Indicators::bundled()
		);

		$findings = $this->by_rule( $result );
		$this->assertArrayNotHasKey( 'name_indicator', $findings );
		$finding = $findings['soft_indicator'];
		$this->assertSame( 'warning', $finding->severity()->name() );
		$this->assertSame( 'xtw4a1f9c', $finding->evidence()['admin_1'] );
		$this->assertStringContainsString( 'login starts with xtw', (string) $finding->evidence()['admin_1_reason'] );
	}

	public function test_an_allowlisted_login_does_not_count_towards_a_registration_cluster(): void {
		list( $result ) = $this->run_with_admins(
			array(
				array( 'owner', '2024-05-01 01:00:00' ),
				array( 'hostbot', '2024-05-01 02:00:00' ),
				array( 'editor', '2024-05-01 03:00:00' ),
			)
		);

		$findings = $this->by_rule( $result );
		$this->assertSame( 'hostbot', $findings['administrator_list']->evidence()['admin_2'] );
		$this->assertArrayNotHasKey( 'registration_cluster', $findings );
	}

	public function test_a_registration_cluster_says_an_import_can_explain_it(): void {
		list( $result ) = $this->run_with_admins(
			array(
				array( 'a', '2024-05-01 01:00:00' ),
				array( 'b', '2024-05-01 01:00:00' ),
				array( 'c', '2024-05-01 01:00:00' ),
			)
		);

		$cluster = $this->by_rule( $result )['registration_cluster'];
		$this->assertSame( 3, $cluster->evidence()['cluster_size'] );
		$this->assertStringContainsString( 'import', (string) $cluster->evidence()['possible_cause'] );
		$this->assertStringContainsString( 'import', $cluster->fix() );
	}
}
