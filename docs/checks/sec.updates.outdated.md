# `sec.updates.outdated`

Plugins, themes and WordPress core with an update that WordPress knows about and that is not installed.

| | |
|---|---|
| Category | security |
| Severity | warning |
| Reads | the database |
| Since | 0.1.0 |

## What it reports

When WordPress asks wordpress.org for updates, it stores the answers in the
`_site_transient_update_plugins`, `_site_transient_update_themes` and
`_site_transient_update_core` site transients. The check reads them from the
options table on a single site. On a multisite network, which is when
`MULTISITE` is true in `wp-config.php`, it reads them from `sitemeta` for the
network that `SITE_ID_CURRENT_SITE` names, or network 1 when it is not set.
Each value is read when it is 8 MiB or smaller, and is walked as serialized
text without being unserialized.

- A plugin or theme counts when the stored data lists it under `response`,
  with the `new_version` offered.
- A core offer counts when its response is `upgrade` or `autoupdate` and its
  version is newer than the installed one, once per version.

The list is as fresh as WordPress's last update check. The scanner itself
does not contact wordpress.org for this check.

## Why it matters

Attacks on WordPress sites often use a known flaw in a plugin or theme that a
released update already fixes. Once a fix is published, the flaw it closes is
public too, and every day the update waits leaves it open.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `plugin_updates` | integer | Plugins with an update pending. |
| `theme_updates` | integer | Themes with an update pending. |
| `core_updates` | integer | WordPress versions offered that are newer than the installed one. |
| `core_available` | string | Those versions, separated by commas. Present only when `core_updates` is above 0. |
| `no_update_data_for` | string | Which of `plugins`, `themes` and `core` have no stored update data, separated by commas. Present only when one of them has none. |
| `plugins` | list of strings | Each plugin as `folder/file.php -> version`, in name order, at most 20. Only the name appears when the stored version is not text. |
| `themes` | list of strings | Each theme as `folder -> version`, in the same form. |

## Example

From the check's tests: one plugin update pending, with no stored update data
for themes and core.

```json
{
	"id": "sec.updates.outdated",
	"severity": "warning",
	"title": "Updates are available and not yet installed",
	"evidence": {
		"plugin_updates": 1,
		"theme_updates": 0,
		"core_updates": 0,
		"no_update_data_for": "themes, core",
		"plugins": [
			"a/a.php -> 2"
		],
		"themes": []
	},
	"fix": "Back up the site, then update from the Updates screen, or with `wp core update`, `wp plugin update --all` and `wp theme update --all`. Update a copy first if the site is business critical. This list is as fresh as the last time WordPress checked for updates."
}
```

## When it skips

- There is no database connection, or the table prefix could not be read.
- On a multisite network, `SITE_ID_CURRENT_SITE` could not be read or is not
  a positive whole number.
- WordPress holds no update data in the database for any of the three kinds,
  either because none is stored or because it is larger than 8 MiB. An
  external object cache may hold it instead. When `MULTISITE` could not be
  read, the reason says the data may be in `sitemeta`.
- Nothing is pending in the data that exists, but one of the three kinds has
  no stored data.

A stored value that is not a plain serialized structure stops the check with
an error rather than a skip.

## False positives

- An update installed outside WordPress after its last update check, such as
  a deploy, a file upload or a host's own updater, is still listed until
  WordPress checks again. The fix text notes how fresh the list is.
- A newer major WordPress version on a site that stays on an older branch on
  purpose. The offer is real, but the older branch may still receive
  security releases.
