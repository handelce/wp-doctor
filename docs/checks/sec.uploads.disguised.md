# `sec.uploads.disguised`

Files in the uploads directory with an image extension that likely hold PHP code.

| | |
|---|---|
| Category | security |
| Severity | critical ("likely") |
| Reads | the site files, the database |
| Since | 0.1.0 |

## What it reports

Every file under the uploads directory with the extension `jpg`, `jpeg`,
`png`, `gif`, `webp`, `ico`, `bmp`, `tif`, `tiff` or `avif` is examined for
two signs:

- its first 1024 bytes contain `<?php` or `<?=`, in any case;
- its name has a PHP extension in front of the image extension: `.php`, or
  `.php` followed by digits, `.phtml`, `.pht`, `.phar` or `.phps`, which may
  be followed by dots, underscores or hyphens, as in `x.php.jpg` or
  `x.php_.jpg`.

A file showing both signs lists both. The content test is a heuristic: it
reads the first kilobyte only. Symlinks inside uploads are not followed and
are listed.

The uploads directory is `UPLOADS` from `wp-config.php` when it is set,
else the `upload_path` option, else `uploads` in the content directory.

## Why it matters

An image name gets a file past an upload form that checks only the
extension. Once it is on the server, a server rule that maps the image type
to PHP, a name the server reads as PHP, or an include from other code runs
it. A PHP payload hidden in an image is a common way to put code on a site
and keep it there, since a quick look at uploads sees only pictures.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `image_files_examined` | integer | Files with an image extension that were examined. |
| `suspicious_files` | integer | Files reported. |
| `scan_incomplete` | boolean | True when the walk of the uploads directory did not see every file. |
| `symlinks_not_followed` | string | Symlinks inside uploads that were not followed, separated by commas, at most 20. Present only when there are some. |
| `uploads_location_unconfirmed` | string | Why the directory examined may not be the one WordPress uses: the `upload_path` option could not be read. Present only then. |
| `files` | list of objects | The files reported, in path order, at most 20. |
| `files[].path` | string | The file, relative to the site root. |
| `files[].bytes` | integer | Its size in bytes. |
| `files[].why` | string | The signs found, separated by semicolons. |

## Example

From the check's tests: a `.jpg` in the uploads directory that begins with a
PHP open tag.

```json
{
	"id": "sec.uploads.disguised",
	"severity": "critical",
	"title": "Files that look like images likely contain PHP code",
	"evidence": {
		"image_files_examined": 1,
		"suspicious_files": 1,
		"scan_incomplete": false,
		"files": [
			{
				"path": "wp-content/uploads/photo.jpg",
				"bytes": 26,
				"why": "a PHP open tag appears in the first 1024 bytes"
			}
		]
	},
	"fix": "Open each file in a text editor. A real image never holds PHP; if you did not put the file there, treat the site as compromised: remove it, work out how it arrived from the access logs, and change the passwords and the keys and salts."
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
- Uploads holds no image files.
- Nothing was found, but the `upload_path` option could not be read, for
  example without a database, so the directory examined may not be the one
  WordPress uses.

## False positives

- An image whose binary data or embedded text, such as a comment or a
  metadata field, contains `<?=` or `<?php` in its first kilobyte. The XMP
  packet header `<?xpacket` does not match.
- A file its owner named with `.php` before the extension, such as a
  screenshot of a page saved as `contact.php.png`.
