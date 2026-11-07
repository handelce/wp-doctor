<?php
/**
 * Tests for Redactor.
 *
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Tests\Report;

use PHPUnit\Framework\TestCase;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Redactor;
use WP_Doctor\Report\Report;
use WP_Doctor\Report\Report_Entry;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;
use WP_Doctor\Site\Config_Parser;

final class Redactor_Test extends TestCase {

	private const SECRET = 'fake-Secret/with+odd&chars"and\'quote 0042';

	/**
	 * Every encoding of the secret that could end up in a report.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function secret_forms(): array {
		return array(
			'raw'                    => array( self::SECRET ),
			'rawurlencoded'          => array( rawurlencode( self::SECRET ) ),
			'urlencoded'             => array( urlencode( self::SECRET ) ),
			'json escaped'           => array( substr( (string) json_encode( self::SECRET ), 1, -1 ) ),
			'json unescaped slashes' => array( substr( (string) json_encode( self::SECRET, JSON_UNESCAPED_SLASHES ), 1, -1 ) ),
			'slash escaped'          => array( addslashes( self::SECRET ) ),
			'html escaped'           => array( htmlspecialchars( self::SECRET, ENT_QUOTES ) ),
			'base64'                 => array( base64_encode( self::SECRET ) ),
		);
	}

	/**
	 * @dataProvider secret_forms
	 * @param string $form One encoding of the secret.
	 */
	public function test_a_secret_is_removed_in_every_encoding( string $form ): void {
		$redactor = new Redactor( array( self::SECRET ) );
		$text     = "before $form after";

		$this->assertStringContainsString( $form, $text );
		$this->assertSame( 'before [redacted] after', $redactor->redact_text( $text ) );
	}

	public function test_the_secret_forms_provider_is_not_empty(): void {
		$this->assertCount( 8, $this->secret_forms() );
	}

	public function test_text_without_a_secret_is_unchanged(): void {
		$this->assertSame( 'wp-content/uploads/a.php', ( new Redactor( array( self::SECRET ) ) )->redact_text( 'wp-content/uploads/a.php' ) );
	}

	public function test_every_occurrence_is_replaced(): void {
		$text = self::SECRET . ' and ' . self::SECRET;

		$this->assertSame( '[redacted] and [redacted]', ( new Redactor( array( self::SECRET ) ) )->redact_text( $text ) );
	}

	public function test_a_secret_shorter_than_four_characters_is_ignored(): void {
		$this->assertSame( 'a b c', ( new Redactor( array( 'a', 'ab', 'abc', '' ) ) )->redact_text( 'a b c' ) );
	}

	public function test_a_longer_secret_containing_a_shorter_one_is_removed_whole(): void {
		$redactor = new Redactor( array( 'password', 'password-extended-9' ) );

		$this->assertSame( 'x [redacted] y', $redactor->redact_text( 'x password-extended-9 y' ) );
	}

	public function test_secrets_are_collected_from_secret_named_constants_and_the_connection_identifiers_only(): void {
		$config   = ( new Config_Parser() )->parse(
			"<?php\ndefine( 'DB_PASSWORD', 'db-pass-0001' );\ndefine( 'AUTH_KEY', 'auth-key-0002' );\ndefine( 'NONCE_SALT', 'nonce-salt-0003' );\n"
			. "define( 'STRIPE_SECRET_KEY', 'stripe-0004' );\ndefine( 'API_TOKEN', 'token-0005' );\ndefine( 'WP_REDIS_PASSWORD', 12345678 );\n"
			. "define( 'DB_NAME', 'example_db' );\ndefine( 'DB_USER', 'example_user' );\ndefine( 'DB_HOST', 'db.example.test' );\ndefine( 'WP_DEBUG', true );\n"
		);
		$redactor = Redactor::for_config( $config );
		$all      = 'db-pass-0001 auth-key-0002 nonce-salt-0003 stripe-0004 token-0005 12345678 example_db example_user db.example.test true WP_DEBUG';

		$this->assertSame( '[redacted] [redacted] [redacted] [redacted] [redacted] [redacted] [redacted] [redacted] [redacted] true WP_DEBUG', $redactor->redact_text( $all ) );
	}

	public function test_secrets_that_could_not_be_read_cannot_be_redacted_by_value(): void {
		$config = ( new Config_Parser() )->parse( "<?php\ndefine( 'DB_PASSWORD', getenv( 'X' ) );" );

		$this->assertSame( 'getenv-value', Redactor::for_config( $config )->redact_text( 'getenv-value' ) );
	}

	public function test_extra_secrets_can_be_added(): void {
		$redactor = ( new Redactor() )->with_secret( 'from-environment-77' );

		$this->assertSame( '[redacted]', $redactor->redact_text( 'from-environment-77' ) );
	}

	public function test_emails_are_masked_only_when_asked(): void {
		$text = 'admin: jane.doe+wp@example.test and root@mail.example.test';

		$this->assertSame( $text, ( new Redactor() )->redact_text( $text ) );
		$this->assertSame( 'admin: ***@example.test and ***@mail.example.test', ( new Redactor( array(), true ) )->redact_text( $text ) );
	}

	public function test_a_report_is_redacted_in_every_text_field(): void {
		$secret   = 'fake-report-secret-0099';
		$redactor = new Redactor( array( $secret ) );
		$report   = new Report(
			"0.1.0-$secret",
			'6.5.2',
			array(
				new Report_Entry(
					'a.b',
					'disk',
					Result::findings(
						new Finding(
							'a.b',
							Severity::warning(),
							"Title $secret",
							array(
								"key-$secret" => "value $secret",
								'plain'       => 7,
							),
							"Fix $secret"
						)
					)
				),
				new Report_Entry( 'a.c', 'disk', Result::skipped( "reason $secret" ) ),
				new Report_Entry( 'a.d', 'disk', Result::error( "error $secret" ) ),
				new Report_Entry( 'a.e', 'disk', Result::pass() ),
			),
			array( "notice $secret" )
		);

		$this->assertStringContainsString( $secret, serialize( $report ), 'The seed did not reach the report' );

		$redacted = $redactor->redact_report( $report );

		$this->assertStringNotContainsString( $secret, serialize( $redacted ) );
		$this->assertStringContainsString( '[redacted]', serialize( $redacted ) );
		$this->assertSame( 7, $redacted->entries()[0]->result()->finding_list()[0]->evidence()['plain'] );
		$this->assertSame( 'Title [redacted]', $redacted->entries()[0]->result()->finding_list()[0]->title() );
		$this->assertSame( 'a.b', $redacted->entries()[0]->check_id() );
		$this->assertSame( 'warning', $redacted->entries()[0]->result()->finding_list()[0]->severity()->name() );
	}

	public function test_redacted_evidence_keys_that_collide_stay_distinct(): void {
		$redactor = new Redactor( array( 'secretkey-1', 'secretkey-2' ) );
		$finding  = new Finding(
			'a.b',
			Severity::info(),
			'T',
			array(
				'secretkey-1' => 'x',
				'secretkey-2' => 'y',
			),
			'F'
		);
		$report   = new Report( '1', null, array( new Report_Entry( 'a.b', 'disk', Result::findings( $finding ) ) ), array() );

		$evidence = $redactor->redact_report( $report )->entries()[0]->result()->finding_list()[0]->evidence();

		$this->assertSame( array( 'x', 'y' ), array_values( $evidence ) );
		$this->assertCount( 2, $evidence );
	}

	public function test_a_weak_secret_masks_the_whole_token_it_appears_in(): void {
		$secrets  = array( 'wordpress', 'admin' );
		$redactor = new Redactor( $secrets );
		$text     = 'Generate values at https://api.wordpress.org/secret-key/1.1/salt/ for the administrator role'; // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- A host name, in lower case as it appears in URLs.

		foreach ( $secrets as $secret ) {
			$this->assertStringContainsString( $secret, $text );
		}
		$this->assertSame( 'Generate values at https:[redacted] for the [redacted] role', $redactor->redact_text( $text ) );
	}

	public function test_a_secret_after_a_separator_masks_only_the_value(): void {
		$redactor = new Redactor( array( 'hunter22' ) );

		$this->assertSame( 'password=[redacted]&user=jo', $redactor->redact_text( 'password=hunter22&user=jo' ) );
		$this->assertSame( '"key": "[redacted]", (x) [redacted]', $redactor->redact_text( '"key": "xhunter22x", (x) hunter22' ) );
	}

	public function test_overlapping_secrets_are_masked_as_one_token(): void {
		$redactor = new Redactor( array( 'alpha123', 'a123beta99' ) );

		$this->assertSame( 'x [redacted] y', $redactor->redact_text( 'x alpha123beta99 y' ) );
	}

	/**
	 * Further encodings of a secret, each inside a longer piece of text.
	 *
	 * @return array<string, array<int, string>>
	 */
	public function wider_encodings(): array {
		$cases = array(
			'unpadded base64'           => array( 'hunter22', 'token=aHVudGVyMjI' ),
			'base64url'                 => array( 'p>>?ss/w0rd', 'token=' . rtrim( strtr( base64_encode( 'p>>?ss/w0rd' ), '+/', '-_' ), '=' ) ),
			'lowercase percent hex'     => array( 'p@ss/w0rd', 'x=p%40ss%2fw0rd' ),
			'json with unescaped utf-8' => array( "p\xc3\xa4/sswd", 'x=p' . "\xc3\xa4" . '\/sswd' ),
		);
		foreach ( array( 'a', 'ro', 'roo', 'root', 'admin', 'deploy' ) as $user ) {
			$cases[ "basic auth for $user" ] = array( 'hunter22', 'Authorization: Basic ' . base64_encode( "$user:hunter22" ) );
		}

		return $cases;
	}

	/**
	 * @dataProvider wider_encodings
	 * @param string $secret Secret value.
	 * @param string $text   Text containing an encoding of it.
	 */
	public function test_a_secret_is_removed_in_wider_encodings( string $secret, string $text ): void {
		$redacted = ( new Redactor( array( $secret ) ) )->redact_text( $text );

		$this->assertNotSame( $text, $redacted );
		$this->assertStringEndsWith( '[redacted]', $redacted );
		$this->assertStringNotContainsString( $secret, $redacted );
	}

	public function test_the_wider_encodings_provider_covers_several_offsets(): void {
		$this->assertCount( 10, $this->wider_encodings() );
	}

	public function test_a_json_form_with_unescaped_utf8_matches_what_json_encode_produces(): void {
		$encoded = substr( (string) json_encode( "p\xc3\xa4/sswd", JSON_UNESCAPED_UNICODE ), 1, -1 );

		$this->assertSame( 'p' . "\xc3\xa4" . '\/sswd', $encoded );
	}

	public function test_a_long_token_repeating_a_secret_is_masked_once(): void {
		$text = str_repeat( 'hunter22', 100000 ) . ' tail';

		$this->assertSame( '[redacted] tail', ( new Redactor( array( 'hunter22' ) ) )->redact_text( $text ) );
	}

	public function test_a_numeric_evidence_value_equal_to_a_numeric_secret_is_masked(): void {
		$redactor = new Redactor( array( '12345678' ) );
		$finding  = new Finding(
			'a.b',
			Severity::info(),
			'T',
			array(
				'size'  => 12345678,
				'other' => 9,
			),
			'F'
		);
		$report   = new Report( '1', null, array( new Report_Entry( 'a.b', 'disk', Result::findings( $finding ) ) ), array() );

		$evidence = $redactor->redact_report( $report )->entries()[0]->result()->finding_list()[0]->evidence();

		$this->assertSame( '[redacted]', $evidence['size'] );
		$this->assertSame( 9, $evidence['other'] );
	}

	public function test_the_database_host_user_and_name_are_masked(): void {
		$config   = ( new Config_Parser() )->parse(
			"<?php\ndefine( 'DB_HOST', 'db.example.test:3307' );\ndefine( 'DB_USER', 'example_user' );\ndefine( 'DB_NAME', 'example_db' );"
		);
		$redactor = Redactor::for_config( $config );

		$text = $redactor->redact_text( "getaddrinfo for db.example.test failed\nAccess denied for user 'example_user' to database 'example_db'" );

		$this->assertStringNotContainsString( 'db.example.test', $text );
		$this->assertStringNotContainsString( 'example_user', $text );
		$this->assertStringNotContainsString( 'example_db', $text );
		$this->assertStringContainsString( '[redacted]', $text );
	}

	public function test_a_socket_path_in_the_database_host_is_masked(): void {
		$config = ( new Config_Parser() )->parse( "<?php\ndefine( 'DB_HOST', 'localhost:/srv/example/mysql.sock' );" );

		$text = Redactor::for_config( $config )->redact_text( 'No such file: /srv/example/mysql.sock' );

		$this->assertStringNotContainsString( '/srv/example/mysql.sock', $text );
	}

	public function test_default_database_names_that_reveal_nothing_are_left_readable(): void {
		$config = ( new Config_Parser() )->parse( "<?php\ndefine( 'DB_HOST', 'localhost' );\ndefine( 'DB_USER', 'root' );\ndefine( 'DB_NAME', 'WordPress' );" );

		$text = Redactor::for_config( $config )->redact_text( 'files above the site root; localhost; https://api.wordpress.org/' );

		$this->assertSame( 'files above the site root; localhost; https://api.wordpress.org/', $text );
	}
}
