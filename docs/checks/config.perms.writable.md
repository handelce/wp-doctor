# `config.perms.writable`

Files and directories under the site root that any account on the server can write.

| | |
|---|---|
| Category | config |
| Severity | warning |
| Reads | the site files |
| Since | 0.1.0 |

## What it reports

The check walks the whole site root and reports every file and directory with
the write bit for others set (`o+w`), such as `0666` or `0777`. Symbolic links
are passed over, since their own permission bits mean nothing. A file that
only the group can write, such as `0664`, is not reported.

## Why it matters

A path anyone can write lets every other account on the server, and any
script exploited on any site there, change the code that WordPress runs or
drop new PHP into a directory the web server serves. It is also a common
leftover of a `chmod 777` used to fix an upload or a cache error.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `world_writable_files` | integer | Files found that anyone can write. |
| `world_writable_directories` | integer | Directories found that anyone can write. |
| `scan_incomplete` | boolean | True when part of the site was not examined, so the counts may be low. |
| `files` | list of objects | The files and directories found, sorted by path, at most 20. |
| `files[].path` | string | The file or directory, relative to the site root. |
| `files[].bytes` | integer | Its size as the file system reports it. For a directory this is the size of the directory entry, not of its contents. |

## Example

From the check's tests: a file with mode `0666` and a cache directory with mode `0777`.

```json
{
	"id": "config.perms.writable",
	"severity": "warning",
	"title": "Files or directories can be written by anyone",
	"evidence": {
		"world_writable_files": 1,
		"world_writable_directories": 1,
		"scan_incomplete": false,
		"files": [
			{
				"path": "config.php",
				"bytes": 1
			},
			{
				"path": "wp-content/cache",
				"bytes": 96
			}
		]
	},
	"fix": "Run `find . -type f -perm -o+w -exec chmod o-w {} +` and the same with `-type d` from the site root, then check the site still works. Files are normally 644 and directories 755."
}
```

## When it skips

- The scan runs on Windows, where permission bits have no meaning.
- Nothing was found, but not every path was examined: the walk stopped at
  `--max-files`, a path could not be read, a symlink was broken or left the
  site root, `--exclude` left nothing to examine, or the root is empty.

## False positives

- A cache or upload directory that a host or plugin sets to `0777` because
  PHP runs as a different user from the file owner. The path is still
  writable by everyone, but changing it may break the plugin until the
  ownership is fixed.
- A copy of the site scanned on a machine where the files were unpacked with
  loose permissions, which says nothing about the live server.
