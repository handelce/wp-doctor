# `sec.wpcron.persistence`

Scheduled WP-Cron hooks that no plugin, theme or WordPress core file accounts for.

| | |
|---|---|
| Category | security |
| Severity | warning ("likely"); info |
| Reads | the database, the site files |
| Since | 0.3.0 |

## What it reports

WP-Cron runs an event by firing the hook stored with it, so the code that
handles the hook names it in a string. The check reads every hook in the
`cron` option, when the option is 16 MiB or smaller, and looks for it in the
site's PHP files and in a `wp-config.php` above the site root. A hook counts
as named when a single- or double-quoted string in the file is the whole
hook name, or a prefix of it of eight or more characters ending in `_` or
`-`, which covers names built at run time such as `'prefix_' . $id`. PHP
means `.php`, `.php3`, `.php4`, `.php5`, `.php7`, `.php8`, `.phtml` and
`.pht`. A file over 4 MiB is searched in its first 4 MiB only.

The code WordPress loads is `wp-config.php`, the files at the top of the core
directory and in `wp-admin` and `wp-includes`, the drop-ins at the top of the
content directory, anything in `mu-plugins`, and the plugins and themes that
`sec.plugins.unknown` and `sec.themes.unknown` would not report. The check
raises:

- one warning ("likely") listing the hooks named only by PHP outside that
  code, such as a file in uploads or in a plugin folder with no plugin
  header, each with the files that name it;
- one info notice ("likely") listing the hooks that no PHP file names, so
  their events run nothing. A plugin that was removed likely left them.

A hook named by any file of loaded code is not reported, even when other
files name it too. A `cron` option that cannot be read in the layout
WordPress writes is reported as a warning that it is likely damaged, as
`perf.cron.overdue` does.

## Why it matters

Injected code often schedules itself with WP-Cron, so that it runs again after
it has been cleaned out of the files where it was found. Handling a hook from
outside any plugin, theme or core file is the pattern of such a planted job.
Hooks that nothing handles are usually harmless clutter, but one you do not
recognise is worth a look.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `hooks_flagged` | integer | Hooks named only by PHP outside loaded code. On the warning only. |
| `hooks_scheduled` | integer | Distinct hooks in the `cron` option. On the info notice only. |
| `hooks_not_named` | integer | Hooks no PHP file names. On the info notice only. |
| `scan_incomplete` | boolean | True when some PHP could not be read in full or the walk of the site was incomplete. On the warning and the info notice. |
| `not_examined` | string | What was not examined: PHP files read only in part, PHP files that could not be read, or what the walk could not see. Present only when `scan_incomplete` is true. |
| `hooks` | list of objects | The hooks reported, in name order, at most 20. On the warning and the info notice. |
| `hooks[].hook` | string | The hook name. |
| `hooks[].events` | integer | Events scheduled for it. |
| `hooks[].named_in` | string | The files that name it, relative to the site root and separated by commas, at most five, with `...` when there are more. A `wp-config.php` above the root is `../wp-config.php`. On the warning only. |
| `reason` | string | Why reading the `cron` option stopped. On the damaged-option warning only. |
| `option_bytes` | integer | Size of the `cron` option in bytes. On the damaged-option warning only. |
| `expected_layout` | string | Always the same description of the layout WordPress writes. On the damaged-option warning only. |
| `stopped_at_byte` | integer | Where in the value reading stopped. On the damaged-option warning, present only when known. |

## Example

From the check's tests: one scheduled hook that no PHP file names.

```json
{
	"id": "sec.wpcron.persistence",
	"severity": "info",
	"title": "Scheduled events are likely left by plugins that were removed",
	"evidence": {
		"hooks_scheduled": 1,
		"hooks_not_named": 1,
		"scan_incomplete": false,
		"hooks": [
			{
				"hook": "rm_job_1",
				"events": 1
			}
		]
	},
	"fix": "No installed PHP names these hooks, so their events run nothing. If you recognise a hook from a plugin you removed, delete its events with `wp cron event delete <hook>`. A hook you do not recognise is worth a look."
}
```

## When it skips

- There is no database connection, or the table prefix could not be read.
- `WP_PLUGIN_DIR` or `WPMU_PLUGIN_DIR` is set in `wp-config.php`, since only
  the default plugins directory is read.
- The `cron` option is missing, or larger than 16 MiB.
- No events are scheduled.
- The site root could not be walked.
- Every scheduled hook is named by loaded code, but some PHP could not be
  read in full or the walk was incomplete.

## False positives

- A plugin that builds a hook name at run time without a quoted prefix of
  eight characters or more ending in `_` or `-`, such as one made with
  `sprintf()`. Its hooks show as not named although the plugin is installed.
- A hook whose only handler is in a path left out with `--exclude` or the
  `exclude` list in `wp-doctor.json`.
- A plugin or theme that keeps code in a companion folder with no plugin
  header or `style.css`. Hooks named only there are reported as a warning.
- A hook left by a plugin you removed yourself. The notice is then true
  but harmless.
