# `perf.autoload.size`

The total size of the options WordPress loads on every request, when it is
above 1 MiB.

| | |
|---|---|
| Category | performance |
| Severity | warning above 1 MiB (1048576 bytes), critical above 3 MiB; skipped when the table has no autoloaded rows |
| Reads | the database |
| Since | 0.1.0 |

## What it reports

The check adds up the sizes of the values of every autoloaded row in the main
site's options table: rows with an `autoload` value of `yes`, `on`, `auto` or
`auto-on`, the values WordPress loads on every request.

A total above 1 MiB (1048576 bytes) is a warning, and one above 3 MiB
(3145728 bytes) is critical. Both are rules of thumb that have not been
measured for the site, and the evidence says so. Change them in
`wp-doctor.json`, as long as the warning stays below the critical:

```json
{ "thresholds": { "perf.autoload.size": { "warning": 2097152, "critical": 6291456 } } }
```

## Why it matters

WordPress reads every autoloaded option into memory at the start of each page
load, the admin and AJAX requests included. A large total slows every request
and raises the memory each one uses.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `autoloaded_bytes` | integer | Total size of the autoloaded values. |
| `autoloaded_options` | integer | Autoloaded rows counted. |
| `warning_above_bytes` | integer | The warning limit, in bytes. |
| `critical_above_bytes` | integer | The critical limit, in bytes. |
| `threshold_basis` | string | Whether the limits are the built-in defaults or were set in a config file, and which one. |

## Example

From the check's tests: 150 bytes of autoloaded options, with limits of 100 and 200 set in a config file.

```json
{
	"id": "perf.autoload.size",
	"severity": "warning",
	"title": "Autoloaded options are large; every request loads them",
	"evidence": {
		"autoloaded_bytes": 150,
		"autoloaded_options": 10,
		"warning_above_bytes": 100,
		"critical_above_bytes": 200,
		"threshold_basis": "set in custom.json"
	},
	"fix": "See perf.autoload.top for the largest options. For options left by plugins that are no longer used, delete the row; for options that are needed but not on every request, set autoload to no. Take a database backup first."
}
```

## When it skips

- `wp-config.php` could not be read.
- There is no database connection, or the table prefix could not be read from
  `wp-config.php`.
- The options table has no autoloaded rows, which points to a wrong table
  prefix or database.

## False positives

The check reports a measured total, so the finding is a fact.
