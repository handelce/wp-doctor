<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Check_Registry;
use WP_Doctor\Check\Thresholds;

final class Thresholds_Test extends TestCase {

	public function test_the_defaults_answer_with_the_built_in_limit_and_say_so(): void {
		$thresholds = Thresholds::defaults();

		$this->assertSame( 1048576, $thresholds->warning( 'perf.autoload.size' ) );
		$this->assertSame( 50, $thresholds->critical( 'perf.cron.duplicates' ) );
		$this->assertStringContainsString( 'default', $thresholds->basis( 'perf.autoload.size' ) );
		$this->assertSame( array(), $thresholds->configured_ids() );
	}

	public function test_configured_limits_replace_the_defaults_for_their_check_only(): void {
		$thresholds = Thresholds::from_config(
			array(
				'perf.autoload.size'   => array(
					'warning'  => 10,
					'critical' => 20,
				),
				'perf.cron.duplicates' => array( 'critical' => 5 ),
			),
			'custom.json'
		);

		$this->assertSame( 10, $thresholds->warning( 'perf.autoload.size' ) );
		$this->assertSame( 20, $thresholds->critical( 'perf.autoload.size' ) );
		$this->assertSame( 5, $thresholds->critical( 'perf.cron.duplicates' ) );
		$this->assertSame( 5000, $thresholds->warning( 'perf.transients.expired' ) );
		$this->assertSame( 'set in custom.json', $thresholds->basis( 'perf.autoload.size' ) );
		$this->assertStringContainsString( 'default', $thresholds->basis( 'perf.transients.expired' ) );
		$this->assertSame( array( 'perf.autoload.size', 'perf.cron.duplicates' ), $thresholds->configured_ids() );
	}

	public function test_an_info_level_is_read_with_its_default_and_from_the_file(): void {
		$this->assertSame( 7, Thresholds::defaults()->info( 'sec.recent.changes' ) );

		$thresholds = Thresholds::from_config( array( 'sec.recent.changes' => array( 'info' => 30 ) ), 'custom.json' );

		$this->assertSame( 30, $thresholds->info( 'sec.recent.changes' ) );
		$this->assertSame( 'set in custom.json', $thresholds->basis( 'sec.recent.changes' ) );
	}

	public function test_an_info_level_is_refused_for_a_check_without_one(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'has no info threshold' );
		Thresholds::from_config( array( 'perf.autoload.size' => array( 'info' => 1 ) ), 'custom.json' );
	}

	public function test_a_check_without_configurable_thresholds_is_refused_naming_the_configurable_ones(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'no configurable thresholds' );
		Thresholds::from_config( array( 'config.debug.enabled' => array( 'warning' => 1 ) ), 'custom.json' );
	}

	public function test_a_level_the_check_does_not_have_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'has no warning threshold' );
		Thresholds::from_config( array( 'perf.cron.duplicates' => array( 'warning' => 1 ) ), 'custom.json' );
	}

	public function test_every_configurable_id_is_a_registered_check_with_a_unit_per_level(): void {
		$registered = Check_Registry::built_in()->ids();

		$configurable = Thresholds::configurable();
		$this->assertNotEmpty( $configurable );
		foreach ( $configurable as $id => $levels ) {
			$this->assertContains( $id, $registered, "$id is configurable but not registered" );
			$this->assertNotEmpty( $levels );
			foreach ( $levels as $level => $unit ) {
				$this->assertContains( $level, array( Thresholds::INFO, Thresholds::WARNING, Thresholds::CRITICAL ) );
				$this->assertNotSame( '', $unit );
			}
		}
	}

	public function test_a_lone_warning_at_or_above_the_built_in_critical_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'warning threshold for "perf.autoload.size" (5242880) must be below its critical threshold (3145728, the built-in default)' );
		Thresholds::from_config( array( 'perf.autoload.size' => array( 'warning' => 5242880 ) ), 'custom.json' );
	}

	public function test_a_lone_critical_at_or_below_the_built_in_warning_is_refused(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( '(1048576, the built-in default) must be below its critical threshold (1000' );
		Thresholds::from_config( array( 'perf.autoload.size' => array( 'critical' => 1000 ) ), 'custom.json' );
	}

	public function test_a_lone_level_says_which_level_the_file_set(): void {
		$thresholds = Thresholds::from_config( array( 'perf.autoload.size' => array( 'warning' => 2097152 ) ), 'custom.json' );

		$this->assertSame( 2097152, $thresholds->warning( 'perf.autoload.size' ) );
		$this->assertSame( 3145728, $thresholds->critical( 'perf.autoload.size' ) );
		$this->assertSame( 'warning set in custom.json; critical is the default rule of thumb, not measured for this site', $thresholds->basis( 'perf.autoload.size' ) );
	}

	public function test_asking_for_a_level_a_check_does_not_have_is_a_bug(): void {
		$this->expectException( \LogicException::class );
		Thresholds::defaults()->warning( 'perf.cron.duplicates' );
	}

	public function test_every_configurable_level_has_a_positive_built_in_limit_with_warning_below_critical(): void {
		$configurable = Thresholds::configurable();
		$this->assertNotEmpty( $configurable );
		foreach ( $configurable as $id => $levels ) {
			if ( isset( $levels[ Thresholds::WARNING ], $levels[ Thresholds::CRITICAL ] ) ) {
				$this->assertLessThan( Thresholds::defaults()->critical( $id ), Thresholds::defaults()->warning( $id ), $id );
			}
			foreach ( array_keys( $levels ) as $level ) {
				$limit = Thresholds::defaults()->{$level}( $id );
				$this->assertGreaterThan( 0, $limit, "$id $level" );
			}
		}
	}
}
