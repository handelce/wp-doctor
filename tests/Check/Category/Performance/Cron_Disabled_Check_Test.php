<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Performance;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Performance\Cron_Disabled_Check;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Temp_Tree;

final class Cron_Disabled_Check_Test extends TestCase {

	/**
	 * @var Temp_Tree
	 */
	private $tree;

	protected function setUp(): void {
		$this->tree = new Temp_Tree();
	}

	protected function tearDown(): void {
		$this->tree->remove();
	}

	/**
	 * Runs the check with the given wp-config.php statements.
	 *
	 * @param string $config PHP statements.
	 * @return Result
	 */
	private function run_with( string $config ): Result {
		return ( new Cron_Disabled_Check() )->run( Context_Factory::for_tree( $this->tree, $config ) );
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Cron_Disabled_Check();

		$this->assertSame( 'perf.cron.disabled', $check->id() );
		$this->assertSame( 'performance', $check->category() );
	}

	public function test_wp_cron_turned_off_is_an_info_notice_pointing_at_a_system_job(): void {
		$finding = $this->run_with( "define( 'DISABLE_WP_CRON', true );" )->finding_list()[0];

		$this->assertSame( 'info', $finding->severity()->name() );
		$this->assertSame( 'true in wp-config.php', $finding->evidence()['DISABLE_WP_CRON'] );
		$this->assertStringContainsString( 'perf.cron.overdue', $finding->evidence()['system_cron_job'] );
		$this->assertStringContainsString( 'wp --path=<site root> cron event run --due-now', $finding->fix() );
	}

	public function test_wp_cron_left_on_passes(): void {
		$this->assertTrue( $this->run_with( "define( 'DISABLE_WP_CRON', false );" )->is_pass() );
		$this->assertTrue( $this->run_with( '' )->is_pass() );
	}

	public function test_a_computed_value_is_a_skip(): void {
		$result = $this->run_with( "define( 'DISABLE_WP_CRON', getenv( 'X' ) );" );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'DISABLE_WP_CRON', (string) $result->message() );
	}

	public function test_an_unset_value_beside_code_that_was_not_read_is_a_skip(): void {
		$result = $this->run_with( "require __DIR__ . '/wp-config-extra.php';" );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'not read', (string) $result->message() );
	}
}
