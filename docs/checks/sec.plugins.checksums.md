# `sec.plugins.checksums`

Plugin files that differ from the wordpress.org release of the installed version.

| | |
|---|---|
| Category | security |
| Severity | warning |
| Reads | the site files, wordpress.org (not with --offline) |
| Since | 0.3.0 |

## What it reports

For each folder at the top of the plugins directory, the check takes the
version from the `Version:` header of the first `.php` file in the folder, in
name order, that carries a `Plugin Name:` header. It fetches the checksums
wordpress.org publishes for the folder's name and that version, and compares
every file in the folder, at any depth, as `wp plugin verify-checksums` does:

- a file whose hash is none of those listed has changed. SHA-256 is used
  where wordpress.org publishes it, MD5 otherwise, and any listed hash is
  accepted;
- a file the list does not name was added.

`readme.txt` and `readme.md` are passed over, since sites often edit them.
Files missing from the folder are not reported.

`hello.php` at the top of the directory is Hello Dolly, which ships with
WordPress and which updates leave in place. It is compared with the copy in
the installed WordPress release and in the last release of each of the eight
series before it, and counts as changed only when it matches none of them.

Some plugins cannot be checked and are listed under `not_checked`: other
single-file plugins, folders with no `Version:` header, and versions
wordpress.org does not publish, such as premium and custom plugins. Names
that begin with a dot are passed over, and must-use plugins are not read.

The check sends each plugin folder's name and version to
`downloads.wordpress.org`, one request per plugin. `--offline` turns this off;
`docs/ARCHITECTURE.md` describes the network use.

## Why it matters

A backdoor is often written into a file of a plugin that is already
installed, where it runs on every request and looks like part of the plugin.
Comparing with the published release finds an edited or added file whatever
its contents, without having to recognise the code.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `plugins_checked` | integer | Plugins compared with their release, Hello Dolly included. |
| `plugins_not_checked` | integer | Plugins that could not be compared. |
| `files_changed` | integer | Files whose hash matches none of those published. |
| `files_added` | integer | Files the published list does not name. |
| `scan_incomplete` | boolean | True when a plugin could not be checked, a file could not be read to hash, or part of the directory could not be examined. |
| `not_examined` | string | What was not examined: plugins that could not be checked, files that could not be hashed, symlinks that were not followed, headers that could not be read, or a walk that stopped. Present only when `scan_incomplete` is true. |
| `files` | list of objects | The files reported, by path, at most 20. |
| `files[].path` | string | The file, relative to the site root. |
| `files[].plugin` | string | The plugin folder and the version it was compared with. For `hello.php`, the WordPress releases it was compared with. |
| `files[].problem` | string | `changed` or `added`. |
| `not_checked` | list of objects | The plugins that could not be compared, at most 20. Empty when every plugin was checked. |
| `not_checked[].plugin` | string | The folder or file name in the plugins directory. |
| `not_checked[].reason` | string | Why it could not be compared. |

## Example

From the check's tests: a plugin with one changed file, beside plugins that
could not be checked.

```json
{
	"id": "sec.plugins.checksums",
	"severity": "warning",
	"title": "Plugin files differ from their wordpress.org release",
	"evidence": {
		"plugins_checked": 1,
		"plugins_not_checked": 4,
		"files_changed": 1,
		"files_added": 0,
		"scan_incomplete": true,
		"not_examined": "4 plugin(s) could not be checked (example-down, example-noversion, example-premium, single.php)",
		"files": [
			{
				"path": "wp-content/plugins/example-forms/inc/render.php",
				"plugin": "example-forms 2.1.0",
				"problem": "changed"
			}
		],
		"not_checked": [
			{
				"plugin": "example-down",
				"reason": "the checksums could not be fetched: The request failed: timed out"
			},
			{
				"plugin": "example-noversion",
				"reason": "no plugin file with a Version header"
			}
		]
	},
	"fix": "Reinstall each plugin listed from wordpress.org with `wp plugin install <slug> --version=<version> --force`, after a backup. If a changed or added file is PHP and nobody on your side edited the plugin, treat the site as compromised: change the passwords and the keys and salts, and look for how the file was changed."
}
```

## When it skips

- `--offline` is given, so no checksums can be fetched.
- `WP_PLUGIN_DIR` or `WPMU_PLUGIN_DIR` is set in `wp-config.php`, since only
  the default plugins directory is read.
- The plugins directory is missing or cannot be read.
- No plugins are installed.
- No file differs, but a plugin could not be checked, a file could not be
  read to hash, a symlink was not followed, a header could not be read, or
  the walk stopped at `--max-files`: a clean result would claim more than was
  examined.

## False positives

- A plugin that someone on your side edited, such as a fix applied by hand
  while waiting for a release.
- A plugin that writes files into its own folder as it runs, such as a cache,
  a log, generated CSS or downloaded translations. These show as added.
- A build of the plugin that did not come from wordpress.org but carries the
  same folder name and version, such as a developer copy or a fork.
- A `hello.php` left by a WordPress release more than eight series older than
  the installed one, which none of the releases compared contains.
