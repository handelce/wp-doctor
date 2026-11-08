# `sec.themes.unknown`

PHP in the themes directory that WordPress would not run as part of a theme.

| | |
|---|---|
| Category | security |
| Severity | warning ("likely") |
| Reads | the site files |
| Since | 0.2.0 |

## What it reports

WordPress finds a theme in each folder of the themes directory that has a
`style.css`. A folder without one is a folder of themes when its subfolders
have one. Names that begin with a dot and `CVS` are passed over. A theme
needs `index.php`, `templates/index.html` or `block-templates/index.html`,
unless its `style.css` has a `Template:` header, which makes it a child
theme. This check reports the PHP that no such theme accounts for:

- a folder holding PHP with no `style.css` in it or in its subfolders;
- a theme holding PHP that has neither a template nor a `Template:` header.
  Inside a folder of themes, the first such theme is named before the reason;
- PHP in a folder of themes outside the themes in it;
- a folder whose name begins with a dot, or `CVS`, that holds PHP;
- a PHP file at the top of the directory, apart from the empty
  `Silence is golden` index.php.

PHP means `.php`, `.php3`, `.php4`, `.php5`, `.php7`, `.php8`, `.phtml` and
`.pht`. A folder without PHP is not reported. Only the `themes` folder of the
content directory is read; theme directories that a plugin registers are not.

## Why it matters

Code in the themes directory that is not part of a working theme never runs
through WordPress, but the web server still runs it when someone requests its
URL. Like the plugins directory, the themes directory is full of PHP already,
and a folder named like a theme draws no attention, which makes it a common
place to hide a backdoor.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `directory` | string | The themes directory, relative to the site root. |
| `entries_listed` | integer | Entries at the top of the themes directory. |
| `flagged` | integer | Entries reported. |
| `scan_incomplete` | boolean | True when part of the directory could not be examined. |
| `not_examined` | string | What could not be examined: symlinks that were not followed, headers that could not be read, or a walk that stopped. Present only when `scan_incomplete` is true. |
| `entries` | list of objects | The entries reported, by path, at most 20. |
| `entries[].path` | string | The folder or file, relative to the site root. |
| `entries[].reason` | string | Why it was reported. |
| `entries[].php_files` | integer | PHP files in it. |
| `entries[].php_newest` | string | When the most recently changed of them was last modified, in UTC. |

## Example

From the check's tests: a folder holding PHP with no `style.css`, beside
working classic, block, child and grouped themes.

```json
{
	"id": "sec.themes.unknown",
	"severity": "warning",
	"title": "PHP in the themes directory likely belongs to no theme",
	"evidence": {
		"directory": "wp-content/themes",
		"entries_listed": 7,
		"flagged": 1,
		"scan_incomplete": false,
		"entries": [
			{
				"path": "wp-content/themes/example-assets",
				"reason": "folder with PHP but no style.css",
				"php_files": 1,
				"php_newest": "2026-11-08 14:54 UTC"
			}
		]
	},
	"fix": "Look at each entry listed. WordPress runs theme code only from a folder with a style.css and an index.php or templates/index.html, or a style.css with a `Template:` header for a child theme, so this code does not run as a theme, yet anyone can request it over the web. A theme you installed that lost files is broken: delete it and install it again. Otherwise, if you did not put the code there, move it out of the web root to inspect it and treat the site as compromised: change the passwords and the keys and salts, and look for how it was added."
}
```

## When it skips

- `WP_CONTENT_DIR` is set in `wp-config.php` in a way that could not be read,
  or points outside the site root.
- The themes directory is missing or cannot be read.
- Nothing was found, but a symlink was not followed, a `style.css` header
  could not be read, or the walk stopped at `--max-files`: a clean result
  would claim more than was examined.

## False positives

- A folder of shared code that a theme or plugin loads from beside the
  themes, with no `style.css` of its own.
- A theme that lost files in a failed upload or update, or a theme still
  being built that has no `index.php` yet. The fix text covers a broken
  theme.
- A backup copy of a theme renamed with a leading dot.
