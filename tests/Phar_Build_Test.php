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

final class Phar_Build_Test extends TestCase {

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

	public static function setUpBeforeClass(): void {
		if ( ! extension_loaded( 'phar' ) ) {
			return;
		}
		self::$directory = sys_get_temp_dir() . '/wpd-phar-' . bin2hex( random_bytes( 6 ) );
		mkdir( self::$directory );
		self::$phar = self::$directory . '/wp-doctor.phar';
		self::run_php( array( '-d', 'phar.readonly=0', self::build_script(), '--output=' . self::$phar ) );
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
		$this->assertFileExists( self::$phar, 'The build did not write a phar' );
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
			$sources[] = substr( $file->getPathname(), strlen( "$root/" ) );
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

	private static function build_script(): string {
		return dirname( __DIR__ ) . '/tools/build-phar.php';
	}

	/**
	 * Runs PHP with the given arguments, without Xdebug.
	 *
	 * @param array<int, string> $args Arguments after the PHP binary.
	 * @return array{exit: int, stdout: string, stderr: string}
	 */
	private static function run_php( array $args ): array {
		$command = escapeshellarg( PHP_BINARY ) . ' -d xdebug.mode=off';
		foreach ( $args as $arg ) {
			$command .= ' ' . escapeshellarg( $arg );
		}
		$pipes   = array();
		$process = proc_open( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Runs the built phar in its own PHP process.
			$command,
			array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			),
			$pipes
		);
		if ( ! is_resource( $process ) ) {
			throw new \RuntimeException( 'Could not start PHP' );
		}
		$stdout = (string) stream_get_contents( $pipes[1] );
		$stderr = (string) stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return array(
			'exit'   => proc_close( $process ),
			'stdout' => $stdout,
			'stderr' => $stderr,
		);
	}
}
