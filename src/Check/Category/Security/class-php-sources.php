<?php
/**
 * The PHP files of a site, read for the code checks.
 *
 * @since 0.2.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Check\Category\Security;

use WP_Doctor\Check\Context;
use WP_Doctor\Check\Evidence;
use WP_Doctor\Fs\File_Scanner;
use WP_Doctor\Util\Php_Tokens;

/**
 * Walks every PHP file under the site root and hands over the ones whose text
 * holds the words a check looks for, so most files are never tokenized. A
 * wp-config.php above the root is read too, since injected code is often
 * placed there. Files that could not be read in full are counted, so a check
 * never passes over code it did not see.
 *
 * @since 0.2.0
 */
final class Php_Sources {

	public const EXTENSIONS = array( 'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht' );

	private const MAX_SOURCE_BYTES = File_Scanner::DEFAULT_MAX_BYTES;

	/**
	 * Site being read.
	 *
	 * @since 0.2.0
	 *
	 * @var Context
	 */
	private $context;

	/**
	 * Groups of words, matched without regard to case. A file is handed over
	 * when its text holds at least one word from every group.
	 *
	 * @since 0.2.0
	 *
	 * @var array<int, string[]>
	 */
	private $word_groups;

	/**
	 * Pattern a file's text must also match. Null when the words alone decide.
	 *
	 * @since 0.3.0
	 *
	 * @var string|null
	 */
	private $pattern;

	/**
	 * PHP files read, in full or in part.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private $examined = 0;

	/**
	 * Paths of PHP files larger than the read limit, which were read in part.
	 *
	 * @since 0.2.0
	 *
	 * @var string[]
	 */
	private $partial = array();

	/**
	 * PHP files that could not be read.
	 *
	 * @since 0.2.0
	 *
	 * @var int
	 */
	private $unreadable = 0;

	/**
	 * What the walk of the root could not see. Null when it saw everything.
	 *
	 * @since 0.2.0
	 *
	 * @var string|null
	 */
	private $walk_gap;

	/**
	 * Takes the site and the words a file must hold to be worth tokenizing.
	 *
	 * @since 0.2.0
	 *
	 * @param Context              $context     Gives the scanner and the location of wp-config.php.
	 * @param array<int, string[]> $word_groups At least one word from each group must appear in a file.
	 * @throws \InvalidArgumentException When there are no groups or a group is empty.
	 */
	public function __construct( Context $context, array $word_groups ) {
		if ( array() === $word_groups ) {
			throw new \InvalidArgumentException( 'At least one group of words is needed' );
		}
		foreach ( $word_groups as $group ) {
			if ( array() === $group ) {
				throw new \InvalidArgumentException( 'A group of words cannot be empty' );
			}
		}
		$this->context     = $context;
		$this->word_groups = $word_groups;
	}

	/**
	 * Sources whose files are handed over when their text holds a PHP opening
	 * tag and matches $pattern, which is cheaper than many separate words.
	 *
	 * @since 0.3.0
	 *
	 * @param Context $context Gives the scanner and the location of wp-config.php.
	 * @param string  $pattern PCRE pattern, with delimiters, that a file's text must match.
	 * @return self
	 */
	public static function matching_pattern( Context $context, string $pattern ): self {
		$sources          = new self( $context, array( array( '<?' ) ) );
		$sources->pattern = $pattern;

		return $sources;
	}

	/**
	 * The text of each PHP file that holds the words, keyed by its path
	 * relative to the root. A wp-config.php above the root is keyed
	 * `../wp-config.php`. Read the counts only after the loop has finished.
	 *
	 * @since 0.2.0
	 *
	 * @return \Generator<string, string>
	 * @throws \WP_Doctor\Fs\Path_Unavailable_Exception When the root cannot be walked.
	 */
	public function matching(): \Generator {
		$files = $this->context->files();
		$walk  = $files->walk();
		foreach ( $walk as $entry ) {
			if ( ! $entry->is_file() || ! in_array( $entry->extension(), self::EXTENSIONS, true ) ) {
				continue;
			}
			$source = $files->read( $entry->path(), self::MAX_SOURCE_BYTES );
			if ( null !== $this->counted( $entry->path(), $source, $entry->size() ) && $this->holds_words( (string) $source ) ) {
				yield $entry->path() => (string) $source;
			}
		}
		$this->walk_gap = $walk->incomplete_reason();

		$config = $this->config_above_root();
		if ( null !== $config && $this->holds_words( $config ) ) {
			yield '../wp-config.php' => $config;
		}
	}

	/**
	 * The places in the matching files that run code, as Code_Sinks finds
	 * them, kept when $keep accepts them.
	 *
	 * @since 0.2.0
	 *
	 * @param callable $keep Takes one Code_Sinks hit and returns true to keep it.
	 * @return array<string, array<int, array{sink: string, line: int, traits: string[], functions: string[]}>> Hits by file path, in walk order.
	 * @throws \WP_Doctor\Fs\Path_Unavailable_Exception When the root cannot be walked.
	 */
	public function hits( callable $keep ): array {
		$found = array();
		foreach ( $this->matching() as $path => $source ) {
			$kept = array_values( array_filter( Code_Sinks::in( Php_Tokens::significant( $source ) ), $keep ) );
			if ( array() !== $kept ) {
				$found[ (string) $path ] = $kept;
			}
		}

		return $found;
	}

	/**
	 * Evidence for files found by hits(): the counts, then each file with the
	 * line and an account of its first hit, and how many more it has.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, array<int, array{sink: string, line: int, traits: string[], functions: string[]}>> $found Output of hits().
	 * @return array<string, mixed>
	 */
	public function evidence( array $found ): array {
		ksort( $found, SORT_STRING );
		$gaps     = $this->gaps();
		$evidence = array(
			'php_files_examined' => $this->examined,
			'files_flagged'      => count( $found ),
			'scan_incomplete'    => array() !== $gaps,
		);
		if ( array() !== $gaps ) {
			$evidence['not_examined'] = implode( '; ', $gaps );
		}
		$evidence['files'] = array();
		foreach ( array_slice( $found, 0, Evidence::MAX_LISTED, true ) as $path => $hits ) {
			$evidence['files'][] = array(
				'path' => (string) $path,
				'line' => $hits[0]['line'],
				'code' => Code_Sinks::describe( $hits[0] ),
				'more' => count( $hits ) - 1,
			);
		}

		return $evidence;
	}

	/**
	 * Number of PHP files read, in full or in part.
	 *
	 * @since 0.2.0
	 *
	 * @return int
	 */
	public function examined(): int {
		return $this->examined;
	}

	/**
	 * Reasons the PHP code was not all examined. Empty when every PHP file was read in full.
	 *
	 * @since 0.2.0
	 *
	 * @return string[]
	 */
	public function gaps(): array {
		$gaps = array();
		if ( null !== $this->walk_gap ) {
			$gaps[] = $this->walk_gap;
		}
		if ( array() !== $this->partial ) {
			sort( $this->partial, SORT_STRING );
			$gaps[] = count( $this->partial ) . ' PHP file(s) larger than ' . self::MAX_SOURCE_BYTES . ' bytes were read only in part ('
				. implode( ', ', array_slice( $this->partial, 0, 5 ) ) . ( count( $this->partial ) > 5 ? ', ...' : '' ) . ')';
		}
		if ( $this->unreadable > 0 ) {
			$gaps[] = $this->unreadable . ' PHP file(s) could not be read';
		}

		return $gaps;
	}

	/**
	 * Records a read in the counts and passes the source through.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $path   Path relative to the root, or ../wp-config.php.
	 * @param string|null $source What the read returned.
	 * @param int         $size   Size of the file on disk.
	 * @return string|null Null when the file could not be read.
	 */
	private function counted( string $path, ?string $source, int $size ): ?string {
		if ( null === $source ) {
			++$this->unreadable;

			return null;
		}
		++$this->examined;
		if ( strlen( $source ) < $size ) {
			$this->partial[] = $path;
		}

		return $source;
	}

	/**
	 * True when the text holds a word from every group and matches the pattern, if there is one.
	 *
	 * @since 0.2.0
	 *
	 * @param string $source File text.
	 * @return bool
	 */
	private function holds_words( string $source ): bool {
		if ( null !== $this->pattern && 1 !== preg_match( $this->pattern, $source ) ) {
			return false;
		}
		foreach ( $this->word_groups as $group ) {
			$found = false;
			foreach ( $group as $word ) {
				if ( false !== stripos( $source, $word ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Text of a wp-config.php that sits above the site root, which the walk
	 * does not reach. Null when it is inside the root or was not read.
	 *
	 * @since 0.2.0
	 *
	 * @return string|null
	 */
	private function config_above_root(): ?string {
		$site      = $this->context->site();
		$directory = dirname( $site->config_path() );
		$root      = $site->root();
		if ( $directory === $root || 0 === strpos( $directory . '/', $root . '/' ) ) {
			return null;
		}
		$scanner = new File_Scanner( $directory, 1, self::MAX_SOURCE_BYTES );
		$info    = $scanner->stat( 'wp-config.php' );

		return $this->counted( '../wp-config.php', $scanner->read( 'wp-config.php', self::MAX_SOURCE_BYTES ), null === $info ? 0 : $info->size() );
	}
}
