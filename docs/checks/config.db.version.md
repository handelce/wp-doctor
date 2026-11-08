# `config.db.version`

A MySQL or MariaDB server whose release series is past the end of its vendor's support.

| | |
|---|---|
| Category | config |
| Severity | warning ("likely") |
| Reads | the database |
| Since | 0.2.0 |

## What it reports

The check asks the server for `SELECT VERSION()` and takes the release series
from it: the major and minor number, such as `8.0` or `10.6`. A version that
contains `MariaDB` is read as MariaDB, with the old `5.5.5-` prefix removed;
anything else, Percona Server and other forks included, is read as MySQL.

The series is looked up in a table of end-of-support dates built into
wp-doctor. For MySQL that is the end of extended support of a long-term
series, and an Innovation series ends when the next one ships. For MariaDB it
is the end of community maintenance. Where a vendor gives only a month or a
quarter, the date is its last day. A series older than every one in the table
has ended. The finding is raised when the date is before today, or when the
vendor says the series has ended without giving a date.

## Why it matters

A database server past its end of support gets no more security fixes from
its vendor. Flaws found after that date stay open, and the database holds
every user, password hash and piece of content on the site.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `server_version` | string | The version string the server reports, as given. |
| `series` | string | The product and release series that were judged, such as `MySQL 8.0`. |
| `support_ended` | string | The date support ended, as `YYYY-MM-DD`, or `yes, on a date the vendor does not give`. |
| `patched_by_host` | string | Always the same note: a host or a fork may still patch the series, which the scan cannot see. |

## Example

From the check's tests: a MySQL 8.0.36 server after the end of 8.0 support.

```json
{
	"id": "config.db.version",
	"severity": "warning",
	"title": "MySQL 8.0 likely no longer receives security fixes",
	"evidence": {
		"server_version": "8.0.36",
		"series": "MySQL 8.0",
		"support_ended": "2026-04-21",
		"patched_by_host": "not visible: a host or a fork may still patch this series"
	},
	"fix": "Ask your host to move the database to a supported series, such as MySQL 8.4 or MariaDB 11.8, or upgrade it yourself after a backup. Check that your plugins support the new version first; most work with both."
}
```

## When it skips

- There is no database connection: `wp-config.php` does not give the
  credentials in a form that can be read, or the connection fails.
- The version string has no series that can be read, such as a bare word.
- The series is not in the table: newer than every series in it, or missing
  between them, as MariaDB 11.3 and 12.0 to 12.2 are, for which MariaDB
  publishes no date.

## False positives

- A host or a Linux distribution that keeps backporting security fixes to an
  old series, as some long-term distribution packages do.
- A fork with its own support schedule that reports a MySQL-style version,
  since forks are judged by the MySQL dates.
- A managed database service that reports an old version number but is
  patched by the provider.
