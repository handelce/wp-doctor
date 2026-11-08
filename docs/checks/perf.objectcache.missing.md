# `perf.objectcache.missing`

No persistent object cache drop-in in the content directory.

| | |
|---|---|
| Category | performance |
| Severity | warning |
| Reads | the site files |
| Since | 0.1.0 |

## What it reports

The check looks for `object-cache.php` in the content directory: `wp-content`,
or the directory that `WP_CONTENT_DIR` names in `wp-config.php`. This is the
drop-in WordPress loads in place of its own object cache, and the one that
Redis, Memcached and similar cache plugins install. A file there passes, and
so does a symlink that stays inside the site root.

The check reports when there is no file of that name, when a directory takes
the name, and when it is a broken symlink, which WordPress cannot load. The
drop-in's contents are not read, so a pass does not say that the cache it
connects to is working.

## Why it matters

Without a persistent object cache, WordPress keeps cached objects only for the
length of one request. Every page view then runs the same database queries for
options, posts and metadata again, and on a busy site that load falls on the
database server.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `expected_file` | string | Where the drop-in was looked for, relative to the site root. |
| `found` | boolean or string | `false` when there is no file, or a directory has the name. `broken symlink` when a symlink is there but leads nowhere. |

## Example

From a scan of the `computed` fixture site, whose `wp-config.php` sets
`WP_CONTENT_DIR` to `dirname( __FILE__ ) . '/content'` and which has no
drop-in there.

```json
{
	"id": "perf.objectcache.missing",
	"severity": "warning",
	"title": "No persistent object cache is installed",
	"evidence": {
		"expected_file": "content/object-cache.php",
		"found": false
	},
	"fix": "If the host offers Redis or Memcached, install an object cache plugin that provides wp-content/object-cache.php. Small sites with little traffic may not need one."
}
```

## When it skips

- The content directory cannot be located: `WP_CONTENT_DIR` could not be read,
  or does not resolve to a directory inside the site root.
- `object-cache.php` is a symlink that leaves the site root, is excluded from
  the scan, or cannot be read, so it is not known whether a drop-in is there.

## False positives

The finding is a plain fact about one file. Whether it matters depends on the
site:

- A small site with little traffic gains little from an object cache, as the
  fix text says.
- A site served almost entirely from a page cache runs few uncached requests
  through PHP, so the repeated queries cost less.
