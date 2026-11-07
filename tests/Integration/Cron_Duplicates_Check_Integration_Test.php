<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Performance\Cron_Duplicates_Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Db\Read_Only_Connection;
use WP_Doctor\Fs\File_Scanner;
use WP_Doctor\Report\Result;
use WP_Doctor\Site\Config_Parser;
use WP_Doctor\Site\Site_Locator;
use WP_Doctor\Tests\Support\Test_Database;

/**
 * Runs the check against a real database server. Needs WPD_TEST_DB_HOST.
 */
final class Cron_Duplicates_Check_Integration_Test extends TestCase {

	/**
	 * Runs the check against a database loaded from a seed file.
	 *
	 * @param string $seed File name under tests/fixtures/sql.
	 * @return Result
	 */
	private function run_against( string $seed ): Result {
		$database = Test_Database::seeded( $seed );
		try {
			$site    = ( new Site_Locator( new Config_Parser() ) )->locate( dirname( __DIR__ ) . '/fixtures/sites/plain' );
			$context = Context::with_database( $site, new File_Scanner( $site->root() ), new Read_Only_Connection( $database->driver() ) );

			return ( new Cron_Duplicates_Check() )->run( $context );
		} finally {
			$database->drop();
		}
	}

	public function test_a_hook_scheduled_sixty_times_is_critical(): void {
		$result = $this->run_against( 'cron-runaway.sql' );

		$findings = $result->finding_list();
		$this->assertCount( 1, $findings );
		$this->assertSame( 'critical', $findings[0]->severity()->name() );
		$this->assertSame( 'example_cleanup', $findings[0]->evidence()['hook_1'] );
		$this->assertSame( 60, $findings[0]->evidence()['hook_1_copies'] );
		$this->assertSame( 61, $findings[0]->evidence()['scheduled_events'] );
		$this->assertArrayNotHasKey( 'hook_2', $findings[0]->evidence() );
	}

	public function test_fifty_identical_events_and_sixty_events_with_different_arguments_pass(): void {
		$result = $this->run_against( 'cron-normal.sql' );

		$this->assertTrue( $result->is_pass(), (string) $result->message() );
	}
}
