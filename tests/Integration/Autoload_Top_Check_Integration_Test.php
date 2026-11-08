<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Integration;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Performance\Autoload_Top_Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Db\Read_Only_Connection;
use WP_Doctor\Fs\File_Scanner;
use WP_Doctor\Site\Config_Parser;
use WP_Doctor\Site\Site_Locator;
use WP_Doctor\Tests\Support\Test_Database;

/**
 * Runs the check against a real database server. Needs WPD_TEST_DB_HOST.
 */
final class Autoload_Top_Check_Integration_Test extends TestCase {

	/**
	 * Runs the check against a database loaded from a seed file.
	 *
	 * @param string $seed File name under tests/fixtures/sql.
	 * @return array<string, string|int|float|bool>
	 */
	private function evidence_from( string $seed ): array {
		$database = Test_Database::seeded( $seed );
		try {
			$site    = ( new Site_Locator( new Config_Parser() ) )->locate( dirname( __DIR__ ) . '/fixtures/sites/plain' );
			$context = Context::with_database( $site, new File_Scanner( $site->root() ), new Read_Only_Connection( $database->driver() ) );
			$result  = ( new Autoload_Top_Check() )->run( $context );
		} finally {
			$database->drop();
		}

		$this->assertCount( 1, $result->finding_list() );

		return $result->finding_list()[0]->evidence();
	}

	public function test_the_heavy_seed_lists_autoloaded_options_largest_first_and_leaves_out_the_others(): void {
		$this->assertSame(
			array(
				'options' => array(
					array(
						'name'  => 'example_plugin_blob',
						'bytes' => 1500000,
					),
					array(
						'name'  => 'siteurl',
						'bytes' => 20,
					),
				),
			),
			$this->evidence_from( 'options-heavy-autoload.sql' )
		);
	}

	public function test_the_light_seed_lists_only_the_autoloaded_options(): void {
		$this->assertSame(
			array(
				'options' => array(
					array(
						'name'  => 'siteurl',
						'bytes' => 20,
					),
					array(
						'name'  => 'blogname',
						'bytes' => 7,
					),
				),
			),
			$this->evidence_from( 'options-light-autoload.sql' )
		);
	}
}
