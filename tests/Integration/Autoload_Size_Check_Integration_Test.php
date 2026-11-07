<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Performance\Autoload_Size_Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Db\Read_Only_Connection;
use WP_Doctor\Fs\File_Scanner;
use WP_Doctor\Site\Config_Parser;
use WP_Doctor\Site\Site_Locator;
use WP_Doctor\Tests\Support\Test_Database;

/**
 * Runs the check against a real database server. Needs WPD_TEST_DB_HOST.
 */
final class Autoload_Size_Check_Integration_Test extends TestCase {

	/**
	 * Runs the check against a database loaded from a seed file.
	 *
	 * @param string $seed File name under tests/fixtures/sql.
	 * @return \WP_Doctor\Report\Result
	 */
	private function run_against( string $seed ) {
		$database = Test_Database::seeded( $seed );
		try {
			$site    = ( new Site_Locator( new Config_Parser() ) )->locate( dirname( __DIR__ ) . '/fixtures/sites/plain' );
			$context = Context::with_database( $site, new File_Scanner( $site->root() ), new Read_Only_Connection( $database->driver() ) );

			return ( new Autoload_Size_Check() )->run( $context );
		} finally {
			$database->drop();
		}
	}

	public function test_a_heavy_autoload_seed_is_a_warning_that_counts_only_autoloaded_rows(): void {
		$result = $this->run_against( 'options-heavy-autoload.sql' );

		$findings = $result->finding_list();
		$this->assertCount( 1, $findings );
		$this->assertSame( 'warning', $findings[0]->severity()->name() );
		$this->assertSame( 1500020, $findings[0]->evidence()['autoloaded_bytes'] );
		$this->assertSame( 2, $findings[0]->evidence()['autoloaded_options'] );
	}

	public function test_a_light_autoload_seed_passes_although_a_large_option_is_not_autoloaded(): void {
		$result = $this->run_against( 'options-light-autoload.sql' );

		$this->assertTrue( $result->is_pass() );
	}
}
