<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Security;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Security\Uploads_Disguised_Check;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\File_Modes;
use WP_Doctor\Tests\Support\Temp_Tree;

final class Uploads_Disguised_Check_Test extends TestCase {

	/**
	 * Site whose uploads directory holds the image files under test.
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
	 * @return Result
	 */
	private function run_check(): Result {
		return ( new Uploads_Disguised_Check() )->run( Context_Factory::for_tree_with_options( $this->tree ) );
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Uploads_Disguised_Check();

		$this->assertSame( 'sec.uploads.disguised', $check->id() );
		$this->assertSame( 'security', $check->category() );
	}

	public function test_real_looking_images_pass_after_being_examined(): void {
		$this->tree->file( 'wp-content/uploads/2024/a.jpg', "\xFF\xD8\xFF\xE0 binary data" );
		$this->tree->file( 'wp-content/uploads/2024/b.png', "\x89PNG\r\n binary data" );
		$this->tree->file( 'wp-content/uploads/2024/photo.phpfoo.jpg', 'x' );
		$this->tree->file( 'wp-content/uploads/2024/notes.txt', '<?php // not an image extension' );

		$this->assertTrue( $this->run_check()->is_pass() );
	}

	public function test_an_uploads_directory_without_images_is_skipped_not_passed(): void {
		$this->tree->file( 'wp-content/uploads/readme.txt', 'x' );

		$result = $this->run_check();

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'nothing to examine', (string) $result->message() );
	}

	public function test_a_missing_uploads_directory_is_skipped(): void {
		$this->assertSame( Result::SKIPPED, $this->run_check()->status() );
	}

	/**
	 * @dataProvider disguised_by_name
	 * @param string $name File name.
	 */
	public function test_a_php_extension_before_the_image_extension_is_critical( string $name ): void {
		$this->tree->file( 'wp-content/uploads/' . $name, 'harmless bytes' );

		$finding = $this->run_check()->finding_list()[0];

		$this->assertSame( 'critical', $finding->severity()->name() );
		$this->assertSame( 'wp-content/uploads/' . $name, $finding->evidence()['files'][0]['path'] );
		$this->assertStringContainsString( 'extension', (string) $finding->evidence()['files'][0]['why'] );
		$this->assertStringContainsString( 'likely', $finding->title() );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function disguised_by_name(): array {
		return array(
			'double extension'    => array( 'x.php.jpg' ),
			'trailing underscore' => array( 'x.php_.jpg' ),
			'php5'                => array( 'x.php5.png' ),
			'phtml'               => array( 'x.phtml.gif' ),
			'uppercase'           => array( 'X.PHP.JPG' ),
			'trailing dot'        => array( 'x.php..jpg' ),
		);
	}

	public function test_the_name_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->disguised_by_name() );
	}

	/**
	 * @dataProvider disguised_by_content
	 * @param string $content File content.
	 */
	public function test_php_code_near_the_start_of_an_image_is_critical( string $content ): void {
		$this->tree->file( 'wp-content/uploads/2024/photo.jpg', $content );

		$finding = $this->run_check()->finding_list()[0];

		$this->assertSame( 'critical', $finding->severity()->name() );
		$this->assertStringContainsString( 'open tag', (string) $finding->evidence()['files'][0]['why'] );
		$this->assertSame( 1, $finding->evidence()['image_files_examined'] );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function disguised_by_content(): array {
		return array(
			'php tag first'     => array( '<?php // inert test marker' ),
			'gif header first'  => array( 'GIF89a' . "\n" . '<?php // inert test marker' ),
			'jpeg header first' => array( "\xFF\xD8\xFF\xE0" . ' <?php // inert test marker' ),
			'short echo tag'    => array( "GIF89a <?= 'inert test marker' ?>" ),
			'uppercase tag'     => array( '<?PHP // inert test marker' ),
		);
	}

	public function test_the_content_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->disguised_by_content() );
	}

	/**
	 * @dataProvider image_extensions
	 * @param string $extension Image extension.
	 */
	public function test_every_image_extension_is_examined( string $extension ): void {
		$this->tree->file( 'wp-content/uploads/photo.' . $extension, '<?php // inert test marker' );

		$this->assertSame( 'critical', $this->run_check()->finding_list()[0]->severity()->name() );
	}

	/**
	 * @return array<string, array<int, string>>
	 */
	public function image_extensions(): array {
		$extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'ico', 'bmp', 'tif', 'tiff', 'avif' );

		return array_combine(
			$extensions,
			array_map(
				static function ( string $extension ): array {
					return array( $extension );
				},
				$extensions
			)
		);
	}

	public function test_the_image_extension_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->image_extensions() );
	}

	public function test_a_php_tag_after_the_first_kilobyte_is_not_reported(): void {
		$this->tree->file( 'wp-content/uploads/photo.jpg', str_repeat( 'a', 1100 ) . '<?php // inert test marker' );

		$this->assertTrue( $this->run_check()->is_pass() );
	}

	public function test_a_file_with_both_signs_names_both_reasons(): void {
		$this->tree->file( 'wp-content/uploads/x.php.jpg', '<?php // inert test marker' );

		$why = (string) $this->run_check()->finding_list()[0]->evidence()['files'][0]['why'];

		$this->assertStringContainsString( 'extension', $why );
		$this->assertStringContainsString( 'open tag', $why );
	}

	public function test_a_second_run_on_the_same_scanner_is_still_a_skip_when_the_walk_stops_at_the_cap(): void {
		for ( $i = 1; $i <= 4; $i++ ) {
			$this->tree->file( "wp-content/uploads/2024/photo$i.jpg", "\xFF\xD8\xFF\xE0 binary data" );
		}
		$context = Context_Factory::for_tree( $this->tree, '', 3 );

		$first  = ( new Uploads_Disguised_Check() )->run( $context );
		$second = ( new Uploads_Disguised_Check() )->run( $context );

		$this->assertSame( Result::SKIPPED, $first->status() );
		$this->assertSame( Result::SKIPPED, $second->status() );
		$this->assertStringContainsString( '--max-files', (string) $second->message() );
	}

	public function test_a_clean_result_is_a_skip_when_an_earlier_walk_met_an_unreadable_directory(): void {
		File_Modes::require_enforced_modes();
		$this->tree->file( 'wp-content/uploads/2024/photo.jpg', "\xFF\xD8\xFF\xE0 binary data" );
		$this->tree->file( 'wp-content/uploads/zz/shell.php.jpg', '<?php // inert test marker' );
		chmod( $this->tree->site() . '/wp-content/uploads/zz', 0000 );
		$context = Context_Factory::after_full_walk( Context_Factory::for_tree( $this->tree ) );

		$result = ( new Uploads_Disguised_Check() )->run( $context );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'could not be read', (string) $result->message() );
	}

	public function test_a_symlink_inside_uploads_is_a_listed_gap_rather_than_a_pass(): void {
		$this->tree->file( 'wp-content/uploads/photo.jpg', "\xFF\xD8\xFF\xE0 real image bytes" );
		$this->tree->file( 'wp-content/plugins/example/picture.jpg', "\xFF\xD8\xFF\xE0 more bytes" );
		$this->tree->link( 'wp-content/uploads/linked.jpg', $this->tree->site() . '/wp-content/plugins/example/picture.jpg' );
		$this->assertTrue( is_link( $this->tree->site() . '/wp-content/uploads/linked.jpg' ) );

		$result = ( new Uploads_Disguised_Check() )->run( Context_Factory::for_tree_with_options( $this->tree ) );

		$this->assertSame( Result::SKIPPED, $result->status() );
		$this->assertStringContainsString( 'wp-content/uploads/linked.jpg', (string) $result->message() );
	}
}
