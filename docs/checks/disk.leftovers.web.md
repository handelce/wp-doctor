# `disk.leftovers.web`

Database dumps, copies of `wp-config.php`, archives and backup copies of files
left under the site root.

| | |
|---|---|
| Category | disk |
| Severity | critical for dumps and config copies, warning for the rest |
| Reads | the site files |
| Since | 0.1.0 |

## What it reports

The check walks the whole site and sorts what it finds into two findings.

Critical:

- SQL dumps: `.sql`, and `.sql` followed by `.gz`, `.zip`, `.bz2`, `.xz` or
  `.7z`.
- Copies of `wp-config.php` that do not end in `.php`: a name that starts with
  `wp-config` and ends in `.bak`, `.old`, `.orig`, `.save`, `.swp`, `.backup`,
  `.txt`, `.zip`, `.gz`, `.tar` or `~`, or any `wp-config.php.` name.

Warning:

- Backup copies: names ending in `.bak`, `.old`, `.orig`, `.save`, `.swp`,
  `.backup` or `~`.
- Copies of `wp-config.php` that keep `.php`, such as `wp-config-old.php`,
  `wp-config.old.php` or `wp-config-2024-05-01.php`. `wp-config-local.php`
  and `wp-config-sample.php` are not copies.
- Archives (`.zip`, `.tar`, `.tgz`, `.gz`, `.bz2`, `.xz`, `.7z`, `.rar`)
  outside the uploads directory. Precompressed pages and assets that cache
  plugins write, such as `index.html.gz` or `app.js.gz`, are not archives.

Inside the plugins, must-use plugins and themes directories, and any `vendor`
or `node_modules` directory, a plain `.sql` file counts as a dump only when
its first 64 KiB hold `INSERT INTO`, since the schema files that code ships
hold no data. A compressed dump there, such as `.sql.gz`, cannot be read, so
it is reported as an archive. Archives in those directories stay warnings,
and the evidence counts them. Names are compared without regard to case. Each finding lists its
files by path, at most 20.

Whether the web server actually serves these files is not tested, so the
titles say "likely".

## Why it matters

The web server hands out any file under the site root that is not blocked. A
database dump gives away every user, password hash and order. A copy of
`wp-config.php` that no longer ends in `.php` is sent as plain text, with the
database password and the keys and salts in it. Archives and backup copies
often hold the same things, and are a common target of automated scans.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `matching_files` | integer | Files in this finding. |
| `scan_incomplete` | boolean | True when the walk did not see every path, so more files may exist. |
| `archives_in_code_folders` | string | How many of the archives are inside a plugin, must-use plugin, theme, `vendor` or `node_modules` directory, with a note that these often come bundled. Present only on the warning finding, and only when there is at least one such archive. |
| `files` | list of objects | The matching files, by path, at most 20. |
| `files[].path` | string | The file, relative to the site root. |
| `files[].bytes` | integer | Size of the file. |

## Example

From the check's tests: a `.zip` file inside a plugin folder.

```json
{
	"id": "disk.leftovers.web",
	"severity": "warning",
	"title": "Archives and backup copies are likely left in the web root",
	"evidence": {
		"matching_files": 1,
		"scan_incomplete": false,
		"archives_in_code_folders": "1; themes and plugins often bundle archives, so check whether each is a site backup",
		"files": [
			{
				"path": "wp-content/plugins/example/release.zip",
				"bytes": 1
			}
		]
	},
	"fix": "Move the files out of the web root, or delete them if they are no longer needed. An archive inside a plugin or theme folder may be one the plugin or theme bundles; leave those, and move only the site backups."
}
```

## When it skips

- `wp-config.php` could not be read.
- The uploads directory cannot be located, for example when `UPLOADS` or the
  `upload_path` option points outside the site root, or `UPLOADS` is computed.
- Nothing was found, but the walk did not see every path: it stopped at
  `--max-files`, met an unreadable path or a symlink leading outside the site
  root, or every entry was excluded. As for `disk.errorlog.size`, the symlink
  is named in the reason, and excluding it with `--exclude` lets a clean site
  pass.

## False positives

- An archive that a plugin or theme ships, such as an add-on or demo content
  bundle. The `archives_in_code_folders` count and the fix text cover this.
- A file the web server is configured to refuse, for example with a deny rule
  for `.sql` or `.bak`. It is still a copy of the data on the server, but not
  downloadable.
- A `.old` or `~` file that is part of a plugin or theme as shipped, such as an
  editor backup left in its release.
