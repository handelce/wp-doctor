<?php
/**
 * Guards the rule that scanned code is never included or evaluated.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests;

use PHPUnit\Framework\TestCase;

final class No_Site_Code_Execution_Test extends TestCase {

	public function test_no_source_file_includes_requires_or_evaluates_code(): void {
		$forbidden = array( T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_EVAL );
		$files     = array();
		$iterator  = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( dirname( __DIR__ ) . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		$this->assertNotEmpty( $files, 'No source files were found to check.' );

		$violations = array();
		foreach ( $files as $path ) {
			foreach ( token_get_all( (string) file_get_contents( $path ) ) as $token ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( is_array( $token ) && in_array( $token[0], $forbidden, true ) ) {
					$violations[] = basename( $path ) . ':' . $token[2] . ' ' . $token[1];
				}
			}
		}

		$this->assertSame( array(), $violations );
	}

	public function test_the_detector_flags_include_require_and_eval(): void {
		$forbidden = array( T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_EVAL );
		$found     = array();
		foreach ( token_get_all( '<?php include "a"; require_once "b"; eval( "1;" );' ) as $token ) {
			if ( is_array( $token ) && in_array( $token[0], $forbidden, true ) ) {
				$found[] = $token[1];
			}
		}

		$this->assertSame( array( 'include', 'require_once', 'eval' ), $found );
	}
}
