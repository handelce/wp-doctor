<?php
/**
 * Tests for Runner.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Check;

use WP_Doctor\Site\Config;
use WP_Doctor\Check\Runs_Without_Config;
use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Check_Registry;
use WP_Doctor\Check\Context;
use WP_Doctor\Check\Runner;
use WP_Doctor\Db\Read_Only_Connection;
use WP_Doctor\Fs\File_Scanner;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Formatter\Markdown_Formatter;
use WP_Doctor\Report\Formatter\Text_Formatter;
use WP_Doctor\Report\Redacted_Report;
use WP_Doctor\Report\Redactor;
use WP_Doctor\Report\Report;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;
use WP_Doctor\Site\Config_Parser;
use WP_Doctor\Site\Site_Locator;
use WP_Doctor\Tests\Support\Recording_Driver;
use WP_Doctor\Tests\Support\Stub_Check;

final class Runner_Test extends TestCase {

	private function context(): Context {
		$site = ( new Site_Locator( new Config_Parser() ) )->locate( dirname( __DIR__ ) . '/fixtures/sites/plain' );

		return Context::with_database( $site, new File_Scanner( $site->root() ), new Read_Only_Connection( new Recording_Driver() ) );
	}

	/**
	 * Result keyed by check id.
	 *
	 * @param Check_Registry $registry Checks to run.
	 * @return array<string, Result>
	 */
	private function results( Check_Registry $registry ): array {
		$results = array();
		foreach ( ( new Runner() )->run( $registry, $this->context() ) as $entry ) {
			$results[ $entry->check_id() ] = $entry->result();
		}

		return $results;
	}

	public function test_every_check_gets_an_entry_in_id_order(): void {
		$pass     = static function () {
			return Result::pass();
		};
		$registry = new Check_Registry( new Stub_Check( 'z.last', $pass ), new Stub_Check( 'a.first', $pass ) );

		$entries = ( new Runner() )->run( $registry, $this->context() );

		$this->assertSame(
			array( 'a.first', 'z.last' ),
			array_map(
				static function ( $e ) {
					return $e->check_id();
				},
				$entries
			)
		);
		$this->assertSame( 'performance', $entries[0]->category() );
	}

	public function test_results_are_passed_through_unchanged(): void {
		$finding  = new Finding( 'a.finding', Severity::warning(), 'T', array( 'k' => 'v' ), 'F' );
		$registry = new Check_Registry(
			new Stub_Check(
				'a.pass',
				static function () {
					return Result::pass();
				}
			),
			new Stub_Check(
				'a.finding',
				static function () use ( $finding ) {
					return Result::findings( $finding );
				}
			),
			new Stub_Check(
				'a.skipped',
				static function () {
					return Result::skipped( 'no database' );
				}
			)
		);

		$results = $this->results( $registry );

		$this->assertSame( Result::PASS, $results['a.pass']->status() );
		$this->assertSame( $finding, $results['a.finding']->finding_list()[0] );
		$this->assertSame( 'no database', $results['a.skipped']->message() );
	}

	public function test_a_check_that_throws_is_recorded_as_an_error_and_the_others_still_run(): void {
		$registry = new Check_Registry(
			new Stub_Check(
				'a.boom',
				static function () {
					throw new \RuntimeException( 'disk on fire' );
				}
			),
			new Stub_Check(
				'b.fine',
				static function () {
					return Result::pass();
				}
			)
		);

		$results = $this->results( $registry );

		$this->assertSame( Result::ERROR, $results['a.boom']->status() );
		$this->assertStringContainsString( 'RuntimeException', (string) $results['a.boom']->message() );
		$this->assertStringContainsString( 'disk on fire', (string) $results['a.boom']->message() );
		$this->assertSame( Result::PASS, $results['b.fine']->status() );
	}

	public function test_a_php_error_in_a_check_is_recorded_as_an_error(): void {
		$registry = new Check_Registry(
			new Stub_Check(
				'a.typeerror',
				static function () {
					return strlen( array() );
				}
			)
		);

		$this->assertSame( Result::ERROR, $this->results( $registry )['a.typeerror']->status() );
	}

	public function test_a_statement_refused_by_the_gate_is_recorded_as_a_wp_doctor_bug(): void {
		$registry = new Check_Registry(
			new Stub_Check(
				'a.writer',
				static function ( Context $context ) {
					$context->database()->query( 'DELETE FROM wp_options' );
					return Result::pass();
				}
			)
		);

		$result = $this->results( $registry )['a.writer'];

		$this->assertSame( Result::ERROR, $result->status() );
		$this->assertStringContainsString( 'Read-only violation', (string) $result->message() );
		$this->assertStringContainsString( 'bug in wp-doctor', (string) $result->message() );
		$this->assertStringContainsString( 'nothing was written', (string) $result->message() );
		$this->assertStringContainsString( 'DELETE', (string) $result->message() );
		$this->assertStringNotContainsString( 'Read_Only_Violation_Exception', (string) $result->message() );
	}

	public function test_a_secret_with_a_control_byte_in_an_exception_message_is_redacted_and_the_output_escaped(): void {
		$secret   = "pass\x1bword-0042";
		$registry = new Check_Registry(
			new Stub_Check(
				'a.boom',
				static function () use ( $secret ) {
					throw new \RuntimeException( "login failed for $secret on file \033[2J.php" ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The raw message is what the test feeds the Runner.
				}
			)
		);
		$entries  = ( new Runner() )->run( $registry, $this->context() );
		$this->assertStringContainsString( $secret, (string) $entries[0]->result()->message(), 'The secret did not reach the error message' );

		$report = new Redacted_Report( new Report( '0.1.0', null, $entries, array() ), new Redactor( array( $secret ) ) );
		foreach ( array( new Text_Formatter(), new Markdown_Formatter() ) as $formatter ) {
			$output = str_replace( '\\', '', $formatter->format( $report ) );

			$this->assertStringContainsString( '[redacted]', $output );
			$this->assertStringNotContainsString( 'word-0042', $output );
			$this->assertStringNotContainsString( "\033", $output );
			$this->assertStringContainsString( 'x1B[2J.php', $output );
		}
	}

	/**
	 * A context over the plain fixture whose wp-config.php is treated as unreadable.
	 *
	 * @return Context
	 */
	private function unreadable_config_context(): Context {
		$located = ( new Site_Locator( new Config_Parser() ) )->locate( dirname( __DIR__ ) . '/fixtures/sites/plain' );
		$site    = $located->with_config( Config::unreadable( 'wp-config.php could not be read in this test' ) );

		return Context::without_database( $site, new File_Scanner( $site->root() ), 'no database in this test' );
	}

	public function test_with_an_unreadable_config_only_checks_that_run_without_it_are_run(): void {
		$ran      = array();
		$plain    = new Stub_Check(
			'a.needs.config',
			static function () use ( &$ran ): Result {
				$ran[] = 'a.needs.config';
				return Result::pass();
			}
		);
		$marked   = new class( $ran ) implements Runs_Without_Config {
			/**
			 * Ids of checks that ran, shared with the test.
			 *
			 * @var string[]
			 */
			private $ran;

			/**
			 * @param string[] $ran Ids of checks that ran.
			 */
			public function __construct( array &$ran ) {
				$this->ran = &$ran;
			}

			public function id(): string {
				return 'b.no.config';
			}

			public function category(): string {
				return 'config';
			}

			public function run( Context $context ): Result {
				$this->ran[] = $this->id();
				return Result::pass();
			}
		};
		$registry = new Check_Registry( $plain, $marked );

		$results = array();
		foreach ( ( new Runner() )->run( $registry, $this->unreadable_config_context() ) as $entry ) {
			$results[ $entry->check_id() ] = $entry->result();
		}

		$this->assertSame( array( 'b.no.config' ), $ran );
		$this->assertSame( Result::SKIPPED, $results['a.needs.config']->status() );
		$this->assertSame( 'wp-config.php could not be read in this test', $results['a.needs.config']->message() );
		$this->assertSame( Result::PASS, $results['b.no.config']->status() );
	}

	public function test_every_built_in_check_that_runs_without_a_config_completes_without_one(): void {
		$context = $this->unreadable_config_context();
		$marked  = array();
		foreach ( Check_Registry::built_in()->all() as $check ) {
			if ( ! $check instanceof Runs_Without_Config ) {
				continue;
			}
			$marked[] = $check->id();
			$result   = ( new Runner() )->run( new Check_Registry( $check ), $context )[0]->result();
			$this->assertNotSame( Result::ERROR, $result->status(), $check->id() . ' failed without a config: ' . (string) $result->message() );
		}

		$this->assertGreaterThanOrEqual( 6, count( $marked ), 'Fewer checks than expected run without a config' );
	}
}
