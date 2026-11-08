# `perf.tables.bloat`

Large tables whose names suggest they hold logs, sessions or queued jobs.

| | |
|---|---|
| Category | performance |
| Severity | warning |
| Reads | the database |
| Since | 0.1.0 |

## What it reports

The check reads the size of the tables that carry the site's table prefix, as
data plus index size from `information_schema.tables`, the 200 largest at
most. A table is reported when it is larger than 100 MiB (104857600 bytes) and
its name, without the prefix, is or ends in `_log`, `_logs`, `_session` or
`_sessions`, or starts with `actionscheduler_`, in any case. The limit is set
in `wp-doctor.json`:

```json
{ "thresholds": { "perf.tables.bloat": { "warning": 524288000 } } }
```

Post revisions live inside the posts table and are not measured here;
`perf.revisions.count` counts them.

## Why it matters

Logging, security and job queue plugins add rows on every request or task, and
many keep them until told otherwise. A table of hundreds of megabytes makes
backups and restores slower, takes disk space, and can slow the plugin's own
queries.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `larger_than_bytes` | integer | The size a table had to exceed, in bytes. |
| `threshold_basis` | string | Where the limit came from: the built-in default, or the configuration file that set it. |
| `tables` | list of objects | The tables reported, largest first, at most ten. |
| `tables[].name` | string | Table name, with the prefix. |
| `tables[].bytes` | integer | Data plus index size, in bytes. |

## Example

From the check's tests: an Action Scheduler table and a security log table,
both above 100 MiB.

```json
{
	"id": "perf.tables.bloat",
	"severity": "warning",
	"title": "Tables that likely hold logs or queued jobs have grown very large",
	"evidence": {
		"larger_than_bytes": 104857600,
		"threshold_basis": "default rule of thumb, not measured for this site",
		"tables": [
			{
				"name": "wptest_actionscheduler_actions",
				"bytes": 314572800
			},
			{
				"name": "wptest_security_log",
				"bytes": 209715200
			}
		]
	},
	"fix": "Check which plugin owns each table. Most log and queue plugins have a setting for how long to keep rows; lower it and run their cleanup. Take a database backup before deleting rows."
}
```

## When it skips

- There is no database access, or the table prefix could not be read from
  `wp-config.php`.
- No table starts with the prefix, so the prefix or the database is likely
  wrong.

## False positives

- The name test is a guess. A table named like a log may hold records the site
  needs, such as an audit trail kept for compliance or a shop's order history.
- The sizes come from the server's table statistics, which are estimates for
  some engines, and do not show what a cleanup would free.
