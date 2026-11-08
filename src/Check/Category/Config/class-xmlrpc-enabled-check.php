<?php
/**
 * The XML-RPC endpoint left reachable.
 *
 * @since 0.2.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Config;

use WP_Doctor\Check\Context;
use WP_Doctor\Check\Runs_Without_Config;
use WP_Doctor\Fs\File_Scanner;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

/**
 * The xmlrpc.php endpoint accepts many login attempts in one request and sends pingbacks
 * to any address it is given, so it is a common target. The `xmlrpc_enabled`
 * filter only turns off the methods that need a login, so the endpoint is
 * stopped only by the web server. This check reads the .htaccess files that
 * Apache applies to it, in the site root and in the core directory, for a
 * rule that denies it. Rules in the server's own configuration, as nginx
 * uses, are not visible, so the finding says "likely".
 *
 * @since 0.2.0
 */
final class Xmlrpc_Enabled_Check implements Runs_Without_Config {

	private const MAX_HTACCESS_BYTES = 262144;

	/**
	 * Id of the XML-RPC notice.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'config.xmlrpc.enabled';
	}

	/**
	 * Grouped under config, since the fix is server configuration.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'config';
	}

	/**
	 * Looks for xmlrpc.php and a rule that blocks it.
	 *
	 * @since 0.2.0
	 *
	 * @param Context $context Gives the core directory and the scanner.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$site     = $context->site();
		$files    = $context->files();
		$endpoint = $site->core_path( 'xmlrpc.php' );
		$presence = $files->presence( $endpoint );
		if ( File_Scanner::ABSENT === $presence ) {
			return Result::pass();
		}
		if ( File_Scanner::PRESENT !== $presence ) {
			return Result::skipped( "$endpoint was not examined: it is $presence" );
		}

		$read = array();
		foreach ( array_unique( array( '.htaccess', $site->core_path( '.htaccess' ) ) ) as $htaccess ) {
			$presence = $files->presence( $htaccess );
			if ( File_Scanner::ABSENT === $presence ) {
				continue;
			}
			$content = File_Scanner::PRESENT === $presence ? $files->read( $htaccess, self::MAX_HTACCESS_BYTES ) : null;
			if ( null === $content ) {
				return Result::skipped( "$htaccess could not be read, so a rule blocking xmlrpc.php may be missed" );
			}
			if ( null !== self::blocking_rule( $content ) ) {
				return Result::pass();
			}
			$read[] = $htaccess;
		}

		return Result::findings(
			new Finding(
				$this->id(),
				Severity::info(),
				'The XML-RPC endpoint is likely reachable',
				array(
					'endpoint'      => $endpoint,
					'htaccess_read' => array() === $read ? 'none found' : implode( ', ', $read ),
					'blocking_rule' => 'none found in .htaccess',
					'server_config' => 'not visible: a rule in the web server configuration, as nginx uses, would not be seen',
				),
				'If nothing on the site needs XML-RPC, block it in the web server. On Apache, add to .htaccess: '
				. '`<Files xmlrpc.php>` `Require all denied` `</Files>`. On nginx: `location = /xmlrpc.php { deny all; }`. '
				. 'Some services connect through it, Jetpack among them, so check those still work afterwards.'
			)
		);
	}

	/**
	 * The first rule in an .htaccess that denies requests for xmlrpc.php. Null when there is none.
	 *
	 * @since 0.2.0
	 *
	 * @param string $content Text of the .htaccess.
	 * @return string|null
	 */
	public static function blocking_rule( string $content ): ?string {
		$inside = null;
		$lines  = preg_split( '/\r\n|\r|\n/', $content );
		foreach ( false === $lines ? array() : $lines as $raw ) {
			$line = trim( $raw );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			if ( 1 === preg_match( '/^<Files(?:Match)?\s+(.+)>$/i', $line, $match ) ) {
				$inside = false !== stripos( $match[1], 'xmlrpc' ) ? $line : null;
				continue;
			}
			if ( 1 === preg_match( '/^<\/Files(?:Match)?>$/i', $line ) ) {
				$inside = null;
				continue;
			}
			if ( null !== $inside && 1 === preg_match( '/^(?:Require\s+all\s+denied|Deny\s+from\s+all)\b/i', $line ) ) {
				return $inside . ' ' . $line;
			}
			if ( false === stripos( $line, 'xmlrpc' ) ) {
				continue;
			}
			if ( 1 === preg_match( '/^RewriteRule\s.*\[(?:[^\]]*,)?\s*(?:F|G|forbidden|gone|R=(?:403|404|410))\s*(?:,[^\]]*)?\]\s*$/i', $line )
				|| 1 === preg_match( '/^Redirect(?:Match)?\s+(?:403|404|410|gone)\s/i', $line ) ) {
				return $line;
			}
		}

		return null;
	}
}
