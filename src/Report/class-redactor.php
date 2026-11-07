<?php
/**
 * Removes secrets from report text.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Report;

use WP_Doctor\Db\Mysqli_Driver;
use WP_Doctor\Site\Config;

/**
 * Replaces known secret values, in the encodings they are likely to appear in,
 * with a mask. The mask covers the whole token around a match, a token being a
 * run of characters between whitespace and the separators in SEPARATORS,
 * so where the mask lands does not reveal a secret that is a common word.
 * Secrets that could not be read from wp-config.php cannot be removed by value.
 * Values shorter than four characters are ignored, because masking them would
 * garble the output.
 *
 * @since 0.1.0
 */
final class Redactor {

	public const MASK = '[redacted]';

	private const MIN_LENGTH              = 4;
	private const UNREVEALING_IDENTIFIERS = array( 'localhost', '127.0.0.1', '::1', '[::1]', 'root', 'wordpress' );
	private const MIN_CORE                = 6;
	private const SECRET_NAME             = '/(PASSWORD|PASSWD|PASSPHRASE|SECRET|TOKEN|SALT|KEY|CREDENTIAL|PRIVATE)/i';
	private const SEPARATORS              = " \t\n\x0b\f\r\"'`=&,;:<>()[]{}";
	private const EMAIL                   = '/[A-Za-z0-9._%+\-]+@([A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,})/';

	/**
	 * Distinct secret values, each at least four characters long.
	 *
	 * @since 0.1.0
	 *
	 * @var string[]
	 */
	private $secrets = array();

	/**
	 * Pattern matching any encoding of any secret, longest first. Null when there are no secrets.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private $pattern;

	/**
	 * Whether to mask the local part of email addresses.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private $redact_emails;

	/**
	 * Collects the secrets and precomputes every encoding to mask.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $secrets       Values to mask. Duplicates and values shorter than four characters are dropped.
	 * @param bool     $redact_emails Whether to mask the local part of email addresses.
	 */
	public function __construct( array $secrets = array(), bool $redact_emails = false ) {
		$this->redact_emails = $redact_emails;

		$forms = array();
		foreach ( $secrets as $secret ) {
			$secret = (string) $secret;
			if ( strlen( $secret ) < self::MIN_LENGTH || in_array( $secret, $this->secrets, true ) ) {
				continue;
			}
			$this->secrets[] = $secret;
			$forms           = array_merge( $forms, $this->encodings_of( $secret ) );
		}
		$this->pattern = array() === $forms ? null : self::forms_pattern( $forms );
	}

	/**
	 * Pattern matching any of the forms, longest first.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $forms Encoded secrets.
	 * @return string
	 */
	private static function forms_pattern( array $forms ): string {
		$forms = array_values( array_unique( $forms ) );
		usort(
			$forms,
			static function ( string $a, string $b ): int {
				return strlen( $b ) <=> strlen( $a );
			}
		);
		$quoted = array_map(
			static function ( string $form ): string {
				return preg_quote( $form, '/' );
			},
			$forms
		);

		return '/' . implode( '|', $quoted ) . '/';
	}

	/**
	 * Builds a redactor for the values in wp-config.php whose names suggest a secret.
	 *
	 * @since 0.1.0
	 *
	 * @param Config $config        Parsed wp-config.php.
	 * @param bool   $redact_emails Whether to mask the local part of email addresses.
	 * @return self
	 */
	public static function for_config( Config $config, bool $redact_emails = false ): self {
		$secrets = array();
		foreach ( $config->constants() as $name => $value ) {
			if ( 1 === preg_match( self::SECRET_NAME, (string) $name ) && ( is_string( $value ) || is_int( $value ) || is_float( $value ) ) ) {
				$secrets[] = (string) $value;
			}
		}
		foreach ( array( 'DB_HOST', 'DB_USER', 'DB_NAME' ) as $name ) {
			$value = $config->has( $name ) ? $config->value( $name ) : null;
			if ( is_string( $value ) ) {
				$secrets = array_merge( $secrets, self::connection_identifiers( $value ) );
			}
		}

		return new self( $secrets, $redact_emails );
	}

	/**
	 * The parts of a database host, user or name worth masking: the whole
	 * value and, for a host, its name and socket path on their own. Defaults
	 * such as localhost and root say nothing about the site, and masking them
	 * would garble every report that mentions the site root, so they are left.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value DB_HOST, DB_USER or DB_NAME, or the same from the environment.
	 * @return string[]
	 */
	public static function connection_identifiers( string $value ): array {
		$parts   = array( $value );
		$host    = Mysqli_Driver::split_host( $value );
		$parts[] = $host['host'];
		$parts[] = $host['socket'];

		return array_values(
			array_filter(
				array_unique( $parts ),
				static function ( string $part ): bool {
					return '' !== $part && ! in_array( strtolower( $part ), self::UNREVEALING_IDENTIFIERS, true );
				}
			)
		);
	}

	/**
	 * A copy that also removes another secret.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value Additional value to mask.
	 * @return self
	 */
	public function with_secret( string $value ): self {
		return new self( array_merge( $this->secrets, array( $value ) ), $this->redact_emails );
	}

	/**
	 * Masks every known secret in a piece of text, and email local parts when enabled.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Text that may contain a secret or an email address.
	 * @return string
	 * @throws \RuntimeException When the pattern cannot be applied, so unmasked text is never returned.
	 */
	public function redact_text( string $text ): string {
		if ( null !== $this->pattern ) {
			$text = $this->mask_tokens( $text );
		}
		if ( $this->redact_emails ) {
			$text = (string) preg_replace( self::EMAIL, '***@$1', $text );
		}

		return $text;
	}

	/**
	 * Replaces each token that contains a secret with the mask. A token reaches
	 * out from the match to the nearest separator on each side, and takes in
	 * base64 padding that ends it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Text that may contain a secret.
	 * @return string
	 * @throws \RuntimeException When the pattern cannot be applied, so unmasked text is never returned.
	 */
	private function mask_tokens( string $text ): string {
		if ( false === preg_match_all( (string) $this->pattern, $text, $found, PREG_OFFSET_CAPTURE ) ) {
			throw new \RuntimeException( 'Secrets could not be removed from the report text' );
		}

		$ranges = array();
		$floor  = 0;
		foreach ( $found[0] as $match ) {
			$at = (int) $match[1];
			if ( $at + strlen( $match[0] ) <= $floor ) {
				continue;
			}
			$start = $at;
			while ( $start > $floor && false === strpos( self::SEPARATORS, $text[ $start - 1 ] ) ) {
				--$start;
			}
			$end  = $at + strlen( $match[0] );
			$end += strcspn( $text, self::SEPARATORS, $end );
			$pad  = strspn( $text, '=', $end, 2 );
			if ( $pad > 0 && strcspn( $text, self::SEPARATORS, $end + $pad ) === 0 ) {
				$end += $pad;
			}

			$last = count( $ranges ) - 1;
			if ( $last >= 0 && $start <= $ranges[ $last ][1] ) {
				$ranges[ $last ][1] = max( $ranges[ $last ][1], $end );
			} else {
				$ranges[] = array( $start, $end );
			}
			$floor = max( $floor, $end );
		}

		$masked = '';
		$copied = 0;
		foreach ( $ranges as $range ) {
			$masked .= substr( $text, $copied, $range[0] - $copied ) . self::MASK;
			$copied  = $range[1];
		}

		return $masked . substr( $text, $copied );
	}

	/**
	 * Returns a copy of the report with secrets removed from every text field.
	 *
	 * @since 0.1.0
	 *
	 * @param Report $report Report as produced by the scan. It is not modified.
	 * @return Report
	 */
	public function redact_report( Report $report ): Report {
		$entries = array();
		foreach ( $report->entries() as $entry ) {
			$entries[] = new Report_Entry(
				$entry->check_id(),
				$entry->category(),
				$this->redact_result( $entry->result() )
			);
		}

		return new Report(
			$this->redact_text( $report->tool_version() ),
			null === $report->wp_version() ? null : $this->redact_text( $report->wp_version() ),
			$entries,
			array_map( array( $this, 'redact_text' ), $report->notices() ),
			$report->ignored_ids()
		);
	}

	/**
	 * Copies a result with its findings or message redacted.
	 *
	 * @since 0.1.0
	 *
	 * @param Result $result Result to copy.
	 * @return Result
	 * @throws \LogicException When the result has an unknown status.
	 */
	private function redact_result( Result $result ): Result {
		switch ( $result->status() ) {
			case Result::PASS:
				return Result::pass();
			case Result::FINDINGS:
				return Result::findings( ...array_map( array( $this, 'redact_finding' ), $result->finding_list() ) );
			case Result::SKIPPED:
				return Result::skipped( $this->redact_text( (string) $result->message() ) );
			case Result::ERROR:
				return Result::error( $this->redact_text( (string) $result->message() ) );
		}

		throw new \LogicException( 'Unknown result status' );
	}

	/**
	 * Copies a finding with its title, fix and evidence redacted. An evidence
	 * key that collides with an earlier one after redaction gets a `#2`, `#3`
	 * suffix, so no value is lost.
	 *
	 * @since 0.1.0
	 *
	 * @param Finding $finding Finding to copy.
	 * @return Finding
	 */
	private function redact_finding( Finding $finding ): Finding {
		$evidence = array();
		foreach ( $finding->evidence() as $key => $value ) {
			$new_key = $this->redact_text( (string) $key );
			$base    = $new_key;
			$n       = 2;
			while ( array_key_exists( $new_key, $evidence ) ) {
				$new_key = $base . '#' . $n;
				++$n;
			}
			$evidence[ $new_key ] = $this->redact_value( $value );
		}

		return new Finding(
			$finding->id(),
			$finding->severity(),
			$this->redact_text( $finding->title() ),
			$evidence,
			$this->redact_text( $finding->fix() )
		);
	}

	/**
	 * Masks an evidence value. A number is masked only when it equals a secret exactly.
	 *
	 * @since 0.1.0
	 *
	 * @param string|int|float|bool $value Evidence value.
	 * @return string|int|float|bool
	 */
	private function redact_value( $value ) {
		if ( is_string( $value ) ) {
			return $this->redact_text( $value );
		}
		if ( ( is_int( $value ) || is_float( $value ) ) && in_array( (string) $value, $this->secrets, true ) ) {
			return self::MASK;
		}

		return $value;
	}

	/**
	 * Forms in which a secret may appear in output or in a URL.
	 *
	 * @since 0.1.0
	 *
	 * @param string $secret Secret value.
	 * @return string[]
	 */
	private function encodings_of( string $secret ): array {
		$base64  = base64_encode( $secret ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Matches the encoded variant of a secret.
		$forms   = array(
			$secret,
			rawurlencode( $secret ),
			urlencode( $secret ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode -- Matches the form-encoded variant of a secret.
			$this->json_inner( $secret, 0 ),
			$this->json_inner( $secret, JSON_UNESCAPED_SLASHES ),
			$this->json_inner( $secret, JSON_UNESCAPED_UNICODE ),
			$this->json_inner( $secret, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			addslashes( $secret ),
			htmlspecialchars( $secret, ENT_QUOTES ),
			$base64,
			rtrim( $base64, '=' ),
		);
		$forms[] = self::lowercase_hex( $forms[1] );
		$forms[] = self::lowercase_hex( $forms[2] );
		$forms   = array_filter(
			$forms,
			static function ( string $form ): bool {
				return strlen( $form ) >= self::MIN_LENGTH;
			}
		);

		$cores = array();
		for ( $offset = 0; $offset < 3; $offset++ ) {
			$cores[] = self::base64_core( $secret, $offset );
		}
		$cores = array_filter(
			$cores,
			static function ( string $core ): bool {
				return strlen( $core ) >= self::MIN_CORE;
			}
		);

		$base64_forms = array_merge( array( $base64, rtrim( $base64, '=' ) ), $cores );
		foreach ( $base64_forms as $form ) {
			$cores[] = strtr( $form, '+/', '-_' );
		}

		return array_values( array_unique( array_merge( $forms, $cores ) ) );
	}

	/**
	 * Percent-encoded text with its hex digits in lower case.
	 *
	 * @since 0.1.0
	 *
	 * @param string $encoded Percent-encoded text.
	 * @return string
	 */
	private static function lowercase_hex( string $encoded ): string {
		return (string) preg_replace_callback(
			'/%[0-9A-F]{2}/',
			static function ( array $found ): string {
				return strtolower( $found[0] );
			},
			$encoded
		);
	}

	/**
	 * The base64 characters that encode only the secret's own bits when it
	 * follows $offset bytes (modulo 3) inside a longer encoded string, such as
	 * the password in a Basic authorization header. Characters that also carry
	 * bits of the neighbouring bytes are trimmed at both ends.
	 *
	 * @since 0.1.0
	 *
	 * @param string $secret Secret value.
	 * @param int    $offset Bytes before the secret, 0 to 2.
	 * @return string
	 */
	private static function base64_core( string $secret, int $offset ): string {
		$encoded = base64_encode( str_repeat( "\0", $offset ) . $secret ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Matches the encoded variant of a secret.
		$first   = (int) ceil( 8 * $offset / 6 );
		$end     = intdiv( 8 * ( $offset + strlen( $secret ) ), 6 );

		return $end > $first ? (string) substr( $encoded, $first, $end - $first ) : '';
	}

	/**
	 * A string as it appears inside a JSON document, without the quotes.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value String to encode.
	 * @param int    $flags Flags for json_encode().
	 * @return string Empty when the value cannot be encoded.
	 */
	private function json_inner( string $value, int $flags ): string {
		$encoded = json_encode( $value, $flags );

		return is_string( $encoded ) ? (string) substr( $encoded, 1, -1 ) : '';
	}
}
