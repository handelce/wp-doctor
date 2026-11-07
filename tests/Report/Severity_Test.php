<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Report;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Report\Severity;

final class Severity_Test extends TestCase {

	public function test_severities_rank_critical_above_warning_above_info(): void {
		$this->assertGreaterThan( Severity::warning()->rank(), Severity::critical()->rank() );
		$this->assertGreaterThan( Severity::info()->rank(), Severity::warning()->rank() );
	}

	public function test_a_severity_round_trips_through_its_name(): void {
		foreach ( array( 'critical', 'warning', 'info' ) as $name ) {
			$this->assertSame( $name, Severity::from_name( $name )->name() );
		}
	}

	public function test_an_unknown_severity_name_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		Severity::from_name( 'severe' );
	}

	public function test_severities_with_the_same_name_are_equal(): void {
		$this->assertTrue( Severity::info()->equals( Severity::from_name( 'info' ) ) );
		$this->assertFalse( Severity::info()->equals( Severity::warning() ) );
	}
}
