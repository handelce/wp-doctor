# `perf.cron.duplicates`

The same cron event, with the same arguments, scheduled more than 50 times.

| | |
|---|---|
| Category | performance |
| Severity | critical above 50 copies; warning when unreadable |
| Reads | the database |
| Since | 0.1.0 |

## What it reports

The check reads the `cron` option from the main site's options table without
`unserialize`, and counts, for each hook, how many times it is scheduled with
the same arguments. A hook is reported when one of its argument sets has more
than 50 copies. A hook scheduled with different arguments, such as one event
per post, is not counted as a duplicate. At most 10 hooks are listed, those
with the most copies first.

The limit of 50 is a rule of thumb and the evidence says so. Change it in
`wp-doctor.json`:

```json
{ "thresholds": { "perf.cron.duplicates": { "critical": 200 } } }
```

A `cron` option that is not in the shape WordPress writes, such as an object, a
text key at the top or truncated data, is a separate warning: WordPress likely
cannot run its schedule. That finding gives the reason, the option size and
the byte where reading stopped.

## Why it matters

A plugin that schedules an event each time it loads, without checking whether
one exists, adds a copy on every request. The `cron` option then grows with
every page load, WordPress reads and rewrites all of it each time the schedule
runs, and the same task runs over and over.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `hooks` | list of objects | The hooks above the limit, most copies first, at most 10. Only on the duplicates finding. |
| `hooks[].hook` | string | The hook name. |
| `hooks[].copies` | integer | Copies of its most repeated argument set. |
| `scheduled_events` | integer | Events in the whole schedule. Only on the duplicates finding. |
| `cron_option_bytes` | integer | Size of the cron option. Only on the duplicates finding. |
| `critical_above_copies` | integer | The number of copies a hook had to exceed. Only on the duplicates finding. |
| `threshold_basis` | string | Whether the limit is the built-in default or was set in a config file, and which one. Only on the duplicates finding. |
| `reason` | string | Why the cron option could not be read as a schedule. Only on the unreadable cron option finding. |
| `option_bytes` | integer | Size of the cron option. Only on the unreadable cron option finding. |
| `expected_layout` | string | The shape WordPress writes the option in. Only on the unreadable cron option finding. |
| `stopped_at_byte` | integer | The byte where reading stopped. Only on the unreadable cron option finding, and only when that position is known. |

## Example

From the check's tests: a hook named like a number, scheduled 60 times with the same arguments.

```json
{
	"id": "perf.cron.duplicates",
	"severity": "critical",
	"title": "Hooks are likely being rescheduled in a loop: the same event is scheduled many times",
	"evidence": {
		"hooks": [
			{
				"hook": "12345",
				"copies": 60
			}
		],
		"scheduled_events": 60,
		"cron_option_bytes": 8785,
		"critical_above_copies": 50,
		"threshold_basis": "default rule of thumb, not measured for this site"
	},
	"fix": "Find the plugin or theme that schedules each listed hook (search wp-content for the hook name) and update or remove it. Then delete the duplicate events, for example with `wp cron event delete <hook>`, and let the plugin schedule one again. Take a database backup first."
}
```

## When it skips

- `wp-config.php` could not be read.
- There is no database connection, or the table prefix could not be read from
  `wp-config.php`.
- There is no `cron` option in the options table, which points to a wrong table
  prefix or database.
- The `cron` option is larger than 16 MiB (16777216 bytes). It is not fetched.
- The schedule holds more than 50000 distinct events, more than the check
  analyses.

## False positives

- A plugin that queues the same task with the same arguments at many future
  times as part of its design, such as one run a day booked weeks ahead.
- A one-off import or migration that queued many identical batches and will
  work through them on its own.
