# `sec.uploads.php`

PHP files in the uploads directory, and server settings there that make files run as PHP.

| | |
|---|---|
| Category | security |
| Severity | critical; warning |
| Reads | the site files, the database |
| Since | 0.1.0 |

## What it reports

Every file under the uploads directory is examined. The check raises up to
three findings:

- **PHP files**, critical: a file with the extension `.php`, `.php3` to
  `.php8`, `.phtml`, `.pht`, `.phps` or `.phar`, in any case. A file of 512
  bytes or less that holds only a PHP open tag, with or without a
  `Silence is golden` comment, is the stub that WordPress and plugins write
  to stop directory listings: it is counted, not reported.
- **Server configuration that runs PHP**, critical ("likely"): an
  `.htaccess`, `.user.ini` or `php.ini` whose first 64 KiB hold a line that
  makes files run as PHP. In an `.htaccess` that is `AddType`, `AddHandler`,
  `SetHandler`, `ForceType` or `Action` with a handler or type that names
  PHP, `x-httpd`, `lsapi`, `fcgid`, `cgi-script` or `proxy:fcgi`, or
  `php_flag engine on`. In any of the three, it is `auto_prepend_file` or
  `auto_append_file` set to a file. Comment lines are passed over, and at
  most five lines are listed from each file.
- **PHP settings files**, warning ("likely"): a `.user.ini` or `php.ini` with
  no such line.

An `.htaccess` that only stops PHP running, such as `php_flag engine off`,
`SetHandler none` or `AddType text/plain .php`, as security plugins write, is
not reported. File names are compared in any case. Symlinks inside uploads
are not followed and are listed.

The uploads directory is `UPLOADS` from `wp-config.php` when it is set,
else the `upload_path` option, else `uploads` in the content directory.

## Why it matters

Uploads hold media that editors and, through forms, visitors send to the
site. A PHP file there is the usual foothold after a vulnerable upload form
or plugin is exploited: the web server runs it when someone requests its URL,
and it gives its author control of the site. A server setting there that maps
images to PHP or prepends a file to every request does the same without any
`.php` name.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `php_files` | integer | PHP files found, stubs not counted. On the PHP files finding only. |
| `empty_index_stubs_ignored` | integer | Empty stub files passed over. On the PHP files finding only. |
| `config_files` | integer | Server configuration files with lines that run PHP. On the server configuration finding only. |
| `settings_files` | integer | `.user.ini` and `php.ini` files without such lines. On the PHP settings finding only. |
| `scan_incomplete` | boolean | True when the walk of the uploads directory did not see every file. On every finding. |
| `symlinks_not_followed` | string | Symlinks inside uploads that were not followed, separated by commas, at most 20. On every finding, present only when there are some. |
| `uploads_location_unconfirmed` | string | Why the directory examined may not be the one WordPress uses: the `upload_path` option could not be read. On every finding, present only then. |
| `files` | list of objects | The PHP files, or on the PHP settings finding the settings files, in path order, at most 20. |
| `files[].path` | string | The file, relative to the site root. |
| `files[].bytes` | integer | Its size in bytes. |
| `directives` | list of objects | One item for each line found, at most five from each of at most 20 files. On the server configuration finding only. |
| `directives[].path` | string | The file the line is in, relative to the site root. |
| `directives[].directive` | string | The line, trimmed. |

## Example

From a scan of the test suite's `demo` fixture site, run without a database:
a PHP file in a dated uploads folder.

```json
{
	"id": "sec.uploads.php",
	"severity": "critical",
	"title": "PHP files in the uploads directory",
	"evidence": {
		"php_files": 1,
		"empty_index_stubs_ignored": 0,
		"scan_incomplete": false,
		"uploads_location_unconfirmed": "the upload_path option, which can move the uploads directory, could not be read: database connection failed: no database in this test",
		"files": [
			{
				"path": "wp-content/uploads/2026/11/cache.php",
				"bytes": 56
			}
		]
	},
	"fix": "Uploads should hold media only. Open each file; if you did not put it there, treat the site as compromised: remove the file, work out how it arrived from the access logs, change the passwords and the keys and salts, and stop the web server from running PHP in the uploads directory."
}
```

## When it skips

- The uploads directory cannot be located: `UPLOADS` or `WP_CONTENT_DIR`
  could not be read from `wp-config.php`, or `UPLOADS`, `WP_CONTENT_DIR` or
  the `upload_path` option points outside the site root or climbs out of it
  with `..`.
- The uploads directory is missing or cannot be read.
- Nothing was found, but not all of uploads was examined: a directory could
  not be read, or the walk stopped at `--max-files`.
- Nothing was found, but symlinks inside uploads were not followed. Each is
  named.
- Uploads holds no files.
- Nothing was found, but the `upload_path` option could not be read, for
  example without a database, so the directory examined may not be the one
  WordPress uses.

## False positives

- A plugin that keeps PHP of its own in a folder under uploads, such as a
  generated cache or a loader for protected downloads.
- An `.htaccess` copied into uploads from the site root that carries the
  host's `AddHandler` line for choosing the PHP version.
- A host or a firewall plugin that writes a `.user.ini` into many
  directories, uploads among them. One that loads the firewall with
  `auto_prepend_file` is reported as critical.
