# `perf.cron.disabled`

`DISABLE_WP_CRON` is set to a true value in `wp-config.php`, so scheduled
events need a system cron job.

| | |
|---|---|
| Category | performance |
| Severity | info |
| Reads | the site files |
| Since | 0.2.0 |

## What it reports

The check reads `DISABLE_WP_CRON` from `wp-config.php` without running it. Any
true value is reported. A false value, or none, passes.

Whether a system cron job runs the events cannot be seen from the site's files,
so this is a notice. `perf.cron.overdue` reports events that are already late.

## Why it matters

With `DISABLE_WP_CRON` true, page loads no longer run scheduled events.
Scheduled posts, update checks, backups and emails then wait for a system cron
job. If that job was never set up, or stopped working after a move to another
server, they do not run at all.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `DISABLE_WP_CRON` | string | The value found: true in `wp-config.php`. |
| `system_cron_job` | string | A note that a system cron job cannot be seen from the site files, pointing to `perf.cron.overdue`. |

## Example

From the check's tests: `define( 'DISABLE_WP_CRON', true );` in `wp-config.php`.

```json
{
	"id": "perf.cron.disabled",
	"severity": "info",
	"title": "WP-Cron is turned off, so scheduled events need a system cron job",
	"evidence": {
		"DISABLE_WP_CRON": "true in wp-config.php",
		"system_cron_job": "not visible from the site files; perf.cron.overdue reports events that are late"
	},
	"fix": "Make sure a system cron job runs the events every few minutes, for example `*/5 * * * * wp --path=<site root> cron event run --due-now`, or one that requests wp-cron.php. If there is none, add one, or remove DISABLE_WP_CRON from wp-config.php."
}
```

## When it skips

- `wp-config.php` could not be read.
- `DISABLE_WP_CRON` is computed, for example from an environment variable, so
  its value is not known.
- `DISABLE_WP_CRON` is not set, but `wp-config.php` loads other code that was
  not read and could set it.

## False positives

- A system cron job runs the events, which is common on managed hosts and
  is the reason to turn WP-Cron off. The notice then needs no action.
