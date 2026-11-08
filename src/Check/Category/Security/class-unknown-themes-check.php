<?php
/**
 * PHP in the themes directory that belongs to no theme.
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
 * WordPress finds a theme in each folder of the themes directory that has a
 * style.css, or in each subfolder that has one when the folder itself does
 * not, and passes over names that begin with a dot and CVS. A theme needs
 * index.php or templates/index.html unless its style.css names a parent with
 * `Template:`. PHP outside such a theme never runs as part of one, but the
 * web server still runs it when it is requested. Reported here: a folder
 * holding PHP that WordPress would not take for a working theme, PHP in a
 * folder of themes outside its themes, and a PHP file at the top apart from
 * the empty index.php. Only the default themes directory is read; a plugin
 * can register others.
 *
 * @since 0.2.0
 */
final class Unknown_Themes_Check implements Check {

	private const BLOCK_INDEXES = array( 'templates/index.html', 'block-templates/index.html' );

	/**
	 * Id of the unknown theme code finding.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public function id(): string {
		return 'sec.themes.unknown';
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
	 * Looks at each entry at the top of the themes directory.
	 *
	 * @since 0.2.0
	 *
	 * @param Context $context Gives the content directory and the scanner that reads it.
	 * @return Result
	 */
	public function run( Context $context ): Result {
		$unavailable = $context->content_dir_unavailable();
		if ( null !== $unavailable ) {
			return Result::skipped( $unavailable );
		}
		try {
			$themes = new Code_Directory( $context->files(), $context->content_dir() . '/themes' );
		} catch ( Path_Unavailable_Exception $e ) {
			return Result::skipped( $e->getMessage() );
		}

		$flagged = array();
		foreach ( $themes->children() as $name => $entry ) {
			$name   = (string) $name;
			$reason = $entry->is_dir() ? $this->folder_problem( $themes, $name ) : $this->file_problem( $themes, $entry );
			if ( null !== $reason ) {
				$flagged[ $name ] = $reason;
			}
		}

		if ( array() === $flagged ) {
			$gaps = $themes->gaps();

			return array() === $gaps ? Result::pass() : Result::skipped( 'Every theme folder examined is a theme, but ' . implode( '; ', $gaps ) );
		}

		return Result::findings(
			new Finding(
				$this->id(),
				Severity::warning(),
				'PHP in the themes directory likely belongs to no theme',
				$themes->evidence( $flagged ),
				'Look at each entry listed. WordPress runs theme code only from a folder with a style.css and an index.php or '
				. 'templates/index.html, or a style.css with a `Template:` header for a child theme, so this code does not run '
				. 'as a theme, yet anyone can request it over the web. A theme you installed that lost files is broken: delete '
				. 'it and install it again. Otherwise, if you did not put the code there, move it out of the web root to inspect '
				. 'it and treat the site as compromised: change the passwords and the keys and salts, and look for how it was added.'
			)
		);
	}

	/**
	 * Why a folder at the top holds code no theme accounts for. Null when it holds no PHP or only working themes.
	 *
	 * @since 0.2.0
	 *
	 * @param Code_Directory $themes The themes directory.
	 * @param string         $name   Folder name.
	 * @return string|null
	 */
	private function folder_problem( Code_Directory $themes, string $name ): ?string {
		if ( array() === $themes->php_under( $name ) ) {
			return null;
		}
		if ( '.' === $name[0] || 'CVS' === $name ) {
			return 'folder WordPress does not read for themes';
		}
		if ( $themes->has_file( "$name/style.css" ) ) {
			return $this->theme_problem( $themes, $name );
		}

		$members = array();
		foreach ( $themes->children( $name ) as $child => $entry ) {
			if ( $entry->is_dir() && $themes->has_file( "$name/$child/style.css" ) ) {
				$members[] = "$name/$child";
			}
		}
		if ( array() === $members ) {
			return 'folder with PHP but no style.css';
		}
		foreach ( $members as $member ) {
			$problem = $this->theme_problem( $themes, $member );
			if ( null !== $problem ) {
				return substr( $member, strlen( $name ) + 1 ) . ': ' . $problem;
			}
		}

		return array() === $themes->php_under( $name, $members ) ? null : 'folder of themes with PHP outside its themes';
	}

	/**
	 * Why a folder with a style.css is not a working theme. Null when it is one, or its style.css could not be read.
	 *
	 * @since 0.2.0
	 *
	 * @param Code_Directory $themes The themes directory.
	 * @param string         $theme  Theme folder inside the directory.
	 * @return string|null
	 */
	private function theme_problem( Code_Directory $themes, string $theme ): ?string {
		if ( $themes->has_file( "$theme/index.php" ) ) {
			return null;
		}
		foreach ( self::BLOCK_INDEXES as $index ) {
			if ( $themes->has_file( "$theme/$index" ) ) {
				return null;
			}
		}
		$parent = $themes->header( "$theme/style.css", 'Template' );
		if ( null === $parent || '' !== $parent ) {
			return null;
		}

		return array() === $themes->php_under( $theme ) ? null : 'theme with PHP but no index.php, templates/index.html or Template header';
	}

	/**
	 * Why a file at the top is code no theme accounts for. Null when it is not PHP or is the empty index.php.
	 *
	 * @since 0.2.0
	 *
	 * @param Code_Directory $themes The themes directory.
	 * @param File_Info      $entry  The file.
	 * @return string|null
	 */
	private function file_problem( Code_Directory $themes, File_Info $entry ): ?string {
		if ( ! Code_Directory::is_php( $entry ) || $themes->is_index_stub( $entry ) ) {
			return null;
		}

		return 'PHP file outside any theme';
	}
}
