<?php
/**
 * Command-line argument parsing.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Cli;

use function WP_Doctor\esc_cli;

/**
 * Accepts --name=value and --name value. Anything unrecognised is an error, so
 * a typo never silently changes what a scan does. A request for help or the
 * version is answered whatever else is on the line, so it works on a command
 * line that is itself wrong.
 *
 * @since 0.1.0
 */
final class Argument_Parser {

	private const FLAGS         = array( 'offline', 'db-from-env', 'redact-emails', 'color', 'no-color', 'version', 'help' );
	private const VALUE_OPTIONS = array( 'format', 'config', 'max-files', 'exclude', 'queries' );
	private const FORMATS       = array( 'text', 'json', 'markdown' );

	/**
	 * Parses the arguments after the script name.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $args Raw arguments, without the script name.
	 * @return Cli_Options
	 * @throws Usage_Exception When the arguments are not valid.
	 */
	public function parse( array $args ): Cli_Options {
		$asked = $this->information_asked_for( $args );
		if ( null !== $asked ) {
			return new Cli_Options( $asked, '', 'text', false, null, false, Cli_Options::DEFAULT_MAX_FILES, array(), false, null, null );
		}

		$flags      = array();
		$values     = array(
			'format'    => 'text',
			'config'    => null,
			'queries'   => null,
			'max-files' => (string) Cli_Options::DEFAULT_MAX_FILES,
		);
		$excludes   = array();
		$positional = array();

		for ( $i = 0, $count = count( $args ); $i < $count; $i++ ) {
			$arg = (string) $args[ $i ];

			if ( '--' === $arg ) {
				$positional = array_merge( $positional, array_map( 'strval', array_slice( $args, $i + 1 ) ) );
				break;
			}
			if ( '-h' === $arg ) {
				$flags['help'] = true;
				continue;
			}
			if ( '-V' === $arg ) {
				$flags['version'] = true;
				continue;
			}
			if ( 0 !== strpos( $arg, '--' ) ) {
				if ( strlen( $arg ) > 1 && '-' === $arg[0] ) {
					throw new Usage_Exception( esc_cli( "Unknown option $arg" ) );
				}
				$positional[] = $arg;
				continue;
			}

			$parts = explode( '=', substr( $arg, 2 ), 2 );
			$name  = $parts[0];
			$value = $parts[1] ?? null;

			if ( in_array( $name, self::FLAGS, true ) ) {
				if ( null !== $value ) {
					throw new Usage_Exception( esc_cli( "Option --$name does not take a value" ) );
				}
				$flags[ $name ] = true;
			} elseif ( in_array( $name, self::VALUE_OPTIONS, true ) ) {
				if ( null === $value && isset( $args[ $i + 1 ] ) && 0 !== strpos( (string) $args[ $i + 1 ], '--' ) ) {
					$value = (string) $args[ ++$i ];
				}
				if ( null === $value || '' === $value ) {
					throw new Usage_Exception( esc_cli( "Option --$name requires a value" ) );
				}
				if ( 'exclude' === $name ) {
					$problem = Tool_Config::exclude_problem( $value );
					if ( null !== $problem ) {
						throw new Usage_Exception( esc_cli( "--exclude=$value $problem" ) );
					}
					$excludes[] = $value;
				} else {
					$values[ $name ] = $value;
				}
			} else {
				throw new Usage_Exception( esc_cli( "Unknown option --$name" ) );
			}
		}

		if ( isset( $flags['color'], $flags['no-color'] ) ) {
			throw new Usage_Exception( 'Options --color and --no-color cannot be used together' );
		}
		if ( ! in_array( $values['format'], self::FORMATS, true ) ) {
			throw new Usage_Exception( esc_cli( 'Unknown format "' . $values['format'] . '"; use one of: ' . implode( ', ', self::FORMATS ) ) );
		}
		if ( 1 !== preg_match( '/^[1-9][0-9]{0,9}$/', (string) $values['max-files'] ) ) {
			throw new Usage_Exception( esc_cli( 'Option --max-files needs a positive whole number, got "' . $values['max-files'] . '"' ) );
		}

		$colour = null;
		if ( isset( $flags['color'] ) ) {
			$colour = true;
		} elseif ( isset( $flags['no-color'] ) ) {
			$colour = false;
		}

		$command = $this->command( $flags, $positional );
		$path    = Cli_Options::SCAN === $command ? $positional[1] : '';

		return new Cli_Options(
			$command,
			$path,
			(string) $values['format'],
			isset( $flags['offline'] ),
			null === $values['config'] ? null : (string) $values['config'],
			isset( $flags['db-from-env'] ),
			(int) $values['max-files'],
			$excludes,
			isset( $flags['redact-emails'] ),
			$colour,
			null === $values['queries'] ? null : (string) $values['queries']
		);
	}

	/**
	 * Help or version, when either is asked for before a `--`. Help wins when
	 * both are given. The argument after a value option given without `=` is
	 * that option's value, as parse() reads it.
	 *
	 * @since 0.1.0
	 *
	 * @param string[] $args Raw arguments, without the script name.
	 * @return string|null Cli_Options::HELP, Cli_Options::VERSION, or null.
	 */
	private function information_asked_for( array $args ): ?string {
		$asked = null;
		for ( $i = 0, $count = count( $args ); $i < $count; $i++ ) {
			$arg = (string) $args[ $i ];
			if ( '--' === $arg ) {
				break;
			}
			if ( '-h' === $arg || '--help' === $arg ) {
				return Cli_Options::HELP;
			}
			if ( '-V' === $arg || '--version' === $arg ) {
				$asked = Cli_Options::VERSION;
			} elseif ( in_array( substr( $arg, 2 ), self::VALUE_OPTIONS, true ) && 0 === strpos( $arg, '--' ) && isset( $args[ $i + 1 ] ) && 0 !== strpos( (string) $args[ $i + 1 ], '--' ) ) {
				++$i;
			}
		}

		return $asked;
	}

	/**
	 * Decides the command from the flags and positional arguments.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, bool> $flags      Names of the flags given, each mapped to true.
	 * @param string[]            $positional Arguments that are not options, starting with the command name.
	 * @return string One of the Cli_Options command constants.
	 * @throws Usage_Exception When no valid command is given.
	 */
	private function command( array $flags, array $positional ): string {
		if ( isset( $flags['help'] ) ) {
			return Cli_Options::HELP;
		}
		if ( isset( $flags['version'] ) ) {
			return Cli_Options::VERSION;
		}
		if ( array() === $positional ) {
			throw new Usage_Exception( 'A command is required, for example: wp-doctor scan <path>' );
		}
		if ( Cli_Options::SCAN !== $positional[0] ) {
			throw new Usage_Exception( esc_cli( 'Unknown command "' . $positional[0] . '"' ) );
		}
		if ( 1 === count( $positional ) ) {
			throw new Usage_Exception( 'The scan command needs a path: wp-doctor scan <path>' );
		}
		if ( count( $positional ) > 2 ) {
			throw new Usage_Exception( 'The scan command takes exactly one path' );
		}

		return Cli_Options::SCAN;
	}
}
