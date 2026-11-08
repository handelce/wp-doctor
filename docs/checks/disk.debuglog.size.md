# `disk.debuglog.size`

The WordPress debug log when it is larger than 100 MiB.

| | |
|---|---|
| Category | disk |
| Severity | warning above 100 MiB, critical above 1 GiB |
| Reads | the site files |
| Since | 0.1.0 |

## What it reports

The check measures one file: `debug.log` in the content directory, or the file
that `WP_DEBUG_LOG` names in `wp-config.php` when it resolves to a path inside
the site root. A `WP_DEBUG_LOG` of `true` or `false` keeps the default file.
A path is understood when it is a literal, or `__DIR__`, `dirname( __FILE__ )`
or `ABSPATH` followed by a literal.

A log above 100 MiB (104857600 bytes) is a warning, and one above 1 GiB
(1073741824 bytes) is critical. A missing log passes. Both limits are defaults
and can be changed in `wp-doctor.json`, as long as the warning stays below the
critical:

```json
{ "thresholds": { "disk.debuglog.size": { "warning": 52428800, "critical": 536870912 } } }
```

## Why it matters

WordPress appends to the debug log and never rotates it. A plugin that raises a
notice on every request can fill the disk with it, and a full disk stops
uploads, updates and database writes. The log is also often reachable over the
web, where it shows paths and errors to anyone.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `path` | string | The log file, relative to the site root. |
| `bytes` | integer | Size of the log. |
| `warning_above_bytes` | integer | The warning limit, in bytes. |
| `critical_above_bytes` | integer | The critical limit, in bytes. |
| `threshold_basis` | string | Whether the limits are the built-in defaults or were set in a config file, and which one. |

## Example

From the check's tests: a 200 MiB log in a content directory that `WP_CONTENT_DIR` moves to `app`.

```json
{
	"id": "disk.debuglog.size",
	"severity": "warning",
	"title": "The WordPress debug log is very large",
	"evidence": {
		"path": "app/debug.log",
		"bytes": 209715200,
		"warning_above_bytes": 104857600,
		"critical_above_bytes": 1073741824,
		"threshold_basis": "default rule of thumb, not measured for this site"
	},
	"fix": "Empty the file with `truncate -s 0 'app/debug.log'`, find what keeps writing to it, and turn WP_DEBUG_LOG off in wp-config.php when you are not debugging. Set up log rotation if it must stay on."
}
```

## When it skips

- `wp-config.php` could not be read.
- `WP_DEBUG_LOG` names a file outside the site root, or a path that is
  computed and could not be read.
- `WP_CONTENT_DIR` points outside the site root or could not be read, so the
  default log cannot be located.
- The log is a symlink or a broken symlink, since its target is not measured.
- The log path is a directory.
- The log is excluded with `--exclude`, sits under a directory that cannot be
  searched, or exists but could not be measured.

## False positives

The check reports the measured size of one file, so the finding is a fact.
