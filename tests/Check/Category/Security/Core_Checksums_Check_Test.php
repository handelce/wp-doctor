<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Security;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Security\Core_Checksums_Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Fake_Checksum_Source;
use WP_Doctor\Tests\Support\Temp_Tree;

final class Core_Checksums_Check_Test extends TestCase {

	/**
	 * Site holding the core files the fake source has checksums for.
	 *
	 * @var Temp_Tree
	 */
	private $tree;

	/**
	 * Checksums the fake source returns.
	 *
	 * @var Fake_Checksum_Source
	 */
	private $source;

	protected function setUp(): void {
		$this->tree   = new Temp_Tree();
		$this->source = new Fake_Checksum_Source();
		$this->core_file( 'wp-includes/version.php', "<?php\n\$wp_version = '6.5.2';\n" );
		$this->core_file( 'wp-login.php', '<?php // login' );
		$this->core_file( 'wp-admin/index.php', '<?php // admin' );
		$this->core_file( 'wp-includes/functions.php', '<?php // functions' );
		$this->core_file( 'wp-includes/js/script.js', 'var x = 1;' );
	}

	protected function tearDown(): void {
		$this->tree->remove();
	}

	/**
	 * Writes a file and records its checksum as the official one.
	 *
	 * @param string $path    Path relative to the site root.
	 * @param string $content File content.
	 */
	private function core_file( string $path, string $content ): void {
		$this->tree->file( $path, $content );
		$this->source->checksums[ $path ] = md5( $content );
	}

	/**
	 * Runs the check with the fake source.
	 *
	 * @param string $config  wp-config.php statements.
	 * @param int    $max_files Entry cap for each walk.
	 * @return Result
	 */
	private function run_check( string $config = '', int $max_files = 200000 ): Result {
		$context = Context_Factory::for_tree( $this->tree, $config, $max_files )->with_checksums( $this->source );

		return ( new Core_Checksums_Check() )->run( $context );
	}

	/**
	 * Findings keyed by severity name.
	 *
	 * @param Result $result Result with findings.
	 * @return array<string, \WP_Doctor\Report\Finding>
	 */
	private function by_severity( Result $result ): array {
		$by = array();
		foreach ( $result->finding_list() as $finding ) {
			$by[ $finding->severity()->name() ] = $finding;
		}

		return $by;
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Core_Checksums_Check();

		$this->assertSame( 'sec.core.checksums', $check->id() );
		$this->assertSame( 'security', $check->category() );
	}

	public function test_a_site_that_matches_the_release_passes_after_the_checksums_were_requested(): void {
		$result = $this->run_check();

		$this->assertTrue( $result->is_pass(), (string) $result->message() );
		$this->assertSame( array( array( '6.5.2', 'en_US' ) ), $this->source->requests );
	}

	public function test_the_locale_is_the_package_locale_recorded_in_version_php(): void {
		$this->core_file( 'wp-includes/version.php', "<?php\n\$wp_version = '6.5.2';\n\$wp_local_package = 'de_DE';\n" );

		$result = $this->run_check();

		$this->assertTrue( $result->is_pass(), (string) $result->message() );
		$this->assertSame( array( array( '6.5.2', 'de_DE' ) ), $this->source->requests );
	}

	public function test_a_variant_locale_is_passed_through_whole(): void {
		$this->core_file( 'wp-includes/version.php', "<?php\n\$wp_version = '6.5.2';\n\$wp_local_package = 'de_DE_formal';\n" );

		$this->run_check();

		$this->assertSame( array( array( '6.5.2', 'de_DE_formal' ) ), $this->source->requests );
	}

	public function test_the_wplang_setting_does_not_change_the_locale_of_the_files(): void {
		$this->run_check( "define( 'WPLANG', 'de_DE' );" );

		$this->assertSame( array( array( '6.5.2', 'en_US' ) ), $this->source->requests );
	}

	public function test_an_empty_package_locale_means_us_english(): void {
		$this->core_file( 'wp-includes/version.php', "<?php\n\$wp_version = '6.5.2';\n\$wp_local_package = '';\n" );

		$this->run_check();

		$this->assertSame( array( array( '6.5.2', 'en_US' ) ), $this->source->requests );
	}

	public function test_a_commented_out_package_locale_is_not_used(): void {
		$this->core_file( 'wp-includes/version.php', "<?php\n\$wp_version = '6.5.2';\n// \$wp_local_package = 'de_DE';\n" );

		$this->run_check();

		$this->assertSame( array( array( '6.5.2', 'en_US' ) ), $this->source->requests );
	}

	public function test_a_modified_core_file_is_critical_and_named(): void {
		$this->tree->file( 'wp-includes/functions.php', '<?php // functions with an injected line' );

		$finding = $this->run_check()->finding_list()[0];

		$evidence = $finding->evidence();
		$this->assertSame( 'critical', $finding->severity()->name() );
		$this->assertSame( 1, $evidence['modified_files'] );
		$this->assertSame( 'wp-includes/functions.php', $evidence['modified'][0] );
		$this->assertSame( 0, $evidence['missing_files'] );
		$this->assertSame( 5, $evidence['files_compared'] );
		$this->assertSame( '6.5.2', $evidence['wordpress_version'] );
		$this->assertStringContainsString( '6.5.2', $finding->fix() );
	}

	public function test_several_changed_and_missing_files_are_listed_in_path_order(): void {
		$this->tree->file( 'wp-login.php', '<?php // changed' );
		$this->tree->file( 'wp-includes/functions.php', '<?php // changed' );
		$this->tree->file( 'wp-admin/index.php', '<?php // changed' );
		unlink( $this->tree->site() . '/wp-includes/js/script.js' );
		$this->source->checksums['wp-admin/missing-a.php'] = md5( 'x' );
		$this->source->checksums['wp-admin/missing-b.php'] = md5( 'x' );

		$evidence = $this->run_check()->finding_list()[0]->evidence();

		$this->assertSame( 'wp-admin/index.php', $evidence['modified'][0] );
		$this->assertSame( 'wp-includes/functions.php', $evidence['modified'][1] );
		$this->assertSame( 'wp-login.php', $evidence['modified'][2] );
		$this->assertSame( 'wp-admin/missing-a.php', $evidence['missing'][0] );
		$this->assertSame( 'wp-admin/missing-b.php', $evidence['missing'][1] );
		$this->assertSame( 'wp-includes/js/script.js', $evidence['missing'][2] );
	}

	public function test_a_missing_core_file_is_critical_and_named(): void {
		unlink( $this->tree->site() . '/wp-admin/index.php' );

		$evidence = $this->run_check()->finding_list()[0]->evidence();

		$this->assertSame( 1, $evidence['missing_files'] );
		$this->assertSame( 'wp-admin/index.php', $evidence['missing'][0] );
	}

	public function test_files_below_wp_content_and_the_sample_documents_are_not_compared(): void {
		$this->source->checksums['wp-content/plugins/hello.php']                 = md5( 'official' );
		$this->source->checksums['wp-content/themes/twentytwentyfour/style.css'] = md5( 'official' );
		$this->source->checksums['readme.html']                                  = md5( 'official' );
		$this->source->checksums['license.txt']                                  = md5( 'official' );
		$this->source->checksums['wp-config-sample.php']                         = md5( 'official' );
		$this->tree->file( 'readme.html', 'edited by the owner' );

		$this->assertTrue( $this->run_check()->is_pass() );
	}

	public function test_a_file_that_the_release_does_not_contain_inside_a_core_directory_is_critical(): void {
		$this->tree->file( 'wp-includes/class-planted.php', '<?php // inert test marker' );
		$this->tree->file( 'wp-admin/css/extra.css', 'body {}' );

		$findings = $this->by_severity( $this->run_check() );

		$evidence = $findings['critical']->evidence();
		$this->assertSame( 2, $evidence['unknown_in_core_dirs'] );
		$this->assertSame( 'wp-admin/css/extra.css', $evidence['unknown'][0] );
		$this->assertSame( 'wp-includes/class-planted.php', $evidence['unknown'][1] );
		$this->assertStringContainsString( 'likely', $findings['critical']->title() );
	}

	public function test_a_php_file_in_the_root_that_the_release_does_not_contain_is_a_warning(): void {
		$this->tree->file( 'planted.php', '<?php // inert test marker' );
		$this->tree->file( 'notes.txt', 'ignored' );
		$this->tree->file( 'wp-config.php.bak', 'ignored here; disk.leftovers.web reports it' );

		$findings = $this->by_severity( $this->run_check() );

		$this->assertArrayNotHasKey( 'critical', $findings );
		$this->assertSame( 1, $findings['warning']->evidence()['unknown_php_in_root'] );
		$this->assertSame( 'planted.php', $findings['warning']->evidence()['unknown'][0] );
	}

	public function test_the_config_template_of_the_official_docker_image_is_not_unknown_in_the_root(): void {
		$this->tree->file( 'wp-config-docker.php', '<?php // template shipped by the docker image' );

		$this->assertTrue( $this->run_check()->is_pass() );
	}

	public function test_the_same_file_name_inside_a_core_directory_is_still_reported(): void {
		$this->tree->file( 'wp-includes/wp-config-docker.php', '<?php // inert test marker' );

		$findings = $this->by_severity( $this->run_check() );

		$this->assertSame( 'wp-includes/wp-config-docker.php', $findings['critical']->evidence()['unknown'][0] );
	}

	public function test_the_fix_for_a_root_file_alone_does_not_declare_the_site_compromised(): void {
		$this->tree->file( 'planted.php', '<?php // inert test marker' );

		$fix = $this->by_severity( $this->run_check() )['warning']->fix();

		$this->assertStringContainsString( 'ask your host', $fix );
		$this->assertStringNotContainsString( 'treat the site as compromised', $fix );
	}

	public function test_the_fix_for_a_file_in_a_core_directory_says_to_treat_the_site_as_compromised(): void {
		$this->tree->file( 'wp-includes/class-planted.php', '<?php // inert test marker' );

		$this->assertStringContainsString( 'treat the site as compromised', $this->by_severity( $this->run_check() )['critical']->fix() );
	}

	public function test_error_logs_that_servers_add_to_core_directories_are_ignored(): void {
		$this->tree->file( 'wp-admin/error_log', 'warnings' );
		$this->tree->file( 'wp-includes/.DS_Store', 'x' );
		$this->tree->file( 'error_log', 'warnings' );

		$this->assertTrue( $this->run_check()->is_pass() );
	}

	public function test_server_configuration_files_in_core_directories_are_reported_as_unexpected(): void {
		$this->tree->file( 'wp-admin/.htaccess', "AddHandler application/x-httpd-php .jpg\n" );
		$this->tree->file( 'wp-includes/.user.ini', "auto_prepend_file = /tmp/example.php\n" );
		$this->tree->file( 'wp-includes/js/php.ini', "display_errors = Off\n" );

		$evidence = $this->by_severity( $this->run_check() )['critical']->evidence();

		$this->assertSame( 3, $evidence['unknown_in_core_dirs'] );
		$this->assertSame( 'wp-admin/.htaccess', $evidence['unknown'][0] );
		$this->assertSame( 'wp-includes/.user.ini', $evidence['unknown'][1] );
		$this->assertSame( 'wp-includes/js/php.ini', $evidence['unknown'][2] );
	}

	public function test_wp_config_and_index_php_in_the_root_are_not_unknown(): void {
		$this->core_file( 'index.php', '<?php // front controller' );

		$this->assertTrue( $this->run_check()->is_pass() );
	}

	public function test_both_kinds_of_problem_give_two_findings(): void {
		$this->tree->file( 'wp-login.php', '<?php // changed' );
		$this->tree->file( 'wp-includes/class-planted.php', '<?php // inert test marker' );

		$this->assertCount( 2, $this->run_check()->finding_list() );
	}

	public function test_a_clean_comparison_that_could_not_read_a_file_is_a_skip(): void {
		chmod( $this->tree->site() . '/wp-login.php', 0000 );
		if ( is_readable( $this->tree->site() . '/wp-login.php' ) ) {
			$this->markTestSkipped( 'This account can read files with mode 0000.' );
		}

		$result = $this->run_check();

		chmod( $this->tree->site() . '/wp-login.php', 0644 );
		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'but 1 could not be read', (string) $result->message() );
	}

	public function test_a_clean_comparison_is_a_skip_when_the_scan_stopped_at_a_limit(): void {
		$result = $this->run_check( '', 3 );

		$this->assertSame( Result::SKIPPED, $result->status() );
	}

	public function test_checksums_that_cannot_be_obtained_make_the_check_skip_with_the_reason(): void {
		$this->source->failure = 'connection refused';

		$result = $this->run_check();

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'connection refused', (string) $result->message() );
		$this->assertStringContainsString( '6.5.2', (string) $result->message() );
	}

	public function test_a_site_whose_version_cannot_be_read_is_skipped(): void {
		$this->tree->file( 'wp-includes/version.php', "<?php\n// no version here\n" );

		$result = $this->run_check();

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertSame( array(), $this->source->requests );
	}

	public function test_a_missing_core_directory_is_skipped(): void {
		$this->source->checksums = array( 'wp-login.php' => md5( '<?php // login' ) );
		$this->tree->remove();
		$this->tree = new Temp_Tree();
		$this->tree->file( 'wp-login.php', '<?php // login' );

		$result = $this->run_check();

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'core directory', (string) $result->message() );
	}

	public function test_lookups_ruled_out_by_the_context_skip_the_check_with_that_reason(): void {
		$context = Context_Factory::for_tree( $this->tree )->with_checksums( null, '--offline was given' );

		$result = ( new Core_Checksums_Check() )->run( $context );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertSame( '--offline was given', $result->message() );
	}

	public function test_a_context_that_was_never_configured_for_lookups_skips_the_check(): void {
		$result = ( new Core_Checksums_Check() )->run( Context_Factory::for_tree( $this->tree ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
	}

	public function test_a_context_without_a_source_needs_a_reason(): void {
		$this->expectException( \InvalidArgumentException::class );
		Context_Factory::for_tree( $this->tree )->with_checksums( null );
	}

	public function test_asking_for_the_source_when_there_is_none_is_a_logic_error(): void {
		$context = Context_Factory::for_tree( $this->tree )->with_checksums( null, 'offline' );

		$this->expectException( \LogicException::class );
		$context->checksums();
	}

	public function test_with_checksums_returns_a_new_context_and_leaves_the_original_alone(): void {
		$original = Context_Factory::for_tree( $this->tree );
		$with     = $original->with_checksums( $this->source );

		$this->assertNotSame( $original, $with );
		$this->assertNotNull( $original->checksums_unavailable() );
		$this->assertNull( $with->checksums_unavailable() );
		$this->assertSame( $this->source, $with->checksums() );
		$this->assertInstanceOf( Context::class, $with );
	}

	/**
	 * Runs the check with exclude globs.
	 *
	 * @param string[] $exclude Exclude globs.
	 * @return Result
	 */
	private function run_excluding( array $exclude ): Result {
		$context = Context_Factory::for_tree( $this->tree, '', 200000, $exclude )->with_checksums( $this->source );

		return ( new Core_Checksums_Check() )->run( $context );
	}

	public function test_an_excluded_core_file_is_not_compared_rather_than_missing(): void {
		$result = $this->run_excluding( array( 'wp-includes/js/**' ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'not compared', (string) $result->message() );
		$this->assertStringContainsString( '1 ', (string) $result->message() );
	}

	public function test_an_excluded_core_file_is_counted_as_not_compared_beside_a_modified_one(): void {
		$this->tree->file( 'wp-login.php', '<?php // changed' );

		$evidence = $this->run_excluding( array( 'wp-includes/js/**' ) )->finding_list()[0]->evidence();

		$this->assertSame( 1, $evidence['modified_files'] );
		$this->assertSame( 0, $evidence['missing_files'] );
		$this->assertSame( 1, $evidence['files_not_compared'] );
		$this->assertSame( 'wp-includes/js/script.js', $evidence['not_compared'][0] );
		$this->assertTrue( $evidence['scan_incomplete'] );
	}

	public function test_a_core_file_behind_a_symlink_leaving_the_root_is_not_compared_rather_than_missing(): void {
		$outside = $this->tree->outside() . '/functions.php';
		file_put_contents( $outside, '<?php // functions' );
		unlink( $this->tree->site() . '/wp-includes/functions.php' );
		$this->tree->link( 'wp-includes/functions.php', $outside );

		$result = $this->run_check();

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'not compared', (string) $result->message() );
	}

	public function test_a_core_file_above_the_hash_limit_is_not_compared(): void {
		$this->tree->sparse_file( 'wp-includes/huge.js', \WP_Doctor\Fs\File_Scanner::MAX_HASH_BYTES + 1 );
		$this->source->checksums['wp-includes/huge.js'] = md5( 'unknown' );

		$result = $this->run_check();

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'not compared', (string) $result->message() );
	}

	public function test_a_second_run_on_the_same_scanner_is_still_a_skip_when_a_walk_stops_at_the_cap(): void {
		$context = Context_Factory::for_tree( $this->tree, '', 3 )->with_checksums( $this->source );

		$first  = ( new Core_Checksums_Check() )->run( $context );
		$second = ( new Core_Checksums_Check() )->run( $context );

		$this->assertSame( Result::SKIPPED, $first->status() );
		$this->assertSame( Result::SKIPPED, $second->status() );
		$this->assertStringContainsString( '--max-files', (string) $second->message() );
	}
}
