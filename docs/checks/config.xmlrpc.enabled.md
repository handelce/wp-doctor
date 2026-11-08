# `config.xmlrpc.enabled`

An `xmlrpc.php` endpoint that no `.htaccess` rule blocks.

| | |
|---|---|
| Category | config |
| Severity | info ("likely") |
| Reads | the site files |
| Since | 0.2.0 |

## What it reports

The check looks for `xmlrpc.php` in the core directory. When it is there, it
reads the `.htaccess` of the site root and, when core is in a subdirectory,
of the core directory, for a rule that denies it. These count as a block:

- a `<Files>` or `<FilesMatch>` block whose name contains `xmlrpc`, holding
  `Require all denied` or `Deny from all`;
- a `RewriteRule` line naming `xmlrpc` with the flag `F`, `G` or
  `R=403`, `404` or `410`;
- a `Redirect` or `RedirectMatch` line naming `xmlrpc` with status `403`,
  `404`, `410` or `gone`.

Commented lines are ignored. With no such rule, the endpoint is reported as
likely reachable. The `xmlrpc_enabled` filter only turns off the methods that
need a login, so it is not looked for. Rules in the web server's own
configuration, as nginx uses, are not visible, which is why the finding says
"likely".

## Why it matters

XML-RPC accepts many login attempts in one request, which makes password
guessing fast and hard to spot, and it sends pingbacks to any address it is
given, which can be used to attack other sites. Most sites no longer need
it.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `endpoint` | string | `xmlrpc.php`, relative to the site root, with the core directory in front when core is in a subdirectory. |
| `htaccess_read` | string | The `.htaccess` files that were read, separated by commas, or `none found`. |
| `blocking_rule` | string | Always `none found in .htaccess`. |
| `server_config` | string | Always the same note: rules in the web server's own configuration are not visible. |

## Example

From the check's tests: an `.htaccess` that holds only the WordPress rewrite rules.

```json
{
	"id": "config.xmlrpc.enabled",
	"severity": "info",
	"title": "The XML-RPC endpoint is likely reachable",
	"evidence": {
		"endpoint": "xmlrpc.php",
		"htaccess_read": ".htaccess",
		"blocking_rule": "none found in .htaccess",
		"server_config": "not visible: a rule in the web server configuration, as nginx uses, would not be seen"
	},
	"fix": "If nothing on the site needs XML-RPC, block it in the web server. On Apache, add to .htaccess: `<Files xmlrpc.php>` `Require all denied` `</Files>`. On nginx: `location = /xmlrpc.php { deny all; }`. Some services connect through it, Jetpack among them, so check those still work afterwards."
}
```

## When it skips

- `xmlrpc.php` could not be examined: it is a broken symlink, leads out of the
  site root, is excluded with `--exclude`, or sits where it cannot be read.
- An `.htaccess` exists but could not be read, so a rule blocking the
  endpoint may be missed.

## False positives

- A site on nginx, or on Apache with the rule in the server or virtual host
  configuration, where the endpoint is blocked but no `.htaccess` says so.
- A host, firewall or CDN that blocks `xmlrpc.php` in front of the site.
- A site that needs XML-RPC, such as one connected to Jetpack or a mobile
  app. The endpoint is reachable, but leaving it open is intended there.
