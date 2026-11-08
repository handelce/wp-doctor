# `sec.plugins.unknown`

PHP in the plugins directory that WordPress would not load as a plugin.

| | |
|---|---|
| Category | security |
| Severity | warning ("likely") |
| Reads | the site files |
| Since | 0.2.0 |

## What it reports

WordPress lists as plugins the `.php` files at the top of the plugins
directory, and at the top of each folder in it, that carry a `Plugin Name:`
header in their first 8 KiB. It passes over names that begin with a dot. This
check reports the PHP that none of those plugins accounts for:

- a folder holding PHP at any depth with no file carrying that header, which
  includes a plugin installed one folder too deep (`plugins/name/name/`);
- a folder whose name begins with a dot and holds PHP;
- a PHP file at the top of the directory with no header, apart from the empty
  `Silence is golden` index.php.

PHP means `.php`, `.php3`, `.php4`, `.php5`, `.php7`, `.php8`, `.phtml` and `.pht`. A folder without
PHP, such as one holding only fonts, is not reported. Folder names are not
judged, since many plugins' main files are not named after their folders.

## Why it matters

Code in the plugins directory that is not part of a plugin never runs through
WordPress, but the web server still runs it when someone requests its URL.
That makes the plugins directory a common place to hide a backdoor: it is
full of PHP already, and a folder named like a plugin draws no attention.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `directory` | string | The plugins directory, relative to the site root. |
| `entries_listed` | integer | Entries at the top of the plugins directory. |
| `flagged` | integer | Entries reported. |
| `scan_incomplete` | boolean | True when part of the directory could not be examined. |
| `not_examined` | string | What could not be examined: symlinks that were not followed, headers that could not be read, or a walk that stopped. Present only when `scan_incomplete` is true. |
| `entries` | list of objects | The entries reported, by path, at most 20. |
| `entries[].path` | string | The folder or file, relative to the site root. |
| `entries[].reason` | string | Why it was reported. |
| `entries[].php_files` | integer | PHP files in it. |
| `entries[].php_newest` | string | When the most recently changed of them was last modified, in UTC. |

## Example

From the check's tests: an `index.php` at the top of the plugins directory that
holds code.

```json
{
	"id": "sec.plugins.unknown",
	"severity": "warning",
	"title": "PHP in the plugins directory likely belongs to no plugin",
	"evidence": {
		"directory": "wp-content/plugins",
		"entries_listed": 4,
		"flagged": 1,
		"scan_incomplete": false,
		"entries": [
			{
				"path": "wp-content/plugins/index.php",
				"reason": "PHP file with no Plugin Name header",
				"php_files": 1,
				"php_newest": "2026-11-08 14:54 UTC"
			}
		]
	},
	"fix": "Look at each entry listed. WordPress loads a plugin only from a .php file with a `Plugin Name:` header at the top of its folder, so this code does not run as a plugin, yet anyone can request it over the web. A folder nested one level too deep (wp-content/plugins/name/name/) is a failed install: delete it and install the plugin again. Otherwise, if you did not put the code there, move it out of the web root to inspect it and treat the site as compromised: change the passwords and the keys and salts, and look for how it was added."
}
```

## When it skips

- `WP_PLUGIN_DIR` or `WPMU_PLUGIN_DIR` is set in `wp-config.php`, since only
  the default plugins directory is read.
- The plugins directory is missing or cannot be read.
- Nothing was found, but a symlink was not followed, a header could not be
  read, or the walk stopped at `--max-files`: a clean result would claim more
  than was examined.

## False positives

- A plugin that keeps helper scripts in a separate top-level folder, such as
  a companion folder with no header of its own.
- Leftovers from maintenance, such as a backup copy of a plugin renamed with a
  leading dot, or a vendor folder copied in beside the plugins.
- A plugin installed one folder too deep by unzipping it by hand. The fix text
  covers this case.
