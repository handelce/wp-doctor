# `config.php.version`

A PHP version past the end of its security support, and plugins that need a newer PHP than the site selects.

| | |
|---|---|
| Category | config |
| Severity | warning; info for plugin mismatch |
| Reads | the site files, runtime |
| Since | 0.1.0 |

## What it reports

The check needs to know which PHP the web server runs, which a command-line
scan cannot see directly. It looks first in `.htaccess` in the site root for
an `AddHandler`, `SetHandler` or `AddType` line whose handler names a minor
version, such as `application/x-httpd-ea-php74`, `php8.1` or `lsphp81`. A
single digit, such as `php8`, names no minor version and is not used. Such a
line likely selects the web server's PHP. Without one, the check judges the
PHP that runs wp-doctor and says so in the evidence.

The version is compared with a table of end-of-security-support dates built
into wp-doctor. A version past its date, or older than PHP 5.6, is a warning.

When the version came from `.htaccess`, the check also reads the plugins
directory. For a single-file plugin it reads the `Requires PHP:` header of the
file; for a plugin folder, the header of the first PHP file at its top that
has a `Plugin Name:` header. Plugins that require a newer minor version than
the hinted one are an info finding, which can appear beside the warning.

## Why it matters

A PHP version past its end of security support gets no fixes for flaws found
after that date, and every request to the site runs through it. A plugin that
needs a newer PHP than the server runs may fail with a fatal error, or the
`.htaccess` line may be stale and point at a version the host no longer
uses.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `php_minor` | string | The major and minor version judged, such as `7.4`. Present only in the warning. |
| `security_support_ended` | string | The date security support ended, as `YYYY-MM-DD`, or `before 2019` for versions older than 5.6. Present only in the warning. |
| `measured_on` | string | Which PHP the version belongs to: the `.htaccess` handler line, or the PHP that runs wp-doctor. Present only in the warning. |
| `table_source` | string | Says the dates are built into wp-doctor and where to check them. Present only in the warning. |
| `php_version` | string | The full version of the PHP that runs wp-doctor. Present only in the warning when there is no `.htaccess` hint. |
| `htaccess_line` | string | The `.htaccess` line the version came from. Present in both findings when there is a hint. |
| `htaccess_php` | string | The minor version the `.htaccess` line selects. Present only in the plugin finding. |
| `plugins_requiring_newer_php` | integer | Plugins whose `Requires PHP` is above that version. Present only in the plugin finding. |
| `plugins_not_all_read` | string | Why some plugins could not be read, such as a folder that could not be listed. Present only in the plugin finding, and only when that happened. |
| `plugins` | list of objects | The plugins that need a newer PHP, sorted by name, at most 20. Present only in the plugin finding. |
| `plugins[].plugin` | string | The plugin's folder or file name in the plugins directory. |
| `plugins[].requires_php` | string | The version its `Requires PHP` header gives. |

## Example

From the check's tests: an `.htaccess` that selects PHP 7.4 and a plugin that requires 8.0.

```json
{
	"id": "config.php.version",
	"severity": "info",
	"title": "Plugins likely require a newer PHP than the .htaccess handler selects",
	"evidence": {
		"htaccess_php": "7.4",
		"htaccess_line": "AddHandler application/x-httpd-ea-php74 .php",
		"plugins_requiring_newer_php": 1,
		"plugins": [
			{
				"plugin": "needs-new",
				"requires_php": "8.0"
			}
		]
	},
	"fix": "Either the handler line is stale or these plugins cannot run. Check the PHP version in the hosting panel, and raise it or remove the plugins that need more."
}
```

## When it skips

- `.htaccess` names more than one PHP version in handler lines, so the web
  server's PHP cannot be told.
- The version is newer than the table in this version of wp-doctor.
- There is no `.htaccess` hint and the PHP that runs wp-doctor is in security
  support: that says nothing about the web server's PHP, so the result is not
  a pass.
- There is no `.htaccess` hint and the running PHP's version string cannot be
  read.
- The hinted version is in support and no plugin needs more, but plugin
  requirements were not all read: `WP_PLUGIN_DIR` or `WPMU_PLUGIN_DIR` is set,
  or a plugin folder or the plugins directory could not be read in full.

## False positives

- A host that ignores or overrides the `.htaccess` handler line, such as one
  that sets the PHP version in its panel and leaves an old line behind.
- Without a hint, a server whose web PHP is newer than the PHP that runs
  wp-doctor. The evidence names the PHP that was measured.
- A distribution or host that backports security fixes to an old PHP.
- A `Requires PHP` header that overstates what the plugin needs, or a
  single PHP file in the plugins directory with the header that is not an
  active plugin.
