<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Util;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Util\File_Headers;

final class File_Headers_Test extends TestCase {

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function headed_files(): array {
		return array(
			'docblock'              => array( "<?php\n/**\n * Plugin Name: Example Tools\n * Version: 1.0\n */", 'Example Tools' ),
			'line comment'          => array( "<?php\n// Plugin Name: Example Tools", 'Example Tools' ),
			'on the opening line'   => array( '<?php /* Plugin Name: Example Tools */', 'Example Tools' ),
			'hash comment'          => array( "<?php\n# Plugin Name: Example Tools\n", 'Example Tools' ),
			'other case'            => array( "<?php\n/*\nplugin name: Example Tools\n*/", 'Example Tools' ),
			'closing tag trimmed'   => array( "<?php // Plugin Name: Example Tools ?>\n", 'Example Tools' ),
			'carriage returns only' => array( "<?php\r/*\r * Plugin Name: Example Tools\r */", 'Example Tools' ),
			'stylesheet'            => array( "/*\nTheme Name: Example\nTemplate: parent-theme\n*/", 'Example' ),
		);
	}

	/**
	 * @dataProvider headed_files
	 * @param string $text     Start of the file.
	 * @param string $expected Value WordPress reads.
	 */
	public function test_a_header_is_read_as_wordpress_reads_it( string $text, string $expected ): void {
		$name = false === strpos( $text, 'Theme Name' ) ? 'Plugin Name' : 'Theme Name';

		$this->assertSame( $expected, File_Headers::value( $text, $name ) );
	}

	public function test_the_provider_has_samples(): void {
		$this->assertGreaterThan( 5, count( $this->headed_files() ) );
	}

	public function test_a_missing_or_blank_header_is_empty(): void {
		$this->assertSame( '', File_Headers::value( "<?php\n// Description: no name here\n", 'Plugin Name' ) );
		$this->assertSame( '', File_Headers::value( "<?php\n/*\n * Plugin Name:\n */", 'Plugin Name' ) );
		$this->assertSame( '', File_Headers::value( "<?php\n/*\n * Plugin Name:   */", 'Plugin Name' ) );
	}

	public function test_a_header_inside_code_is_not_a_header(): void {
		$this->assertSame( '', File_Headers::value( "<?php\n\$label = 'Plugin Name: Example';\n", 'Plugin Name' ) );
	}

	public function test_a_header_after_the_first_8_kib_is_not_read(): void {
		$text = "<?php\n" . str_repeat( "// padding\n", 800 ) . "// Plugin Name: Late\n";

		$this->assertGreaterThan( File_Headers::READ_BYTES, strpos( $text, 'Plugin Name' ) );
		$this->assertSame( '', File_Headers::value( $text, 'Plugin Name' ) );
	}
}
