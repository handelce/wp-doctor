<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Config;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Config\Php_Settings_Check;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Temp_Tree;

final class Php_Settings_Check_Test extends TestCase {

	/**
	 * Runs the check against a wp-config.php holding the given statements and the given root files.
	 *
	 * @param string                $config wp-config.php statements.
	 * @param array<string, string> $files  Root files by name.
	 * @return Result
	 */
	private function run_with( string $config, array $files = array() ): Result {
		$tree = new Temp_Tree();
		try {
			foreach ( $files as $name => $content ) {
				$tree->file( $name, $content );
			}

			return ( new Php_Settings_Check() )->run( Context_Factory::for_tree( $tree, $config ) );
		} finally {
			$tree->remove();
		}
	}

	/**
	 * Findings keyed by the setting in their evidence.
	 *
	 * @param Result $result Result with findings.
	 * @return array<string, Finding>
	 */
	private function by_setting( Result $result ): array {
		$by = array();
		foreach ( $result->finding_list() as $finding ) {
			$by[ (string) $finding->evidence()['setting'] ] = $finding;
		}
		$this->assertNotEmpty( $by );

		return $by;
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Php_Settings_Check();

		$this->assertSame( 'config.php.settings', $check->id() );
		$this->assertSame( 'config', $check->category() );
	}

	/**
	 * @dataProvider enough_memory
	 * @param string $value Raw PHP expression for the limit.
	 */
	public function test_a_memory_limit_of_sixty_four_mebibytes_or_more_passes( string $value ): void {
		$this->assertTrue( $this->run_with( "define( 'WP_MEMORY_LIMIT', $value );" )->is_pass() );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function enough_memory(): array {
		return array(
			'64M'       => array( "'64M'" ),
			'256M'      => array( "'256M'" ),
			'1G'        => array( "'1G'" ),
			'unlimited' => array( "'-1'" ),
			'bytes'     => array( '134217728' ),
			'lowercase' => array( "'128m'" ),
		);
	}

	/**
	 * @dataProvider too_little_memory
	 * @param string $value Raw PHP expression for the limit.
	 * @param int    $bytes Expected bytes.
	 */
	public function test_a_lower_memory_limit_is_an_info_finding( string $value, int $bytes ): void {
		$finding = $this->by_setting( $this->run_with( "define( 'WP_MEMORY_LIMIT', $value );" ) )['WP_MEMORY_LIMIT'];

		$this->assertSame( 'info', $finding->severity()->name() );
		$this->assertSame( $bytes, $finding->evidence()['bytes'] );
		$this->assertSame( 'wp-config.php', $finding->evidence()['source'] );
		$this->assertStringContainsString( 'likely', $finding->title() );
		$this->assertStringContainsString( 'default', (string) $finding->evidence()['threshold_basis'] );
		$this->assertStringContainsString( 'web server', (string) $finding->evidence()['not_examined'] );
		$this->assertNotSame( '', $finding->fix() );
	}

	/**
	 * @return array<string, array<int, mixed>>
	 */
	public function too_little_memory(): array {
		return array(
			'32M' => array( "'32M'", 33554432 ),
			'40M' => array( "'40M'", 41943040 ),
			'63M' => array( "'63M'", 66060288 ),
			'64K' => array( "'64K'", 65536 ),
			'int' => array( '1048576', 1048576 ),
		);
	}

	public function test_the_memory_providers_are_not_empty(): void {
		$this->assertNotEmpty( $this->enough_memory() );
		$this->assertNotEmpty( $this->too_little_memory() );
	}

	public function test_nothing_set_anywhere_is_skipped_not_passed(): void {
		$result = $this->run_with( '' );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'web server', (string) $result->message() );
	}

	public function test_a_limit_that_cannot_be_read_is_skipped_with_the_reason(): void {
		$result = $this->run_with( "define( 'WP_MEMORY_LIMIT', getenv( 'X' ) );" );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'could not be read', (string) $result->message() );
		$this->assertStringContainsString( 'computed value', (string) $result->message() );
	}

	public function test_a_value_that_is_not_a_size_is_skipped(): void {
		$this->assertSame( Result::SKIPPED, $this->run_with( "define( 'WP_MEMORY_LIMIT', 'lots' );" )->status() );
	}

	/**
	 * @dataProvider display_errors_on
	 * @param string $value PHP expression passed to ini_set().
	 */
	public function test_display_errors_switched_on_with_ini_set_is_a_warning( string $value ): void {
		$finding = $this->by_setting( $this->run_with( "ini_set( 'display_errors', $value );" ) )['display_errors'];

		$this->assertSame( 'warning', $finding->severity()->name() );
		$this->assertStringContainsString( 'likely', $finding->title() );
		$this->assertSame( 'ini_set() in wp-config.php', $finding->evidence()['source'] );
		$this->assertStringContainsString( 'display_errors', $finding->fix() );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function display_errors_on(): array {
		return array(
			'string one' => array( "'1'" ),
			'int one'    => array( '1' ),
			'On'         => array( "'On'" ),
			'true'       => array( 'true' ),
			'stdout'     => array( "'stdout'" ),
		);
	}

	public function test_the_display_errors_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->display_errors_on() );
	}

	public function test_display_errors_switched_off_passes(): void {
		$this->assertTrue( $this->run_with( "ini_set( 'display_errors', '0' );" )->is_pass() );
		$this->assertTrue( $this->run_with( "ini_set( 'display_errors', 'stderr' );" )->is_pass() );
		$this->assertTrue( $this->run_with( "ini_set( 'display_errors', 'STDERR' );" )->is_pass() );
		$this->assertTrue( $this->run_with( "ini_set( 'display_errors', false );" )->is_pass() );
	}

	public function test_settings_in_user_ini_are_judged_and_named_as_the_source(): void {
		$result = $this->run_with(
			'',
			array( '.user.ini' => "; site settings\ndisplay_errors = On\nmemory_limit = 32M\nmax_execution_time = 10\n" )
		);

		$findings = $this->by_setting( $result );
		$this->assertSame( array( 'display_errors', 'memory_limit', 'max_execution_time' ), array_keys( $findings ) );
		$this->assertSame( '.user.ini', $findings['memory_limit']->evidence()['source'] );
		$this->assertSame( 33554432, $findings['memory_limit']->evidence()['bytes'] );
		$this->assertSame( 'info', $findings['memory_limit']->severity()->name() );
		$this->assertSame( 10, $findings['max_execution_time']->evidence()['seconds'] );
		$this->assertSame( 'info', $findings['max_execution_time']->severity()->name() );
	}

	public function test_a_php_ini_with_a_section_is_read_and_user_ini_overrides_it(): void {
		$result = $this->run_with(
			'',
			array(
				'php.ini'   => "[PHP]\ndisplay_errors = On\nmax_execution_time = 5\n",
				'.user.ini' => "display_errors = Off\n",
			)
		);

		$findings = $this->by_setting( $result );
		$this->assertArrayNotHasKey( 'display_errors', $findings );
		$this->assertSame( 'php.ini', $findings['max_execution_time']->evidence()['source'] );
	}

	public function test_ini_set_overrides_the_ini_files(): void {
		$result = $this->run_with(
			"ini_set( 'display_errors', '0' );",
			array( '.user.ini' => "display_errors = On\n" )
		);

		$this->assertTrue( $result->is_pass() );
	}

	public function test_an_unlimited_or_zero_time_and_a_generous_limit_pass(): void {
		$result = $this->run_with(
			'',
			array( '.user.ini' => "memory_limit = -1\nmax_execution_time = 0\n" )
		);

		$this->assertTrue( $result->is_pass() );
		$this->assertTrue( $this->run_with( '', array( '.user.ini' => "max_execution_time = 30\n" ) )->is_pass() );
	}

	public function test_a_fine_value_next_to_an_unreadable_one_is_skipped_saying_both(): void {
		$result = $this->run_with( "ini_set( 'display_errors', '0' );\nini_set( 'memory_limit', getenv( 'M' ) );" );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'display_errors from ini_set() in wp-config.php', (string) $result->message() );
		$this->assertStringContainsString( 'memory_limit', (string) $result->message() );
		$this->assertStringContainsString( 'could not be read', (string) $result->message() );
	}

	public function test_an_ini_file_that_does_not_parse_is_reported_not_guessed(): void {
		$result = $this->run_with( '', array( '.user.ini' => "display_errors = On\n= value\n" ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( '.user.ini', (string) $result->message() );
		$this->assertStringContainsString( 'could not be parsed', (string) $result->message() );
	}
}
