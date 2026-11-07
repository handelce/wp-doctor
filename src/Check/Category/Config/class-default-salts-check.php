<?php
/**
 * Missing, placeholder or repeated keys and salts.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Config;

use WP_Doctor\Check\Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

/**
 * The keys and salts sign login cookies. If they are the sample text or are
 * shared, cookies can be forged. A short value is likely guessable; the
 * WordPress generator gives 64 characters. Values are compared and measured
 * but never reported.
 *
 * @since 0.1.0
 */
final class Default_Salts_Check implements Check {

	private const NAMES = array(
		'AUTH_KEY',
		'SECURE_AUTH_KEY',
		'LOGGED_IN_KEY',
		'NONCE_KEY',
		'AUTH_SALT',
		'SECURE_AUTH_SALT',
		'LOGGED_IN_SALT',
		'NONCE_SALT',
	);

	private const PLACEHOLDER = 'put your unique phrase here';

	private const MIN_CHARACTERS = 32;

	/**
	 * Id under which key and salt problems are reported.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'config.salts.default';
	}

	/**
	 * Config, alongside the other wp-config.php checks.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'config';
	}

	/**
	 * Checks the eight keys and salts.
	 *
	 * @since 0.1.0
	 *
	 * @param Context $context Holds the keys and salts parsed from wp-config.php, and the count of includes it could not follow.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$config      = $context->config();
		$includes    = $config->unread_code() > 0;
		$problems    = array();
		$not_read    = array();
		$short       = array();
		$first_named = array();
		foreach ( self::NAMES as $name ) {
			if ( ! $config->has( $name ) ) {
				if ( array_key_exists( $name, $config->unresolved() ) || $includes ) {
					$not_read[] = $name;
				} else {
					$problems[ $name ] = 'not defined in wp-config.php';
				}
				continue;
			}

			$value = $config->value( $name );
			if ( ! is_string( $value ) || '' === trim( $value ) ) {
				$problems[ $name ] = 'empty';
			} elseif ( false !== stripos( $value, self::PLACEHOLDER ) ) {
				$problems[ $name ] = 'still the sample placeholder text';
			} elseif ( isset( $first_named[ $value ] ) ) {
				$problems[ $name ] = 'same value as ' . $first_named[ $value ];
			} else {
				$first_named[ $value ] = $name;
				$length                = $this->characters( $value );
				if ( $length < self::MIN_CHARACTERS ) {
					$short[ $name ] = "$length characters, under " . self::MIN_CHARACTERS;
				}
			}
		}

		if ( array() === $problems && array() === $short ) {
			return array() === $not_read
				? Result::pass()
				: Result::skipped( 'wp-config.php values unavailable: ' . implode( ', ', $not_read ) );
		}

		$fix = 'Generate fresh random values at https://api.wordpress.org/secret-key/1.1/salt/ and paste all eight define() lines '
			. 'over the old ones in wp-config.php. Everyone is logged out once when you do.';
		if ( array() !== $not_read ) {
			if ( array() !== $problems ) {
				$problems['not_read'] = implode( ', ', $not_read );
			} else {
				$short['not_read'] = implode( ', ', $not_read );
			}
		}
		$findings = array();
		if ( array() !== $problems ) {
			$findings[] = new Finding( $this->id(), Severity::critical(), 'Authentication keys and salts are missing, sample text or repeated', $problems, $fix );
		}
		if ( array() !== $short ) {
			$findings[] = new Finding( $this->id(), Severity::warning(), 'Authentication keys or salts are likely too short to be secret', $short, $fix );
		}

		return Result::findings( ...$findings );
	}

	/**
	 * Length in characters, or in bytes when the value is not valid UTF-8.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value Key or salt.
	 * @return int
	 */
	private function characters( string $value ): int {
		$count = preg_match_all( '/./su', $value );

		return false === $count ? strlen( $value ) : $count;
	}
}
