# `perf.autoload.orphaned`

Large autoloaded options whose names match no installed plugin, must-use
plugin or theme.

| | |
|---|---|
| Category | performance |
| Severity | warning |
| Reads | the database, the site files |
| Since | 0.1.0 |

## What it reports

The check reads the 50 largest autoloaded options above 10 KiB (10240 bytes)
from the main site's options table. Autoloaded means an `autoload` value of
`yes`, `on`, `auto` or `auto-on`. For each one it takes the prefix: the text of
the lowercased name before its first `_` or `-`. An option is reported when
none of these is true:

- the prefix is one WordPress core uses: `cron`, `rewrite`, `sidebars`,
  `widget`, `theme`, `wp`, `active`, `recently`, `uninstall`, `nav`, `fresh`,
  `db`, `site`, `blog`, `home`, `template`, `stylesheet`, `current`, `admin`,
  `category`, `default`, `comment`, `comments`, `disallowed` or `moderation`;
- the prefix is shorter than three characters;
- the name is `user_roles` after the site's table prefix;
- the name of an installed plugin, must-use plugin or theme starts with the
  prefix, or the prefix starts with it.

Installed names are the entries at the top of the plugins, must-use plugins and
themes directories, with `.php` removed from file names, compared without
regard to case. Names shorter than three characters and `index` are left out,
so a plugin named `ab` does not claim every option starting with those
letters. A symlinked folder counts by its name even when it leads outside the
site root. At most 20 options are listed.

The size limit is a default that has not been measured against real sites.
Change it in `wp-doctor.json`:

```json
{ "thresholds": { "perf.autoload.orphaned": { "warning": 51200 } } }
```

Many plugins use an option prefix that differs from their folder name, so this
is a guess and the title says "likely".

## Why it matters

Uninstalling a plugin often leaves its options behind. An autoloaded leftover
is still read from the database on every request, for a plugin that no longer
uses it, and adds to the total that `perf.autoload.size` reports.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `large_options_examined` | integer or string | How many options above the limit were examined: a number when fewer than 50 were found, or a note that only the largest 50 were examined. |
| `larger_than_bytes` | integer | The size an option had to exceed, in bytes. |
| `threshold_basis` | string | Whether the limit is the built-in default or was set in a config file, and which one. |
| `options` | list of objects | The options reported, largest first, at most 20. |
| `options[].name` | string | The option name. |
| `options[].bytes` | integer | Size of its value. |

## Example

From the check's tests: a large `abandoned_cache` option on a site whose only other plugin is named `ab`, too short to claim it.

```json
{
	"id": "perf.autoload.orphaned",
	"severity": "warning",
	"title": "Large autoloaded options likely belong to plugins or themes that are no longer installed",
	"evidence": {
		"large_options_examined": 1,
		"larger_than_bytes": 10240,
		"threshold_basis": "default rule of thumb, not measured for this site",
		"options": [
			{
				"name": "abandoned_cache",
				"bytes": 900000
			}
		]
	},
	"fix": "For each option, search the web for its name to find the plugin that wrote it. If that plugin is gone, delete the row (`wp option delete <name>`) after a database backup. If the plugin is still in use under a different folder name, ignore the option."
}
```

## When it skips

- `wp-config.php` could not be read.
- There is no database connection, or the table prefix could not be read from
  `wp-config.php`.
- `WP_PLUGIN_DIR` or `WPMU_PLUGIN_DIR` is set in `wp-config.php`, or the
  content directory cannot be located.
- The plugins directory is missing or cannot be listed in full.
- The must-use plugins or themes directory exists but cannot be listed in
  full, is excluded, or leads outside the site root. A missing one is fine.

## False positives

- A plugin whose options start with a different word than its folder name,
  such as a brand name or an abbreviation. The fix text says to ignore these.
- An option written by code outside the plugins and themes directories, such
  as a custom drop-in or code in `wp-config.php`.
