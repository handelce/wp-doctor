<?php
/**
 * Runs bin/wp-doctor as a separate process.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Cli;

use PHPUnit\Framework\TestCase;

final class Bin_Smoke_Test extends TestCase {

	/**
	 * Runs bin/wp-doctor under the current PHP binary.
	 *
	 * @param string[] $args Command line after the script name.
	 * @return array{0: int, 1: string} Exit code and combined output.
	 */
	private function run_bin( array $args ): array {
		$command = escapeshellarg( PHP_BINARY ) . ' -d display_errors=1 -d xdebug.mode=off ' . escapeshellarg( dirname( __DIR__, 2 ) . '/bin/wp-doctor' ) . ' ' . implode( ' ', array_map( 'escapeshellarg', $args ) ) . ' 2>&1';
		exec( $command, $lines, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Runs the project's own script.

		return array( $code, implode( "\n", $lines ) );
	}

	public function test_the_script_prints_its_version(): void {
		list( $code, $output ) = $this->run_bin( array( '--version' ) );

		$this->assertSame( 0, $code );
		$this->assertSame( 1, preg_match( '/^wp-doctor \d+\.\d+\.\d+/', $output ), $output );
	}

	public function test_the_script_scans_a_site_and_prints_json(): void {
		list( $code, $output ) = $this->run_bin( array( 'scan', dirname( __DIR__ ) . '/fixtures/sites/plain', '--format=json', '--offline' ) );

		$data = json_decode( $output, true );

		$this->assertLessThan( 3, $code, $output );
		$this->assertIsArray( $data, $output );
		$this->assertSame( '6.5.2', $data['site']['wp_version'] );
		$this->assertNotEmpty( $data['summary'], 'The report has no summary' );
	}

	public function test_the_script_scans_a_web_root_whose_core_is_in_a_subdirectory(): void {
		list( $code, $output ) = $this->run_bin( array( 'scan', dirname( __DIR__ ) . '/fixtures/sites/subdirectory-core', '--format=json', '--offline' ) );

		$data = json_decode( $output, true );

		$this->assertIsArray( $data, $output );
		$this->assertLessThan( 3, $code, $output );
		$this->assertSame( '6.5.2', $data['site']['wp_version'] );
		$found = array();
		foreach ( $data['findings'] as $finding ) {
			$found[ $finding['id'] ] = $finding['evidence'];
		}
		$this->assertArrayHasKey( 'config.debug.enabled', $found, 'wp-config.php in the web root was not read' );
		$this->assertSame( 'wp/readme.html', $found['config.exposed.files']['path_1'] ?? null, 'The top of the core directory was not listed' );
	}

	public function test_the_script_exits_three_for_a_bad_path(): void {
		list( $code, $output ) = $this->run_bin( array( 'scan', '/nonexistent/example.test' ) );

		$this->assertSame( 3, $code );
		$this->assertStringContainsString( 'does not exist', $output );
	}

	public function test_the_script_is_executable_and_starts_with_a_php_shebang(): void {
		$path = dirname( __DIR__, 2 ) . '/bin/wp-doctor';

		if ( 'Windows' !== PHP_OS_FAMILY ) {
			$this->assertTrue( is_executable( $path ), 'Windows has no executable bit; everywhere else bin/wp-doctor needs it' );
		}
		$this->assertSame( '#!/usr/bin/env php', (string) strtok( (string) file_get_contents( $path ), "\n" ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}
