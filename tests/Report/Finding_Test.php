<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Report;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Severity;

final class Finding_Test extends TestCase {

	public function test_a_finding_exposes_every_field_it_was_built_with(): void {
		$finding = new Finding(
			'perf.autoload.size',
			Severity::warning(),
			'Autoloaded options are large',
			array(
				'autoload_bytes' => 2097152,
				'rows'           => 412,
			),
			'Delete unused options: wp option delete <name>'
		);

		$this->assertSame( 'perf.autoload.size', $finding->id() );
		$this->assertTrue( $finding->severity()->equals( Severity::warning() ) );
		$this->assertSame( 'Autoloaded options are large', $finding->title() );
		$this->assertSame(
			array(
				'autoload_bytes' => 2097152,
				'rows'           => 412,
			),
			$finding->evidence()
		);
		$this->assertSame( 'Delete unused options: wp option delete <name>', $finding->fix() );
	}

	public function test_evidence_keeps_the_order_it_was_given_in(): void {
		$finding = new Finding(
			'a.b',
			Severity::info(),
			'T',
			array(
				'z' => 1,
				'a' => 2,
				'm' => 3,
			),
			'F'
		);

		$this->assertSame( array( 'z', 'a', 'm' ), array_keys( $finding->evidence() ) );
	}

	/**
	 * @dataProvider incomplete_constructions
	 */
	public function test_a_finding_missing_a_required_field_cannot_be_built( callable $build ): void {
		$this->expectException( \Throwable::class );
		$build();
	}

	/**
	 * @return array<string, array<int, callable>>
	 */
	public function incomplete_constructions(): array {
		$sev = Severity::info();

		return array(
			'no arguments'                 => array(
				function () {
						return new Finding();
				},
			),
			'no severity'                  => array(
				function () {
								return new Finding( 'a.b' );
				},
			),
			'no title'                     => array(
				function () use ( $sev ) {
								return new Finding( 'a.b', $sev );
				},
			),
			'no evidence'                  => array(
				function () use ( $sev ) {
								return new Finding( 'a.b', $sev, 'T' );
				},
			),
			'no fix'                       => array(
				function () use ( $sev ) {
								return new Finding( 'a.b', $sev, 'T', array( 'k' => 'v' ) );
				},
			),
			'empty id'                     => array(
				function () use ( $sev ) {
								return new Finding( '', $sev, 'T', array( 'k' => 'v' ), 'F' );
				},
			),
			'blank title'                  => array(
				function () use ( $sev ) {
								return new Finding( 'a.b', $sev, '  ', array( 'k' => 'v' ), 'F' );
				},
			),
			'empty evidence'               => array(
				function () use ( $sev ) {
								return new Finding( 'a.b', $sev, 'T', array(), 'F' );
				},
			),
			'empty fix'                    => array(
				function () use ( $sev ) {
								return new Finding( 'a.b', $sev, 'T', array( 'k' => 'v' ), '' );
				},
			),
			'evidence with a numeric key'  => array(
				function () use ( $sev ) {
								return new Finding( 'a.b', $sev, 'T', array( 'v' ), 'F' );
				},
			),
			'evidence with an array value' => array(
				function () use ( $sev ) {
								return new Finding( 'a.b', $sev, 'T', array( 'k' => array( 'nested' ) ), 'F' );
				},
			),
		);
	}

	public function test_the_incomplete_constructions_are_not_an_empty_list(): void {
		$this->assertNotEmpty( $this->incomplete_constructions() );
	}
}
