<?php
/**
 * The registry and docs/CHECKS.md describe the same checks.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Check_Registry;

final class Checks_Catalogue_Test extends TestCase {

	/**
	 * Check ids in docs/CHECKS.md, by the version column.
	 *
	 * @return array<string, string> Version by id.
	 */
	private function documented(): array {
		$text = (string) file_get_contents( dirname( __DIR__ ) . '/docs/CHECKS.md' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$rows = array();
		foreach ( explode( "\n", $text ) as $line ) {
			if ( 1 === preg_match( '/^\| `([a-z]+(?:\.[a-z]+){2})` \|.*\| ([0-9.]+(?: \(planned\))?|later) \|\s*$/', $line, $match ) ) {
				$rows[ $match[1] ] = $match[2];
			}
		}

		return $rows;
	}

	/**
	 * Ids of the registered checks.
	 *
	 * @return string[]
	 */
	private function registered(): array {
		$ids = array();
		foreach ( Check_Registry::built_in()->all() as $check ) {
			$ids[] = $check->id();
		}
		sort( $ids );

		return $ids;
	}

	public function test_every_built_check_in_the_catalogue_is_registered_and_the_other_way_round(): void {
		$documented = array_keys(
			array_filter(
				$this->documented(),
				static function ( string $version ): bool {
					return 1 === preg_match( '/^[0-9.]+$/', $version );
				}
			)
		);
		sort( $documented );

		$this->assertGreaterThan( 25, count( $documented ), 'Too few built rows were found in docs/CHECKS.md' );
		$this->assertSame( $documented, $this->registered() );
	}

	public function test_every_registered_check_has_the_category_its_id_starts_with(): void {
		$prefixes = array(
			'perf'   => 'performance',
			'disk'   => 'disk',
			'sec'    => 'security',
			'config' => 'config',
		);
		$checked  = 0;
		foreach ( Check_Registry::built_in()->all() as $check ) {
			$prefix = explode( '.', $check->id() )[0];
			$this->assertArrayHasKey( $prefix, $prefixes, $check->id() );
			$this->assertSame( $prefixes[ $prefix ], $check->category(), $check->id() );
			++$checked;
		}

		$this->assertGreaterThan( 0, $checked );
	}
}
