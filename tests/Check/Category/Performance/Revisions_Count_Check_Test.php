<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Performance;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Performance\Revisions_Count_Check;
use WP_Doctor\Check\Thresholds;
use WP_Doctor\Report\Result;
use WP_Doctor\Tests\Support\Context_Factory;
use WP_Doctor\Tests\Support\Recording_Driver;
use WP_Doctor\Tests\Support\Temp_Tree;

final class Revisions_Count_Check_Test extends TestCase {

	/**
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
	 * A driver that answers the two revision queries.
	 *
	 * @param string                                            $revisions Total revision rows.
	 * @param array<int, array{parent: string, revisions: string}> $top       Rows of the per-post query.
	 * @return Recording_Driver
	 */
	private function driver( string $revisions, array $top = array() ): Recording_Driver {
		$driver           = new Recording_Driver();
		$driver->rows_for = array(
			'COUNT(DISTINCT post_parent)' => array(
				array(
					'revisions' => $revisions,
					'parents'   => (string) count( $top ),
				),
			),
			'GROUP BY post_parent'        => $top,
		);

		return $driver;
	}

	/**
	 * Runs the check.
	 *
	 * @param Recording_Driver $driver Database stand-in.
	 * @param string           $config Statements for wp-config.php.
	 * @param int|null         $above  Configured warning threshold, or null for the default.
	 * @return Result
	 */
	private function run_with( Recording_Driver $driver, string $config = '', ?int $above = null ): Result {
		$context = Context_Factory::for_tree_with_driver( $this->tree, $driver, $config );
		if ( null !== $above ) {
			$context = $context->with_thresholds( Thresholds::from_config( array( 'perf.revisions.count' => array( 'warning' => $above ) ), 'test.json' ) );
		}

		return ( new Revisions_Count_Check() )->run( $context );
	}

	public function test_it_has_an_id_and_a_category(): void {
		$check = new Revisions_Count_Check();

		$this->assertSame( 'perf.revisions.count', $check->id() );
		$this->assertSame( 'performance', $check->category() );
	}

	public function test_ten_thousand_revisions_pass_after_one_query_on_the_prefixed_posts_table(): void {
		$driver = $this->driver( '10000' );

		$this->assertTrue( $this->run_with( $driver )->is_pass() );
		$statements = array_values( preg_grep( '/revision/', $driver->statements ) );
		$this->assertCount( 1, $statements );
		$this->assertStringContainsString( "FROM `wptest_posts` WHERE post_type = 'revision'", $statements[0] );
	}

	public function test_more_revisions_than_the_threshold_is_a_warning_naming_the_posts_with_most(): void {
		$top = array(
			array(
				'parent'    => '42',
				'revisions' => '900',
			),
			array(
				'parent'    => '7',
				'revisions' => '120',
			),
		);

		$finding = $this->run_with( $this->driver( '10001', $top ) )->finding_list()[0];

		$evidence = $finding->evidence();
		$this->assertSame( 'warning', $finding->severity()->name() );
		$this->assertSame( 10001, $evidence['revisions'] );
		$this->assertSame( 2, $evidence['posts_with_revisions'] );
		$this->assertSame( 10000, $evidence['warning_above_rows'] );
		$this->assertSame( 'not set, so every revision is kept', $evidence['WP_POST_REVISIONS'] );
		$this->assertSame( 42, $evidence['post_1'] );
		$this->assertSame( 900, $evidence['post_1_revisions'] );
		$this->assertSame( 7, $evidence['post_2'] );
		$this->assertStringContainsString( "define( 'WP_POST_REVISIONS', 10 );", $finding->fix() );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function revision_settings(): array {
		return array(
			'true keeps all'    => array( "define( 'WP_POST_REVISIONS', true );", 'every revision is kept' ),
			'minus one'         => array( "define( 'WP_POST_REVISIONS', -1 );", 'every revision is kept' ),
			'false keeps none'  => array( "define( 'WP_POST_REVISIONS', false );", 'no revisions are kept' ),
			'a number'          => array( "define( 'WP_POST_REVISIONS', 5 );", '5 per post' ),
			'computed'          => array( "define( 'WP_POST_REVISIONS', getenv( 'X' ) );", 'not read: ' ),
			'beside an include' => array( "<?php\nrequire __DIR__ . '/extra.php';\n\$table_prefix = 'wptest_';\n", 'not set in wp-config.php, which loads code that was not read' ),
		);
	}

	/**
	 * @dataProvider revision_settings
	 * @param string $config   Statements for wp-config.php, or a whole file when it starts with the PHP tag.
	 * @param string $expected Start of the evidence value.
	 */
	public function test_the_revision_limit_is_described_as_wordpress_reads_it( string $config, string $expected ): void {
		if ( 0 === strpos( $config, '<?php' ) ) {
			$this->tree->file( 'wp-config.php', $config );
			$config = '';
		}
		$evidence = $this->run_with( $this->driver( '5' ), $config, 1 )->finding_list()[0]->evidence();

		$this->assertStringStartsWith( $expected, $evidence['WP_POST_REVISIONS'] );
	}

	public function test_the_provider_has_samples(): void {
		$this->assertGreaterThan( 4, count( $this->revision_settings() ) );
	}

	public function test_a_malformed_count_is_an_error(): void {
		$this->expectException( \UnexpectedValueException::class );
		$this->run_with( $this->driver( 'many' ) );
	}

	public function test_without_a_database_it_is_skipped(): void {
		$result = ( new Revisions_Count_Check() )->run( Context_Factory::without_database() );

		$this->assertSame( Result::SKIPPED, $result->status() );
	}
}
