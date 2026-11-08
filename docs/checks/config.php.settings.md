# `config.php.settings`

PHP settings in the site's own files that show errors to visitors or leave too little memory or time.

| | |
|---|---|
| Category | config |
| Severity | warning; info |
| Reads | the site files |
| Since | 0.1.0 |

## What it reports

The check reads `WP_MEMORY_LIMIT` from `wp-config.php` and reports it as info
when it is below 64 MiB. A value of `-1` means no limit and passes.

It then finds the value of three PHP settings as the site's files set them:
`php.ini` in the site root, then `.user.ini` there, which overrides it, then
literal `ini_set()` calls in `wp-config.php`, which override both. Sections
in the INI files are read as if they were not there. For each setting found:

- `display_errors` set to `1`, `On`, `true`, `yes` or `stdout` is a warning.
  `stderr` passes, since it sends errors to the server log, not to the page.
- `memory_limit` below 64 MiB is info. `-1` passes.
- `max_execution_time` from 1 to 29 seconds is info. `0`, no limit, passes.

Each setting gives its own finding. The web server's own PHP configuration
can override every one of these values and cannot be read from a
command-line scan, so the titles say "likely".

## Why it matters

Errors displayed in pages show visitors file paths, plugin names and
sometimes database details. A memory limit that is too low makes the
dashboard, updates and imports fail with fatal errors. A short execution
time limit stops updates, imports and backups part-way, which can leave a
plugin or the database half changed.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `setting` | string | The setting judged: `WP_MEMORY_LIMIT`, `display_errors`, `memory_limit` or `max_execution_time`. |
| `source` | string | Where the value that applies was set: `wp-config.php`, `ini_set() in wp-config.php`, `.user.ini` or `php.ini`. |
| `WP_MEMORY_LIMIT` | string | The value of the constant as written. Present only in the `WP_MEMORY_LIMIT` finding. |
| `bytes` | integer | The memory limit in bytes. Present only in the two memory findings. |
| `low_below_bytes` | integer | The threshold, 64 MiB in bytes. Present only in the two memory findings. |
| `threshold_basis` | string | Says the threshold is a default rule of thumb, not measured for this site. Present in the memory and execution time findings. |
| `not_examined` | string | Names the web server's own PHP configuration, which was not read. Present in every finding. |
| `value` | string | The value as written, possibly in quotes. Present in the `display_errors`, `memory_limit` and `max_execution_time` findings. |
| `seconds` | integer | The execution time limit. Present only in the `max_execution_time` finding. |
| `low_below_seconds` | integer | The threshold, 30 seconds, PHP's default. Present only in the `max_execution_time` finding. |

## Example

From the check's tests: `WP_MEMORY_LIMIT` set to `64K` in `wp-config.php`.

```json
{
	"id": "config.php.settings",
	"severity": "info",
	"title": "The WordPress memory limit is likely too low",
	"evidence": {
		"setting": "WP_MEMORY_LIMIT",
		"source": "wp-config.php",
		"WP_MEMORY_LIMIT": "64K",
		"bytes": 65536,
		"low_below_bytes": 67108864,
		"threshold_basis": "default rule of thumb, not measured for this site",
		"not_examined": "the web server's own PHP configuration, which can override these values and cannot be read from a command-line scan"
	},
	"fix": "Raise it in wp-config.php, for example:\ndefine( 'WP_MEMORY_LIMIT', '256M' );\nThe host may cap the value."
}
```

## When it skips

- None of these settings is set in `wp-config.php`, `.user.ini` or `php.ini`
  in the site root.
- Nothing else was found wrong, but a value could not be read:
  `WP_MEMORY_LIMIT` is computed or is not a size such as `256M`, an
  `ini_set()` value is computed, or `php.ini` or `.user.ini` does not parse as
  an INI file.

## False positives

- A host whose own PHP configuration sets other values, which win over the
  site's files in many setups. A `php.ini` in the site root is read only by
  some hosts, and `.user.ini` only under PHP-FPM or CGI.
- A small site that runs well within a lower memory or time limit. The
  thresholds are rules of thumb, as the evidence says.
- `display_errors` switched on in a development copy.
