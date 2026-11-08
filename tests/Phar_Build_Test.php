<?php
/**
 * The release phar runs on its own and holds only what a scan needs.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Application;
use WP_Doctor\Check\Category\Security\Rogue_Admin_Indicators;

use function WP_Doctor\esc_cli;

final class Phar_Build_Test extends TestCase {

	private const TIMEOUT_SECONDS = 120;
	private const EPOCH           = 1767225600;

	/**
	 * Directory holding the phar built for this class.
	 *
	 * @var string
	 */
	private static $directory = '';

	/**
	 * Path of the phar built for this class.
	 *
	 * @var string
	 */
	private static $phar = '';

	/**
	 * What the build printed, shown when it wrote no phar.
	 *
	 * @var string
	 */
	private static $build_output = '';

	public static function setUpBeforeClass(): void {
		if ( ! extension_loaded( 'phar' ) ) {
			return;
		}
		self::$directory = sys_get_temp_dir() . '/wpd-phar-' . bin2hex( random_bytes( 6 ) );
		mkdir( self::$directory );
		self::$phar         = self::$directory . '/wp-doctor.phar';
		$build              = self::build( self::$phar );
		self::$build_output = "exit {$build['exit']}: {$build['stdout']}{$build['stderr']}";
	}

	public static function tearDownAfterClass(): void {
		if ( '' === self::$directory ) {
			return;
		}
		foreach ( (array) glob( self::$directory . '/*' ) as $file ) {
			unlink( (string) $file );
		}
		rmdir( self::$directory );
	}

	protected function setUp(): void {
		if ( ! extension_loaded( 'phar' ) ) {
			$this->markTestSkipped( 'The phar extension is not loaded, so no phar can be built.' );
		}
		$this->assertFileExists( self::$phar, 'The build did not write a phar; it printed ' . self::$build_output );
	}

	public function test_the_phar_prints_the_version(): void {
		$result = self::run_php( array( self::$phar, '--version' ) );

		$this->assertSame( 0, $result['exit'] );
		$this->assertSame( 'wp-doctor ' . Application::VERSION . "\n", $result['stdout'] );
	}

	public function test_the_phar_scans_a_site(): void {
		$site   = dirname( __DIR__ ) . '/tests/fixtures/sites/plain';
		$result = self::run_php( array( self::$phar, 'scan', $site, '--offline', '--format=json' ) );
		$report = json_decode( $result['stdout'], true );

		$this->assertSame( 2, $result['exit'], 'The plain fixture has default salts, a critical finding' );
		$this->assertIsArray( $report );
		$this->assertSame( Application::VERSION, $report['tool']['version'] );
		$critical = array();
		foreach ( $report['findings'] as $finding ) {
			if ( 'critical' === $finding['severity'] ) {
				$critical[] = $finding['id'];
			}
		}
		$this->assertContains( 'config.salts.default', $critical );
	}

	public function test_the_bundled_data_loads_from_inside_the_phar(): void {
		$code   = 'require ' . var_export( 'phar://' . self::$phar . '/vendor/autoload.php', true ) . ';' // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Quotes a path as a PHP string literal.
			. ' echo \WP_Doctor\Check\Category\Security\Rogue_Admin_Indicators::bundled()->disposable_domain_count();';
		$result = self::run_php( array( '-r', $code ) );
		$count  = Rogue_Admin_Indicators::bundled()->disposable_domain_count();

		$this->assertGreaterThan( 0, $count );
		$this->assertSame( (string) $count, $result['stdout'] );
	}

	public function test_the_phar_holds_the_runtime_files_and_nothing_else(): void {
		$root    = dirname( __DIR__ );
		$entries = array();
		foreach ( new \RecursiveIteratorIterator( new \Phar( self::$phar ) ) as $file ) {
			$path      = $file->getPathname();
			$entries[] = substr( $path, strpos( $path, 'wp-doctor.phar/' ) + strlen( 'wp-doctor.phar/' ) );
		}
		$sources = array();
		foreach ( new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( "$root/src", \FilesystemIterator::SKIP_DOTS ) ) as $file ) {
			$sources[] = str_replace( '\\', '/', substr( $file->getPathname(), strlen( "$root/" ) ) );
		}
		$expected = array_merge(
			$sources,
			array( 'bin/wp-doctor', 'data/disposable-email-domains.txt', 'data/rogue-admin-indicators.json', 'vendor/autoload.php', 'LICENSE' )
		);
		sort( $entries );
		sort( $expected );

		$this->assertGreaterThan( 50, count( $sources ) );
		$this->assertSame( $expected, $entries );
	}

	public function test_two_builds_of_the_same_source_are_byte_identical(): void {
		$again = self::$directory . '/again.phar';
		sleep( 1 );
		$build = self::build( $again );

		$this->assertSame( 0, $build['exit'], $build['stderr'] );
		$this->assertSame( hash_file( 'sha256', self::$phar ), hash_file( 'sha256', $again ) );
	}

	public function test_every_file_in_the_phar_has_the_build_time_and_the_same_permissions(): void {
		$times = array();
		$perms = array();
		foreach ( new \RecursiveIteratorIterator( new \Phar( self::$phar ) ) as $file ) {
			$times[ $file->getMTime() ]        = true;
			$perms[ $file->getPerms() & 0777 ] = true;
		}

		$this->assertSame( array( self::EPOCH ), array_keys( $times ) );
		$this->assertCount( 1, $perms, 'Files in the phar have different permissions' );
	}

	public function test_a_source_date_epoch_that_is_not_a_unix_time_is_refused(): void {
		$output = self::$directory . '/dated.phar';
		$result = self::run_php( array( '-d', 'phar.readonly=0', self::build_script(), '--output=' . $output ), array( 'SOURCE_DATE_EPOCH' => '2026-11-08' ) );

		$this->assertSame( 1, $result['exit'] );
		$this->assertStringContainsString( 'SOURCE_DATE_EPOCH', $result['stderr'] );
		$this->assertFalse( file_exists( $output ), "A refused build left $output behind" );
	}

	public function test_a_build_for_another_version_is_refused(): void {
		$output = self::$directory . '/other.phar';
		$result = self::run_php( array( '-d', 'phar.readonly=0', self::build_script(), '--output=' . $output, '--expect-version=9.9.9' ) );

		$this->assertSame( 1, $result['exit'] );
		$this->assertStringContainsString( '9.9.9', $result['stderr'] );
		$this->assertStringContainsString( Application::VERSION, $result['stderr'] );
		$this->assertFalse( file_exists( $output ), "A refused build left $output behind" );
	}

	public function test_a_build_with_phar_readonly_on_is_refused_with_how_to_turn_it_off(): void {
		$output = self::$directory . '/readonly.phar';
		$result = self::run_php( array( '-d', 'phar.readonly=1', self::build_script(), '--output=' . $output ) );

		$this->assertSame( 1, $result['exit'] );
		$this->assertStringContainsString( '-d phar.readonly=0', $result['stderr'] );
		$this->assertFalse( file_exists( $output ), "A refused build left $output behind" );
	}

	/**
	 * Builds a phar from this checkout, dated EPOCH.
	 *
	 * @param string $output Path of the phar to write.
	 * @return array{exit: int, stdout: string, stderr: string}
	 */
	private static function build( string $output ): array {
		return self::run_php( array( '-d', 'phar.readonly=0', self::build_script(), '--output=' . $output ), array( 'SOURCE_DATE_EPOCH' => (string) self::EPOCH ) );
	}

	private static function build_script(): string {
		return dirname( __DIR__ ) . '/tools/build-phar.php';
	}

	/**
	 * Runs PHP with the given arguments, without Xdebug. Output goes to files,
	 * since a pipe left unread can block the child on Windows.
	 *
	 * @param array<int, string>    $args Arguments after the PHP binary.
	 * @param array<string, string> $env  Variables to set on top of this process's environment.
	 * @return array{exit: int, stdout: string, stderr: string}
	 * @throws \RuntimeException When PHP cannot start or runs past the time limit.
	 */
	private static function run_php( array $args, array $env = array() ): array {
		$command = escapeshellarg( PHP_BINARY ) . ' -d xdebug.mode=off';
		foreach ( $args as $arg ) {
			$command .= ' ' . escapeshellarg( $arg );
		}
		$out     = (string) tempnam( sys_get_temp_dir(), 'wpd-out' );
		$err     = (string) tempnam( sys_get_temp_dir(), 'wpd-err' );
		$pipes   = array();
		$process = proc_open( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Runs the built phar in its own PHP process.
			$command,
			array(
				1 => array( 'file', $out, 'w' ),
				2 => array( 'file', $err, 'w' ),
			),
			$pipes,
			null,
			array() === $env ? null : array_merge( getenv(), $env ),
			array( 'bypass_shell' => true )
		);
		if ( ! is_resource( $process ) ) {
			throw new \RuntimeException( 'Could not start PHP' );
		}
		$deadline = microtime( true ) + self::TIMEOUT_SECONDS;
		$status   = proc_get_status( $process );
		while ( $status['running'] ) {
			if ( microtime( true ) > $deadline ) {
				proc_terminate( $process );
				proc_close( $process );
				throw new \RuntimeException( esc_cli( 'PHP did not finish within ' . self::TIMEOUT_SECONDS . " seconds: $command" ) );
			}
			usleep( 20000 );
			$status = proc_get_status( $process );
		}
		proc_close( $process );
		$result = array(
			'exit'   => (int) $status['exitcode'],
			'stdout' => (string) file_get_contents( $out ),
			'stderr' => (string) file_get_contents( $err ),
		);
		unlink( $out );
		unlink( $err );

		return $result;
	}
}
