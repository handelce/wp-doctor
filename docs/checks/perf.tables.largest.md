# `perf.tables.largest`

The ten largest tables that carry the site's table prefix.

| | |
|---|---|
| Category | performance |
| Severity | info |
| Reads | the database |
| Since | 0.1.0 |

## What it reports

The check reads the data plus index size of the tables that carry the site's
table prefix, from `information_schema.tables` in the site's database, biggest
first and 200 at most, and lists the ten largest. It reports whenever a table
has the prefix: there is no limit, and the finding is an info notice. The
sizes are the server's statistics, which are estimates for some engines.

## Why it matters

The listing shows where the database space goes. The largest tables are where
a slow database or a large backup usually comes from, and a big table left by
a plugin that is no longer installed can be dropped.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `tables_with_prefix` | integer or string | Tables with the prefix. When 200 were read, the most the check reads, a string such as `at least 200; only the largest 200 were read`. |
| `tables` | list of objects | The largest tables, biggest first, at most ten. |
| `tables[].name` | string | Table name, with the prefix. |
| `tables[].bytes` | integer | Data plus index size, in bytes. |

## Example

From the check's tests: a database holding one table with the prefix,
`wptest_posts`, at 100 bytes.

```json
{
	"id": "perf.tables.largest",
	"severity": "info",
	"title": "Largest database tables",
	"evidence": {
		"tables_with_prefix": 1,
		"tables": [
			{
				"name": "wptest_posts",
				"bytes": 100
			}
		]
	},
	"fix": "Look at the biggest tables first when the database is slow or the backups are large. Tables left by plugins that are no longer installed can be dropped after a backup."
}
```

## When it skips

- There is no database access, or the table prefix could not be read from
  `wp-config.php`.
- No table starts with the prefix, so the prefix or the database is likely
  wrong.

## False positives

The listing is a plain fact, not a judgment, though the sizes are estimates
for some engines.
