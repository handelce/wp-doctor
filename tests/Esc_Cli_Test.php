<?php
/**
 * Tests for esc_cli().
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Report\Severity;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Report_Entry;
use WP_Doctor\Report\Report;
use WP_Doctor\Report\Redactor;
use WP_Doctor\Report\Redacted_Report;
use WP_Doctor\Report\Formatter\Json_Formatter;
use WP_Doctor\Report\Finding;

use function WP_Doctor\esc_cli;

final class Esc_Cli_Test extends TestCase {

	public function test_plain_text_is_returned_unchanged(): void {
		$this->assertSame( 'wp-content/uploads/2026/a b.jpg', esc_cli( 'wp-content/uploads/2026/a b.jpg' ) );
	}

	public function test_an_ansi_escape_sequence_is_made_visible(): void {
		$escaped = esc_cli( "evil\x1b[2Jname" );

		$this->assertSame( 'evil\x1B[2Jname', $escaped );
		$this->assertStringNotContainsString( "\x1b", $escaped );
	}

	public function test_newlines_tabs_nul_and_delete_are_made_visible(): void {
		$escaped = esc_cli( "a\nb\tc\0d\x7fe" );

		$this->assertSame( 'a\x0Ab\x09c\x00d\x7Fe', $escaped );
	}

	public function test_newlines_can_be_kept_while_other_control_bytes_are_still_made_visible(): void {
		$escaped = esc_cli( "line one\nline\ttwo\x1b[2J\r\x1f\x0b", true );

		$this->assertSame( "line one\nline\\x09two\\x1B[2J\\x0D\\x1F\\x0B", $escaped );
	}

	public function test_a_c1_control_character_is_made_visible(): void {
		$escaped = esc_cli( "a\xc2\x9bb" );

		$this->assertSame( 'a\xC2\x9Bb', $escaped );
	}

	public function test_multibyte_text_is_returned_unchanged(): void {
		$this->assertSame( 'caf' . "\xc3\xa9", esc_cli( 'caf' . "\xc3\xa9" ) );
	}

	/**
	 * Unicode bidirectional controls, which reorder how the text around them is shown.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function bidi_controls(): array {
		return array(
			'LRM' => array( "\xe2\x80\x8e", '\xE2\x80\x8E' ),
			'RLM' => array( "\xe2\x80\x8f", '\xE2\x80\x8F' ),
			'ALM' => array( "\xd8\x9c", '\xD8\x9C' ),
			'LRE' => array( "\xe2\x80\xaa", '\xE2\x80\xAA' ),
			'RLO' => array( "\xe2\x80\xae", '\xE2\x80\xAE' ),
			'LRI' => array( "\xe2\x81\xa6", '\xE2\x81\xA6' ),
			'PDI' => array( "\xe2\x81\xa9", '\xE2\x81\xA9' ),
		);
	}

	/**
	 * @dataProvider bidi_controls
	 * @param string $control Raw control character.
	 * @param string $visible Expected escape.
	 */
	public function test_a_bidi_control_is_made_visible( string $control, string $visible ): void {
		$escaped = esc_cli( "evil{$control}gnp.php" );

		$this->assertSame( "evil{$visible}gnp.php", $escaped );
		$this->assertStringNotContainsString( $control, $escaped );
	}

	public function test_the_bidi_controls_provider_is_not_empty(): void {
		$this->assertCount( 7, $this->bidi_controls() );
	}

	public function test_a_byte_that_is_not_part_of_valid_utf8_is_made_visible(): void {
		$this->assertSame( 'a\x9B31mb', esc_cli( "a\x9b31mb" ) );
		$this->assertSame( 'x\xFFy\xC3', esc_cli( "x\xffy\xc3" ) );
		$this->assertSame( '\xE2\x80z', esc_cli( "\xe2\x80z" ) );
	}

	public function test_valid_multibyte_text_next_to_an_invalid_byte_is_kept(): void {
		$this->assertSame( "\xc3\xa9\\x9B\xe2\x82\xac\xf0\x9f\x98\x80", esc_cli( "\xc3\xa9\x9b\xe2\x82\xac\xf0\x9f\x98\x80" ) );
	}

	/**
	 * @dataProvider invisible_format_characters
	 * @param string $character Invisible character.
	 */
	public function test_an_invisible_format_character_is_made_visible( string $character ): void {
		$escaped = esc_cli( 'wp-config' . $character . '.php' );

		$this->assertStringNotContainsString( $character, $escaped );
		$this->assertSame( 'wp-config\x' . implode( '\x', str_split( strtoupper( bin2hex( $character ) ), 2 ) ) . '.php', $escaped );
	}

	/**
	 * @dataProvider invisible_format_characters
	 * @param string $character Invisible character.
	 */
	public function test_json_output_escapes_the_same_characters_as_esc_cli( string $character ): void {
		$report = new Report(
			'0.1.0-test',
			null,
			array( new Report_Entry( 'a.b', 'security', Result::findings( new Finding( 'a.b', Severity::info(), 'T', array( 'file' => 'x' . $character . 'y' ), 'F' ) ) ) ),
			array()
		);

		$json = ( new Json_Formatter() )->format( new Redacted_Report( $report, new Redactor() ) );

		$this->assertStringNotContainsString( $character, $json );
		$this->assertSame( 'x' . $character . 'y', json_decode( $json, true )['findings'][0]['evidence']['file'] );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function invisible_format_characters(): array {
		return array(
			'soft hyphen'             => array( "\u{00AD}" ),
			'arabic letter mark'      => array( "\u{061C}" ),
			'zero width space'        => array( "\u{200B}" ),
			'zero width non-joiner'   => array( "\u{200C}" ),
			'zero width joiner'       => array( "\u{200D}" ),
			'left-to-right mark'      => array( "\u{200E}" ),
			'line separator'          => array( "\u{2028}" ),
			'paragraph separator'     => array( "\u{2029}" ),
			'right-to-left override'  => array( "\u{202E}" ),
			'word joiner'             => array( "\u{2060}" ),
			'invisible plus'          => array( "\u{2064}" ),
			'first strong isolate'    => array( "\u{2068}" ),
			'byte order mark'         => array( "\u{FEFF}" ),
			'next line, a C1 control' => array( "\u{0085}" ),
		);
	}

	public function test_the_invisible_format_characters_provider_is_not_empty(): void {
		$this->assertCount( 14, $this->invisible_format_characters() );
	}

	public function test_visible_neighbours_of_the_invisible_ranges_are_kept(): void {
		$text = "\u{00AC}\u{00AE}\u{2010}\u{2027}\u{2030}\u{205F}\u{2070}\u{FEFE}";

		$this->assertSame( $text, esc_cli( $text ) );
	}
}
