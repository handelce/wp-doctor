<?php
/**
 * Scheduled events that no installed code accounts for.
 *
 * @since 0.3.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Security;

use WP_Doctor\Check\Check;
use WP_Doctor\Check\Context;
use WP_Doctor\Check\Option_Value;
use WP_Doctor\Fs\File_Info;
use WP_Doctor\Fs\Path_Unavailable_Exception;
use WP_Doctor\Report\Finding;
use WP_Doctor\Report\Result;
use WP_Doctor\Report\Severity;
use WP_Doctor\Util\Cron_Schedule;
use WP_Doctor\Util\Malformed_Serialization_Exception;

/**
 * WP-Cron runs an event by calling the hook stored with it, so the code that
 * handles the hook names it in a string. For every hook in the `cron` option
 * this check looks for that string in the site's PHP files, as an exact
 * quoted literal or as a quoted prefix of eight or more characters ending in
 * `_` or `-`, which covers names built at run time such as `'prefix_' . $id`.
 *
 * A hook that no PHP file names was likely left by a plugin that has been
 * removed. A hook named only by PHP outside WordPress core, a plugin, a
 * must-use plugin, a theme, a drop-in or wp-config.php is the pattern of code
 * that keeps itself scheduled after the plugin it came with is gone, or that
 * was planted to come back. A plugin or theme folder that the unknown-code
 * checks would report does not count as a plugin or theme here.
 *
 * @since 0.3.0
 */
final class Cron_Persistence_Check implements Check {

	private const MAX_OPTION_BYTES = 16777216;
	private const MIN_PREFIX       = 8;
	private const LISTED           = 20;
	private const DROP_INS         = array(
		'advanced-cache.php',
		'db.php',
		'db-error.php',
		'install.php',
		'maintenance.php',
		'object-cache.php',
		'php-error.php',
		'fatal-error-handler.php',
		'sunrise.php',
		'blog-deleted.php',
		'blog-inactive.php',
		'blog-suspended.php',
	);
	private const CORE_DIRECTORIES = array( 'wp-admin', 'wp-includes' );

	/**
	 * Plugins directory, read when a mention there must be judged.
	 *
	 * @since 0.3.0
	 *
	 * @var Code_Directory|null
	 */
	private $plugins;

	/**
	 * Themes directory, read when a mention there must be judged.
	 *
	 * @since 0.3.0
	 *
	 * @var Code_Directory|null
	 */
	private $themes;

	/**
	 * Id of the cron persistence findings.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'sec.wpcron.persistence';
	}

	/**
	 * Grouped under security.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public function category(): string {
		return 'security';
	}

	/**
	 * Reads the scheduled hooks and finds the PHP that names each one.
	 *
	 * @since 0.3.0
	 *
	 * @param Context $context Gives the cron option, the site's directories and the scanner.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$unavailable = $context->site_tables_unavailable();
		if ( null === $unavailable ) {
			$unavailable = $context->plugins_dir_unavailable();
		}
		if ( null !== $unavailable ) {
			return Result::skipped( $unavailable );
		}
		list( $value, $reason ) = Option_Value::read( $context, 'cron', self::MAX_OPTION_BYTES );
		if ( null === $value ) {
			return Result::skipped( (string) $reason );
		}
		$events = array();
		try {
			foreach ( Cron_Schedule::events( $value ) as $event ) {
				$events[ $event[1] ] = ( $events[ $event[1] ] ?? 0 ) + 1;
			}
		} catch ( Malformed_Serialization_Exception $e ) {
			return Result::findings( Cron_Schedule::unreadable( $this->id(), $e, strlen( $value ) ) );
		}
		if ( array() === $events ) {
			return Result::skipped( 'No events are scheduled, so there was nothing to examine' );
		}

		$this->plugins = null;
		$this->themes  = null;
		$hooks         = array_map( 'strval', array_keys( $events ) );
		$sources       = Php_Sources::matching_pattern( $context, self::pattern( $hooks ) );
		$mentions      = array();
		try {
			foreach ( $sources->matching() as $path => $source ) {
				foreach ( self::named_hooks( $source, $hooks ) as $hook ) {
					$mentions[ $hook ][] = (string) $path;
				}
			}
		} catch ( Path_Unavailable_Exception $e ) {
			return Result::skipped( 'The site root could not be walked: ' . $e->getMessage() );
		}
		$gaps = $sources->gaps();

		$orphans = array();
		$planted = array();
		foreach ( $hooks as $hook ) {
			if ( ! isset( $mentions[ $hook ] ) ) {
				$orphans[ $hook ] = $events[ $hook ];
				continue;
			}
			$outside = array();
			foreach ( $mentions[ $hook ] as $path ) {
				if ( $this->is_installed_code( $context, $path ) ) {
					$outside = array();
					break;
				}
				$outside[] = $path;
			}
			if ( array() !== $outside ) {
				$planted[ $hook ] = $outside;
			}
		}

		$findings = array();
		if ( array() !== $planted ) {
			$findings[] = $this->planted_finding( $planted, $events, $gaps );
		}
		if ( array() !== $orphans ) {
			$findings[] = $this->orphan_finding( $orphans, count( $hooks ), $gaps );
		}
		if ( array() === $findings ) {
			return array() === $gaps ? Result::pass() : Result::skipped( 'Every scheduled hook is named by installed code, but ' . implode( '; ', $gaps ) );
		}

		return Result::findings( ...$findings );
	}

	/**
	 * A pattern matching a quoted hook name, or a quoted prefix of one.
	 *
	 * @since 0.3.0
	 *
	 * @param string[] $hooks Scheduled hook names.
	 * @return string
	 */
	private static function pattern( array $hooks ): string {
		$names = array();
		foreach ( $hooks as $hook ) {
			foreach ( self::candidates( $hook ) as $candidate ) {
				$names[ $candidate ] = preg_quote( $candidate, '/' );
			}
		}
		uksort(
			$names,
			static function ( $a, $b ): int {
				return strlen( (string) $b ) <=> strlen( (string) $a );
			}
		);

		return '/([\'"])(' . implode( '|', $names ) . ')\1/';
	}

	/**
	 * The hook itself and each prefix of it that counts as a mention.
	 *
	 * @since 0.3.0
	 *
	 * @param string $hook Hook name.
	 * @return string[]
	 */
	private static function candidates( string $hook ): array {
		$candidates = array( $hook );
		$length     = strlen( $hook );
		for ( $end = self::MIN_PREFIX; $end < $length; $end++ ) {
			if ( '_' === $hook[ $end - 1 ] || '-' === $hook[ $end - 1 ] ) {
				$candidates[] = substr( $hook, 0, $end );
			}
		}

		return $candidates;
	}

	/**
	 * Hooks a PHP file names, exactly or by prefix.
	 *
	 * @since 0.3.0
	 *
	 * @param string   $source File text.
	 * @param string[] $hooks  Scheduled hook names.
	 * @return string[]
	 */
	private static function named_hooks( string $source, array $hooks ): array {
		if ( false === preg_match_all( self::pattern( $hooks ), $source, $matches ) ) {
			return array();
		}
		$literals = array_flip( $matches[2] );
		$named    = array();
		foreach ( $hooks as $hook ) {
			foreach ( self::candidates( $hook ) as $candidate ) {
				if ( isset( $literals[ $candidate ] ) ) {
					$named[] = $hook;
					break;
				}
			}
		}

		return $named;
	}

	/**
	 * Whether a PHP file belongs to code WordPress loads: core, a plugin, a
	 * must-use plugin, a theme, a drop-in or wp-config.php.
	 *
	 * @since 0.3.0
	 *
	 * @param Context $context Gives the site's directories.
	 * @param string  $path    Path relative to the root, or ../wp-config.php.
	 * @return bool
	 */
	private function is_installed_code( Context $context, string $path ): bool {
		if ( in_array( $path, array( '../wp-config.php', 'wp-config.php' ), true ) ) {
			return true;
		}
		$core = $context->site()->core_dir();
		$in   = '' === $core ? $path : ( 0 === strpos( $path, $core . '/' ) ? substr( $path, strlen( $core ) + 1 ) : null );
		if ( null !== $in && ( false === strpos( $in, '/' ) || in_array( explode( '/', $in, 2 )[0], self::CORE_DIRECTORIES, true ) ) ) {
			return true;
		}
		$content = $context->content_dir();
		if ( dirname( $path ) === $content && in_array( basename( $path ), self::DROP_INS, true ) ) {
			return true;
		}
		if ( 0 === strpos( $path, $content . '/mu-plugins/' ) ) {
			return true;
		}
		$plugins = $context->plugins_dir() . '/';
		if ( 0 === strpos( $path, $plugins ) ) {
			return $this->is_known( $context, 'plugins', substr( $path, strlen( $plugins ) ) );
		}
		$themes = $content . '/themes/';
		if ( 0 === strpos( $path, $themes ) ) {
			return $this->is_known( $context, 'themes', substr( $path, strlen( $themes ) ) );
		}

		return false;
	}

	/**
	 * Whether a path inside the plugins or themes directory is in a plugin or
	 * theme that the unknown-code checks would not report.
	 *
	 * @since 0.3.0
	 *
	 * @param Context $context Gives the scanner and the directories.
	 * @param string  $kind    plugins or themes.
	 * @param string  $inside  Path inside that directory.
	 * @return bool
	 */
	private function is_known( Context $context, string $kind, string $inside ): bool {
		try {
			if ( 'plugins' === $kind ) {
				$this->plugins = $this->plugins ?? new Code_Directory( $context->files(), $context->plugins_dir() );
				$directory     = $this->plugins;
				$check         = new Unknown_Plugins_Check();
			} else {
				$this->themes = $this->themes ?? new Code_Directory( $context->files(), $context->content_dir() . '/themes' );
				$directory    = $this->themes;
				$check        = new Unknown_Themes_Check();
			}
		} catch ( Path_Unavailable_Exception $e ) {
			return false;
		}
		$top   = explode( '/', $inside, 2 )[0];
		$entry = $directory->children()[ $top ] ?? null;
		if ( ! $entry instanceof File_Info ) {
			return false;
		}
		if ( $entry->is_dir() ) {
			return null === $check->folder_problem( $directory, $top );
		}
		$problem = $check instanceof Unknown_Plugins_Check ? $check->file_problem( $directory, $top, $entry ) : $check->file_problem( $directory, $entry );

		return null === $problem;
	}

	/**
	 * The warning for hooks named only by PHP outside installed code.
	 *
	 * @since 0.3.0
	 *
	 * @param array<string, string[]> $planted Paths naming each hook.
	 * @param array<string, int>      $events  Events by hook.
	 * @param string[]                $gaps    What could not be examined.
	 * @return Finding
	 */
	private function planted_finding( array $planted, array $events, array $gaps ): Finding {
		ksort( $planted, SORT_STRING );
		$evidence = array(
			'hooks'           => count( $planted ),
			'scan_incomplete' => array() !== $gaps,
		);
		if ( array() !== $gaps ) {
			$evidence['not_examined'] = implode( '; ', $gaps );
		}
		$rank = 0;
		foreach ( array_slice( $planted, 0, self::LISTED, true ) as $hook => $paths ) {
			++$rank;
			sort( $paths, SORT_STRING );
			$evidence[ "hook_$rank" ]            = (string) $hook;
			$evidence[ "hook_{$rank}_events" ]   = $events[ $hook ];
			$evidence[ "hook_{$rank}_named_in" ] = implode( ', ', array_slice( $paths, 0, 5 ) ) . ( count( $paths ) > 5 ? ', ...' : '' );
		}

		return new Finding(
			$this->id(),
			Severity::warning(),
			'Scheduled events are likely run by code outside any plugin, theme or WordPress core',
			$evidence,
			'Open each file listed. Code that schedules itself from outside a plugin or theme is a common way for injected '
			. 'malware to come back after it is removed. If you do not recognise it, treat the site as compromised: move the '
			. 'files out of the web root to inspect them, remove the events with `wp cron event delete <hook>`, change the '
			. 'passwords and the keys and salts, and look for how the code was added.'
		);
	}

	/**
	 * The notice for hooks no PHP file names.
	 *
	 * @since 0.3.0
	 *
	 * @param array<string, int> $orphans   Events by hook.
	 * @param int                $scheduled Distinct hooks scheduled.
	 * @param string[]           $gaps      What could not be examined.
	 * @return Finding
	 */
	private function orphan_finding( array $orphans, int $scheduled, array $gaps ): Finding {
		ksort( $orphans, SORT_STRING );
		$evidence = array(
			'hooks_scheduled' => $scheduled,
			'hooks_not_named' => count( $orphans ),
			'scan_incomplete' => array() !== $gaps,
		);
		if ( array() !== $gaps ) {
			$evidence['not_examined'] = implode( '; ', $gaps );
		}
		$rank = 0;
		foreach ( array_slice( $orphans, 0, self::LISTED, true ) as $hook => $count ) {
			++$rank;
			$evidence[ "hook_$rank" ]          = (string) $hook;
			$evidence[ "hook_{$rank}_events" ] = $count;
		}

		return new Finding(
			$this->id(),
			Severity::info(),
			'Scheduled events are likely left by plugins that were removed',
			$evidence,
			'No installed PHP names these hooks, so their events run nothing. If you recognise a hook from a plugin you '
			. 'removed, delete its events with `wp cron event delete <hook>`. A hook you do not recognise is worth a look.'
		);
	}
}
