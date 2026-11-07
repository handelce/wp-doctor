<?php
/**
 * Published indicators of attacker-created administrator accounts.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Security;

use function WP_Doctor\esc_cli;

/**
 * Login names from public incident reports, logins that are never reported,
 * and the email-domain sets the administrator check judges against. The
 * bundled data lives in data/ at the repository root. Tests pass their own.
 *
 * @since 0.1.0
 */
final class Rogue_Admin_Indicators {

	public const STRONG = 'strong';
	public const SOFT   = 'soft';

	/**
	 * Name entries: login, match (exact or prefix), signal, campaign, source, optional email.
	 *
	 * @since 0.1.0
	 *
	 * @var array<int, array<string, string>>
	 */
	private $names;

	/**
	 * Lowercase logins that are never reported, as keys.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, true>
	 */
	private $allowlist;

	/**
	 * Lowercase placeholder domains, as keys.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, true>
	 */
	private $placeholder_domains;

	/**
	 * Lowercase placeholder top-level domains, as keys.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, true>
	 */
	private $placeholder_tlds;

	/**
	 * Lowercase disposable-mail domains, as keys.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, true>
	 */
	private $disposable_domains;

	/**
	 * Validates the name entries and lowercases every list.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array<string, string>> $names               Entries with login, match, signal, campaign, source and an optional email.
	 * @param string[]                          $allowlist           Logins never reported.
	 * @param string[]                          $placeholder_domains Reserved domains such as example.com.
	 * @param string[]                          $placeholder_tlds    Reserved top-level domains such as test and invalid.
	 * @param string[]                          $disposable_domains  Domains of disposable-mail services.
	 * @throws \UnexpectedValueException When a name entry is malformed.
	 */
	public function __construct( array $names, array $allowlist, array $placeholder_domains, array $placeholder_tlds, array $disposable_domains ) {
		$this->names = array();
		foreach ( $names as $index => $entry ) {
			$this->names[] = $this->validated_entry( $entry, (int) $index );
		}
		$this->allowlist           = $this->lowercase_set( $allowlist );
		$this->placeholder_domains = $this->lowercase_set( $placeholder_domains );
		$this->placeholder_tlds    = $this->lowercase_set( $placeholder_tlds );
		$this->disposable_domains  = $this->lowercase_set( $disposable_domains );
	}

	/**
	 * The indicator set shipped in data/. Domains in `mail_providers` are left
	 * out of the disposable list, so a refresh from upstream cannot put them back.
	 *
	 * @since 0.1.0
	 *
	 * @return self
	 * @throws \UnexpectedValueException When a data file is missing or malformed.
	 */
	public static function bundled(): self {
		$directory = dirname( __DIR__, 4 ) . '/data';
		$json      = self::read_data_file( $directory . '/rogue-admin-indicators.json' );
		$data      = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			throw new \UnexpectedValueException( 'data/rogue-admin-indicators.json is not a JSON object' );
		}
		foreach ( array( 'names', 'allowlist', 'placeholder_domains', 'placeholder_tlds', 'mail_providers' ) as $key ) {
			if ( ! isset( $data[ $key ] ) || ! is_array( $data[ $key ] ) ) {
				throw new \UnexpectedValueException( esc_cli( "data/rogue-admin-indicators.json has no \"$key\" list" ) );
			}
		}

		$domains = array();
		foreach ( preg_split( '/\R/', self::read_data_file( $directory . '/disposable-email-domains.txt' ) ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] || in_array( $line, $data['mail_providers'], true ) ) {
				continue;
			}
			$domains[] = $line;
		}
		if ( array() === $domains ) {
			throw new \UnexpectedValueException( 'data/disposable-email-domains.txt lists no domains' );
		}

		return new self( $data['names'], $data['allowlist'], $data['placeholder_domains'], $data['placeholder_tlds'], $domains );
	}

	/**
	 * The name entry a login matches, if any.
	 *
	 * @since 0.1.0
	 *
	 * @param string $login Login as stored.
	 * @return array<string, string>|null Entry with login, match, signal, campaign, source and optional email.
	 */
	public function name_indicator( string $login ): ?array {
		$login = strtolower( $login );
		foreach ( $this->names as $entry ) {
			if ( 'exact' === $entry['match'] ? $login === $entry['login'] : 0 === strpos( $login, $entry['login'] ) ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Whether a login is never reported.
	 *
	 * @since 0.1.0
	 *
	 * @param string $login Login as stored.
	 * @return bool
	 */
	public function is_allowlisted( string $login ): bool {
		return isset( $this->allowlist[ strtolower( $login ) ] );
	}

	/**
	 * True for a placeholder domain, a reserved top-level domain, or a name with no dot.
	 *
	 * @since 0.1.0
	 *
	 * @param string $domain Lowercase domain.
	 * @return bool
	 */
	public function is_placeholder_domain( string $domain ): bool {
		if ( isset( $this->placeholder_domains[ $domain ] ) || false === strpos( $domain, '.' ) ) {
			return true;
		}
		$tld = substr( $domain, strrpos( $domain, '.' ) + 1 );

		return isset( $this->placeholder_tlds[ $tld ] );
	}

	/**
	 * Whether an email domain, or a domain above it, is a disposable-mail service.
	 *
	 * @since 0.1.0
	 *
	 * @param string $domain Lowercase domain.
	 * @return bool
	 */
	public function is_disposable_domain( string $domain ): bool {
		while ( '' !== $domain ) {
			if ( isset( $this->disposable_domains[ $domain ] ) ) {
				return true;
			}
			$dot = strpos( $domain, '.' );
			if ( false === $dot ) {
				return false;
			}
			$domain = substr( $domain, $dot + 1 );
		}

		return false;
	}

	/**
	 * Every name entry.
	 *
	 * @since 0.1.0
	 *
	 * @return array<int, array<string, string>>
	 */
	public function names(): array {
		return $this->names;
	}

	/**
	 * Number of disposable-mail domains.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function disposable_domain_count(): int {
		return count( $this->disposable_domains );
	}

	/**
	 * Checks one name entry's shape and lowercases its login.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $entry One element of the names list, as decoded from JSON.
	 * @param int   $index Position, for the error message.
	 * @return array<string, string>
	 * @throws \UnexpectedValueException When a field is missing or wrong.
	 */
	private function validated_entry( $entry, int $index ): array {
		if ( ! is_array( $entry ) ) {
			throw new \UnexpectedValueException( esc_cli( "Name indicator $index is not an object" ) );
		}
		foreach ( array( 'login', 'match', 'signal', 'campaign', 'source' ) as $field ) {
			if ( ! isset( $entry[ $field ] ) || ! is_string( $entry[ $field ] ) || '' === $entry[ $field ] ) {
				throw new \UnexpectedValueException( esc_cli( "Name indicator $index has no \"$field\"" ) );
			}
		}
		if ( ! in_array( $entry['match'], array( 'exact', 'prefix' ), true ) ) {
			throw new \UnexpectedValueException( esc_cli( "Name indicator $index has an unknown match type: " . $entry['match'] ) );
		}
		if ( ! in_array( $entry['signal'], array( self::STRONG, self::SOFT ), true ) ) {
			throw new \UnexpectedValueException( esc_cli( "Name indicator $index has an unknown signal: " . $entry['signal'] ) );
		}
		if ( 0 !== strpos( $entry['source'], 'https://' ) ) {
			throw new \UnexpectedValueException( esc_cli( "Name indicator $index has a source that is not an https URL: " . $entry['source'] ) );
		}
		if ( isset( $entry['email'] ) && ( ! is_string( $entry['email'] ) || false === strpos( $entry['email'], '@' ) ) ) {
			throw new \UnexpectedValueException( esc_cli( "Name indicator $index has an email that is not an address" ) );
		}

		$clean = array(
			'login'    => strtolower( $entry['login'] ),
			'match'    => $entry['match'],
			'signal'   => $entry['signal'],
			'campaign' => $entry['campaign'],
			'source'   => $entry['source'],
		);
		if ( isset( $entry['email'] ) ) {
			$clean['email'] = strtolower( $entry['email'] );
		}

		return $clean;
	}

	/**
	 * Lowercase strings as array keys.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, mixed> $values Values from one indicator list.
	 * @return array<string, true>
	 * @throws \UnexpectedValueException When a value is not a string.
	 */
	private function lowercase_set( array $values ): array {
		$set = array();
		foreach ( $values as $value ) {
			if ( ! is_string( $value ) || '' === $value ) {
				throw new \UnexpectedValueException( 'An indicator list holds a value that is not a non-empty string' );
			}
			$set[ strtolower( $value ) ] = true;
		}

		return $set;
	}

	/**
	 * Reads a file shipped with wp-doctor.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Absolute path inside the repository.
	 * @return string
	 * @throws \UnexpectedValueException When the file cannot be read.
	 */
	private static function read_data_file( string $path ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A data file shipped with wp-doctor, never a site path.
		$content = is_file( $path ) ? file_get_contents( $path ) : false;
		if ( false === $content || '' === $content ) {
			throw new \UnexpectedValueException( esc_cli( 'Cannot read the bundled data file ' . basename( $path ) ) );
		}

		return $content;
	}
}
