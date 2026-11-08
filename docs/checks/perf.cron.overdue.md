# `perf.cron.overdue`

Scheduled events that are more than an hour late.

| | |
|---|---|
| Category | performance |
| Severity | warning |
| Reads | the database |
| Since | 0.1.0 |

## What it reports

The check reads the `cron` option from the main site's options table without
`unserialize`, and counts the events whose scheduled time is more than 3600
seconds before the time of the scan. It lists the hooks with the most late
events first, at most 10, and notes `DISABLE_WP_CRON` when it is true in
`wp-config.php`, or when its value could not be read.

The hour is a rule of thumb and the evidence says so. Change it in
`wp-doctor.json`:

```json
{ "thresholds": { "perf.cron.overdue": { "warning": 7200 } } }
```

A `cron` option that is not in the shape WordPress writes is a separate
warning, as for `perf.cron.duplicates`.

## Why it matters

WordPress runs due events when a visitor loads a page, or when a system cron
job requests `wp-cron.php`. Events more than an hour late mean neither is
happening: scheduled posts stay unpublished, and update checks, backups and
emails stop. Common causes are `DISABLE_WP_CRON` without a working system job,
and a plugin or firewall that blocks the site's requests to itself.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `overdue_events` | integer | Events more than the limit late. Only on the overdue finding. |
| `scheduled_events` | integer | Events in the whole schedule. Only on the overdue finding. |
| `oldest_overdue_seconds` | integer | How late the most overdue event is, in seconds. Only on the overdue finding. |
| `overdue_after_seconds` | integer | The lateness an event had to exceed, in seconds. Only on the overdue finding. |
| `threshold_basis` | string | Whether the limit is the built-in default or was set in a config file, and which one. Only on the overdue finding. |
| `hooks` | list of objects | The hooks with late events, most first, at most 10. Only on the overdue finding. |
| `hooks[].hook` | string | The hook name. |
| `hooks[].overdue` | integer | Late events for the hook, across all its argument sets. |
| `DISABLE_WP_CRON` | string | Present on the overdue finding only when `DISABLE_WP_CRON` is true, or when its value could not be read because it is computed or `wp-config.php` loads code that was not read. Says that events then depend on a system cron job. |
| `reason` | string | Why the cron option could not be read as a schedule. Only on the unreadable cron option finding. |
| `option_bytes` | integer | Size of the cron option. Only on the unreadable cron option finding. |
| `expected_layout` | string | The shape WordPress writes the option in. Only on the unreadable cron option finding. |
| `stopped_at_byte` | integer | The byte where reading stopped. Only on the unreadable cron option finding, and only when that position is known. |

## Example

From the check's tests: one event two hours late on a site that sets `DISABLE_WP_CRON` to true.

```json
{
	"id": "perf.cron.overdue",
	"severity": "warning",
	"title": "Scheduled events are overdue",
	"evidence": {
		"overdue_events": 1,
		"scheduled_events": 1,
		"oldest_overdue_seconds": 7200,
		"overdue_after_seconds": 3600,
		"threshold_basis": "default rule of thumb, not measured for this site",
		"hooks": [
			{
				"hook": "example_late",
				"overdue": 1
			}
		],
		"DISABLE_WP_CRON": "true: events then depend on a system cron job"
	},
	"fix": "WordPress only runs scheduled events when someone visits the site. Add a system cron job that requests wp-cron.php every few minutes (or runs `wp cron event run --due-now`), and if DISABLE_WP_CRON is true make sure that job exists and works. If it does, check for a plugin or firewall rule that blocks loopback requests."
}
```

## When it skips

- `wp-config.php` could not be read.
- There is no database connection, or the table prefix could not be read from
  `wp-config.php`.
- There is no `cron` option in the options table, which points to a wrong table
  prefix or database.
- The `cron` option is larger than 16 MiB (16777216 bytes). It is not fetched.

## False positives

- A scan of a database copy, such as a restored backup or a staging site, made
  some time before. Lateness is measured from the clock of the machine running
  the scan, so every event due since the copy was made looks late.
- A clock on the scanning machine that is ahead of the server's.
- A site with almost no visitors and no system cron job, where events run only
  when someone next loads a page. The events are late, but nothing is broken.
