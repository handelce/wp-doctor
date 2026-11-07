<?php
/**
 * Parsed command-line options.
 *
 * @since 0.1.0
 * @package WP_Doctor
 */

declare(strict_types=1);

namespace WP_Doctor\Cli;

/**
 * Immutable result of Argument_Parser.
 *
 * @since 0.1.0
 */
final class Cli_Options {

	public const SCAN    = 'scan';
	public const VERSION = 'version';
	public const HELP    = 'help';

	public const DEFAULT_MAX_FILES = 200000;

	/**
	 * One of SCAN, VERSION or HELP.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $command;

	/**
	 * Site path for a scan. Empty for other commands.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $path;

	/**
	 * Output format: text, json or markdown.
	 *
	 * @since 0.1.0
	 *
	 * @var string
	 */
	private $format;

	/**
	 * Whether --offline turned off network access.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private $offline;

	/**
	 * Path given with --config. Null when the option is absent.
	 *
	 * @since 0.1.0
	 *
	 * @var string|null
	 */
	private $config_path;

	/**
	 * Whether --db-from-env asked for database credentials from the environment.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private $db_from_env;

	/**
	 * Most entries one directory walk may yield, from --max-files.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private $max_files;

	/**
	 * Globs given with --exclude.
	 *
	 * @since 0.1.0
	 *
	 * @var string[]
	 */
	private $excludes;

	/**
	 * Whether --redact-emails masks the local part of email addresses.
	 *
	 * @since 0.1.0
	 *
	 * @var bool
	 */
	private $redact_emails;

	/**
	 * True for --color, false for --no-color, null to decide from the terminal.
	 *
	 * @since 0.1.0
	 *
	 * @var bool|null
	 */
	private $colour;

	/**
	 * Holds the options once Argument_Parser has validated them.
	 *
	 * @since 0.1.0
	 *
	 * @param string      $command       SCAN, VERSION or HELP.
	 * @param string      $path          Site path. Empty unless the command is SCAN.
	 * @param string      $format        Text, json or markdown.
	 * @param bool        $offline       Skip network access.
	 * @param string|null $config_path   Path given with --config, or null.
	 * @param bool        $db_from_env   Read database credentials from the environment.
	 * @param int         $max_files     Most entries per walk.
	 * @param string[]    $excludes      Globs given with --exclude.
	 * @param bool        $redact_emails Mask email local parts.
	 * @param bool|null   $colour        Forced colour, or null to decide from the terminal.
	 */
	public function __construct(
		string $command,
		string $path,
		string $format,
		bool $offline,
		?string $config_path,
		bool $db_from_env,
		int $max_files,
		array $excludes,
		bool $redact_emails,
		?bool $colour
	) {
		$this->command       = $command;
		$this->path          = $path;
		$this->format        = $format;
		$this->offline       = $offline;
		$this->config_path   = $config_path;
		$this->db_from_env   = $db_from_env;
		$this->max_files     = $max_files;
		$this->excludes      = $excludes;
		$this->redact_emails = $redact_emails;
		$this->colour        = $colour;
	}

	/**
	 * Command to run: SCAN, VERSION or HELP.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function command(): string {
		return $this->command;
	}

	/**
	 * Site path given to scan. Empty for other commands.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function path(): string {
		return $this->path;
	}

	/**
	 * Output format: text, json or markdown.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function format(): string {
		return $this->format;
	}

	/**
	 * Whether network access is off.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function offline(): bool {
		return $this->offline;
	}

	/**
	 * Path given with --config.
	 *
	 * @since 0.1.0
	 *
	 * @return string|null
	 */
	public function config_path(): ?string {
		return $this->config_path;
	}

	/**
	 * Whether database credentials come from the environment.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function db_from_env(): bool {
		return $this->db_from_env;
	}

	/**
	 * Most entries one directory walk may yield.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function max_files(): int {
		return $this->max_files;
	}

	/**
	 * Exclude globs from the command line.
	 *
	 * @since 0.1.0
	 *
	 * @return string[]
	 */
	public function excludes(): array {
		return $this->excludes;
	}

	/**
	 * Whether email local parts are masked.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function redact_emails(): bool {
		return $this->redact_emails;
	}

	/**
	 * Forced colour setting. Null means decide from the terminal.
	 *
	 * @since 0.1.0
	 *
	 * @return bool|null
	 */
	public function colour(): ?bool {
		return $this->colour;
	}
}
