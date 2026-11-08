<?php
/**
 * Settings from a wp-doctor.json file.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Cli;

use function WP_Doctor\esc_cli;

/**
 * Strictly validated: an unknown key is an error, so a typo cannot silently
 * disable anything. Check ids in "thresholds" and "ignore" are checked against
 * the registry by assert_check_ids() before a scan uses them.
 *
 * @since 0.1.0
 */
final class Tool_Config {

	private const MAX_BYTES = 65536;
	private const KEYS      = array( 'thresholds', 'ignore', 'exclude' );
	private const CHECK_ID  = '/^[a-z]+(\.[a-z]+){1,3}$/';

	/**
	 * Where the settings came from, for the report. Null when there is no file.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private $source;

	/**
	 * Globs from the "exclude" key.
	 *
	 * @since 0.1.0
	 *
	 * @var string[]
	 */
	private $excludes;

	/**
	 * Check ids to silence.
	 *
	 * @since 0.1.0
	 *
	 * @var string[]
	 */
	private $ignore;

	/**
	 * Info, warning and critical limits by check id.
	 *
	 * @since 0.1.0
	 *
	 * @var array<string, array<string, int>>
	 */
	private $thresholds;

	/**
	 * Use none() or from_json().
	 *
	 * @since 0.1.0
	 *
	 * @param string|null                       $source     Origin of the settings, or null when there is no file.
	 * @param string[]                          $excludes   Exclude globs.
	 * @param string[]                          $ignore     Check ids to silence.
	 * @param array<string, array<string, int>> $thresholds Info, warning and critical limits by check id.
	 */
	private function __construct( ?string $source, array $excludes, array $ignore, array $thresholds ) {
		$this->source     = $source;
		$this->excludes   = $excludes;
		$this->ignore     = $ignore;
		$this->thresholds = $thresholds;
	}

	/**
	 * Settings for a run without a config file.
	 *
	 * @since 0.1.0
	 *
	 * @return self
	 */
	public static function none(): self {
		return new self( null, array(), array(), array() );
	}

	/**
	 * Parses and validates the contents of a wp-doctor.json file of at most 64 KiB.
	 *
	 * @since 0.1.0
	 *
	 * @param string $json   Contents of the file.
	 * @param string $source Where it came from, for the report and messages.
	 * @return self
	 * @throws Config_File_Exception When the document is not valid.
	 */
	public static function from_json( string $json, string $source ): self {
		$label = $source;
		if ( strlen( $json ) > self::MAX_BYTES ) {
			throw new Config_File_Exception( esc_cli( "$label is larger than " . self::MAX_BYTES . ' bytes' ) );
		}

		if ( 0 === strpos( $json, "\xef\xbb\xbf" ) ) {
			$json = substr( $json, 3 );
		}

		$data = json_decode( $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new Config_File_Exception( esc_cli( "$label is not valid JSON: " . json_last_error_msg() ) );
		}
		$repeated = self::repeated_key( $json );
		if ( null !== $repeated ) {
			throw new Config_File_Exception( esc_cli( "Key \"$repeated\" appears more than once in $label" ) );
		}
		$shape = json_decode( $json );
		if ( ! is_array( $data ) || ! $shape instanceof \stdClass ) {
			throw new Config_File_Exception( esc_cli( "$label must be a JSON object" ) );
		}
		self::assert_threshold_shape( $shape, $label );

		foreach ( array_keys( $data ) as $key ) {
			if ( ! in_array( $key, self::KEYS, true ) ) {
				throw new Config_File_Exception( esc_cli( "Unknown key \"$key\" in $source; expected: " . implode( ', ', self::KEYS ) ) );
			}
		}

		return new self(
			$source,
			self::string_list( $data, 'exclude', $label, false ),
			self::string_list( $data, 'ignore', $label, true ),
			self::read_thresholds( $data['thresholds'] ?? array(), $label )
		);
	}

	/**
	 * The first key that appears twice in one object, which json_decode()
	 * would otherwise settle silently by keeping the last.
	 *
	 * @since 0.1.0
	 *
	 * @param string $json A document json_decode() has accepted.
	 * @return string|null The key as decoded, or null when every key is unique.
	 */
	private static function repeated_key( string $json ): ?string {
		preg_match_all( '/"(?:[^"\\\\]|\\\\.)*"|[{}\[\],]/s', $json, $tokens );
		$stack = array();
		foreach ( $tokens[0] as $token ) {
			$top = count( $stack ) - 1;
			if ( '{' === $token || '[' === $token ) {
				$stack[] = array(
					'object'   => '{' === $token,
					'keys'     => array(),
					'want_key' => '{' === $token,
				);
			} elseif ( '}' === $token || ']' === $token ) {
				array_pop( $stack );
			} elseif ( ',' === $token ) {
				$stack[ $top ]['want_key'] = $stack[ $top ]['object'];
			} elseif ( $top >= 0 && $stack[ $top ]['want_key'] ) {
				$key = (string) json_decode( $token );
				if ( isset( $stack[ $top ]['keys'][ $key ] ) ) {
					return $key;
				}
				$stack[ $top ]['keys'][ $key ] = true;
				$stack[ $top ]['want_key']     = false;
			}
		}

		return null;
	}

	/**
	 * Where the settings came from. Null when there is no file.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null
	 */
	public function source(): ?string {
		return $this->source;
	}

	/**
	 * Globs from the "exclude" key.
	 *
	 * @since 0.1.0
	 *
	 * @return string[]
	 */
	public function excludes(): array {
		return $this->excludes;
	}

	/**
	 * Check ids to silence.
	 *
	 * @since 0.1.0
	 *
	 * @return string[]
	 */
	public function ignore(): array {
		return $this->ignore;
	}

	/**
	 * Info, warning and critical limits by check id.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, array<string, int>>
	 */
	public function thresholds(): array {
		return $this->thresholds;
	}

	/**
	 * Refuses a check id in "ignore" or "thresholds" that no registered check has.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $known_ids Ids of the registered checks.
	 * @throws Config_File_Exception When an id is not one of them.
	 */
	public function assert_check_ids( array $known_ids ): void {
		foreach ( array(
			'ignore'     => $this->ignore,
			'thresholds' => array_keys( $this->thresholds ),
		) as $key => $ids ) {
			foreach ( $ids as $id ) {
				if ( ! in_array( (string) $id, $known_ids, true ) ) {
					throw new Config_File_Exception( esc_cli( "\"$id\" in \"$key\" is not a check this version knows" . ( null === $this->source ? '' : " ($this->source)" ) ) );
				}
			}
		}
	}

	/**
	 * Why an exclude glob can never match, or null when it can. Walks match
	 * paths relative to the site root, such as wp-content/cache/x, so a glob
	 * that is absolute, climbs out with .., or starts with ./ would be listed
	 * as active while excluding nothing.
	 *
	 * @since 0.1.0
	 *
	 * @param string $glob Exclude glob.
	 * @return string|null The end of a sentence that starts with the glob.
	 */
	public static function exclude_problem( string $glob ): ?string {
		$glob = str_replace( '\\', '/', $glob );
		if ( '/' === $glob[0] || 1 === preg_match( '#^[A-Za-z]:/#', $glob ) ) {
			return 'is an absolute path; excludes are relative to the site root, such as wp-content/cache/**';
		}
		if ( in_array( '..', explode( '/', $glob ), true ) ) {
			return 'climbs out of the site root with ..; excludes can only name paths inside it';
		}
		if ( 0 === strpos( $glob, './' ) ) {
			return 'starts with ./; write the path without it, such as wp-content/cache/**';
		}

		return null;
	}

	/**
	 * Reads the optional list of non-empty strings under $key.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $data      Decoded JSON object.
	 * @param string               $key       Key to read.
	 * @param string               $label     File label for messages.
	 * @param bool                 $check_ids Whether every entry must be a check id.
	 * @return string[]
	 * @throws Config_File_Exception When the value is not a list of valid strings.
	 */
	private static function string_list( array $data, string $key, string $label, bool $check_ids ): array {
		if ( ! array_key_exists( $key, $data ) ) {
			return array();
		}
		$list = $data[ $key ];
		if ( ! is_array( $list ) || array_values( $list ) !== $list ) {
			throw new Config_File_Exception( esc_cli( "\"$key\" must be a list of strings in $label" ) );
		}
		foreach ( $list as $entry ) {
			if ( ! is_string( $entry ) || '' === $entry || false !== strpos( $entry, "\0" ) ) {
				throw new Config_File_Exception( esc_cli( "\"$key\" must hold only non-empty strings in $label" ) );
			}
			if ( $check_ids && 1 !== preg_match( self::CHECK_ID, $entry ) ) {
				throw new Config_File_Exception( esc_cli( "\"$entry\" in \"$key\" is not a check id such as perf.autoload.size" ) );
			}
			$problem = 'exclude' === $key ? self::exclude_problem( $entry ) : null;
			if ( null !== $problem ) {
				throw new Config_File_Exception( esc_cli( "\"$entry\" in \"$key\" $problem in $label" ) );
			}
		}

		return $list;
	}

	/**
	 * Checks the parts of the thresholds that decode to the same PHP array
	 * whether written as an object or a list: "thresholds" itself and each
	 * check's levels must be objects, and a check's levels must set something.
	 *
	 * @since 0.1.0
	 *
	 * @param \stdClass $shape Document decoded with objects kept.
	 * @param string    $label File label for messages.
	 * @throws Config_File_Exception When a part has the wrong shape.
	 */
	private static function assert_threshold_shape( \stdClass $shape, string $label ): void {
		if ( ! property_exists( $shape, 'thresholds' ) ) {
			return;
		}
		if ( ! $shape->thresholds instanceof \stdClass ) {
			throw new Config_File_Exception( esc_cli( "\"thresholds\" must be an object in $label" ) );
		}
		foreach ( get_object_vars( $shape->thresholds ) as $id => $levels ) {
			if ( ! $levels instanceof \stdClass ) {
				throw new Config_File_Exception( esc_cli( "The thresholds for \"$id\" must be an object in $label" ) );
			}
			if ( array() === get_object_vars( $levels ) ) {
				throw new Config_File_Exception( esc_cli( "The thresholds for \"$id\" set no level in $label; give info, warning or critical" ) );
			}
		}
	}

	/**
	 * Reads the thresholds object.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed  $thresholds Value of the thresholds key.
	 * @param string $label      File label for messages.
	 * @return array<string, array<string, int>>
	 * @throws Config_File_Exception When it is not a valid thresholds object.
	 */
	private static function read_thresholds( $thresholds, string $label ): array {
		if ( ! is_array( $thresholds ) ) {
			throw new Config_File_Exception( esc_cli( "\"thresholds\" must be an object in $label" ) );
		}

		$result = array();
		foreach ( $thresholds as $id => $levels ) {
			$id = (string) $id;
			if ( 1 !== preg_match( self::CHECK_ID, $id ) ) {
				throw new Config_File_Exception( esc_cli( "\"$id\" in \"thresholds\" is not a check id such as perf.autoload.size" ) );
			}
			if ( ! is_array( $levels ) ) {
				throw new Config_File_Exception( esc_cli( "The thresholds for \"$id\" must be an object in $label" ) );
			}
			foreach ( $levels as $level => $limit ) {
				if ( ! in_array( $level, array( 'info', 'warning', 'critical' ), true ) ) {
					throw new Config_File_Exception( esc_cli( "Unknown key \"$level\" in the thresholds for \"$id\"; expected: info, warning, critical" ) );
				}
				if ( ! is_int( $limit ) || $limit < 1 ) {
					throw new Config_File_Exception( esc_cli( "The $level threshold for \"$id\" must be a positive integer" ) );
				}
			}
			if ( isset( $levels['warning'], $levels['critical'] ) && $levels['warning'] >= $levels['critical'] ) {
				throw new Config_File_Exception( esc_cli( "The warning threshold for \"$id\" must be below the critical threshold" ) );
			}
			$result[ $id ] = $levels;
		}

		return $result;
	}
}
