<?php
/**
 * Tests for Redacted_Report and the formatter contract.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Report;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Report\Formatter\Formatter;
use WP_Doctor\Report\Redacted_Report;
use WP_Doctor\Report\Redactor;
use WP_Doctor\Report\Report;
use WP_Doctor\Report\Report_Entry;
use WP_Doctor\Report\Result;

final class Redacted_Report_Test extends TestCase {

	public function test_constructing_one_always_redacts(): void {
		$report = new Report( '1', null, array( new Report_Entry( 'a.b', 'disk', Result::skipped( 'reason fake-secret-0123' ) ) ), array( 'note fake-secret-0123' ) );

		$redacted = new Redacted_Report( $report, new Redactor( array( 'fake-secret-0123' ) ) );

		$this->assertStringNotContainsString( 'fake-secret-0123', serialize( $redacted->report() ) );
		$this->assertStringContainsString( '[redacted]', serialize( $redacted->report() ) );
		$this->assertStringContainsString( 'fake-secret-0123', serialize( $report ), 'The original report was altered' );
	}

	public function test_every_formatter_accepts_only_a_redacted_report(): void {
		$files = glob( dirname( __DIR__, 2 ) . '/src/Report/Formatter/class-*-formatter.php' );
		$this->assertNotEmpty( $files, 'No formatters were found.' );

		foreach ( (array) $files as $file ) {
			$name  = 'WP_Doctor\\Report\\Formatter\\' . str_replace( ' ', '_', ucwords( str_replace( '-', ' ', substr( basename( $file, '.php' ), 6 ) ) ) );
			$class = new \ReflectionClass( $name );

			$this->assertTrue( $class->implementsInterface( Formatter::class ), $name );
			$parameter = $class->getMethod( 'format' )->getParameters()[0];
			$type      = $parameter->getType();
			$this->assertNotNull( $type, $name );
			$this->assertSame( Redacted_Report::class, ltrim( (string) $type, '?\\' ), $name );
		}
	}
}
