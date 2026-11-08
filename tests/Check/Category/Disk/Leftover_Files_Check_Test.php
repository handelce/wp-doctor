<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Disk;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Disk\Leftover_Files_Check;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\File_Modes;
use WP_Doctor\Tests\Support\Temp_Tree;

final class Leftover_Files_Check_Test extends TestCase {

	/**
	 * Site holding the dumps, archives and backup copies under test.
	 *
	 * @var Temp_Tree
	 */
	private $tree;

	protected function setUp(): void {
		$this->tree = new Temp_Tree();
	}

	protected function tearDown(): void {
		$this->tree->remove();
	}

	/**
	 * Runs the check on the temporary site.
	 *
	 * @param string $config wp-config.php statements.
	 * @return Result
	 */
	private function run_check( string $config = '' ): Result {
		return ( new Leftover_Files_Check() )->run( Context_Factory::for_tree( $this->tree, $config ) );
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
		$check = new Leftover_Files_Check();

		$this->assertSame( 'disk.leftovers.web', $check->id() );
		$this->assertSame( 'disk', $check->category() );
	}

	public function test_ordinary_site_files_pass(): void {
		$this->tree->file( 'index.php' );
		$this->tree->file( 'wp-config-sample.php' );
		$this->tree->file( 'wp-content/uploads/2024/photo.jpg' );
		$this->tree->file( 'wp-content/uploads/2024/download.zip' );
		$this->tree->file( 'wp-content/plugins/example/example.php' );

		$this->assertTrue( $this->run_check()->is_pass() );
	}

	/**
	 * @dataProvider serious_leftovers
	 * @param string $relative Path of the leftover.
	 */
	public function test_database_dumps_and_config_copies_are_critical( string $relative ): void {
		$this->tree->file( $relative );

		$findings = $this->by_severity( $this->run_check() );

		$this->assertArrayHasKey( 'critical', $findings );
		$this->assertArrayNotHasKey( 'warning', $findings );
		$this->assertSame( $relative, $findings['critical']->evidence()['files'][0]['path'] );
		$this->assertStringContainsString( 'likely', $findings['critical']->title() );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function serious_leftovers(): array {
		return array(
			'sql dump'            => array( 'backup.sql' ),
			'compressed dump'     => array( 'db/site.sql.gz' ),
			'dump in uploads'     => array( 'wp-content/uploads/export.sql' ),
			'uppercase dump'      => array( 'DUMP.SQL' ),
			'config backup'       => array( 'wp-config.php.bak' ),
			'config old'          => array( 'wp-config.old' ),
			'config tilde'        => array( 'wp-config.php~' ),
			'config text copy'    => array( 'wp-config.txt' ),
			'config renamed away' => array( 'wp-config.php.disabled' ),
		);
	}

	public function test_the_serious_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->serious_leftovers() );
	}

	/**
	 * @dataProvider other_leftovers
	 * @param string $relative Path of the leftover.
	 */
	public function test_archives_and_backup_copies_are_warnings( string $relative ): void {
		$this->tree->file( $relative );

		$findings = $this->by_severity( $this->run_check() );

		$this->assertArrayHasKey( 'warning', $findings );
		$this->assertArrayNotHasKey( 'critical', $findings );
		$this->assertSame( $relative, $findings['warning']->evidence()['files'][0]['path'] );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function other_leftovers(): array {
		return array(
			'zip in the root' => array( 'site.zip' ),
			'tar gz'          => array( 'old-site.tar.gz' ),
			'rar'             => array( 'files.rar' ),
			'zip in a plugin' => array( 'wp-content/plugins/example/release.zip' ),
			'bak copy'        => array( 'index.php.bak' ),
			'old copy'        => array( 'wp-content/themes/example/header.old' ),
			'editor swap'     => array( '.index.php.swp' ),
			'tilde copy'      => array( 'functions.php~' ),
		);
	}

	public function test_the_other_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->other_leftovers() );
	}

	public function test_both_groups_become_two_findings_with_counts(): void {
		$this->tree->file( 'a.sql' );
		$this->tree->file( 'b.sql' );
		$this->tree->file( 'c.zip' );

		$findings = $this->by_severity( $this->run_check() );

		$this->assertSame( 2, $findings['critical']->evidence()['matching_files'] );
		$this->assertSame( 1, $findings['warning']->evidence()['matching_files'] );
		$this->assertSame( 'a.sql', $findings['critical']->evidence()['files'][0]['path'] );
		$this->assertSame( 'b.sql', $findings['critical']->evidence()['files'][1]['path'] );
		$this->assertNotSame( '', $findings['critical']->fix() );
	}

	public function test_archives_in_plugin_and_theme_folders_carry_a_note_that_they_may_be_bundled(): void {
		$this->tree->file( 'wp-content/plugins/example/addons.zip' );
		$this->tree->file( 'wp-content/themes/example/plugins/slider.zip' );
		$this->tree->file( 'site.zip' );

		$warning = $this->by_severity( $this->run_check() )['warning'];

		$this->assertSame( 3, $warning->evidence()['matching_files'] );
		$this->assertStringStartsWith( '2; ', (string) $warning->evidence()['archives_in_code_folders'] );
		$this->assertStringContainsString( 'bundle', (string) $warning->evidence()['archives_in_code_folders'] );
		$this->assertStringContainsString( 'bundle', $warning->fix() );
	}

	public function test_a_compressed_dump_in_a_code_folder_is_a_bundled_archive_warning(): void {
		$this->tree->file( 'wp-content/plugins/example/backups/site.sql.gz', 'x' );

		$findings = $this->by_severity( $this->run_check() );

		$this->assertArrayNotHasKey( 'critical', $findings );
		$this->assertSame( 'wp-content/plugins/example/backups/site.sql.gz', $findings['warning']->evidence()['files'][0]['path'] );
		$this->assertStringStartsWith( '1; ', (string) $findings['warning']->evidence()['archives_in_code_folders'] );
	}

	public function test_an_archive_outside_code_folders_has_no_bundling_note(): void {
		$this->tree->file( 'site.zip' );

		$warning = $this->by_severity( $this->run_check() )['warning'];

		$this->assertSame( 'site.zip', $warning->evidence()['files'][0]['path'] );
		$this->assertArrayNotHasKey( 'archives_in_code_folders', $warning->evidence() );
	}

	public function test_archives_in_a_moved_uploads_directory_are_ignored_like_the_default_one(): void {
		$this->tree->file( 'media/download.zip' );
		$this->tree->file( 'wp-content/uploads/old.zip' );

		$result = $this->run_check( "define( 'UPLOADS', 'media' );" );

		$this->assertSame( 'wp-content/uploads/old.zip', $this->by_severity( $result )['warning']->evidence()['files'][0]['path'] );
		$this->assertSame( 1, $this->by_severity( $result )['warning']->evidence()['matching_files'] );
	}

	public function test_an_uploads_directory_that_cannot_be_resolved_is_skipped(): void {
		$result = $this->run_check( "define( 'UPLOADS', getenv( 'UPLOADS' ) );" );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'UPLOADS', (string) $result->message() );
	}

	public function test_a_clean_result_is_a_skip_after_an_earlier_check_already_stopped_at_the_cap(): void {
		$this->tree->file( 'a.txt' );
		$this->tree->file( 'b.txt' );
		$this->tree->file( 'c.txt' );
		$this->tree->file( 'zz/backup.sql' );
		$context = Context_Factory::for_tree( $this->tree, '', 3 );
		$this->assertSame( Result::SKIPPED, ( new \WP_Doctor\Check\Category\Disk\Error_Log_Size_Check() )->run( $context )->status() );

		$result = ( new Leftover_Files_Check() )->run( $context );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( '--max-files', (string) $result->message() );
	}

	public function test_a_clean_result_is_a_skip_when_an_earlier_walk_met_an_unreadable_directory(): void {
		File_Modes::require_enforced_modes();
		$this->tree->file( 'wp-content/uploads/2024/photo.jpg' );
		$this->tree->file( 'zz/backup.sql' );
		chmod( $this->tree->site() . '/zz', 0000 );
		$context = Context_Factory::after_full_walk( Context_Factory::for_tree( $this->tree ) );

		$result = ( new Leftover_Files_Check() )->run( $context );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'could not be read', (string) $result->message() );
	}

	/**
	 * @dataProvider shipped_files
	 * @param string $relative Path of a file that plugins, themes or caches ship or write.
	 * @param string $content  Its content.
	 */
	public function test_files_that_code_and_caches_ship_are_not_reported( string $relative, string $content ): void {
		$this->tree->file( $relative, $content );
		$this->assertFileExists( $this->tree->site() . '/' . $relative );

		$this->assertTrue( $this->run_check()->is_pass(), "$relative was reported" );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function shipped_files(): array {
		return array(
			'plugin schema'  => array( 'wp-content/plugins/example/install/schema.sql', "CREATE TABLE {prefix}example (id INT);\n" ),
			'theme schema'   => array( 'wp-content/themes/example/data/tables.sql', "CREATE TABLE example (id INT);\n" ),
			'vendor fixture' => array( 'wp-content/plugins/example/vendor/lib/tests/fixture.sql', "CREATE TABLE t (id INT);\n" ),
			'cached page'    => array( 'wp-content/cache/supercache/example.test/index.html.gz', 'gz' ),
			'cached script'  => array( 'wp-content/cache/min/1/app.js.gz', 'gz' ),
		);
	}

	public function test_the_shipped_files_provider_is_not_empty(): void {
		$this->assertCount( 5, $this->shipped_files() );
	}

	public function test_a_dump_with_data_inside_a_plugin_directory_is_still_critical(): void {
		$this->tree->file( 'wp-content/plugins/example/export.sql', "CREATE TABLE wp_users (id INT);\nINSERT INTO `wp_users` VALUES (1);\n" );

		$evidence = $this->by_severity( $this->run_check() )['critical']->evidence();

		$this->assertSame( 'wp-content/plugins/example/export.sql', $evidence['files'][0]['path'] );
	}

	/**
	 * @dataProvider php_config_copies
	 * @param string $relative Copy of wp-config.php that keeps a .php extension.
	 */
	public function test_a_php_copy_of_wp_config_is_a_warning( string $relative ): void {
		$this->tree->file( $relative, '<?php // copy' );

		$evidence = $this->by_severity( $this->run_check() )['warning']->evidence();

		$this->assertSame( $relative, $evidence['files'][0]['path'] );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function php_config_copies(): array {
		return array(
			'dash old'    => array( 'wp-config-old.php' ),
			'dash backup' => array( 'wp-config-backup.php' ),
			'dot old'     => array( 'wp-config.old.php' ),
			'dash copy'   => array( 'wp-config-copy.php' ),
			'dated'       => array( 'wp-config-2024-05-01.php' ),
		);
	}

	public function test_the_php_config_copies_provider_is_not_empty(): void {
		$this->assertCount( 5, $this->php_config_copies() );
	}

	public function test_files_that_load_alongside_wp_config_are_not_copies(): void {
		$this->tree->file( 'wp-config-local.php', '<?php' );
		$this->tree->file( 'wp-config-sample.php', '<?php' );

		$this->assertTrue( $this->run_check()->is_pass() );
	}
}
