# `config.fileedit.enabled`

The dashboard's theme and plugin code editor left available.

| | |
|---|---|
| Category | config |
| Severity | info |
| Reads | the site files |
| Since | 0.1.0 |

## What it reports

WordPress turns off the theme and plugin file editor when `DISALLOW_FILE_EDIT`
is true, and also when `DISALLOW_FILE_MODS` is true. The check reads both from
`wp-config.php` and passes when either is a literal true value. Otherwise,
when both are missing or false, it reports that the editor is likely on.
Either constant could be defined in a plugin or elsewhere outside
`wp-config.php`, so the title says "likely".

## Why it matters

The editor lets any administrator change PHP files from the browser. Anyone
who gets an administrator login, by a stolen password or a hijacked session,
can then put a backdoor into a theme or plugin in a few clicks, without any
access to the server's files.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `DISALLOW_FILE_EDIT` | string | Always `not set to true in wp-config.php`. |

## Example

From the check's tests: a `wp-config.php` that defines neither constant.

```json
{
	"id": "config.fileedit.enabled",
	"severity": "info",
	"title": "The theme and plugin code editor is likely enabled",
	"evidence": {
		"DISALLOW_FILE_EDIT": "not set to true in wp-config.php"
	},
	"fix": "Add this line to wp-config.php above the line that loads wp-settings.php:\ndefine( 'DISALLOW_FILE_EDIT', true );"
}
```

## When it skips

- Neither constant is a literal true, and one of them is defined with a value
  that cannot be read: computed, such as from `getenv()`, or defined
  conditionally.
- Neither constant is a literal true, and one of them is not defined in
  `wp-config.php` while the file includes another file or calls `eval()`,
  which could define it.

## False positives

- A security or hardening plugin that defines `DISALLOW_FILE_EDIT` itself,
  or a must-use plugin that does. The check reads only `wp-config.php`.
- A host that removes the editor in its own way, such as through a platform
  must-use plugin or by taking away the capability from every role.
