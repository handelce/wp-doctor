<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Config;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Config\Php_Version_Check;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\File_Modes;
use WP_Doctor\Tests\Support\Temp_Tree;

final class Php_Version_Check_Test extends TestCase {

	/**
	 * Runs the check for a PHP version at a given date.
	 *
	 * @param string $version PHP version.
	 * @param string $date    Date and time, UTC.
	 * @return Result
	 */
	private function run_for( string $version, string $date ): Result {
		return ( new Php_Version_Check( $version, (int) strtotime( $date . ' UTC' ) ) )->run( Context_Factory::without_database() );
	}

	/**
	 * Runs the check on a site tree holding the given root files, at a given date.
	 *
	 * @param array<string, string> $files    Files by relative path.
	 * @param string                $date     Date and time, UTC.
	 * @param string                $cli      Version of the PHP running wp-doctor.
	 * @return Result
	 */
	private function run_on_tree( array $files, string $date = '2026-11-04 12:00:00', string $cli = '8.3.4' ): Result {
		$tree = new Temp_Tree();
		try {
			foreach ( $files as $path => $content ) {
				$tree->file( $path, $content );
			}

			return ( new Php_Version_Check( $cli, (int) strtotime( $date . ' UTC' ) ) )->run( Context_Factory::for_tree( $tree ) );
		} finally {
			$tree->remove();
		}
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Php_Version_Check();

		$this->assertSame( 'config.php.version', $check->id() );
		$this->assertSame( 'config', $check->category() );
	}

	public function test_a_version_still_in_security_support_is_skipped_not_passed(): void {
		$result = $this->run_for( '8.3.4', '2026-11-04 12:00:00' );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( '8.3', (string) $result->message() );
		$this->assertStringContainsString( 'web server', (string) $result->message() );
	}

	public function test_the_last_day_of_support_is_skipped(): void {
		$this->assertSame( Result::SKIPPED, $this->run_for( '8.2.1', '2026-12-31 23:59:00' )->status() );
	}

	public function test_the_day_after_support_ends_is_a_warning(): void {
		$finding = $this->run_for( '8.2.1', '2027-01-01 00:00:00' )->finding_list()[0];

		$this->assertSame( 'warning', $finding->severity()->name() );
		$this->assertSame( '8.2.1', $finding->evidence()['php_version'] );
		$this->assertSame( '8.2', $finding->evidence()['php_minor'] );
		$this->assertStringContainsString( 'likely', $finding->title() );
		$this->assertSame( '2026-12-31', $finding->evidence()['security_support_ended'] );
		$this->assertStringContainsString( 'web server', (string) $finding->evidence()['measured_on'] );
		$this->assertNotSame( '', $finding->fix() );
	}

	/**
	 * @dataProvider ended_versions
	 * @param string $version PHP version.
	 */
	public function test_every_version_past_its_end_is_reported( string $version ): void {
		$this->assertSame( Result::FINDINGS, $this->run_for( $version, '2026-11-04 00:00:00' )->status() );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function ended_versions(): array {
		return array(
			'5.6' => array( '5.6.40' ),
			'7.0' => array( '7.0.33' ),
			'7.2' => array( '7.2.34' ),
			'7.4' => array( '7.4.33' ),
			'8.0' => array( '8.0.30' ),
			'8.1' => array( '8.1.27' ),
			'5.2' => array( '5.2.17' ),
		);
	}

	public function test_the_ended_versions_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->ended_versions() );
	}

	public function test_php_seven_zero_is_supported_through_the_tenth_of_january_2019(): void {
		$this->assertSame( Result::SKIPPED, $this->run_for( '7.0.33', '2019-01-10 23:59:00' )->status() );
		$this->assertSame( Result::FINDINGS, $this->run_for( '7.0.33', '2019-01-11 00:00:00' )->status() );
	}

	public function test_php_eight_five_is_supported_through_the_end_of_2029(): void {
		$this->assertSame( Result::SKIPPED, $this->run_for( '8.5.0', '2029-12-31 23:59:00' )->status() );
		$this->assertSame( Result::FINDINGS, $this->run_for( '8.5.0', '2030-01-01 00:00:00' )->status() );
	}

	public function test_a_version_newer_than_the_table_is_skipped_not_passed(): void {
		$result = $this->run_for( '9.7.0', '2026-11-04 00:00:00' );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( '9.7', (string) $result->message() );
	}

	public function test_a_version_string_that_cannot_be_read_is_skipped(): void {
		$this->assertSame( Result::SKIPPED, $this->run_for( 'unknown', '2026-11-04 00:00:00' )->status() );
	}

	public function test_a_version_with_a_suffix_is_read_by_its_minor_version(): void {
		$this->assertStringContainsString( 'PHP 8.3,', (string) $this->run_for( '8.3.0-dev', '2026-11-04 00:00:00' )->message() );
	}

	public function test_the_running_php_is_judged_when_no_version_is_given(): void {
		$result = ( new Php_Version_Check() )->run( Context_Factory::without_database() );

		$this->assertContains( $result->status(), array( Result::FINDINGS, Result::SKIPPED ) );
	}

	/**
	 * @dataProvider handler_lines
	 * @param string $line    .htaccess handler line.
	 * @param string $version Version it names.
	 */
	public function test_an_htaccess_handler_line_names_the_web_servers_php( string $line, string $version ): void {
		$result = $this->run_on_tree( array( '.htaccess' => "# BEGIN host\n$line\n# END host\n" ), '2026-11-04 12:00:00' );

		if ( version_compare( $version, '8.2', '>=' ) ) {
			$this->assertTrue( $result->is_pass(), $line );
			return;
		}
		$finding = $result->finding_list()[0];
		$this->assertSame( 'warning', $finding->severity()->name() );
		$this->assertSame( $version, $finding->evidence()['php_minor'] );
		$this->assertSame( trim( $line ), $finding->evidence()['htaccess_line'] );
		$this->assertStringContainsString( '.htaccess', (string) $finding->evidence()['measured_on'] );
		$this->assertArrayNotHasKey( 'php_version', $finding->evidence() );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function handler_lines(): array {
		return array(
			'cPanel EasyApache'   => array( 'AddHandler application/x-httpd-ea-php74 .php .php7 .phtml', '7.4' ),
			'cPanel with lsphp'   => array( 'AddHandler application/x-httpd-ea-php81___lsphp .php .php8 .phtml', '8.1' ),
			'SetHandler'          => array( '  SetHandler application/x-httpd-ea-php82', '8.2' ),
			'dotted version'      => array( 'AddHandler application/x-httpd-php8.3 .php', '8.3' ),
			'LiteSpeed'           => array( 'AddType application/x-lsphp80 .php', '8.0' ),
			'CloudLinux alt-php'  => array( 'AddHandler application/x-httpd-alt-php72___lsphp .php', '7.2' ),
			'lowercase directive' => array( 'addhandler application/x-httpd-php56 .php', '5.6' ),
		);
	}

	public function test_the_handler_lines_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->handler_lines() );
	}

	public function test_an_htaccess_without_a_version_falls_back_to_the_running_php(): void {
		$result = $this->run_on_tree( array( '.htaccess' => "RewriteEngine On\nAddHandler application/x-httpd-php .php\nAddHandler application/x-httpd-php8 .php\n" ), '2026-11-04 12:00:00', '7.4.33' );

		$finding = $result->finding_list()[0];
		$this->assertSame( '7.4.33', $finding->evidence()['php_version'] );
		$this->assertStringContainsString( 'wp-doctor', (string) $finding->evidence()['measured_on'] );
	}

	public function test_conflicting_handler_lines_are_skipped_with_both_versions(): void {
		$result = $this->run_on_tree( array( '.htaccess' => "AddHandler application/x-httpd-ea-php74 .php\nSetHandler application/x-httpd-ea-php82\n" ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( '7.4', (string) $result->message() );
		$this->assertStringContainsString( '8.2', (string) $result->message() );
	}

	public function test_a_hinted_version_newer_than_the_table_is_skipped(): void {
		$result = $this->run_on_tree( array( '.htaccess' => 'AddHandler application/x-httpd-ea-php97 .php' ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( '9.7', (string) $result->message() );
	}

	public function test_the_hinted_version_is_judged_not_the_running_one(): void {
		$in_support = $this->run_on_tree( array( '.htaccess' => 'AddHandler application/x-httpd-ea-php84 .php' ), '2026-11-04 12:00:00', '7.2.34' );
		$ended      = $this->run_on_tree( array( '.htaccess' => 'AddHandler application/x-httpd-ea-php74 .php' ), '2026-11-04 12:00:00', '8.4.0' );

		$this->assertTrue( $in_support->is_pass() );
		$this->assertSame( Result::FINDINGS, $ended->status() );
	}

	public function test_plugins_requiring_more_than_the_hinted_php_are_an_info_finding(): void {
		$result = $this->run_on_tree(
			array(
				'.htaccess'                               => 'AddHandler application/x-httpd-ea-php82 .php',
				'wp-content/plugins/needs-new/needs-new.php' => "<?php\n/**\n * Plugin Name: Needs New\n * Requires PHP: 8.3\n */\n",
				'wp-content/plugins/needs-new/helper.php' => "<?php\n// Requires PHP: 9.9 in a comment of a non-plugin file\n",
				'wp-content/plugins/fine/fine.php'        => "<?php\n/*\nPlugin Name: Fine\nRequires PHP: 8.2.1\n*/\n",
				'wp-content/plugins/old/old.php'          => "<?php\n/**\n * Plugin Name: Old\n * Requires PHP: 7.0\n */\n",
				'wp-content/plugins/single.php'           => "<?php\n/**\n * Plugin Name: Single File\n * Requires PHP: 8.4.0\n */\n",
				'wp-content/plugins/no-header/no-header.php' => "<?php\n/**\n * Plugin Name: No Requirement\n */\n",
			)
		);

		$this->assertSame( Result::FINDINGS, $result->status() );
		$finding = $result->finding_list()[0];
		$this->assertSame( 'info', $finding->severity()->name() );
		$this->assertStringContainsString( 'likely', $finding->title() );
		$evidence = $finding->evidence();
		$this->assertSame( 2, $evidence['plugins'] );
		$this->assertSame( '8.2', $evidence['htaccess_php'] );
		$this->assertSame( 'needs-new', $evidence['plugin_1'] );
		$this->assertSame( '8.3', $evidence['plugin_1_requires_php'] );
		$this->assertSame( 'single.php', $evidence['plugin_2'] );
		$this->assertSame( '8.4.0', $evidence['plugin_2_requires_php'] );
	}

	public function test_an_ended_hinted_php_and_a_plugin_mismatch_are_both_reported(): void {
		$result = $this->run_on_tree(
			array(
				'.htaccess' => 'AddHandler application/x-httpd-ea-php74 .php',
				'wp-content/plugins/needs-new/needs-new.php' => "<?php\n/**\n * Plugin Name: Needs New\n * Requires PHP: 8.0\n */\n",
			)
		);

		$severities = array_map(
			static function ( $finding ): string {
				return $finding->severity()->name();
			},
			$result->finding_list()
		);
		$this->assertSame( array( 'warning', 'info' ), $severities );
	}

	public function test_plugin_requirements_are_not_read_without_a_hint(): void {
		$result = $this->run_on_tree(
			array( 'wp-content/plugins/needs-new/needs-new.php' => "<?php\n/**\n * Plugin Name: Needs New\n * Requires PHP: 9.9\n */\n" ),
			'2026-11-04 12:00:00',
			'8.3.4'
		);

		$this->assertSame( Result::SKIPPED, $result->status() );
	}

	public function test_a_hinted_version_in_support_is_skipped_when_a_plugin_folder_could_not_be_read(): void {
		File_Modes::require_enforced_modes();
		$tree = new Temp_Tree();
		try {
			$tree->file( '.htaccess', 'AddHandler application/x-httpd-ea-php84 .php' );
			$tree->file( 'wp-content/plugins/fine/fine.php', "<?php\n/**\n * Plugin Name: Fine\n * Requires PHP: 7.4\n */\n" );
			$tree->file( 'wp-content/plugins/locked/locked.php', "<?php\n/**\n * Plugin Name: Locked\n * Requires PHP: 9.9\n */\n" );
			$locked = $tree->site() . '/wp-content/plugins/locked';
			chmod( $locked, 0000 );
			try {
				$result = ( new Php_Version_Check( '8.3.4', (int) strtotime( '2026-11-04 12:00:00 UTC' ) ) )->run( Context_Factory::for_tree( $tree ) );
			} finally {
				chmod( $locked, 0755 );
			}

			$this->assertSame( Result::SKIPPED, $result->status() );
			$this->assertStringContainsString( 'plugin PHP requirements were not all read', (string) $result->message() );
		} finally {
			$tree->remove();
		}
	}

	public function test_an_empty_plugin_folder_does_not_stop_a_supported_hint_from_passing(): void {
		$tree = new Temp_Tree();
		try {
			$tree->file( '.htaccess', 'AddHandler application/x-httpd-ea-php84 .php' );
			$tree->file( 'wp-content/plugins/fine/fine.php', "<?php\n/**\n * Plugin Name: Fine\n * Requires PHP: 7.4\n */\n" );
			mkdir( $tree->site() . '/wp-content/plugins/empty' );
			$this->assertDirectoryExists( $tree->site() . '/wp-content/plugins/empty' );

			$result = ( new Php_Version_Check( '8.3.4', (int) strtotime( '2026-11-04 12:00:00 UTC' ) ) )->run( Context_Factory::for_tree( $tree ) );

			$this->assertTrue( $result->is_pass(), (string) $result->message() );
		} finally {
			$tree->remove();
		}
	}
}
