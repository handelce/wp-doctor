# `disk.uploads.size`

The total size and file count of the uploads directory, with its largest
folders.

| | |
|---|---|
| Category | disk |
| Severity | info |
| Reads | the site files |
| Since | 0.2.0 |

## What it reports

The check adds up the regular files under the uploads directory and lists the
10 largest entries at its top, largest first. These are usually the year
folders WordPress files media under, and folders that plugins write there. It
is a notice on every site where the directory can be read, with no limit.

The directory is the one WordPress uses: `UPLOADS` from `wp-config.php` when it
is set, else the `upload_path` option from the database, else `uploads` in the
content directory. When the option cannot be read, the default is measured and
the evidence says the location is unconfirmed.

Symlinks are not followed. One that leads outside the site root or nowhere is
named in the evidence, and the total is marked incomplete.

## Why it matters

Uploads are usually the largest part of a site on disk and in its backups. The
listing shows where the space goes before anything is deleted.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `scan_incomplete` | boolean | True when the walk missed something, so the totals are lower bounds. |
| `directory` | string | The uploads directory, relative to the site root. |
| `bytes` | integer | Bytes in the regular files under it. |
| `files` | integer | Regular files under it. |
| `not_counted` | string | What the walk could not see, such as unreadable folders or symlinks that were not followed. Present only when `scan_incomplete` is true. |
| `largest` | list of objects | The 10 largest entries at the top of the directory, largest first. |
| `largest[].path` | string | The entry, relative to the site root. |
| `largest[].bytes` | integer | Bytes in the regular files under it. |
| `location_unconfirmed` | string | Why the directory may not be the one WordPress uses: the `upload_path` option, which can move it, could not be read or was too long to use. Present only when `UPLOADS` is not set and the option was not read. |

## Example

From a scan of a test fixture site: one small upload, scanned without a database.

```json
{
	"id": "disk.uploads.size",
	"severity": "info",
	"title": "Size of the uploads directory",
	"evidence": {
		"scan_incomplete": false,
		"directory": "wp-content/uploads",
		"bytes": 6,
		"files": 1,
		"largest": [
			{
				"path": "wp-content/uploads/a.jpg",
				"bytes": 6
			}
		],
		"location_unconfirmed": "the upload_path option, which can move the uploads directory, could not be read: database connection failed: no database in this test"
	},
	"fix": "Look at the largest folders first when the disk or the backups are too large. Media that no post uses can be found with a media cleanup plugin; folders that plugins left behind can be removed after a backup."
}
```

## When it skips

- `wp-config.php` could not be read.
- The uploads directory cannot be located, for example when `UPLOADS` or the
  `upload_path` option points outside the site root, or `UPLOADS` is computed.
- The directory is missing, cannot be read, or is excluded with `--exclude`.

## False positives

The check reports measured sizes, so the finding is a fact.
