# `disk.errorlog.size`

Files named `error_log` anywhere under the site that are larger than 50 MiB.

| | |
|---|---|
| Category | disk |
| Severity | warning |
| Reads | the site files |
| Since | 0.1.0 |

## What it reports

PHP writes an `error_log` file into the directory of the script that failed, so
the check walks the whole site and reports every regular file named exactly
`error_log` that is above 50 MiB (52428800 bytes). The files are listed
largest first, at most 20.

The limit is a default and can be changed in `wp-doctor.json`:

```json
{ "thresholds": { "disk.errorlog.size": { "warning": 104857600 } } }
```

This check runs even when `wp-config.php` cannot be read.

## Why it matters

An `error_log` grows by a line for every error on every request and is never
rotated by PHP. A repeating error can fill the disk, and the size points at a
fault that is still happening. These files are often reachable over the web,
where they show paths and errors to anyone.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `large_files` | integer | Files above the limit. |
| `warning_above_bytes` | integer | The limit a file had to exceed, in bytes. |
| `threshold_basis` | string | Whether the limit is the built-in default or was set in a config file, and which one. |
| `scan_incomplete` | boolean | True when the walk did not see every path: it stopped at `--max-files`, met an unreadable path, or met a symlink leading outside the site root or nowhere. |
| `files` | list of objects | The files above the limit, largest first, at most 20. |
| `files[].path` | string | The file, relative to the site root. |
| `files[].bytes` | integer | Size of the file. |

## Example

From the check's tests: a 60 MiB `error_log` at the site root, found before the walk stopped at the file limit.

```json
{
	"id": "disk.errorlog.size",
	"severity": "warning",
	"title": "Large error_log files",
	"evidence": {
		"large_files": 1,
		"warning_above_bytes": 52428800,
		"threshold_basis": "default rule of thumb, not measured for this site",
		"scan_incomplete": true,
		"files": [
			{
				"path": "error_log",
				"bytes": 62914560
			}
		]
	},
	"fix": "Read the end of each file to find the repeating error and fix its cause, then empty the file (`truncate -s 0 <path>`). Turn on log rotation on the host if the file keeps growing."
}
```

## When it skips

- No large `error_log` was found, but the walk did not see every path: it
  stopped at `--max-files`, met an unreadable path or a symlink leading outside
  the site root, or every entry was excluded. A symlink such as uploads on
  shared storage is named in the reason; excluding it with `--exclude` lets a
  clean site pass.

## False positives

The check reports measured sizes, so the finding is a fact. A large file can
hold old errors whose cause is already fixed; the end of the file shows whether
it is still growing.
