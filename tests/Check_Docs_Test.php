<?php
/**
 * Each check has a page in docs/checks/ that documents its evidence.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Application;
use WP_Doctor\Check\Check_Registry;
use WP_Doctor\Db\Database_Driver;
use WP_Doctor\Db\Database_Exception;
use WP_Doctor\Tests\Support\Buffer_Console;

final class Check_Docs_Test extends TestCase {

	private const SECTIONS = array( '## What it reports', '## Why it matters', '## Evidence', '## Example', '## When it skips', '## False positives' );

	/**
	 * Text of a check's page.
	 *
	 * @param string $id Check id.
	 * @return string
	 */
	private static function page( string $id ): string {
		return (string) file_get_contents( dirname( __DIR__ ) . "/docs/checks/$id.md" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Evidence keys a page documents, with list fields as `key[].field`.
	 *
	 * @param string $id Check id.
	 * @return string[]
	 */
	private static function documented_keys( string $id ): array {
		preg_match_all( '/^\| `([a-z0-9_]+(?:\[\]\.[a-z0-9_]+)?|[A-Z][A-Z0-9_]+)` \|/mi', self::page( $id ), $matches );

		return $matches[1];
	}

	/**
	 * Keys of a finding's evidence, with list fields as `key[].field`.
	 *
	 * @param array<string, mixed> $evidence Evidence of one finding.
	 * @return string[]
	 */
	private static function keys_of( array $evidence ): array {
		$keys = array();
		foreach ( $evidence as $key => $value ) {
			$keys[] = (string) $key;
			foreach ( is_array( $value ) ? $value : array() as $item ) {
				foreach ( is_array( $item ) ? array_keys( $item ) : array() as $field ) {
					$keys[] = $key . '[].' . $field;
				}
			}
		}

		return array_values( array_unique( $keys ) );
	}

	/**
	 * Ids of the registered checks.
	 *
	 * @return string[]
	 */
	public function check_ids(): array {
		$ids = array();
		foreach ( Check_Registry::built_in()->ids() as $id ) {
			$ids[ $id ] = array( $id );
		}

		return $ids;
	}

	public function test_every_registered_check_has_a_page_and_every_page_a_check(): void {
		$pages = array();
		foreach ( (array) glob( dirname( __DIR__ ) . '/docs/checks/*.md' ) as $path ) {
			$pages[] = basename( (string) $path, '.md' );
		}
		$ids = Check_Registry::built_in()->ids();
		sort( $pages );
		sort( $ids );

		$this->assertGreaterThan( 40, count( $ids ) );
		$this->assertSame( $ids, $pages );
	}

	/**
	 * @dataProvider check_ids
	 * @param string $id Check id.
	 */
	public function test_a_page_has_every_section_in_order_and_nothing_left_to_fill( string $id ): void {
		$page = self::page( $id );

		$this->assertStringStartsWith( "# `$id`\n", $page );
		$last = 0;
		foreach ( self::SECTIONS as $section ) {
			$at = strpos( $page, "\n$section\n" );
			$this->assertNotFalse( $at, "$id has no \"$section\" section" );
			$this->assertGreaterThan( $last, $at, "$id has \"$section\" out of order" );
			$last = (int) $at;
		}
		$this->assertStringNotContainsString( '<!-- fill', $page );
	}

	/**
	 * @dataProvider check_ids
	 * @param string $id Check id.
	 */
	public function test_the_example_is_a_finding_of_the_check_that_uses_only_documented_keys( string $id ): void {
		$this->assertSame( 1, preg_match( '/\n## Example\n.*?```json\n(.*?)\n```/s', self::page( $id ), $match ), "$id has no JSON example" );
		$finding = json_decode( $match[1], true );

		$this->assertIsArray( $finding, "The example on $id's page is not valid JSON" );
		$this->assertSame( $id, $finding['id'] );
		$this->assertContains( $finding['severity'], array( 'critical', 'warning', 'info' ) );
		$this->assertNotEmpty( $finding['evidence'] );
		$this->assertSame( array(), array_values( array_diff( self::keys_of( $finding['evidence'] ), self::documented_keys( $id ) ) ), "Keys in $id's example are not in its evidence table" );
	}

	public function test_evidence_from_scans_of_the_fixture_sites_is_documented(): void {
		$undocumented = array();
		$seen         = 0;
		foreach ( (array) glob( __DIR__ . '/fixtures/sites/*', GLOB_ONLYDIR ) as $site ) {
			$console = new Buffer_Console();
			$app     = new Application(
				Check_Registry::built_in(),
				$console,
				array(),
				static function (): Database_Driver {
					throw new Database_Exception( 'no database in this test' );
				}
			);
			$app->run( array( 'scan', (string) $site, '--offline', '--format=json' ) );
			$report = json_decode( $console->out, true );
			foreach ( is_array( $report ) ? $report['findings'] : array() as $finding ) {
				++$seen;
				foreach ( array_diff( self::keys_of( $finding['evidence'] ), self::documented_keys( $finding['id'] ) ) as $key ) {
					$undocumented[] = $finding['id'] . ': ' . $key;
				}
			}
		}

		$this->assertGreaterThan( 20, $seen, 'The fixture scans produced too few findings to check' );
		$this->assertSame( array(), array_values( array_unique( $undocumented ) ) );
	}

	/**
	 * @dataProvider check_ids
	 * @param string $id Check id.
	 */
	public function test_the_catalogue_links_the_check_to_its_page( string $id ): void {
		$catalogue = (string) file_get_contents( dirname( __DIR__ ) . '/docs/CHECKS.md' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertStringContainsString( "](checks/$id.md)", $catalogue );
	}

	public function test_the_check_provider_is_not_empty(): void {
		$this->assertGreaterThan( 40, count( $this->check_ids() ) );
	}
}
