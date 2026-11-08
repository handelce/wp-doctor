# `sec.content.injected`

Published posts that likely hold injected scripts, and recently changed posts that likely hold defacement text.

| | |
|---|---|
| Category | security |
| Severity | warning ("likely"); info |
| Reads | the database |
| Since | 0.3.0 |

## What it reports

The check gives up to two findings.

**Scripts** (warning). It reads the published posts of every type apart from
revisions, attachments, menu items, customizer changesets, oEmbed caches and
user requests whose content holds `<script`: at most 1000 posts, in ID order,
and the first 1 MiB of each. A script is reported when:

- its `src` names a host other than the site's, or a subdomain of it,
  protocol-relative URLs included;
- it has no `src` and calls `eval()`, `atob()`, `String.fromCharCode()`,
  `document.write()` or `unescape()`, or holds a run of 200 or more base64
  characters or 50 `\x` escapes.

The site's hosts are those of `WP_HOME` and `WP_SITEURL` in `wp-config.php`
and of the `home` and `siteurl` options, in lower case and without a leading
`www.`. A `src` with no host, such as `/wp-includes/js/x.js`, is the site's
own. No list of trusted script hosts is kept.

**Defacement** (info). It reads the published posts of the same types modified
in the last 30 days, by `post_modified_gmt`, whose title or content holds
"hacked by", "h4cked by", "h4ck3d by", "defaced by" or "greetz" in any case.
At most 20 are listed, the most recently modified first.

## Why it matters

WordPress removes script tags from posts written by anyone without the
`unfiltered_html` capability, and embeds are stored as URLs, so a script in a
published post is unusual. Injected spam and redirect code usually arrives as
a script from another host, or an inline one that hides what it does. Every
visitor's browser runs it, and search engines read what it adds. The
defacement phrases are the ones defacers sign their work with.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `posts_with_scripts` | integer | Script finding. Published posts holding a script tag that were read. |
| `posts_flagged` | integer | Both findings. Posts reported. |
| `site_hosts` | string | Script finding. The site's hosts the scripts were judged against, separated by commas. |
| `scan_incomplete` | boolean | Script finding. True when not every post with a script tag was read in full. |
| `not_examined` | string | Script finding. What was not read: posts beyond the first 1000 with a script tag, or posts longer than 1 MiB that were read only in part. Present only when `scan_incomplete` is true. |
| `recent_days` | integer | Defacement finding. How many days back modified posts were searched. |
| `phrases` | string | Defacement finding. The phrases searched for, separated by commas. |
| `posts` | list of objects | Both findings. The posts reported, at most 20: in ID order for scripts, most recently modified first for defacement. |
| `posts[].id` | integer | The post's ID. |
| `posts[].type` | string | Its post type, such as `post` or `page`. |
| `posts[].title` | string | Its title. |
| `posts[].scripts` | string | Script finding. What was found, such as `script from cdn.bad.invalid` or `inline script using eval()`, separated by semicolons. |
| `posts[].modified` | string | Defacement finding. When the post was last modified, in UTC. |

## Example

From the integration tests: a published post that loads a script from another
host.

```json
{
	"id": "sec.content.injected",
	"severity": "warning",
	"title": "Published posts likely hold injected scripts",
	"evidence": {
		"posts_with_scripts": 2,
		"posts_flagged": 1,
		"site_hosts": "example.test",
		"scan_incomplete": false,
		"posts": [
			{
				"id": 2,
				"type": "post",
				"title": "Foreign script",
				"scripts": "script from cdn.bad.invalid"
			}
		]
	},
	"fix": "Open each post in the block editor's code view and remove scripts nobody on your side added. Then look for the same host elsewhere with `wp db search <host> --all-tables`. Injected posts usually mean an account or a plugin was compromised: change the passwords, review the administrators, and update or remove vulnerable plugins."
}
```

## When it skips

- There is no database access, or the table prefix could not be read from
  `wp-config.php`.
- No site address could be read from `WP_HOME`, `WP_SITEURL` or the `home` and
  `siteurl` options, so no script host can be judged.
- Nothing was found, but more than 1000 posts hold a script tag, or a post
  longer than 1 MiB was read only in part.

## False positives

- An embed that an administrator added knowingly, such as an analytics tag,
  a form, a map, a booking widget or an advert from another host. Since no
  list of trusted hosts is kept, every one is reported.
- A site that also answers on a domain that is not in `WP_HOME`, `WP_SITEURL`,
  `home` or `siteurl`, such as a separate static domain: its scripts count as
  another host.
- Older advert and widget code that uses `document.write()` inline.
- A post about security that quotes a defacement phrase, or uses "greetz" in
  passing.
