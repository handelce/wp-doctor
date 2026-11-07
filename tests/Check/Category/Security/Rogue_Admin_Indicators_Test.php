<?php

declare(strict_types=1);

namespace WP_Doctor\Tests\Check\Category\Security;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Check\Category\Security\Rogue_Admin_Indicators;

final class Rogue_Admin_Indicators_Test extends TestCase {

	/**
	 * A valid name entry with one field changed.
	 *
	 * @param array<string, mixed> $changes Fields to override.
	 * @return array<string, mixed>
	 */
	private function entry( array $changes = array() ): array {
		return array_merge(
			array(
				'login'    => 'rogue',
				'match'    => 'exact',
				'signal'   => 'strong',
				'campaign' => 'Invented',
				'source'   => 'https://reports.example.test/x',
			),
			$changes
		);
	}

	public function test_the_bundled_data_loads_and_every_name_cites_an_https_source(): void {
		$indicators = Rogue_Admin_Indicators::bundled();

		$names = $indicators->names();
		$this->assertGreaterThanOrEqual( 10, count( $names ) );
		foreach ( $names as $entry ) {
			$this->assertStringStartsWith( 'https://', $entry['source'], $entry['login'] );
			$this->assertNotSame( '', $entry['campaign'], $entry['login'] );
			$this->assertSame( strtolower( $entry['login'] ), $entry['login'] );
		}
	}

	public function test_the_bundled_disposable_list_is_large_and_skips_its_header(): void {
		$indicators = Rogue_Admin_Indicators::bundled();

		$this->assertGreaterThan( 1000, $indicators->disposable_domain_count() );
		$this->assertFalse( $indicators->is_disposable_domain( '# source' ) );
		$this->assertFalse( $indicators->is_disposable_domain( 'example.test' ) );
	}

	public function test_an_ordinary_mail_provider_on_the_upstream_list_is_not_treated_as_disposable(): void {
		$upstream = (string) file_get_contents( dirname( __DIR__, 4 ) . '/data/disposable-email-domains.txt' );
		$this->assertSame( 1, preg_match( '/^21cn\.com$/m', $upstream ), 'The upstream list no longer holds the domain under test' );

		$indicators = Rogue_Admin_Indicators::bundled();

		$this->assertFalse( $indicators->is_disposable_domain( '21cn.com' ) );
		$this->assertTrue( $indicators->is_disposable_domain( '0-mail.com' ) );
	}

	public function test_the_bundled_data_treats_reserved_names_as_placeholders_and_allowlists_the_host_account(): void {
		$indicators = Rogue_Admin_Indicators::bundled();

		$this->assertTrue( $indicators->is_placeholder_domain( 'example.com' ) );
		$this->assertTrue( $indicators->is_placeholder_domain( 'site.test' ) );
		$this->assertTrue( $indicators->is_placeholder_domain( 'localhost' ) );
		$this->assertFalse( $indicators->is_placeholder_domain( 'agency-example.org' ) );
		$this->assertTrue( $indicators->is_allowlisted( 'WPEngine' ) );
		$this->assertFalse( $indicators->is_allowlisted( 'wpenginer' ) );
	}

	public function test_names_match_exactly_or_by_prefix_ignoring_case(): void {
		$indicators = new Rogue_Admin_Indicators(
			array(
				$this->entry(),
				$this->entry(
					array(
						'login' => 'pre_',
						'match' => 'prefix',
					)
				),
			),
			array(),
			array(),
			array(),
			array()
		);

		$this->assertSame( 'rogue', $indicators->name_indicator( 'ROGUE' )['login'] );
		$this->assertNull( $indicators->name_indicator( 'rogue2' ) );
		$this->assertSame( 'pre_', $indicators->name_indicator( 'Pre_abc' )['login'] );
		$this->assertNull( $indicators->name_indicator( 'xpre_abc' ) );
	}

	public function test_a_documented_email_is_lowercased_with_the_entry(): void {
		$indicators = new Rogue_Admin_Indicators( array( $this->entry( array( 'email' => 'Rogue@Reports.example.test' ) ) ), array(), array(), array(), array() );

		$this->assertSame( 'rogue@reports.example.test', $indicators->name_indicator( 'rogue' )['email'] );
	}

	public function test_a_disposable_domain_matches_from_any_subdomain_but_not_from_a_suffix(): void {
		$indicators = new Rogue_Admin_Indicators( array(), array(), array(), array(), array( 'Burner.test' ) );

		$this->assertTrue( $indicators->is_disposable_domain( 'burner.test' ) );
		$this->assertTrue( $indicators->is_disposable_domain( 'a.b.burner.test' ) );
		$this->assertFalse( $indicators->is_disposable_domain( 'test' ) );
		$this->assertFalse( $indicators->is_disposable_domain( 'notburner.test' ) );
	}

	/**
	 * @dataProvider malformed_entries
	 * @param array<string, mixed> $changes Fields that make the entry invalid.
	 */
	public function test_a_malformed_name_entry_is_refused( array $changes ): void {
		$this->expectException( \UnexpectedValueException::class );
		new Rogue_Admin_Indicators( array( $this->entry( $changes ) ), array(), array(), array(), array() );
	}

	/**
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function malformed_entries(): array {
		return array(
			'no login'        => array( array( 'login' => '' ) ),
			'unknown match'   => array( array( 'match' => 'regex' ) ),
			'unknown signal'  => array( array( 'signal' => 'medium' ) ),
			'no source'       => array( array( 'source' => '' ) ),
			'http source'     => array( array( 'source' => 'http://reports.example.test/x' ) ),
			'email not email' => array( array( 'email' => 'rogue' ) ),
		);
	}

	public function test_the_malformed_entries_provider_is_not_empty(): void {
		$this->assertNotEmpty( $this->malformed_entries() );
	}

	public function test_a_non_string_in_a_domain_list_is_refused(): void {
		$this->expectException( \UnexpectedValueException::class );
		new Rogue_Admin_Indicators( array(), array(), array( 42 ), array(), array() );
	}
}
