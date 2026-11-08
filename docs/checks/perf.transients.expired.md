# `perf.transients.expired`

More expired transients than the limit are still stored in the options table.

| | |
|---|---|
| Category | performance |
| Severity | warning above 5000 rows |
| Reads | the database |
| Since | 0.1.0 |

## What it reports

The check reads the timeout rows of transients in the options table,
`_transient_timeout_` and `_site_transient_timeout_`, and counts the ones
whose time is before the scan time. It adds up the length of the values those
rows guard. More than 5000 expired transients is a warning. The limit is set
in `wp-doctor.json`:

```json
{ "thresholds": { "perf.transients.expired": { "warning": 10000 } } }
```

Only transients stored in the database are counted. With an external object
cache they live in the cache instead.

## Why it matters

WordPress deletes an expired transient when something reads it again.
Transients that are never read again stay in the options table, which makes it
larger and its queries slower.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `expired_transients` | integer | Expired transients of both kinds, counted by their timeout rows. |
| `expired_value_bytes` | integer | Total length of their values, in bytes. |
| `warning_above_rows` | integer | The limit the count was compared with. |
| `threshold_basis` | string | Where the limit came from: the built-in default, or the configuration file that set it. |

## Example

From the check's tests: 5001 expired transients of both kinds, one more than
the default limit.

```json
{
	"id": "perf.transients.expired",
	"severity": "warning",
	"title": "Many expired transients are still stored in the options table",
	"evidence": {
		"expired_transients": 5001,
		"expired_value_bytes": 2000,
		"warning_above_rows": 5000,
		"threshold_basis": "default rule of thumb, not measured for this site"
	},
	"fix": "Delete the expired ones with `wp transient delete --expired` (and `wp transient delete --expired --network` on multisite), or let a cleanup plugin do it. If they come back quickly, find the plugin that creates them. Take a database backup first."
}
```

## When it skips

- There is no database access, or the table prefix could not be read from
  `wp-config.php`.

## False positives

The count is a plain fact about the options table. It counts timeout rows, so
a timeout row whose transient is gone counts too and adds nothing to
`expired_value_bytes`.
