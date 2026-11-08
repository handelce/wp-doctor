<?php
/**
 * PHP that decodes a hidden string and runs it.
 *
 * @since 0.2.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Security;

use WP_Doctor\Check\Context;
use WP_Doctor\Check\Runs_Without_Config;
use WP_Doctor\Fs\Path_Unavailable_Exception;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

/**
 * Injected code is usually packed so that it cannot be read or searched for:
 * eval( gzinflate( base64_decode( '...' ) ) ) and its variants. This check
 * reads every PHP file in the site, and wp-config.php wherever it is, and
 * reports eval(), assert() or create_function() whose argument comes from a
 * decoder or a long encoded string, directly or through a variable assigned
 * in the same file. Plugins occasionally do this for their own reasons, so
 * findings say "likely".
 *
 * @since 0.2.0
 */
final class Obfuscated_Code_Check implements Runs_Without_Config {

	private const RUNNERS = array( 'eval', 'assert', 'create_function' );

	/**
	 * Id of the obfuscated code finding.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'sec.code.obfuscated';
	}

	/**
	 * Grouped under security.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'security';
	}

	/**
	 * Looks for decoded or encoded data passed to code-running functions.
	 *
	 * @since 0.2.0
	 *
	 * @param Context $context Gives the scanner that walks and reads the PHP files.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$sources = new Php_Sources( $context, array( self::RUNNERS ) );
		try {
			$found = $sources->hits(
				static function ( array $hit ): bool {
					return in_array( $hit['sink'], self::RUNNERS, true )
						&& ( in_array( Code_Sinks::DECODED, $hit['traits'], true ) || in_array( Code_Sinks::ENCODED, $hit['traits'], true ) );
				}
			);
		} catch ( Path_Unavailable_Exception $e ) {
			return Result::skipped( 'The site root could not be walked: ' . $e->getMessage() );
		}

		if ( array() === $found ) {
			if ( 0 === $sources->examined() ) {
				return Result::skipped( 'No PHP files were found, so there was nothing to examine' );
			}
			$gaps = $sources->gaps();

			return array() === $gaps ? Result::pass() : Result::skipped( 'No obfuscated code was found, but ' . implode( '; ', $gaps ) );
		}

		return Result::findings(
			new Finding(
				$this->id(),
				Severity::warning(),
				'PHP files likely run obfuscated code',
				$sources->evidence( $found ),
				'Open each file at the line given. Code that decodes a hidden string and runs it is a common form of injected '
				. 'malware, and plugins and themes seldom need it. Compare the file with a clean copy from where it came from: '
				. 'WordPress itself, or the plugin or theme download. If the code is not in the clean copy, treat the site as '
				. 'compromised: replace the file, change the passwords and the keys and salts, and look for how it was added.'
			)
		);
	}
}
