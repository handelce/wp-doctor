<?php
/**
 * PHP in the plugins directory that belongs to no plugin.
 *
 * @since 0.2.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Security;

use WP_Doctor\Check\Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Fs\File_Info;
use WP_Doctor\Fs\Path_Unavailable_Exception;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;

/**
 * WordPress lists as plugins the `.php` files at the top of the plugins
 * directory and at the top of each folder in it that carry a `Plugin Name:`
 * header, and passes over names that begin with a dot. PHP that none of those
 * accounts for never runs as a plugin, but the web server still runs it when
 * it is requested, so a backdoor is often hidden there. Reported here: a
 * folder holding PHP with no such header file, a dot-named folder holding PHP,
 * and a PHP file at the top with no header, apart from the empty index.php.
 *
 * @since 0.2.0
 */
final class Unknown_Plugins_Check implements Check {

	/**
	 * Id of the unknown plugin code finding.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'sec.plugins.unknown';
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
	 * Looks at each entry at the top of the plugins directory.
	 *
	 * @since 0.2.0
	 *
	 * @param Context $context Gives the plugins directory and the scanner that reads it.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$unavailable = $context->plugins_dir_unavailable();
		if ( null !== $unavailable ) {
			return Result::skipped( $unavailable );
		}
		try {
			$plugins = new Code_Directory( $context->files(), $context->plugins_dir() );
		} catch ( Path_Unavailable_Exception $e ) {
			return Result::skipped( $e->getMessage() );
		}

		$flagged = array();
		foreach ( $plugins->children() as $name => $entry ) {
			$reason = $entry->is_dir() ? $this->folder_problem( $plugins, (string) $name ) : $this->file_problem( $plugins, (string) $name, $entry );
			if ( null !== $reason ) {
				$flagged[ (string) $name ] = $reason;
			}
		}

		if ( array() === $flagged ) {
			$gaps = $plugins->gaps();

			return array() === $gaps ? Result::pass() : Result::skipped( 'Every plugin folder examined has a plugin header, but ' . implode( '; ', $gaps ) );
		}

		return Result::findings(
			new Finding(
				$this->id(),
				Severity::warning(),
				'PHP in the plugins directory likely belongs to no plugin',
				$plugins->evidence( $flagged ),
				'Look at each entry listed. WordPress loads a plugin only from a .php file with a `Plugin Name:` header at the '
				. 'top of its folder, so this code does not run as a plugin, yet anyone can request it over the web. A folder '
				. 'nested one level too deep (' . $plugins->path() . '/name/name/) is a failed install: delete it and install the '
				. 'plugin again. Otherwise, if you did not put the code there, move it out of the web root to inspect it and treat '
				. 'the site as compromised: change the passwords and the keys and salts, and look for how it was added.'
			)
		);
	}

	/**
	 * Why a folder at the top holds code no plugin accounts for. Null when it is a plugin or holds no PHP.
	 *
	 * @since 0.2.0
	 *
	 * @param Code_Directory $plugins The plugins directory.
	 * @param string         $name    Folder name.
	 * @return string|null
	 */
	private function folder_problem( Code_Directory $plugins, string $name ): ?string {
		if ( array() === $plugins->php_under( $name ) ) {
			return null;
		}
		if ( '.' === $name[0] ) {
			return 'folder whose name begins with a dot, which WordPress does not read';
		}
		$unreadable = false;
		foreach ( $plugins->children( $name ) as $file => $entry ) {
			if ( ! $this->may_hold_a_plugin( (string) $file, $entry->is_file() ) ) {
				continue;
			}
			$header = $plugins->header( "$name/$file", 'Plugin Name' );
			if ( null === $header ) {
				$unreadable = true;
			} elseif ( '' !== $header ) {
				return null;
			}
		}

		return $unreadable ? null : 'folder with PHP but no file with a Plugin Name header';
	}

	/**
	 * Why a file at the top is code no plugin accounts for. Null when it is a plugin, the empty index.php, or not PHP.
	 *
	 * @since 0.2.0
	 *
	 * @param Code_Directory $plugins The plugins directory.
	 * @param string         $name    File name.
	 * @param File_Info      $entry   The file.
	 * @return string|null
	 */
	private function file_problem( Code_Directory $plugins, string $name, File_Info $entry ): ?string {
		if ( ! Code_Directory::is_php( $entry ) ) {
			return null;
		}
		if ( $this->may_hold_a_plugin( $name, true ) ) {
			$header = $plugins->header( $name, 'Plugin Name' );
			if ( null === $header || '' !== $header ) {
				return null;
			}
		}

		return $plugins->is_index_stub( $entry ) ? null : 'PHP file with no Plugin Name header';
	}

	/**
	 * Whether WordPress would read the file for a plugin header: a `.php` name, not beginning with a dot.
	 *
	 * @since 0.2.0
	 *
	 * @param string $name    File name.
	 * @param bool   $is_file Whether it is a regular file.
	 * @return bool
	 */
	private function may_hold_a_plugin( string $name, bool $is_file ): bool {
		return $is_file && '.' !== $name[0] && '.php' === substr( $name, -4 );
	}
}
