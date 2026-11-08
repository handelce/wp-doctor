# `sec.recent.changes`

PHP files written in the last few days apart from the rest of their plugin, theme or WordPress release.

| | |
|---|---|
| Category | security |
| Severity | info ("likely") |
| Reads | the site files |
| Since | 0.2.0 |

## What it reports

An install or update writes every file of a package within seconds. The
check walks the whole site and groups every file, of any type, into
packages:

- WordPress core: the files at the top of the core directory, and those in
  `wp-admin` and `wp-includes`;
- each entry at the top of the plugins, `mu-plugins` and themes directories;
- for any other file, its own directory.

A PHP file is reported when it was written within the last 7 days and fewer
than half of the other files in its package were written within an hour of
it. A PHP file that is alone in its package is reported whenever it is
recent. PHP means `.php`, `.php3`, `.php4`, `.php5`, `.php7`, `.php8`,
`.phtml` and `.pht`.

The time judged is the inode change time, which `touch` cannot set back. The
modification time, which an archive or a copy keeps, is shown beside it. The
`cache` folder of the content directory and the empty `Silence is golden`
index.php are passed over.

The number of days is the check's `info` threshold, 7 by default. Change it
in a `wp-doctor.json` passed with `--config`, for example
`{"thresholds": {"sec.recent.changes": {"info": 14}}}`.

## Why it matters

A compromise usually adds or changes PHP. A file written on its own a few
days ago, in a plugin whose other files were all written months earlier, is
what an edit through a stolen login or a dropped backdoor looks like. The
check finds such a file without having to recognise its code.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `php_files_examined` | integer | PHP files judged, outside the cache folder and apart from the empty index.php stubs. |
| `recent_days` | integer | How many days back a change counts as recent. |
| `threshold_basis` | string | Whether `recent_days` is the built-in default or was set in a config file, which it names. |
| `files_flagged` | integer | PHP files reported. |
| `scan_incomplete` | boolean | True when the walk of the site did not see every file. |
| `not_examined` | string | What the walk could not see, such as unreadable directories, symlinks that were not followed, or the `--max-files` limit. Present only when `scan_incomplete` is true. |
| `files` | list of objects | The files reported, newest first, at most 20. |
| `files[].path` | string | The file, relative to the site root. |
| `files[].written` | string | When the file was last written, from its inode change time, in UTC. |
| `files[].modified` | string | Its modification time, in UTC, which an archive or a copy can keep. |
| `files[].package` | string | The package it was compared with, such as `plugin akismet`, `theme <name>`, `must-use plugin <name>`, `WordPress core`, `directory <path>` or `the site root`. |
| `files[].package_files` | integer | Files in the package, this one included. |
| `files[].written_with` | integer | Other files of the package written within an hour of it. |

## Example

From a scan of a copy of 20 plugins downloaded from wordpress.org, in which a
harmless file was planted in one plugin and files in others were rewritten
one by one.

```json
{
	"id": "sec.recent.changes",
	"severity": "info",
	"title": "PHP files were likely changed outside an install or update",
	"evidence": {
		"php_files_examined": 23997,
		"recent_days": 7,
		"threshold_basis": "default rule of thumb, not measured for this site",
		"files_flagged": 4,
		"scan_incomplete": false,
		"files": [
			{
				"path": "wp-content/plugins/wordfence/lib/zz-planted.php",
				"written": "2026-11-08 14:54 UTC",
				"modified": "2026-11-08 14:54 UTC",
				"package": "plugin wordfence",
				"package_files": 632,
				"written_with": 0
			},
			{
				"path": "wp-content/plugins/akismet/akismet.php",
				"written": "2026-11-08 11:55 UTC",
				"modified": "2026-11-08 10:23 UTC",
				"package": "plugin akismet",
				"package_files": 47,
				"written_with": 0
			}
		]
	},
	"fix": "For each file listed, ask whether you or someone you work with changed it at that time. If not, compare it with a clean copy of its plugin, theme or WordPress release; for core, `wp core verify-checksums` does this. An unexplained change means the site may be compromised: replace the file, change the passwords and the keys and salts, and look for how it was changed."
}
```

## When it skips

- `WP_PLUGIN_DIR` or `WPMU_PLUGIN_DIR` is set in `wp-config.php`, since the
  packages depend on the default plugins directory.
- The scan runs on Windows, where PHP reports when a file was created, not
  when it was last written.
- The site root could not be walked.
- No PHP files were found outside the cache folder.
- Every PHP file was written within the same hour, as after a fresh install
  or a copy of the site, so no change can stand out.
- No PHP file was written apart from its package, but the walk was
  incomplete.

## False positives

- An edit you or your developer made, such as a change to a theme's
  `functions.php` or a fix applied by hand.
- A plugin that writes PHP files as it runs outside `wp-content/cache`, such
  as a cache or a settings file in its own folder or in uploads.
- `chmod` or `chown` on part of the site, or restoring a few files from a
  backup: both set the change time without changing the content. The
  `modified` time is then often older than `written`.
- A sync or deploy tool that rewrites only the files that differ.
