<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Security;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Security\Uploads_Php_Check;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Temp_Tree;

final class Uploads_Php_Check_Test extends TestCase {

	/**
	 * Site whose uploads directory holds the PHP files and index stubs under test.
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
		return ( new Uploads_Php_Check() )->run( Context_Factory::for_tree_with_options( $this->tree, array( 'upload_path' => '' ), $config ) );
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Uploads_Php_Check();

		$this->assertSame( 'sec.uploads.php', $check->id() );
		$this->assertSame( 'security', $check->category() );
	}

	public function test_an_uploads_directory_with_only_media_passes(): void {
		$this->tree->file( 'wp-content/uploads/2024/05/photo.jpg' );
		$this->tree->file( 'wp-content/uploads/2024/05/document.pdf' );
		$this->tree->file( 'wp-content/plugins/example/example.php' );

		$this->assertTrue( $this->run_check()->is_pass() );
	}

	/**
	 * @dataProvider php_extensions
	 * @param string $name File name.
	 */
	public function test_a_file_with_a_php_extension_is_critical( string $name ): void {
		$this->tree->file( 'wp-content/uploads/2024/' . $name, '<?php // inert test marker' );

		$finding = $this->run_check()->finding_list()[0];

		$this->assertSame( 'critical', $finding->severity()->name() );
		$this->assertSame( 'wp-content/uploads/2024/' . $name, $finding->evidence()['file_1'] );
		$this->assertSame( 1, $finding->evidence()['php_files'] );
		$this->assertNotSame( '', $finding->fix() );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function php_extensions(): array {
		return array(
			'php'       => array( 'shell.php' ),
			'phtml'     => array( 'shell.phtml' ),
			'pht'       => array( 'shell.pht' ),
			'php5'      => array( 'shell.php5' ),
			'php6'      => array( 'shell.php6' ),
			'php7'      => array( 'shell.php7' ),
			'phar'      => array( 'shell.phar' ),
			'uppercase' => array( 'SHELL.PHP' ),
		);
	}

	public function test_the_extension_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->php_extensions() );
	}

	/**
	 * @dataProvider empty_index_stubs
	 * @param string $content Content of an index.php stub.
	 */
	public function test_the_empty_index_stubs_that_wordpress_writes_are_not_reported( string $content ): void {
		$this->tree->file( 'wp-content/uploads/index.php', $content );
		$this->tree->file( 'wp-content/uploads/2024/index.php', $content );
		$this->tree->file( 'wp-content/uploads/2024/photo.jpg' );

		$this->assertTrue( $this->run_check()->is_pass() );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function empty_index_stubs(): array {
		return array(
			'line comment'  => array( "<?php\n// Silence is golden.\n" ),
			'block comment' => array( "<?php\n/* Silence is golden. */\n" ),
			'with close'    => array( "<?php\n// Silence is golden.\n?>\n" ),
			'bare'          => array( '<?php' ),
		);
	}

	public function test_the_stub_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->empty_index_stubs() );
	}

	public function test_an_index_file_with_real_code_is_reported_and_stubs_are_counted(): void {
		$this->tree->file( 'wp-content/uploads/index.php', "<?php\n// Silence is golden.\n" );
		$this->tree->file( 'wp-content/uploads/2024/index.php', "<?php\n// Silence is golden.\necho 'x';" );

		$evidence = $this->run_check()->finding_list()[0]->evidence();

		$this->assertSame( 1, $evidence['php_files'] );
		$this->assertSame( 1, $evidence['empty_index_stubs_ignored'] );
		$this->assertSame( 'wp-content/uploads/2024/index.php', $evidence['file_1'] );
	}

	public function test_a_large_php_file_named_index_is_not_taken_for_a_stub(): void {
		$this->tree->file( 'wp-content/uploads/index.php', "<?php\n// Silence is golden.\n" . str_repeat( ' ', 600 ) . "echo 'x';" );

		$this->assertSame( 'critical', $this->run_check()->finding_list()[0]->severity()->name() );
	}

	public function test_files_are_listed_in_path_order(): void {
		$this->tree->file( 'wp-content/uploads/b.php', '<?php' . "\necho 1;" );
		$this->tree->file( 'wp-content/uploads/a.php', '<?php' . "\necho 1;" );

		$evidence = $this->run_check()->finding_list()[0]->evidence();

		$this->assertSame( 'wp-content/uploads/a.php', $evidence['file_1'] );
		$this->assertSame( 'wp-content/uploads/b.php', $evidence['file_2'] );
	}

	public function test_a_missing_uploads_directory_is_skipped_not_passed(): void {
		$result = $this->run_check();

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'wp-content/uploads', (string) $result->message() );
	}

	public function test_a_moved_uploads_directory_is_skipped(): void {
		$this->tree->file( 'wp-content/uploads/shell.php', '<?php echo 1;' );

		$result = $this->run_check( "define( 'UPLOADS', 'media' );" );

		$this->assertSame( Result::SKIPPED, $result->status() );
	}

	public function test_a_second_run_on_the_same_scanner_is_still_a_skip_when_the_walk_stops_at_the_cap(): void {
		for ( $i = 1; $i <= 4; $i++ ) {
			$this->tree->file( "wp-content/uploads/2024/photo$i.jpg" );
		}
		$context = Context_Factory::for_tree( $this->tree, '', 3 );

		$first  = ( new Uploads_Php_Check() )->run( $context );
		$second = ( new Uploads_Php_Check() )->run( $context );

		$this->assertSame( Result::SKIPPED, $first->status() );
		$this->assertSame( Result::SKIPPED, $second->status() );
		$this->assertStringContainsString( '--max-files', (string) $second->message() );
	}

	public function test_a_clean_result_is_a_skip_when_an_earlier_walk_met_an_unreadable_directory(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Permissions are not enforced for root.' );
		}
		$this->tree->file( 'wp-content/uploads/2024/photo.jpg' );
		$this->tree->file( 'wp-content/uploads/zz/shell.php', '<?php // inert test marker' );
		chmod( $this->tree->site() . '/wp-content/uploads/zz', 0000 );
		$context = Context_Factory::after_full_walk( Context_Factory::for_tree( $this->tree ) );

		$result = ( new Uploads_Php_Check() )->run( $context );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'could not be read', (string) $result->message() );
	}

	public function test_a_shell_in_a_directory_named_by_upload_path_is_found_when_uploads_is_empty(): void {
		mkdir( $this->tree->site() . '/wp-content/uploads', 0755, true );
		$this->tree->file( 'wp-content/files/2024/shell.php', '<?php echo 1;' );

		$result = ( new Uploads_Php_Check() )->run( Context_Factory::for_tree_with_options( $this->tree, array( 'upload_path' => 'wp-content/files' ) ) );

		$this->assertSame( Result::FINDINGS, $result->status() );
		$this->assertSame( 'wp-content/files/2024/shell.php', $result->finding_list()[0]->evidence()['file_1'] );
	}

	public function test_a_clean_uploads_directory_is_skipped_when_upload_path_could_not_be_read(): void {
		$this->tree->file( 'wp-content/uploads/2024/photo.jpg' );

		$result = ( new Uploads_Php_Check() )->run( Context_Factory::for_tree( $this->tree ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'upload_path', (string) $result->message() );
	}

	public function test_an_uploads_directory_with_folders_but_no_files_is_skipped(): void {
		mkdir( $this->tree->site() . '/wp-content/uploads/2024/05', 0755, true );
		$this->assertDirectoryExists( $this->tree->site() . '/wp-content/uploads/2024/05' );

		$result = ( new Uploads_Php_Check() )->run( Context_Factory::for_tree_with_options( $this->tree ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'no files', (string) $result->message() );
	}

	public function test_an_htaccess_that_runs_images_as_php_in_uploads_is_critical(): void {
		$this->tree->file( 'wp-content/uploads/photo.jpg' );
		$this->tree->file( 'wp-content/uploads/.htaccess', "# cache\nAddType application/x-httpd-php .jpg\n" );

		$result = $this->run_check();

		$this->assertSame( Result::FINDINGS, $result->status() );
		$finding = $result->finding_list()[0];
		$this->assertSame( 'critical', $finding->severity()->name() );
		$this->assertStringContainsString( 'likely', $finding->title() );
		$this->assertSame( 'wp-content/uploads/.htaccess', $finding->evidence()['file_1'] );
		$this->assertSame( 'AddType application/x-httpd-php .jpg', $finding->evidence()['file_1_directive_1'] );
	}

	/**
	 * @dataProvider protective_htaccess
	 * @param string $content Rules that a security plugin writes to stop PHP running in uploads.
	 */
	public function test_an_htaccess_that_only_stops_php_running_is_not_reported( string $content ): void {
		$this->tree->file( 'wp-content/uploads/photo.jpg' );
		$this->tree->file( 'wp-content/uploads/.htaccess', $content );

		$this->assertTrue( $this->run_check()->is_pass() );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function protective_htaccess(): array {
		return array(
			'deny php'       => array( "<Files *.php>\ndeny from all\n</Files>\n" ),
			'engine off'     => array( "php_flag engine off\n" ),
			'remove handler' => array( "RemoveHandler .php .phtml\nAddType text/plain .php\n" ),
			'handler none'   => array( "SetHandler none\nSetHandler default-handler\n" ),
			'require denied' => array( "<FilesMatch \"\\.(php|phtml)$\">\nRequire all denied\n</FilesMatch>\n" ),
		);
	}

	public function test_the_protective_htaccess_provider_is_not_empty(): void {
		$this->assertCount( 5, $this->protective_htaccess() );
	}

	public function test_a_user_ini_in_uploads_is_reported_and_critical_when_it_prepends_code(): void {
		$this->tree->file( 'wp-content/uploads/photo.jpg' );
		$this->tree->file( 'wp-content/uploads/2024/.user.ini', "auto_prepend_file = \"/var/www/example/wp-content/uploads/2024/x.jpg\"\n" );
		$this->tree->file( 'wp-content/uploads/2023/.user.ini', "upload_max_filesize = 64M\n" );

		$findings = array();
		foreach ( $this->run_check()->finding_list() as $finding ) {
			$findings[ $finding->severity()->name() ] = $finding;
		}

		$this->assertSame( 'wp-content/uploads/2024/.user.ini', $findings['critical']->evidence()['file_1'] );
		$this->assertSame( 'wp-content/uploads/2023/.user.ini', $findings['warning']->evidence()['file_1'] );
	}

	public function test_a_symlink_inside_uploads_is_a_listed_gap_rather_than_a_pass(): void {
		$this->tree->file( 'wp-content/uploads/photo.jpg' );
		$this->tree->file( 'wp-content/plugins/example/shell.php', '<?php echo 1;' );
		$this->tree->link( 'wp-content/uploads/x.php', $this->tree->site() . '/wp-content/plugins/example/shell.php' );
		$this->assertTrue( is_link( $this->tree->site() . '/wp-content/uploads/x.php' ) );

		$result = $this->run_check();

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'wp-content/uploads/x.php', (string) $result->message() );
	}
}
