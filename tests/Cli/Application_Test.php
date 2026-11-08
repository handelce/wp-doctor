<?php
/**
 * Tests for Application.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Cli;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Application;
use WP_Doctor\Check\Check_Registry;
use WP_Doctor\Check\Context;
use WP_Doctor\Db\Database_Driver;
use WP_Doctor\Db\Database_Exception;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;
use WP_Doctor\Tests\Support\Buffer_Console;
use WP_Doctor\Tests\Support\Fake_Checksum_Source;
use WP_Doctor\Tests\Support\File_Modes;
use WP_Doctor\Tests\Support\Recording_Driver;
use WP_Doctor\Tests\Support\Stub_Check;
use WP_Doctor\Tests\Support\Temp_Tree;

final class Application_Test extends TestCase {

	/**
	 * Arguments the connector was called with, one entry per call.
	 *
	 * @var array<int, array<int, string>>
	 */
	private $connections = array();

	/**
	 * Driver handed to every connection.
	 *
	 * @var Recording_Driver
	 */
	private $driver;

	/**
	 * Temp tree for config files.
	 *
	 * @var Temp_Tree|null
	 */
	private $tree;

	/**
	 * Checksum source handed to the application.
	 *
	 * @var Fake_Checksum_Source
	 */
	private $checksums;

	protected function setUp(): void {
		$this->checksums   = new Fake_Checksum_Source();
		$this->connections = array();
		$this->driver      = new Recording_Driver();
		$this->tree        = null;
	}

	protected function tearDown(): void {
		if ( null !== $this->tree ) {
			$this->tree->remove();
		}
	}

	private function fixture( string $name ): string {
		return dirname( __DIR__ ) . '/fixtures/sites/' . $name;
	}

	/**
	 * Runs the application.
	 *
	 * @param string[]            $args     Arguments after the script name.
	 * @param Check_Registry|null $registry Checks to run.
	 * @param array<string, string> $env    Environment variables by name.
	 * @param bool                $tty      Whether stdout is a terminal.
	 * @param callable|null       $connect  Database connector.
	 * @return array{0: int, 1: string, 2: string} Exit code, stdout, stderr.
	 */
	private function run_app( array $args, ?Check_Registry $registry = null, array $env = array(), bool $tty = false, ?callable $connect = null ): array {
		$console      = new Buffer_Console();
		$console->tty = $tty;
		$connect      = $connect ?? function ( string $host, string $user, string $password, string $name ): Database_Driver {
			$this->connections[] = array( $host, $user, $password, $name );
			return $this->driver;
		};

		$app  = new Application( $registry ?? new Check_Registry(), $console, $env, $connect, $this->checksums );
		$code = $app->run( $args );

		return array( $code, $console->out, $console->err );
	}

	/**
	 * A registry with one check that asks whether the database is available, which opens it.
	 *
	 * @return Check_Registry
	 */
	private function database_user(): Check_Registry {
		return $this->registry_of(
			static function ( Context $context ) {
				$context->database_unavailable();
				return Result::pass();
			}
		);
	}

	/**
	 * A registry with one check that returns the given result builder's value.
	 *
	 * @param callable $body Receives the Context, returns a Result.
	 * @return Check_Registry
	 */
	private function registry_of( callable $body ): Check_Registry {
		return new Check_Registry( new Stub_Check( 'test.check', $body ) );
	}

	private function finding( string $title, string $severity = 'warning', string $fix = 'Fix it.' ): Finding {
		return new Finding( 'test.check', Severity::from_name( $severity ), $title, array( 'k' => 'v' ), $fix );
	}

	public function test_version_prints_the_version_and_exits_zero(): void {
		list( $code, $out, $err ) = $this->run_app( array( '--version' ) );

		$this->assertSame( 0, $code );
		$this->assertSame( 'wp-doctor ' . Application::VERSION . "\n", $out );
		$this->assertSame( '', $err );
	}

	public function test_help_prints_usage_to_stdout_and_exits_zero(): void {
		list( $code, $out ) = $this->run_app( array( '--help' ) );

		$this->assertSame( 0, $code );
		foreach ( array( 'wp-doctor scan <path>', '--format', '--offline', '--config', '--db-from-env', '--max-files', '--exclude', '--redact-emails', '--no-color', 'Exit codes', 'administer' ) as $needle ) {
			$this->assertStringContainsString( $needle, $out );
		}
	}

	public function test_a_usage_error_goes_to_stderr_with_exit_three_and_nothing_on_stdout(): void {
		list( $code, $out, $err ) = $this->run_app( array( 'scan', 'site', '--bogus' ) );

		$this->assertSame( 3, $code );
		$this->assertSame( '', $out );
		$this->assertStringContainsString( '--bogus', $err );
		$this->assertStringContainsString( '--help', $err );
	}

	public function test_a_path_that_is_not_a_site_exits_three_with_the_reason(): void {
		list( $code, $out, $err ) = $this->run_app( array( 'scan', $this->fixture( 'not-wordpress' ) ) );

		$this->assertSame( 3, $code );
		$this->assertSame( '', $out );
		$this->assertStringContainsString( 'wp-includes/version.php', $err );
	}

	public function test_scanning_a_site_with_no_checks_prints_a_clean_text_report(): void {
		list( $code, $out, $err ) = $this->run_app( array( 'scan', $this->fixture( 'plain' ) ) );

		$this->assertSame( 0, $code );
		$this->assertSame( '', $err );
		$this->assertStringContainsString( 'wp-doctor ' . Application::VERSION . ', WordPress 6.5.2', $out );
		$this->assertStringContainsString( 'No findings.', $out );
	}

	public function test_json_output_is_only_the_json_document(): void {
		list( $code, $out, $err ) = $this->run_app( array( 'scan', $this->fixture( 'plain' ), '--format=json' ) );

		$data = json_decode( $out, true );

		$this->assertSame( 0, $code );
		$this->assertSame( '', $err );
		$this->assertIsArray( $data );
		$this->assertSame( Application::VERSION, $data['tool']['version'] );
		$this->assertSame( '6.5.2', $data['site']['wp_version'] );
	}

	/**
	 * @dataProvider exit_code_cases
	 * @param string   $severity Severity of the finding, or 'error' / 'skipped' / 'pass'.
	 * @param int      $expected Expected exit code.
	 */
	public function test_the_exit_code_follows_the_report( string $severity, int $expected ): void {
		$registry = $this->registry_of(
			function () use ( $severity ) {
				switch ( $severity ) {
					case 'pass':
						return Result::pass();
					case 'skipped':
						return Result::skipped( 'offline' );
					case 'error':
						throw new \RuntimeException( 'boom' );
					default:
						return Result::findings( $this->finding( 'A finding', $severity ) );
				}
			}
		);

		list( $code ) = $this->run_app( array( 'scan', $this->fixture( 'plain' ), '--format=json' ), $registry );

		$this->assertSame( $expected, $code );
	}

	/**
	 * @return array<string, array<int, mixed>>
	 */
	public function exit_code_cases(): array {
		return array(
			'pass'     => array( 'pass', 0 ),
			'skipped'  => array( 'skipped', 0 ),
			'info'     => array( 'info', 0 ),
			'warning'  => array( 'warning', 1 ),
			'critical' => array( 'critical', 2 ),
			'error'    => array( 'error', 3 ),
		);
	}

	public function test_a_check_error_is_shown_in_the_report_and_never_as_a_pass(): void {
		$registry = $this->registry_of(
			static function () {
				throw new \RuntimeException( 'disk on fire' );
			}
		);

		list( $code, $out ) = $this->run_app( array( 'scan', $this->fixture( 'plain' ) ), $registry );

		$this->assertSame( 3, $code );
		$this->assertStringContainsString( 'test.check: RuntimeException: disk on fire', $out );
		$this->assertStringContainsString( '0 passed', $out );
	}

	public function test_secrets_from_wp_config_are_redacted_in_text_and_json(): void {
		$password = 'fake-db-password-0001';
		$registry = $this->registry_of(
			function () use ( $password ) {
				return Result::findings( $this->finding( "Leaked $password", 'critical', "mysql -p$password" ) );
			}
		);

		foreach ( array( 'text', 'json' ) as $format ) {
			list( , $out ) = $this->run_app( array( 'scan', $this->fixture( 'plain' ), "--format=$format" ), $registry );

			$this->assertStringContainsString( '[redacted]', $out, $format );
			$this->assertSame( false, strpos( $out, $password ), "The password appears in $format output" );
		}
	}

	public function test_the_environment_password_is_redacted_when_db_from_env_is_used(): void {
		$password = 'env-only-password-7788';
		$env      = array(
			'WPD_DB_HOST'     => '127.0.0.1',
			'WPD_DB_NAME'     => 'env_db',
			'WPD_DB_USER'     => 'env_user',
			'WPD_DB_PASSWORD' => $password,
		);
		$registry = $this->registry_of(
			function () use ( $password ) {
				return Result::findings( $this->finding( "Leaked $password" ) );
			}
		);

		list( , $out ) = $this->run_app( array( 'scan', $this->fixture( 'plain' ), '--db-from-env' ), $registry, $env );

		$this->assertStringContainsString( 'Leaked [redacted]', $out );
		$this->assertSame( false, strpos( $out, $password ) );
	}

	public function test_emails_are_masked_only_with_the_flag(): void {
		$registry = $this->registry_of(
			function () {
				return Result::findings( $this->finding( 'Admin jane@example.test created' ) );
			}
		);

		list( , $plain )  = $this->run_app( array( 'scan', $this->fixture( 'plain' ) ), $registry );
		list( , $masked ) = $this->run_app( array( 'scan', $this->fixture( 'plain' ), '--redact-emails' ), $registry );

		$this->assertStringContainsString( 'jane@example.test', $plain );
		$this->assertStringContainsString( '***@example.test', $masked );
		$this->assertSame( false, strpos( $masked, 'jane@' ) );
	}

	public function test_a_scan_whose_checks_never_ask_for_the_database_does_not_connect(): void {
		$registry = $this->registry_of(
			static function ( Context $context ) {
				foreach ( $context->files()->walk( '', false ) as $entry ) {
					break;
				}
				return Result::pass();
			}
		);

		list( $code ) = $this->run_app( array( 'scan', $this->fixture( 'plain' ) ), $registry );

		$this->assertSame( 0, $code );
		$this->assertSame( array(), $this->connections, 'The database was opened although no check asked for it' );
		$this->assertSame( array(), $this->driver->statements );
	}

	public function test_two_database_checks_share_one_connection(): void {
		$ask      = static function ( Context $context ) {
			$context->database()->query( 'SELECT 1' );
			return Result::pass();
		};
		$registry = new Check_Registry( new Stub_Check( 'a.first', $ask ), new Stub_Check( 'b.second', $ask ) );

		list( $code ) = $this->run_app( array( 'scan', $this->fixture( 'plain' ) ), $registry );

		$this->assertSame( 0, $code );
		$this->assertCount( 1, $this->connections );
		$this->assertSame( array( 'SET SESSION TRANSACTION READ ONLY', 'SELECT 1', 'SELECT 1' ), $this->driver->statements );
	}

	public function test_the_database_is_opened_with_the_credentials_from_wp_config(): void {
		$seen     = null;
		$registry = $this->registry_of(
			function ( Context $context ) use ( &$seen ) {
				$seen = $context->database_unavailable();
				$context->database()->query( 'SELECT 1' );
				return Result::pass();
			}
		);

		list( $code ) = $this->run_app( array( 'scan', $this->fixture( 'plain' ) ), $registry );

		$this->assertSame( 0, $code );
		$this->assertNull( $seen );
		$this->assertSame( array( array( 'db.example.test', 'example_user', 'fake-db-password-0001', 'example_db' ) ), $this->connections );
		$this->assertSame( array( 'SET SESSION TRANSACTION READ ONLY', 'SELECT 1' ), $this->driver->statements );
	}

	public function test_checks_skip_with_the_reason_when_wp_config_values_cannot_be_read(): void {
		$reason   = null;
		$registry = $this->registry_of(
			function ( Context $context ) use ( &$reason ) {
				$reason = $context->database_unavailable();
				return Result::skipped( (string) $reason );
			}
		);

		list( $code, $out ) = $this->run_app( array( 'scan', $this->fixture( 'computed' ) ), $registry );

		$this->assertSame( 0, $code );
		$this->assertSame( array(), $this->connections, 'A connection was attempted without credentials' );
		$this->assertStringContainsString( 'DB_NAME (computed value)', (string) $reason );
		$this->assertStringContainsString( 'DB_PASSWORD (computed value)', (string) $reason );
		$this->assertStringContainsString( '$table_prefix (computed value)', (string) $reason );
		$this->assertStringContainsString( 'DB_NAME (computed value)', $out );
	}

	public function test_a_failed_connection_makes_database_checks_skip_with_the_error(): void {
		$reason   = null;
		$registry = $this->registry_of(
			function ( Context $context ) use ( &$reason ) {
				$reason = $context->database_unavailable();
				return Result::skipped( (string) $reason );
			}
		);
		$connect  = static function (): Database_Driver {
			throw new Database_Exception( 'Could not connect to the database (error 2002): refused' );
		};

		list( $code ) = $this->run_app( array( 'scan', $this->fixture( 'plain' ) ), $registry, array(), false, $connect );

		$this->assertSame( 0, $code );
		$this->assertStringContainsString( 'error 2002', (string) $reason );
	}

	public function test_db_from_env_replaces_the_credentials_but_not_the_table_prefix(): void {
		$env = array(
			'WPD_DB_HOST'     => 'env.example.test:3307',
			'WPD_DB_NAME'     => 'env_db',
			'WPD_DB_USER'     => 'env_user',
			'WPD_DB_PASSWORD' => '',
		);

		$this->run_app( array( 'scan', $this->fixture( 'plain' ), '--db-from-env' ), $this->database_user(), $env );
		$this->assertSame( array( array( 'env.example.test:3307', 'env_user', '', 'env_db' ) ), $this->connections );

		$this->connections = array();
		$reason            = null;
		$registry          = $this->registry_of(
			function ( Context $context ) use ( &$reason ) {
				$reason = $context->database_unavailable();
				return Result::skipped( (string) $reason );
			}
		);
		$this->run_app( array( 'scan', $this->fixture( 'computed' ), '--db-from-env' ), $registry, $env );

		$this->assertSame( array(), $this->connections );
		$this->assertStringContainsString( '$table_prefix (computed value)', (string) $reason );
		$this->assertStringNotContainsString( 'DB_NAME', (string) $reason );
	}

	public function test_the_skip_reason_suggests_db_from_env_only_when_the_flag_is_missing(): void {
		$reason   = null;
		$registry = $this->registry_of(
			function ( Context $context ) use ( &$reason ) {
				$reason = $context->database_unavailable();
				return Result::skipped( (string) $reason );
			}
		);

		$this->run_app( array( 'scan', $this->fixture( 'computed' ) ), $registry );
		$this->assertNotNull( $reason, 'The computed fixture must leave the database unavailable' );
		$this->assertStringContainsString( 'use --db-from-env', (string) $reason );

		$reason = null;
		$this->run_app( array( 'scan', $this->fixture( 'computed' ), '--db-from-env' ), $registry );
		$this->assertNotNull( $reason );
		$this->assertStringNotContainsString( 'use --db-from-env', (string) $reason );
	}

	public function test_db_from_env_can_supply_the_table_prefix_that_wp_config_computes(): void {
		$env      = array(
			'WPD_DB_HOST'         => 'env.example.test',
			'WPD_DB_NAME'         => 'env_db',
			'WPD_DB_USER'         => 'env_user',
			'WPD_DB_PASSWORD'     => 'x',
			'WPD_DB_TABLE_PREFIX' => 'envp_',
		);
		$table    = null;
		$registry = $this->registry_of(
			function ( Context $context ) use ( &$table ) {
				$table = $context->table( 'options' );
				return Result::pass();
			}
		);

		list( $code ) = $this->run_app( array( 'scan', $this->fixture( 'computed' ), '--db-from-env' ), $registry, $env );

		$this->assertSame( 0, $code );
		$this->assertCount( 1, $this->connections );
		$this->assertSame( '`envp_options`', $table );
	}

	public function test_the_table_prefix_variable_wins_over_a_literal_prefix_in_wp_config(): void {
		$env      = array(
			'WPD_DB_HOST'         => 'h',
			'WPD_DB_NAME'         => 'n',
			'WPD_DB_USER'         => 'u',
			'WPD_DB_PASSWORD'     => 'x',
			'WPD_DB_TABLE_PREFIX' => 'other_',
		);
		$table    = null;
		$registry = $this->registry_of(
			function ( Context $context ) use ( &$table ) {
				$table = $context->table( 'options' );
				return Result::pass();
			}
		);

		$this->run_app( array( 'scan', $this->fixture( 'plain' ), '--db-from-env' ), $registry, $env );

		$this->assertSame( '`other_options`', $table );
	}

	public function test_the_table_prefix_variable_is_ignored_without_db_from_env(): void {
		$table    = null;
		$registry = $this->registry_of(
			function ( Context $context ) use ( &$table ) {
				$table = $context->table( 'options' );
				return Result::pass();
			}
		);

		$this->run_app( array( 'scan', $this->fixture( 'plain' ) ), $registry, array( 'WPD_DB_TABLE_PREFIX' => 'other_' ) );

		$this->assertSame( '`wptest_options`', $table );
	}

	/**
	 * @dataProvider unusable_prefixes
	 * @param string $prefix Value of WPD_DB_TABLE_PREFIX.
	 */
	public function test_an_unusable_table_prefix_variable_is_a_usage_error_and_nothing_connects( string $prefix ): void {
		$env = array(
			'WPD_DB_HOST'         => 'h',
			'WPD_DB_NAME'         => 'n',
			'WPD_DB_USER'         => 'u',
			'WPD_DB_PASSWORD'     => 'x',
			'WPD_DB_TABLE_PREFIX' => $prefix,
		);

		list( $code, , $err ) = $this->run_app( array( 'scan', $this->fixture( 'plain' ), '--db-from-env' ), null, $env );

		$this->assertSame( 3, $code );
		$this->assertStringContainsString( 'WPD_DB_TABLE_PREFIX', $err );
		$this->assertSame( array(), $this->connections );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function unusable_prefixes(): array {
		return array(
			'backtick'         => array( 'wp`_' ),
			'space'            => array( 'wp _' ),
			'dash'             => array( 'wp-' ),
			'trailing newline' => array( "wp_\n" ),
		);
	}

	public function test_the_unusable_prefix_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->unusable_prefixes() );
	}

	public function test_db_from_env_names_the_missing_variable(): void {
		$reason   = null;
		$registry = $this->registry_of(
			function ( Context $context ) use ( &$reason ) {
				$reason = $context->database_unavailable();
				return Result::skipped( (string) $reason );
			}
		);

		$this->run_app(
			array( 'scan', $this->fixture( 'plain' ), '--db-from-env' ),
			$registry,
			array(
				'WPD_DB_HOST' => 'h',
				'WPD_DB_NAME' => 'n',
				'WPD_DB_USER' => 'u',
			)
		);

		$this->assertSame( array(), $this->connections );
		$this->assertStringContainsString( 'WPD_DB_PASSWORD', (string) $reason );
	}

	public function test_without_db_from_env_the_environment_is_ignored(): void {
		$this->run_app(
			array( 'scan', $this->fixture( 'plain' ) ),
			$this->database_user(),
			array(
				'WPD_DB_HOST'     => 'ignored.example.test',
				'WPD_DB_NAME'     => 'x',
				'WPD_DB_USER'     => 'x',
				'WPD_DB_PASSWORD' => 'x',
			)
		);

		$this->assertSame( 'db.example.test', $this->connections[0][0] );
	}

	/**
	 * Registry with a check that lists every file it can see.
	 *
	 * @param array<int, string> $seen Receives the paths.
	 * @return Check_Registry
	 */
	private function lister( array &$seen ): Check_Registry {
		return $this->registry_of(
			function ( Context $context ) use ( &$seen ) {
				foreach ( $context->files()->walk() as $info ) {
					if ( $info->is_file() ) {
						$seen[] = $info->path();
					}
				}
				return Result::pass();
			}
		);
	}

	public function test_max_files_limits_each_walk_and_the_report_says_so(): void {
		$seen = array();

		list( , $out ) = $this->run_app( array( 'scan', $this->fixture( 'plain' ), '--max-files=2' ), $this->lister( $seen ) );

		$this->assertLessThanOrEqual( 2, count( $seen ) );
		$this->assertStringContainsString( 'stopped after 2 entries', $out );
	}

	public function test_exclude_hides_paths_from_checks(): void {
		$seen = array();

		$this->run_app( array( 'scan', $this->fixture( 'with-config' ), '--exclude=wp-content/uploads/**', '--exclude=wp-content/cache/**' ), $this->lister( $seen ) );

		$this->assertContains( 'wp-config.php', $seen );
		$this->assertNotContains( 'wp-content/uploads/a.jpg', $seen );
		$this->assertNotContains( 'wp-content/cache/page.html', $seen );
	}

	public function test_wp_doctor_json_in_the_site_root_is_ignored_and_reported_as_unused(): void {
		$seen = array();

		list( $code, $out ) = $this->run_app( array( 'scan', $this->fixture( 'with-config' ) ), $this->lister( $seen ) );

		$this->assertSame( 0, $code );
		$this->assertContains( 'wp-content/uploads/a.jpg', $seen, 'The fixture held nothing to examine' );
		$this->assertContains( 'wp-content/cache/page.html', $seen, 'The site-root config excluded a path' );
		$this->assertStringContainsString( 'wp-doctor.json in the site root was not used', $out );
		$this->assertStringNotContainsString( 'Excluded from the scan', $out );
		$this->assertStringNotContainsString( 'read but not applied', $out );
	}

	public function test_an_explicit_config_file_is_applied_while_the_site_root_one_is_still_reported(): void {
		$this->tree = new Temp_Tree();
		$path       = $this->tree->file( 'custom.json', '{"exclude":["wp-content/cache/**"],"ignore":["test.check"]}' );
		$seen       = array();

		list( $code, $out ) = $this->run_app( array( 'scan', $this->fixture( 'with-config' ), "--config=$path" ), $this->lister( $seen ) );

		$this->assertSame( 0, $code );
		$this->assertNotContains( 'wp-content/cache/page.html', $seen );
		$this->assertStringContainsString( 'custom.json', $out );
		$this->assertStringContainsString( 'wp-content/cache/**', $out );
		$this->assertStringContainsString( 'Ignored by ' . $path . ': test.check.', $out );
		$this->assertStringContainsString( 'wp-doctor.json in the site root was not used', $out );
	}

	/**
	 * @dataProvider config_paths_inside_the_site
	 * @param string $relative Path of the --config file, relative to the with-config fixture.
	 */
	public function test_a_config_file_inside_the_scanned_site_is_refused( string $relative ): void {
		$site = $this->fixture( 'with-config' );
		$this->assertFileExists( $site . '/' . $relative );
		$seen = array();

		list( $code, $out, $err ) = $this->run_app( array( 'scan', $site, '--config=' . $site . '/' . $relative ), $this->lister( $seen ) );

		$this->assertSame( 3, $code );
		$this->assertSame( '', $out );
		$this->assertSame( array(), $seen, 'The scan ran with a config file from inside the site' );
		$this->assertStringContainsString( 'inside the scanned site', $err );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function config_paths_inside_the_site(): array {
		return array(
			'site root'        => array( 'wp-doctor.json' ),
			'through a parent' => array( 'wp-content/../wp-doctor.json' ),
		);
	}

	public function test_the_config_paths_inside_the_site_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->config_paths_inside_the_site() );
	}

	public function test_a_config_file_linked_from_outside_into_the_site_is_refused(): void {
		$this->tree = new Temp_Tree();
		$this->tree->link( 'linked.json', $this->fixture( 'with-config' ) . '/wp-doctor.json' );
		$path = $this->tree->site() . '/linked.json';
		$this->assertFileExists( $path );

		list( $code, $out, $err ) = $this->run_app( array( 'scan', $this->fixture( 'with-config' ), "--config=$path" ) );

		$this->assertSame( 3, $code );
		$this->assertSame( '', $out );
		$this->assertStringContainsString( 'inside the scanned site', $err );
	}

	public function test_an_ignored_check_is_listed_but_does_not_set_the_exit_code(): void {
		$this->tree = new Temp_Tree();
		$path       = $this->tree->file( 'custom.json', '{"ignore":["test.check"]}' );
		$registry   = $this->registry_of(
			function () {
				return Result::findings( $this->finding( 'Silenced problem', 'critical' ) );
			}
		);

		list( $code, $text )          = $this->run_app( array( 'scan', $this->fixture( 'plain' ), "--config=$path" ), $registry );
		list( $json_code, $json_out ) = $this->run_app( array( 'scan', $this->fixture( 'plain' ), "--config=$path", '--format=json' ), $registry );
		$data                         = json_decode( $json_out, true );

		$this->assertSame( 0, $code );
		$this->assertStringContainsString( 'Ignored by configuration (1)', $text );
		$this->assertStringContainsString( 'test.check: 1 finding, most severe critical (not counted)', $text );
		$this->assertStringNotContainsString( 'CRITICAL (1)', $text );
		$this->assertStringContainsString( '1 ignored by configuration', $text );
		$this->assertSame( 0, $json_code );
		$this->assertSame( 0, $data['summary']['exit_code'] );
		$this->assertSame( 1, $data['summary']['checks']['ignored'] );
		$this->assertSame( array(), $data['findings'] );
		$this->assertTrue( $data['checks'][0]['ignored'] );
		$this->assertSame( 'Silenced problem', $data['checks'][0]['findings'][0]['title'] );
	}

	public function test_configured_thresholds_reach_the_check_and_are_announced(): void {
		$this->tree = new Temp_Tree();
		$path       = $this->tree->file( 'custom.json', '{"thresholds":{"perf.autoload.size":{"warning":7,"critical":9}}}' );
		$registry   = new Check_Registry(
			new Stub_Check(
				'perf.autoload.size',
				function ( Context $context ) {
					return Result::findings(
						new Finding(
							'perf.autoload.size',
							Severity::info(),
							'Limits seen',
							array(
								'warning'  => $context->thresholds()->warning( 'perf.autoload.size', 1 ),
								'critical' => $context->thresholds()->critical( 'perf.autoload.size', 2 ),
								'basis'    => $context->thresholds()->basis( 'perf.autoload.size' ),
							),
							'None.'
						)
					);
				}
			)
		);

		list( $code, $out ) = $this->run_app( array( 'scan', $this->fixture( 'plain' ), "--config=$path", '--format=json' ), $registry );
		$report             = json_decode( $out, true );
		$evidence           = $report['findings'][0]['evidence'];

		$this->assertSame( 0, $code );
		$this->assertSame( 7, $evidence['warning'] );
		$this->assertSame( 9, $evidence['critical'] );
		$this->assertSame( 'set in ' . $path, $evidence['basis'] );
		$this->assertContains( 'Thresholds from ' . $path . ' apply to: perf.autoload.size.', $report['notices'] );
	}

	public function test_a_config_naming_an_unknown_check_or_an_unsupported_level_exits_three(): void {
		$this->tree = new Temp_Tree();
		$unknown    = $this->tree->file( 'unknown.json', '{"ignore":["test.chekc"]}' );
		$level      = $this->tree->file( 'level.json', '{"thresholds":{"perf.autoload.size":{"warning":1}}}' );
		$registry   = new Check_Registry(
			new Stub_Check(
				'test.check',
				static function () {
					return Result::pass();
				}
			),
			new Stub_Check(
				'perf.cron.duplicates',
				static function () {
					return Result::pass();
				}
			)
		);

		list( $code, $out, $err ) = $this->run_app( array( 'scan', $this->fixture( 'plain' ), "--config=$unknown" ), $registry );
		$this->assertSame( 3, $code );
		$this->assertSame( '', $out );
		$this->assertStringContainsString( '"test.chekc" in "ignore" is not a check this version knows', $err );

		list( $code, , $err ) = $this->run_app( array( 'scan', $this->fixture( 'plain' ), "--config=$level" ), $registry );
		$this->assertSame( 3, $code );
		$this->assertStringContainsString( 'not a check this version knows', $err );

		$cron_level           = $this->tree->file( 'cron.json', '{"thresholds":{"perf.cron.duplicates":{"warning":1}}}' );
		list( $code, , $err ) = $this->run_app( array( 'scan', $this->fixture( 'plain' ), "--config=$cron_level" ), $registry );
		$this->assertSame( 3, $code );
		$this->assertStringContainsString( '"perf.cron.duplicates" has no warning threshold', $err );
	}

	public function test_an_explicit_config_file_adds_to_the_command_line_excludes(): void {
		$this->tree = new Temp_Tree();
		$path       = $this->tree->file( 'custom.json', '{"exclude":["wp-content/uploads/**"]}' );
		$seen       = array();

		list( $code, $out ) = $this->run_app( array( 'scan', $this->fixture( 'with-config' ), "--config=$path", '--exclude=wp-config.php' ), $this->lister( $seen ) );

		$this->assertSame( 0, $code );
		$this->assertNotEmpty( $seen );
		$this->assertNotContains( 'wp-content/uploads/a.jpg', $seen );
		$this->assertNotContains( 'wp-config.php', $seen );
		$this->assertContains( 'wp-content/cache/page.html', $seen, 'The site-root config was used instead of the explicit one' );
		$this->assertStringContainsString( 'custom.json', $out );
	}

	public function test_a_config_file_with_an_unknown_key_exits_three(): void {
		$this->tree = new Temp_Tree();
		$path       = $this->tree->file( 'bad.json', '{"exlcude":["a"]}' );

		list( $code, $out, $err ) = $this->run_app( array( 'scan', $this->fixture( 'plain' ), "--config=$path" ) );

		$this->assertSame( 3, $code );
		$this->assertSame( '', $out );
		$this->assertStringContainsString( 'Unknown key "exlcude"', $err );
	}

	public function test_a_missing_config_file_exits_three(): void {
		list( $code, , $err ) = $this->run_app( array( 'scan', $this->fixture( 'plain' ), '--config=/nonexistent/wp-doctor.json' ) );

		$this->assertSame( 3, $code );
		$this->assertStringContainsString( '/nonexistent/wp-doctor.json', $err );
	}

	public function test_a_config_path_that_is_a_directory_is_named_as_one(): void {
		$this->tree = new Temp_Tree();
		$directory  = $this->tree->outside();

		list( $code, $out, $err ) = $this->run_app( array( 'scan', $this->fixture( 'plain' ), "--config=$directory" ) );

		$this->assertSame( 3, $code );
		$this->assertSame( '', $out );
		$this->assertStringContainsString( "Config file $directory is a directory", $err );
		$this->assertStringNotContainsString( 'Syntax error', $err );
	}

	public function test_a_32_bit_php_is_refused_naming_the_reason_and_64_bit_is_accepted(): void {
		$this->assertSame( 'wp-doctor needs a 64-bit PHP; this one is 32-bit, so file and table sizes above 2 GiB would be wrong', Application::platform_problem( 4 ) );
		$this->assertNull( Application::platform_problem( 8 ) );
		$this->assertNull( Application::platform_problem( PHP_INT_SIZE ), 'The PHP running the tests is 32-bit' );
	}

	public function test_a_site_without_wp_doctor_json_reports_no_config(): void {
		list( , $out ) = $this->run_app( array( 'scan', $this->fixture( 'plain' ) ) );

		$this->assertStringNotContainsString( 'wp-doctor.json', $out );
	}

	public function test_colour_is_used_only_for_a_terminal_unless_forced(): void {
		$registry = $this->registry_of(
			function () {
				return Result::findings( $this->finding( 'Colourful', 'critical' ) );
			}
		);
		$site     = $this->fixture( 'plain' );

		list( , $piped )        = $this->run_app( array( 'scan', $site ), $registry );
		list( , $terminal )     = $this->run_app( array( 'scan', $site ), $registry, array(), true );
		list( , $no_color_env ) = $this->run_app( array( 'scan', $site ), $registry, array( 'NO_COLOR' => '1' ), true );
		list( , $dumb )         = $this->run_app( array( 'scan', $site ), $registry, array( 'TERM' => 'dumb' ), true );
		list( , $forced_on )    = $this->run_app( array( 'scan', $site, '--color' ), $registry );
		list( , $forced_off )   = $this->run_app( array( 'scan', $site, '--no-color' ), $registry, array(), true );

		$this->assertStringNotContainsString( "\033", $piped );
		$this->assertStringContainsString( "\033[31m", $terminal );
		$this->assertStringNotContainsString( "\033", $no_color_env );
		$this->assertStringNotContainsString( "\033", $dumb );
		$this->assertStringContainsString( "\033[31m", $forced_on );
		$this->assertStringNotContainsString( "\033", $forced_off );
	}

	public function test_json_output_never_contains_colour_codes(): void {
		$registry = $this->registry_of(
			function () {
				return Result::findings( $this->finding( 'Colourful', 'critical' ) );
			}
		);

		list( , $out ) = $this->run_app( array( 'scan', $this->fixture( 'plain' ), '--format=json', '--color' ), $registry, array(), true );

		$this->assertStringNotContainsString( "\033", $out );
		$this->assertIsArray( json_decode( $out, true ) );
	}

	public function test_a_finding_text_with_terminal_escapes_cannot_reach_the_terminal(): void {
		$registry = $this->registry_of(
			function () {
				return Result::findings( $this->finding( "Evil \033[2J name" ) );
			}
		);

		list( , $out ) = $this->run_app( array( 'scan', $this->fixture( 'plain' ) ), $registry );

		$this->assertStringNotContainsString( "\033", $out );
	}

	public function test_the_scan_makes_no_change_to_the_site(): void {
		$before = $this->snapshot( $this->fixture( 'with-config' ) );
		$this->assertNotEmpty( $before );

		$this->run_app(
			array( 'scan', $this->fixture( 'with-config' ) ),
			$this->registry_of(
				function ( Context $context ) {
					foreach ( $context->files()->walk() as $info ) {
						if ( $info->is_file() ) {
							$context->files()->read( $info->path() );
							$context->files()->hash( $info->path() );
						}
					}
					return Result::pass();
				}
			)
		);

		$this->assertSame( $before, $this->snapshot( $this->fixture( 'with-config' ) ) );
	}

	/**
	 * Path, size, mtime and mode of every file under a directory.
	 *
	 * @param string $dir Directory to list recursively.
	 * @return string[]
	 */
	private function snapshot( string $dir ): array {
		$rows     = array();
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			$rows[] = $file->getPathname() . '|' . $file->getSize() . '|' . $file->getMTime() . '|' . $file->getPerms();
		}
		sort( $rows );

		return $rows;
	}

	public function test_checks_are_given_the_checksum_source_unless_offline_is_passed(): void {
		$seen     = array();
		$registry = $this->registry_of(
			function ( Context $context ) use ( &$seen ) {
				$seen[] = $context->checksums_unavailable();
				$seen[] = null === $context->checksums_unavailable() ? $context->checksums() : null;

				return Result::pass();
			}
		);

		$this->run_app( array( 'scan', dirname( __DIR__ ) . '/fixtures/sites/plain' ), $registry );

		$this->assertCount( 2, $seen, 'The check did not run' );
		$this->assertNull( $seen[0] );
		$this->assertSame( $this->checksums, $seen[1] );
	}

	public function test_offline_rules_out_checksum_lookups_with_that_reason(): void {
		$seen     = array();
		$registry = $this->registry_of(
			function ( Context $context ) use ( &$seen ) {
				$seen[] = $context->checksums_unavailable();

				return Result::pass();
			}
		);

		$this->run_app( array( 'scan', dirname( __DIR__ ) . '/fixtures/sites/plain', '--offline' ), $registry );

		$this->assertCount( 1, $seen, 'The check did not run' );
		$this->assertStringContainsString( '--offline', (string) $seen[0] );
	}

	public function test_an_unreadable_wp_config_gives_a_partial_scan_rather_than_stopping(): void {
		File_Modes::require_enforced_modes();
		$this->tree = new Temp_Tree();
		$this->tree->file( 'wp-includes/version.php', "<?php\n\$wp_version = '6.5.2';\n" );
		$config = $this->tree->file( 'wp-config.php', "<?php\ndefine( 'DB_PASSWORD', 'example-password' );\n\$table_prefix = 'wp_';\n" );
		chmod( $config, 0000 );

		try {
			list( $code, $out, $err ) = $this->run_app( array( 'scan', $this->tree->site(), '--offline', '--format=json' ), Check_Registry::built_in() );
		} finally {
			chmod( $config, 0600 );
		}

		$this->assertSame( '', $err );
		$this->assertNotSame( 3, $code );
		$this->assertSame( array(), $this->connections, 'The database was opened without readable credentials' );
		$report = json_decode( $out, true );
		$this->assertIsArray( $report );
		$this->assertStringContainsString( 'Partial scan', implode( "\n", $report['notices'] ) );
		$statuses = array();
		foreach ( $report['checks'] as $check ) {
			$statuses[ $check['id'] ] = $check['status'];
		}
		$this->assertSame( 'skipped', $statuses['config.salts.default'] );
		$this->assertSame( 'skipped', $statuses['perf.autoload.size'] );
		$this->assertNotSame( 'skipped', $statuses['config.perms.wpconfig'] );
	}

	public function test_the_environment_host_user_and_name_are_redacted_when_db_from_env_is_used(): void {
		$env      = array(
			'WPD_DB_HOST'     => 'env-db.example.test',
			'WPD_DB_NAME'     => 'env_example_db',
			'WPD_DB_USER'     => 'env_example_user',
			'WPD_DB_PASSWORD' => 'env-only-password-7788',
		);
		$registry = $this->registry_of(
			function () {
				return Result::findings( $this->finding( 'Could not reach env-db.example.test as env_example_user for env_example_db' ) );
			}
		);

		list( , $out ) = $this->run_app( array( 'scan', $this->fixture( 'plain' ), '--db-from-env' ), $registry, $env );

		$this->assertStringContainsString( 'Could not reach [redacted]', $out );
		foreach ( array( 'env-db.example.test', 'env_example_user', 'env_example_db' ) as $value ) {
			$this->assertSame( false, strpos( $out, $value ), "$value appears in the output" );
		}
	}
}
