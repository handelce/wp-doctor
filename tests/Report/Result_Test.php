<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Report;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

final class Result_Test extends TestCase {

	private function finding( string $id = 'a.b' ): Finding {
		return new Finding( $id, Severity::info(), 'T', array( 'k' => 'v' ), 'F' );
	}

	public function test_a_pass_has_no_findings_and_no_message(): void {
		$result = Result::pass();

		$this->assertTrue( $result->is_pass() );
		$this->assertSame( Result::PASS, $result->status() );
		$this->assertSame( array(), $result->finding_list() );
		$this->assertNull( $result->message() );
	}

	public function test_findings_carry_every_finding_in_order(): void {
		$result = Result::findings( $this->finding( 'a.one' ), $this->finding( 'a.two' ) );

		$this->assertSame( Result::FINDINGS, $result->status() );
		$this->assertSame(
			array( 'a.one', 'a.two' ),
			array_map(
				function ( Finding $f ) {
					return $f->id();
				},
				$result->finding_list()
			)
		);
		$this->assertNull( $result->message() );
	}

	public function test_findings_without_any_finding_is_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		Result::findings();
	}

	public function test_skipped_carries_its_reason(): void {
		$result = Result::skipped( 'database credentials are unresolved: DB_PASSWORD' );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertSame( 'database credentials are unresolved: DB_PASSWORD', $result->message() );
		$this->assertSame( array(), $result->finding_list() );
	}

	public function test_error_carries_its_message(): void {
		$result = Result::error( 'RuntimeException: connection refused' );

		$this->assertSame( Result::ERROR, $result->status() );
		$this->assertSame( 'RuntimeException: connection refused', $result->message() );
		$this->assertFalse( $result->is_pass() );
	}

	public function test_skipped_and_error_require_a_reason(): void {
		foreach ( array( 'skipped', 'error' ) as $factory ) {
			try {
				Result::$factory( ' ' );
				$this->fail( "Result::$factory accepted a blank reason" );
			} catch ( \InvalidArgumentException $e ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}

	public function test_there_are_exactly_four_statuses(): void {
		$statuses = array(
			Result::pass()->status(),
			Result::findings( $this->finding() )->status(),
			Result::skipped( 'r' )->status(),
			Result::error( 'e' )->status(),
		);

		$this->assertCount( 4, array_unique( $statuses ) );
	}

	public function test_a_result_cannot_be_constructed_directly(): void {
		$constructor = ( new \ReflectionClass( Result::class ) )->getConstructor();

		$this->assertNotNull( $constructor );
		$this->assertTrue( $constructor->isPrivate() );
	}

	public function test_the_highest_severity_is_the_highest_ranked_finding_not_the_first(): void {
		$result = Result::findings(
			$this->finding(),
			new Finding( 'a.c', Severity::critical(), 'T', array( 'k' => 'v' ), 'F' ),
			new Finding( 'a.d', Severity::warning(), 'T', array( 'k' => 'v' ), 'F' )
		);

		$highest = $result->highest_severity();
		$this->assertNotNull( $highest );
		$this->assertSame( 'critical', $highest->name() );
	}

	public function test_a_result_without_findings_has_no_highest_severity(): void {
		$this->assertNull( Result::pass()->highest_severity() );
		$this->assertNull( Result::skipped( 'no database' )->highest_severity() );
	}
}
