<?php
/**
 * Tests for Argument_Parser.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Cli;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Cli\Argument_Parser;
use WP_Doctor\Cli\Cli_Options;
use WP_Doctor\Cli\Usage_Exception;

final class Argument_Parser_Test extends TestCase {

	private function parse( string ...$args ): Cli_Options {
		return ( new Argument_Parser() )->parse( $args );
	}

	public function test_scan_with_a_path_uses_the_defaults(): void {
		$options = $this->parse( 'scan', '/var/www/example.test' );

		$this->assertSame( Cli_Options::SCAN, $options->command() );
		$this->assertSame( '/var/www/example.test', $options->path() );
		$this->assertSame( 'text', $options->format() );
		$this->assertFalse( $options->offline() );
		$this->assertNull( $options->config_path() );
		$this->assertFalse( $options->db_from_env() );
		$this->assertSame( 200000, $options->max_files() );
		$this->assertSame( array(), $options->excludes() );
		$this->assertFalse( $options->redact_emails() );
		$this->assertNull( $options->colour() );
	}

	public function test_every_option_can_be_given(): void {
		$options = $this->parse(
			'scan',
			'--format=json',
			'--offline',
			'--config=/tmp/wp-doctor.json',
			'--db-from-env',
			'--max-files=500',
			'--exclude=wp-content/cache/**',
			'--exclude',
			'*.log',
			'--redact-emails',
			'--no-color',
			'site'
		);

		$this->assertSame( 'json', $options->format() );
		$this->assertTrue( $options->offline() );
		$this->assertSame( '/tmp/wp-doctor.json', $options->config_path() );
		$this->assertTrue( $options->db_from_env() );
		$this->assertSame( 500, $options->max_files() );
		$this->assertSame( array( 'wp-content/cache/**', '*.log' ), $options->excludes() );
		$this->assertTrue( $options->redact_emails() );
		$this->assertFalse( $options->colour() );
		$this->assertSame( 'site', $options->path() );
	}

	public function test_a_value_can_follow_the_option_as_a_separate_argument(): void {
		$options = $this->parse( 'scan', 'site', '--format', 'json', '--max-files', '9' );

		$this->assertSame( 'json', $options->format() );
		$this->assertSame( 9, $options->max_files() );
	}

	public function test_color_forces_colour_on(): void {
		$this->assertTrue( $this->parse( 'scan', 'site', '--color' )->colour() );
	}

	public function test_version_and_help_need_no_path(): void {
		$this->assertSame( Cli_Options::VERSION, $this->parse( '--version' )->command() );
		$this->assertSame( Cli_Options::HELP, $this->parse( '--help' )->command() );
		$this->assertSame( Cli_Options::HELP, $this->parse( '-h' )->command() );
		$this->assertSame( Cli_Options::VERSION, $this->parse( '-V' )->command() );
	}

	/**
	 * @dataProvider help_or_version_with_other_arguments
	 * @param string[] $args    Command line after the script name.
	 * @param string   $command Command the parser must choose.
	 */
	public function test_help_and_version_win_over_other_and_invalid_arguments( array $args, string $command ): void {
		$this->assertSame( $command, $this->parse( ...$args )->command() );
	}

	/**
	 * @return array<string, array{0: string[], 1: string}>
	 */
	public function help_or_version_with_other_arguments(): array {
		return array(
			'help with a bad format'      => array( array( '--help', '--format=xml' ), Cli_Options::HELP ),
			'version with an unknown one' => array( array( '--version', '--bogus' ), Cli_Options::VERSION ),
			'help after a scan command'   => array( array( 'scan', 'site', '--max-files=0', '-h' ), Cli_Options::HELP ),
			'version with a stray value'  => array( array( '-V', '--format' ), Cli_Options::VERSION ),
			'help before version'         => array( array( '--version', '--help' ), Cli_Options::HELP ),
		);
	}

	public function test_the_help_or_version_provider_is_not_empty(): void {
		$this->assertCount( 5, $this->help_or_version_with_other_arguments() );
	}

	public function test_help_after_double_dash_is_a_path_not_a_request_for_help(): void {
		$this->assertSame( '--help', $this->parse( 'scan', '--', '--help' )->path() );
	}

	public function test_an_argument_after_double_dash_is_a_path_even_if_it_looks_like_an_option(): void {
		$this->assertSame( '--odd', $this->parse( 'scan', '--', '--odd' )->path() );
	}

	/**
	 * @dataProvider bad_arguments
	 * @param string[] $args     Command line after the script name.
	 * @param string   $fragment Text the message must contain.
	 */
	public function test_bad_arguments_are_rejected_with_a_reason( array $args, string $fragment ): void {
		try {
			( new Argument_Parser() )->parse( $args );
			$this->fail( 'Accepted: ' . implode( ' ', $args ) );
		} catch ( Usage_Exception $e ) {
			$this->assertStringContainsString( $fragment, $e->getMessage() );
		}
	}

	/**
	 * @return array<string, array<int, mixed>>
	 */
	public function bad_arguments(): array {
		return array(
			'no arguments'              => array( array(), 'command' ),
			'unknown command'           => array( array( 'fix', 'site' ), 'Unknown command' ),
			'scan without a path'       => array( array( 'scan' ), 'path' ),
			'two paths'                 => array( array( 'scan', 'a', 'b' ), 'one path' ),
			'unknown option'            => array( array( 'scan', 'site', '--verbose' ), '--verbose' ),
			'unknown short option'      => array( array( 'scan', 'site', '-x' ), '-x' ),
			'bad format'                => array( array( 'scan', 'site', '--format=xml' ), 'format' ),
			'unknown format'            => array( array( 'scan', 'site', '--format=xml' ), 'format' ),
			'format without a value'    => array( array( 'scan', 'site', '--format' ), 'requires a value' ),
			'format value is an option' => array( array( 'scan', 'site', '--format', '--offline' ), 'requires a value' ),
			'config without a value'    => array( array( 'scan', 'site', '--config=' ), 'requires a value' ),
			'zero max files'            => array( array( 'scan', 'site', '--max-files=0' ), 'max-files' ),
			'negative max files'        => array( array( 'scan', 'site', '--max-files=-5' ), 'max-files' ),
			'non-numeric max files'     => array( array( 'scan', 'site', '--max-files=many' ), 'max-files' ),
			'flag with a value'         => array( array( 'scan', 'site', '--offline=yes' ), 'does not take a value' ),
			'color and no-color'        => array( array( 'scan', 'site', '--color', '--no-color' ), 'together' ),
			'empty exclude'             => array( array( 'scan', 'site', '--exclude=' ), 'requires a value' ),
		);
	}

	public function test_the_bad_argument_provider_is_not_empty(): void {
		$this->assertGreaterThan( 10, count( $this->bad_arguments() ) );
	}

	/**
	 * @dataProvider unmatchable_excludes
	 * @param string $glob     Exclude that can never match a path relative to the site root.
	 * @param string $fragment Expected part of the message.
	 */
	public function test_an_exclude_that_can_never_match_is_refused( string $glob, string $fragment ): void {
		$this->expectException( Usage_Exception::class );
		$this->expectExceptionMessage( $fragment );
		$this->parse( 'scan', '/srv/example', "--exclude=$glob" );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function unmatchable_excludes(): array {
		return array(
			'absolute'        => array( '/etc', 'is an absolute path' ),
			'windows drive'   => array( 'C:\\site\\cache', 'is an absolute path' ),
			'parent'          => array( '../x', 'climbs out of the site root' ),
			'parent inside'   => array( 'wp-content/../../x', 'climbs out of the site root' ),
			'dot slash start' => array( './wp-content/cache/**', 'starts with ./' ),
		);
	}

	public function test_the_unmatchable_excludes_provider_is_not_empty(): void {
		$this->assertCount( 5, $this->unmatchable_excludes() );
	}

	public function test_a_relative_exclude_with_dots_in_names_is_accepted(): void {
		$this->assertSame( array( 'wp-content/cache..old/**', '.git' ), $this->parse( 'scan', '/srv/example', '--exclude=wp-content/cache..old/**', '--exclude=.git' )->excludes() );
	}
}
