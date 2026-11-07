<?php
/**
 * Guards the PHP 7.2 syntax floor.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests;

use PHPUnit\Framework\TestCase;

final class Php_Floor_Test extends TestCase {

	private const NEWER_TOKENS = array( 'T_FN', 'T_MATCH', 'T_NULLSAFE_OBJECT_OPERATOR', 'T_ENUM', 'T_READONLY', 'T_ATTRIBUTE' );

	/**
	 * @dataProvider newer_snippets
	 * @param string $token   Token constant that PHP gives the syntax.
	 * @param string $snippet Source using the syntax.
	 */
	public function test_the_detector_flags_syntax_newer_than_php_7_2( string $token, string $snippet ): void {
		if ( ! defined( $token ) ) {
			$this->markTestSkipped( "This PHP version cannot tokenize $token; the sources are checked on versions that can." );
		}

		$this->assertContains( $token, $this->newer_syntax_in( $snippet ) );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function newer_snippets(): array {
		return array(
			'arrow function' => array( 'T_FN', '<?php $f = fn( $x ) => $x;' ),
			'nullsafe'       => array( 'T_NULLSAFE_OBJECT_OPERATOR', '<?php $a?->b;' ),
			'match'          => array( 'T_MATCH', '<?php $a = match ( $x ) { 1 => 2 };' ),
		);
	}

	public function test_the_detector_accepts_syntax_that_php_7_2_parses(): void {
		$this->assertSame( array(), $this->newer_syntax_in( '<?php $f = function ( $x ) { return $x; }; $a = $b ?? 1; $c = array( 1, 2 );' ) );
	}

	public function test_no_source_file_uses_syntax_newer_than_php_7_2(): void {
		$root  = dirname( __DIR__ );
		$files = array();
		foreach ( array( 'src', 'bin', 'tests' ) as $dir ) {
			if ( ! is_dir( "$root/$dir" ) ) {
				continue;
			}
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( "$root/$dir", \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( 'php' === $file->getExtension() || false !== strpos( (string) file_get_contents( $file->getPathname(), false, null, 0, 64 ), '#!/usr/bin/env php' ) ) {
					$files[] = $file->getPathname();
				}
			}
		}

		$this->assertNotEmpty( $files, 'No PHP files were found to check.' );

		$violations = array();
		foreach ( $files as $path ) {
			foreach ( $this->newer_syntax_in( (string) file_get_contents( $path ) ) as $name ) {
				$violations[] = basename( $path ) . ': ' . $name;
			}
		}

		$this->assertSame( array(), $violations );
	}

	public function test_the_function_list_is_loaded_and_holds_known_entries(): void {
		$functions = $this->functions_after_7_2();

		$this->assertGreaterThan( 50, count( $functions ) );
		$this->assertSame( '8.0', $functions['str_contains'] );
		$this->assertSame( '8.1', $functions['array_is_list'] );
		$this->assertSame( '8.4', $functions['array_find'] );
		$this->assertArrayNotHasKey( 'printf', $functions );
	}

	/**
	 * @dataProvider newer_calls
	 * @param string $code     PHP source with one such call.
	 * @param string $expected Call the detector must report.
	 */
	public function test_the_detector_flags_a_call_to_a_function_newer_than_php_7_2( string $code, string $expected ): void {
		$this->assertSame( array( $expected ), $this->newer_calls_in( $code ) );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function newer_calls(): array {
		return array(
			'plain call'     => array( '<?php if ( str_contains( $a, "b" ) ) {}', 'str_contains (8.0)' ),
			'qualified call' => array( '<?php $x = \\array_is_list( $a );', 'array_is_list (8.1)' ),
			'mixed case'     => array( '<?php $x = Json_Validate( $a );', 'json_validate (8.3)' ),
			'space before (' => array( '<?php $x = array_find ( $a, $f );', 'array_find (8.4)' ),
		);
	}

	public function test_the_newer_calls_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->newer_calls() );
	}

	public function test_the_detector_ignores_methods_strings_and_declarations_of_the_same_name(): void {
		$code = '<?php $o->str_contains( 1 ); Foo::array_is_list( 2 ); $s = "str_contains( x )"; function_exists( \'str_contains\' );'
			. ' function str_starts_with( $a ) {} $n = new json_validate();';

		$this->assertSame( array(), $this->newer_calls_in( $code ) );
	}

	public function test_no_source_file_calls_a_function_newer_than_php_7_2(): void {
		$root  = dirname( __DIR__ );
		$files = array();
		foreach ( array( 'src', 'bin', 'tools', 'tests' ) as $dir ) {
			if ( ! is_dir( "$root/$dir" ) ) {
				continue;
			}
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( "$root/$dir", \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( false !== strpos( $file->getPathname(), '/tests/fixtures/' ) ) {
					continue;
				}
				if ( 'php' === $file->getExtension() || false !== strpos( (string) file_get_contents( $file->getPathname(), false, null, 0, 64 ), '#!/usr/bin/env php' ) ) {
					$files[] = $file->getPathname();
				}
			}
		}
		$this->assertGreaterThan( 100, count( $files ), 'Too few PHP files were found to check.' );

		$violations = array();
		foreach ( $files as $path ) {
			foreach ( $this->newer_calls_in( (string) file_get_contents( $path ) ) as $call ) {
				$violations[] = substr( $path, strlen( $root ) + 1 ) . ': ' . $call;
			}
		}

		$this->assertSame( array(), $violations );
	}

	/**
	 * Functions added after PHP 7.2, by lowercase name, with the version that added them.
	 *
	 * @return array<string, string>
	 */
	private function functions_after_7_2(): array {
		$functions = array();
		foreach ( (array) file( __DIR__ . '/fixtures/php-functions-after-7.2.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
			if ( '#' === $line[0] ) {
				continue;
			}
			list( $version, $name )           = explode( ' ', $line );
			$functions[ strtolower( $name ) ] = $version;
		}

		return $functions;
	}

	/**
	 * Calls in the code to global functions added after PHP 7.2.
	 *
	 * @param string $code PHP source.
	 * @return string[] Each as "name (version)".
	 */
	private function newer_calls_in( string $code ): array {
		$functions = $this->functions_after_7_2();
		$names     = array( T_STRING );
		if ( defined( 'T_NAME_FULLY_QUALIFIED' ) ) {
			$names[] = constant( 'T_NAME_FULLY_QUALIFIED' );
		}
		$skip   = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT );
		$tokens = array_values(
			array_filter(
				token_get_all( $code ),
				static function ( $token ) use ( $skip ): bool {
					return ! is_array( $token ) || ! in_array( $token[0], $skip, true );
				}
			)
		);
		$found  = array();
		foreach ( $tokens as $i => $token ) {
			if ( ! is_array( $token ) || ! in_array( $token[0], $names, true ) ) {
				continue;
			}
			$name = strtolower( ltrim( $token[1], '\\' ) );
			if ( ! isset( $functions[ $name ] ) || ! isset( $tokens[ $i + 1 ] ) || '(' !== $tokens[ $i + 1 ] ) {
				continue;
			}
			$before = $tokens[ $i - 1 ] ?? null;
			if ( is_array( $before ) && in_array( $before[0], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW ), true ) ) {
				continue;
			}
			$found[] = $name . ' (' . $functions[ $name ] . ')';
		}

		return $found;
	}

	/**
	 * Names of tokens in the code that PHP 7.2 cannot parse.
	 *
	 * @param string $code PHP source.
	 * @return string[]
	 */
	private function newer_syntax_in( string $code ): array {
		$found = array();
		foreach ( self::NEWER_TOKENS as $name ) {
			if ( ! defined( $name ) ) {
				continue;
			}
			foreach ( token_get_all( $code ) as $token ) {
				if ( is_array( $token ) && constant( $name ) === $token[0] ) {
					$found[] = $name;
				}
			}
		}

		return $found;
	}
}
