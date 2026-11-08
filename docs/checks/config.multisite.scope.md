# `config.multisite.scope`

A multisite network, of which only the main site's database tables were scanned.

| | |
|---|---|
| Category | config |
| Severity | info |
| Reads | the site files |
| Since | 0.1.0 |

## What it reports

The check reads `MULTISITE` from `wp-config.php`. When it is a literal true
value, the site is a network, and the finding says so. Each site on a
network keeps its own tables, named with the table prefix and the site number
(`wp_2_options`). The database checks read only the main site's tables, so
their results say nothing about the other sites. `MULTISITE` false or not
defined passes.

The finding is a notice about the scope of the scan, not a problem with the
site.

## Why it matters

Without this notice, a report with no database findings would read as a clean
network. A rogue administrator, a spam option or an injected script on one of
the other sites would not be seen.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `MULTISITE` | string | Always `true`. |
| `SUBDOMAIN_INSTALL` | string | `true` or `false` as defined in `wp-config.php`, or `not read from wp-config.php` when it is not defined there with a literal value. |
| `sites_scanned` | string | Always `main site only`. |
| `main_site_table_prefix` | string | The table prefix of the main site, read from `wp-config.php`. Present only when the prefix could be read. |

## Example

From the check's tests: `MULTISITE` true and `SUBDOMAIN_INSTALL` false in `wp-config.php`.

```json
{
	"id": "config.multisite.scope",
	"severity": "info",
	"title": "This is a multisite network and only its main site was scanned",
	"evidence": {
		"MULTISITE": "true",
		"SUBDOMAIN_INSTALL": "false",
		"sites_scanned": "main site only",
		"main_site_table_prefix": "wptest_"
	},
	"fix": "Treat a clean database result as covering the main site only. The other sites keep their own tables (the prefix, the site number and the table name, such as wp_2_options), which no check reads yet."
}
```

## When it skips

- `MULTISITE` is defined but its value cannot be read: it is computed, such
  as from `getenv()`, or defined conditionally.
- `MULTISITE` is not defined in `wp-config.php`, but the file includes another
  file or calls `eval()`, either of which could define it.

## False positives

The finding reports a plain fact read from `wp-config.php`. A network with
only the main site still produces it.
