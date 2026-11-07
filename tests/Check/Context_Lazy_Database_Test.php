<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Context;
use WP_Doctor\Db\Read_Only_Connection;
use WP_Doctor\Fs\File_Scanner;
use WP_Doctor\Site\Config_Parser;
use WP_Doctor\Site\Site_Locator;
use WP_Doctor\Tests\Support\Recording_Driver;

final class Context_Lazy_Database_Test extends TestCase {

	/**
	 * A lazy context over the plain fixture whose opener counts its calls and returns the given result.
	 *
	 * @param array{0: Read_Only_Connection|null, 1: string|null}|mixed $result What the opener returns.
	 * @param int                                                    $calls  Call counter, by reference.
	 * @return Context
	 */
	private function lazy( $result, int &$calls ): Context {
		$site = ( new Site_Locator( new Config_Parser() ) )->locate( dirname( __DIR__ ) . '/fixtures/sites/plain' );

		return Context::with_lazy_database(
			$site,
			new File_Scanner( $site->root() ),
			static function () use ( $result, &$calls ) {
				++$calls;
				return $result;
			}
		);
	}

	public function test_the_database_is_not_opened_until_a_check_asks(): void {
		$calls   = 0;
		$context = $this->lazy( array( new Read_Only_Connection( new Recording_Driver() ), null ), $calls );

		$context->files();
		$context->config();
		$context->thresholds();
		$this->assertSame( 0, $calls );

		$this->assertNull( $context->database_unavailable() );
		$context->database()->query( 'SELECT 1' );
		$this->assertNull( $context->site_tables_unavailable() );
		$this->assertSame( 1, $calls, 'The opener ran more than once' );
	}

	public function test_a_failed_open_is_remembered_as_the_skip_reason_and_not_retried(): void {
		$calls   = 0;
		$context = $this->lazy( array( null, 'database connection failed: refused' ), $calls );

		$this->assertSame( 'database connection failed: refused', $context->database_unavailable() );
		$this->assertSame( 'database connection failed: refused', $context->site_tables_unavailable() );
		$this->assertSame( 1, $calls );
		$this->expectException( \LogicException::class );
		$context->database();
	}

	public function test_copies_made_before_the_first_use_share_nothing_but_open_on_their_own(): void {
		$calls   = 0;
		$context = $this->lazy( array( new Read_Only_Connection( new Recording_Driver() ), null ), $calls );

		$copy = $context->with_checksums( null, 'offline' );

		$this->assertNull( $copy->database_unavailable() );
		$this->assertSame( 1, $calls );
	}

	public function test_an_opener_that_returns_neither_a_connection_nor_a_reason_is_an_error(): void {
		$calls   = 0;
		$context = $this->lazy( array( null, null ), $calls );

		$this->expectException( \UnexpectedValueException::class );
		$context->database_unavailable();
	}

	public function test_an_opener_that_throws_leaves_a_true_reason_for_later_checks(): void {
		$site    = ( new Site_Locator( new Config_Parser() ) )->locate( dirname( __DIR__ ) . '/fixtures/sites/plain' );
		$context = Context::with_lazy_database(
			$site,
			new File_Scanner( $site->root() ),
			static function () {
				throw new \RuntimeException( "driver missing\x1b[2J" );
			}
		);

		try {
			$context->database_unavailable();
			$this->fail( 'The opener\'s exception was swallowed' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'driver missing', $e->getMessage() );
		}

		$this->assertSame( "the database could not be opened: driver missing\x1b[2J", $context->database_unavailable() );
		$this->assertSame( "the database could not be opened: driver missing\x1b[2J", $context->site_tables_unavailable() );
	}

	public function test_a_malformed_opener_result_leaves_a_true_reason_for_later_checks(): void {
		foreach ( array( 'not an array', array( null, null ), array( null, '  ' ) ) as $malformed ) {
			$calls   = 0;
			$context = $this->lazy( $malformed, $calls );

			try {
				$context->database_unavailable();
				$this->fail( 'A malformed opener result was accepted' );
			} catch ( \UnexpectedValueException $e ) {
				$this->assertStringContainsString( 'connection or a reason', $e->getMessage() );
			}

			$reason = $context->database_unavailable();
			$this->assertNotNull( $reason );
			$this->assertStringStartsWith( 'the database could not be opened: ', $reason );
			$this->assertSame( 1, $calls );
		}
	}
}
