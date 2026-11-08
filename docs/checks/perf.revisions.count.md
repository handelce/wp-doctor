# `perf.revisions.count`

The posts table holds more post revisions than the limit.

| | |
|---|---|
| Category | performance |
| Severity | warning |
| Reads | the database |
| Since | 0.2.0 |

## What it reports

The check counts the rows of type `revision` in the posts table, and the posts
they belong to. More than 10000 revision rows is a warning. The limit is set
in `wp-doctor.json`:

```json
{ "thresholds": { "perf.revisions.count": { "warning": 20000 } } }
```

The finding lists the five posts with the most revisions, and says what
`WP_POST_REVISIONS` in `wp-config.php` tells WordPress to keep, read as
`wp_revisions_to_keep()` reads it: `true` or a negative number keeps every
revision, `false` or `0` keeps none, and a positive number keeps that many per
post.

## Why it matters

Each revision is a full copy of a post, stored as another row in the posts
table. A site that keeps every revision makes that table, its indexes and its
backups larger, and its queries slower. Setting `WP_POST_REVISIONS` limits
revisions as posts are saved again, but the ones already stored stay until
they are removed.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `revisions` | integer | Rows of type `revision` in the posts table. |
| `posts_with_revisions` | integer | Posts those revisions belong to. |
| `warning_above_rows` | integer | The limit the count was compared with. |
| `threshold_basis` | string | Where the limit came from: the built-in default, or the configuration file that set it. |
| `WP_POST_REVISIONS` | string | What the constant tells WordPress to keep, such as `10 per post` or `not set, so every revision is kept`. It starts with `not read:` when the value is computed and could not be read, and says so when the constant is not set but `wp-config.php` loads code that was not read. |
| `posts` | list of objects | The posts with the most revisions, most first, at most five. |
| `posts[].id` | integer | The post's ID, which is its revisions' `post_parent`. |
| `posts[].revisions` | integer | Revisions of that post. |

## Example

From the integration tests: six revisions of two posts, with the limit set to
5 in a configuration file named `test.json`.

```json
{
	"id": "perf.revisions.count",
	"severity": "warning",
	"title": "The posts table holds many post revisions",
	"evidence": {
		"revisions": 6,
		"posts_with_revisions": 2,
		"warning_above_rows": 5,
		"threshold_basis": "set in test.json",
		"WP_POST_REVISIONS": "not set, so every revision is kept",
		"posts": [
			{
				"id": 2,
				"revisions": 4
			},
			{
				"id": 1,
				"revisions": 2
			}
		]
	},
	"fix": "Limit how many revisions WordPress keeps with `define( 'WP_POST_REVISIONS', 10 );` in wp-config.php. The limit applies as posts are saved again, so remove the old ones once: take a database backup, then run `wp post list --post_type=revision --format=ids | xargs wp post delete --force`."
}
```

## When it skips

- There is no database access, or the table prefix could not be read from
  `wp-config.php`.

## False positives

The count is a plain fact, and the limit is a rule of thumb. On a site with
tens of thousands of posts, 10000 revisions is only a few for each, and a site
that needs its editing history keeps them for a reason. Raise the limit in
`wp-doctor.json` for such a site.
