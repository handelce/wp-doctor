# `perf.tables.engine`

Site tables that use the MyISAM storage engine.

| | |
|---|---|
| Category | performance |
| Severity | info |
| Reads | the database |
| Since | 0.2.0 |

## What it reports

The check reads the engine of every base table whose name starts with the
site's table prefix, from `information_schema.tables` in the site's database,
and lists the ones that use MyISAM. Views and tables without the prefix are
not read. The finding is an info notice.

## Why it matters

MyISAM locks a whole table for each write and has no transactions or crash
recovery. On a busy site writes queue behind each other, and a crash can leave
a table that needs repair before it can be used. MyISAM tables in a WordPress
database are usually left from an old install or an import.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `tables_with_prefix` | integer | Base tables with the site's prefix. |
| `myisam_tables` | integer | Those that use MyISAM. |
| `tables` | list of strings | Names of the MyISAM tables, in name order, at most 20. |

## Example

From the integration tests: two MyISAM tables among three base tables with the
prefix.

```json
{
	"id": "perf.tables.engine",
	"severity": "info",
	"title": "Tables use the MyISAM storage engine",
	"evidence": {
		"tables_with_prefix": 3,
		"myisam_tables": 2,
		"tables": [
			"wptest_old_log",
			"wptest_terms"
		]
	},
	"fix": "Take a database backup, then convert each table to InnoDB, for example `wp db query \"ALTER TABLE wptest_old_log ENGINE=InnoDB\"`. Converting a large table rewrites it and locks it while it runs, so do it when the site is quiet."
}
```

## When it skips

- There is no database access, or the table prefix could not be read from
  `wp-config.php`.
- No table starts with the prefix, so the prefix or the database is likely
  wrong.
- More than 10000 tables carry the prefix.

## False positives

The engine is a plain fact read from the server, so the finding is not a
guess.
