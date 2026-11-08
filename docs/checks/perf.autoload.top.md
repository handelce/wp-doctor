# `perf.autoload.top`

The ten largest options that WordPress loads on every request.

| | |
|---|---|
| Category | performance |
| Severity | info |
| Reads | the database |
| Since | 0.1.0 |

## What it reports

The check lists the 10 largest autoloaded rows in the main site's options
table, by the size of their values, largest first and then by name. It counts
the same `autoload` values as `perf.autoload.size`: `yes`, `on`, `auto` and
`auto-on`. It is a notice on every site whose options table has autoloaded
rows, with no limit.

## Why it matters

When `perf.autoload.size` reports a large total, a few options usually account
for most of it. The list shows which ones to look at first, and their names
usually point to the plugin or theme that wrote them.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `options` | list of objects | The largest autoloaded options, largest first, at most 10. |
| `options[].name` | string | The option name. |
| `options[].bytes` | integer | Size of its value. |

## Example

From the check's tests: one autoloaded option whose name looks like a number.

```json
{
	"id": "perf.autoload.top",
	"severity": "info",
	"title": "Largest autoloaded options",
	"evidence": {
		"options": [
			{
				"name": "2026",
				"bytes": 500
			}
		]
	},
	"fix": "Look up which plugin or theme owns each large option. Delete rows left by plugins that are no longer installed, and set autoload to no for options that are not needed on every request. Take a database backup first."
}
```

## When it skips

- `wp-config.php` could not be read.
- There is no database connection, or the table prefix could not be read from
  `wp-config.php`.
- The options table has no autoloaded rows, which points to a wrong table
  prefix or database.

## False positives

The check lists measured sizes, so the finding is a fact.
