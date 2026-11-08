# `config.debug.enabled`

`WP_DEBUG` switched on in `wp-config.php`.

| | |
|---|---|
| Category | config |
| Severity | warning |
| Reads | the site files |
| Since | 0.1.0 |

## What it reports

The check reads `WP_DEBUG` from `wp-config.php` without running it. A literal
value that PHP treats as true is reported; false, or no definition at all,
passes, since WordPress leaves debugging off by default.

WordPress reads `WP_DEBUG_DISPLAY` and `WP_DEBUG_LOG` only while `WP_DEBUG` is
on, so those two never decide the result. They are given as evidence of what
debugging does on this site. `WP_DEBUG_DISPLAY` is on when it is not defined,
as in WordPress. Whether the site is live cannot be told from its files, so
the title says "likely".

## Why it matters

With debugging on, PHP notices and errors are printed into pages unless
`WP_DEBUG_DISPLAY` is off. They show visitors file paths, plugin names and
sometimes query details that help an attacker. With `WP_DEBUG_LOG` on, the
log in `wp-content` grows with every request and can fill the disk, and in its
default place it can often be downloaded.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `WP_DEBUG` | string | Always `on`. |
| `WP_DEBUG_DISPLAY` | string | `on`, `off`, `on, by default when WP_DEBUG is on` when it is not defined, or `not read` when its value could not be read. |
| `WP_DEBUG_LOG` | string | `on`, `off`, or `not read` when its value could not be read. A log file path counts as on. |

## Example

From the check's tests: `WP_DEBUG` on, with `WP_DEBUG_DISPLAY` false and `WP_DEBUG_LOG` set to a log file path.

```json
{
	"id": "config.debug.enabled",
	"severity": "warning",
	"title": "Debugging is likely switched on for a live site",
	"evidence": {
		"WP_DEBUG": "on",
		"WP_DEBUG_DISPLAY": "off",
		"WP_DEBUG_LOG": "on"
	},
	"fix": "On a live site set WP_DEBUG to false in wp-config.php; WP_DEBUG_DISPLAY and WP_DEBUG_LOG only apply while it is on.\nKeep debugging on only in a development copy."
}
```

## When it skips

- `WP_DEBUG` is defined but its value cannot be read: it is computed, such as
  from `getenv()`, or defined conditionally.
- `WP_DEBUG` is not defined in `wp-config.php`, but the file includes another
  file or calls `eval()`, either of which could define it.

## False positives

- A development or staging copy, where debugging is expected. The finding
  cannot tell such a copy from the live site.
- A live site where `WP_DEBUG_DISPLAY` is off and the log is kept outside the
  web root, which is a sound way to collect errors. The evidence shows
  this case, and the finding stays a warning.
- A `wp-config.php` that switches `WP_DEBUG` back off in code the parser does
  not follow, such as a later `if` on the host name.
