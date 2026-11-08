# `disk.cache.size`

A cache folder in the content directory that has grown above the size limit.

| | |
|---|---|
| Category | disk |
| Severity | warning |
| Reads | the site files |
| Since | 0.2.0 |

## What it reports

The check measures three folders in the content directory, when they exist:
`cache` (WP Super Cache, W3 Total Cache and others), `litespeed` (LiteSpeed
Cache) and `wpo-cache` (WP-Optimize). It adds up the sizes of the regular files
under each one and reports every folder whose total is above the limit, with
the five largest entries at its top.

The limit is 1 GiB (1073741824 bytes) in one folder, a default that has not
been measured against real sites. Change it in `wp-doctor.json`:

```json
{ "thresholds": { "disk.cache.size": { "warning": 2147483648 } } }
```

Symlinks are not followed. A cache folder that is itself a symlink is not
measured, and a symlink inside one that leads outside the site root or nowhere
is left out of the total.

## Why it matters

Caching plugins are meant to prune what they write. When pruning is off or
broken, the cache grows with every page and every variant of it until it fills
the disk, and it makes each backup larger and slower to take.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `warning_above_bytes` | integer | The limit a folder had to exceed, in bytes. |
| `threshold_basis` | string | Whether the limit is the built-in default or was set in a config file, and which one. |
| `caches` | list of objects | The cache folders above the limit. |
| `caches[].path` | string | The folder, relative to the site root. |
| `caches[].bytes` | integer | Bytes in the regular files under it. |
| `caches[].files` | integer | Regular files under it. |
| `caches[].not_counted` | string | What the walk could not see, such as an unreadable folder or symlinks that were not followed, so that `bytes` is a lower bound. Present only when the walk missed something. |
| `largest` | list of objects | The five largest entries at the top of each folder listed, largest first, folder by folder. |
| `largest[].path` | string | The entry, relative to the site root. |
| `largest[].bytes` | integer | Bytes in the regular files under it. |

## Example

From the check's tests: a `cache` folder of 900 bytes, with a limit of 500 set in a config file.

```json
{
	"id": "disk.cache.size",
	"severity": "warning",
	"title": "A cache folder is very large",
	"evidence": {
		"warning_above_bytes": 500,
		"threshold_basis": "set in test.json",
		"caches": [
			{
				"path": "wp-content/cache",
				"bytes": 900,
				"files": 2
			}
		],
		"largest": [
			{
				"path": "wp-content/cache/supercache",
				"bytes": 600
			},
			{
				"path": "wp-content/cache/autoptimize",
				"bytes": 300
			}
		]
	},
	"fix": "Clear the cache from the caching plugin's settings, which rebuilds it as pages are visited, and set a cache lifetime or garbage collection there so it is pruned. If the plugin is no longer installed, delete the folder."
}
```

## When it skips

- The content directory cannot be located, for example when `WP_CONTENT_DIR`
  points outside the site root or cannot be read from `wp-config.php`.
- `wp-config.php` could not be read.
- No folder was above the limit, but one could not be measured in full: it is
  a symlink, not a directory, excluded with `--exclude`, unreadable, or its walk
  missed some entries.

## False positives

The check reports a measured size, not a judgement, so the number is a fact.
A folder named `cache` can hold files a plugin means to keep, such as
minified assets or generated thumbnails, which return after the folder is
cleared.
