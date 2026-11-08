<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Supplied_Queries;

final class Supplied_Queries_Test extends TestCase {

	public function test_each_line_is_one_statement_keyed_by_its_line_number(): void {
		$queries = Supplied_Queries::from_text( "# from the slow log\r\nSELECT 1;\n\n  SELECT 2 ;  \nSELECT 3" );

		$this->assertSame(
			array(
				2 => 'SELECT 1',
				4 => 'SELECT 2',
				5 => 'SELECT 3',
			),
			$queries->all()
		);
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function refused_files(): array {
		return array(
			'only comments' => array( "# a\n\n# b\n", 'holds no statement' ),
			'too many'      => array( str_repeat( "SELECT 1\n", Supplied_Queries::MAX_QUERIES + 1 ), 'more than ' . Supplied_Queries::MAX_QUERIES ),
			'too large'     => array( str_repeat( 'x', Supplied_Queries::MAX_BYTES + 1 ), 'larger than' ),
		);
	}

	/**
	 * @dataProvider refused_files
	 * @param string $text     File content.
	 * @param string $fragment Part of the reason.
	 */
	public function test_a_file_that_cannot_be_used_is_refused_with_a_reason( string $text, string $fragment ): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( $fragment );
		Supplied_Queries::from_text( $text );
	}

	public function test_the_provider_has_samples(): void {
		$this->assertCount( 3, $this->refused_files() );
	}
}
