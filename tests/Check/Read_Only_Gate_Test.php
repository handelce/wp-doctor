<?php
/**
 * Every check stays inside the read-only gate.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Check;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Check;
use WP_Doctor\Check\Check_Registry;
use WP_Doctor\Check\Context;
use WP_Doctor\Db\Read_Only_Connection;
use WP_Doctor\Db\Read_Only_Violation_Exception;
use WP_Doctor\Fs\File_Scanner;
use WP_Doctor\Report\Result;
use WP_Doctor\Site\Config_Parser;
use WP_Doctor\Site\Site_Locator;
use WP_Doctor\Tests\Support\Recording_Driver;
use WP_Doctor\Tests\Support\Stub_Check;

final class Read_Only_Gate_Test extends TestCase {

	private const SESSION_SETUP = 'SET SESSION TRANSACTION READ ONLY';

	/**
	 * Runs checks against a recording connection and returns what was sent.
	 *
	 * A rejected statement propagates and fails the test. Any other failure is
	 * ignored: the recording driver returns no rows, so a check may refuse the
	 * result after it has sent its statements.
	 *
	 * @param Check[] $checks Checks to run.
	 * @return string[] Statements received by the driver, session setup first.
	 */
	private function statements_sent_by( array $checks ): array {
		$driver  = new Recording_Driver();
		$site    = ( new Site_Locator( new Config_Parser() ) )->locate( dirname( __DIR__ ) . '/fixtures/sites/plain' );
		$context = Context::with_database( $site, new File_Scanner( $site->root() ), new Read_Only_Connection( $driver ) );

		foreach ( $checks as $check ) {
			try {
				$check->run( $context );
			} catch ( Read_Only_Violation_Exception $e ) {
				throw $e;
			} catch ( \Throwable $e ) {
				continue;
			}
		}

		return $driver->statements;
	}

	/**
	 * Whether a statement starts with an allow-listed verb.
	 *
	 * @param string $sql Statement the driver received.
	 * @return bool
	 */
	private function is_read_statement( string $sql ): bool {
		return 1 === preg_match( '/^\s*(SELECT|SHOW|EXPLAIN|DESCRIBE|DESC)\b/i', $sql );
	}

	public function test_a_well_behaved_check_sends_only_read_statements_after_the_session_setup(): void {
		$check = new Stub_Check(
			'test.reads',
			static function ( Context $context ) {
				$context->database()->query( 'SELECT 1' );
				$context->database()->query( 'SHOW TABLES' );
				return Result::pass();
			}
		);

		$statements = $this->statements_sent_by( array( $check ) );

		$this->assertSame( self::SESSION_SETUP, $statements[0] );
		$this->assertCount( 3, $statements );
		foreach ( array_slice( $statements, 1 ) as $sql ) {
			$this->assertTrue( $this->is_read_statement( $sql ), $sql );
		}
	}

	/**
	 * @dataProvider writing_statements
	 * @param string $sql Statement a rogue check tries to send.
	 */
	public function test_a_check_that_writes_is_stopped_before_the_driver( string $sql ): void {
		$check = new Stub_Check(
			'test.writes',
			static function ( Context $context ) use ( $sql ) {
				$context->database()->query( $sql );
				return Result::pass();
			}
		);

		$this->expectException( Read_Only_Violation_Exception::class );
		$this->statements_sent_by( array( $check ) );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function writing_statements(): array {
		return array(
			'insert'     => array( "INSERT INTO wp_options (option_name) VALUES ('x')" ),
			'delete'     => array( 'DELETE FROM wp_options' ),
			'drop'       => array( 'DROP TABLE wp_options' ),
			'write mode' => array( 'SET SESSION TRANSACTION READ WRITE' ),
			'stacked'    => array( 'SELECT 1; DELETE FROM wp_options' ),
		);
	}

	public function test_the_writing_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->writing_statements() );
	}

	public function test_every_registered_check_stays_inside_the_gate(): void {
		$checks = Check_Registry::built_in()->all();
		$this->assertNotEmpty( $checks, 'No checks are registered, so nothing was tested' );

		$statements = $this->statements_sent_by( $checks );

		$this->assertGreaterThan( 1, count( $statements ), 'No check sent a statement, so the gate was not exercised.' );
		$this->assertSame( self::SESSION_SETUP, $statements[0] );
		foreach ( array_slice( $statements, 1 ) as $sql ) {
			$this->assertTrue( $this->is_read_statement( $sql ), $sql );
		}
	}
}
