# `sec.plugins.inactive`

Plugins in the plugins directory that are not active.

| | |
|---|---|
| Category | security |
| Severity | info |
| Reads | the database, the site files |
| Since | 0.1.0 |

## What it reports

The check reads the `active_plugins` option and lists the entries at the top
of the plugins directory that it does not name. An entry is a folder, a
symlink, or a `.php` file other than `index.php`. A folder counts whether or
not it holds a plugin file, and a symlinked folder counts by its name without
being followed. Names that begin with a dot, such as `.git`, are passed over
as WordPress does.

An entry in `active_plugins` is matched by its folder, the part before the
first slash, or by its file name for a single-file plugin. An active entry
for a plugin that is no longer on disk is ignored. The option is read when it
is 1 MiB or smaller.

## Why it matters

An inactive plugin does not run through WordPress, but its files stay on the
server and anyone can request them by URL. A vulnerable inactive plugin is
still a way in, and a plugin nobody uses is easy to forget when updating.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `plugins_on_disk` | integer | Entries at the top of the plugins directory that count as plugins. |
| `inactive` | integer | Of those, the entries `active_plugins` does not name. |
| `scan_incomplete` | boolean | True when not every name in the plugins directory could be listed. |
| `plugins` | list of strings | Folder or file names of the inactive plugins, in name order, at most 20. |

## Example

From the check's tests: one active plugin and one inactive one, beside a
`.git` folder and a dot-named PHP file that are passed over.

```json
{
	"id": "sec.plugins.inactive",
	"severity": "info",
	"title": "Inactive plugins are still installed",
	"evidence": {
		"plugins_on_disk": 2,
		"inactive": 1,
		"scan_incomplete": false,
		"plugins": [
			"gone"
		]
	},
	"fix": "Delete plugins you do not use from the Plugins screen, or with `wp plugin delete <plugin>`. Inactive plugins are not updated automatically on every host, and their files can still be requested directly."
}
```

## When it skips

- There is no database connection, or the table prefix could not be read.
- `WP_PLUGIN_DIR` or `WPMU_PLUGIN_DIR` is set in `wp-config.php`, since only
  the default plugins directory is read.
- `MULTISITE` is true: network-activated plugins are stored elsewhere and are
  not read.
- `MULTISITE` could not be read, or is not defined while `wp-config.php`
  includes a file that was not read.
- The `active_plugins` option is missing, or larger than 1 MiB.
- The plugins directory is missing or cannot be read.
- Every plugin listed is active, but not every name in the directory could be
  listed.

An `active_plugins` value that is not a plain list stops the check with an
error rather than a skip.

## False positives

The finding is a plain comparison with `active_plugins`, but two kinds of
entry look inactive without being unused plugins:

- A folder in the plugins directory that holds no plugin, such as a leftover
  from a failed install or a folder of shared files.
- A plugin that a must-use plugin or the theme loads directly, without
  WordPress activating it.
