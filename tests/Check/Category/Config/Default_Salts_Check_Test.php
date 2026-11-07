<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Config;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Config\Default_Salts_Check;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Temp_Tree;

final class Default_Salts_Check_Test extends TestCase {

	private const NAMES = array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' );

	/**
	 * Builds define() lines for the eight keys and salts.
	 *
	 * @param array<string, string|null> $overrides Raw PHP value expressions by name. Null removes the name.
	 * @return string
	 */
	private function salts( array $overrides = array() ): string {
		$lines = '';
		foreach ( self::NAMES as $index => $name ) {
			$value = array_key_exists( $name, $overrides ) ? $overrides[ $name ] : "'" . $this->long_value( $index ) . "'";
			if ( null !== $value ) {
				$lines .= "define( '$name', $value );\n";
			}
		}

		return $lines;
	}

	/**
	 * An invented value as long as the ones the WordPress generator gives.
	 *
	 * @param int $index Makes the value distinct.
	 * @return string
	 */
	private function long_value( int $index ): string {
		return str_pad( "fake-unique-value-$index-", 64, 'x' );
	}

	/**
	 * Runs the check against a wp-config.php holding the given statements.
	 *
	 * @param string $config wp-config.php statements.
	 * @return Result
	 */
	private function run_with( string $config ): Result {
		$tree = new Temp_Tree();
		try {
			return ( new Default_Salts_Check() )->run( Context_Factory::for_tree( $tree, $config ) );
		} finally {
			$tree->remove();
		}
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Default_Salts_Check();

		$this->assertSame( 'config.salts.default', $check->id() );
		$this->assertSame( 'config', $check->category() );
	}

	public function test_eight_distinct_values_pass(): void {
		$this->assertTrue( $this->run_with( $this->salts() )->is_pass() );
	}

	public function test_short_distinct_values_are_a_warning_naming_each_without_its_value(): void {
		$short = array();
		foreach ( self::NAMES as $index => $name ) {
			$short[ $name ] = "'changeme$index'";
		}
		$result = $this->run_with( $this->salts( $short ) );

		$findings = $result->finding_list();
		$this->assertCount( 1, $findings );
		$this->assertSame( 'warning', $findings[0]->severity()->name() );
		$this->assertStringContainsString( 'likely', $findings[0]->title() );
		$this->assertCount( 8, $findings[0]->evidence() );
		$this->assertSame( '9 characters, under 32', $findings[0]->evidence()['AUTH_KEY'] );
		$this->assertStringNotContainsString( 'changeme', (string) json_encode( $findings[0]->evidence() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$this->assertNotSame( '', $findings[0]->fix() );
	}

	public function test_a_short_value_next_to_a_placeholder_gives_both_findings(): void {
		$result = $this->run_with(
			$this->salts(
				array(
					'AUTH_KEY'  => "'put your unique phrase here'",
					'NONCE_KEY' => "'short-0001'",
				)
			)
		);

		$findings = $result->finding_list();
		$this->assertCount( 2, $findings );
		$this->assertSame( array( 'AUTH_KEY' => 'still the sample placeholder text' ), $findings[0]->evidence() );
		$this->assertSame( array( 'NONCE_KEY' => '10 characters, under 32' ), $findings[1]->evidence() );
	}

	public function test_the_sample_placeholder_is_critical_and_the_value_is_not_reported(): void {
		$result = $this->run_with( $this->salts( array( 'AUTH_KEY' => "'Put Your Unique Phrase Here'" ) ) );

		$finding = $result->finding_list()[0];
		$this->assertSame( 'critical', $finding->severity()->name() );
		$this->assertSame( array( 'AUTH_KEY' => 'still the sample placeholder text' ), $finding->evidence() );
		$this->assertStringNotContainsString( 'Unique Phrase', (string) json_encode( $finding->evidence() ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public function test_a_name_that_is_not_defined_is_reported(): void {
		$finding = $this->run_with( $this->salts( array( 'NONCE_SALT' => null ) ) )->finding_list()[0];

		$this->assertSame( array( 'NONCE_SALT' => 'not defined in wp-config.php' ), $finding->evidence() );
	}

	public function test_an_empty_value_is_reported(): void {
		$finding = $this->run_with( $this->salts( array( 'NONCE_KEY' => "'  '" ) ) )->finding_list()[0];

		$this->assertSame( array( 'NONCE_KEY' => 'empty' ), $finding->evidence() );
	}

	public function test_a_value_that_is_not_a_string_is_reported_as_empty(): void {
		$finding = $this->run_with( $this->salts( array( 'NONCE_KEY' => 'false' ) ) )->finding_list()[0];

		$this->assertSame( array( 'NONCE_KEY' => 'empty' ), $finding->evidence() );
	}

	public function test_a_repeated_value_names_the_first_constant_that_used_it(): void {
		$finding = $this->run_with( $this->salts( array( 'NONCE_SALT' => "'" . $this->long_value( 0 ) . "'" ) ) )->finding_list()[0];

		$this->assertSame( array( 'NONCE_SALT' => 'same value as AUTH_KEY' ), $finding->evidence() );
	}

	public function test_a_value_that_cannot_be_read_with_nothing_wrong_elsewhere_is_skipped(): void {
		$result = $this->run_with( $this->salts( array( 'AUTH_SALT' => "getenv( 'EXAMPLE_SALT' )" ) ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'AUTH_SALT', (string) $result->message() );
	}

	public function test_a_problem_is_reported_with_the_names_that_could_not_be_read(): void {
		$evidence = $this->run_with(
			$this->salts(
				array(
					'AUTH_SALT' => "getenv( 'EXAMPLE_SALT' )",
					'NONCE_KEY' => "'put your unique phrase here'",
				)
			)
		)->finding_list()[0]->evidence();

		$this->assertSame( 'still the sample placeholder text', $evidence['NONCE_KEY'] );
		$this->assertSame( 'AUTH_SALT', $evidence['not_read'] );
	}

	public function test_a_missing_name_is_not_called_missing_when_wp_config_includes_another_file(): void {
		$result = $this->run_with( $this->salts( array( 'NONCE_SALT' => null ) ) . "require __DIR__ . '/salts.php';\n" );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'NONCE_SALT', (string) $result->message() );
	}
}
