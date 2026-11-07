<?php
/**
 * Tests for Check_Registry.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Check;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Check_Registry;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Stub_Check;

final class Check_Registry_Test extends TestCase {

	private function stub( string $id ): Stub_Check {
		return new Stub_Check(
			$id,
			static function () {
				return Result::pass();
			}
		);
	}

	public function test_checks_are_returned_ordered_by_id(): void {
		$registry = new Check_Registry( $this->stub( 'sec.b' ), $this->stub( 'perf.z' ), $this->stub( 'perf.a' ) );

		$ids = array_map(
			static function ( $check ) {
				return $check->id();
			},
			$registry->all()
		);

		$this->assertSame( array( 'perf.a', 'perf.z', 'sec.b' ), $ids );
	}

	public function test_two_checks_with_the_same_id_are_rejected(): void {
		$this->expectException( \InvalidArgumentException::class );
		new Check_Registry( $this->stub( 'perf.a' ), $this->stub( 'perf.a' ) );
	}

	public function test_every_built_in_check_has_a_well_formed_unique_id(): void {
		$ids    = array();
		$checks = Check_Registry::built_in()->all();
		$this->assertNotEmpty( $checks, 'No checks are registered' );
		foreach ( $checks as $check ) {
			$this->assertSame( 1, preg_match( '/^[a-z]+(\.[a-z]+){1,3}$/', $check->id() ), $check->id() );
			$ids[] = $check->id();
		}

		$this->assertSame( $ids, array_values( array_unique( $ids ) ) );
	}
}
