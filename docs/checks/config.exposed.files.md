# `config.exposed.files`

Files at the top of the web root that give away secrets, server details or the WordPress version.

| | |
|---|---|
| Category | config |
| Severity | warning; info for readme.html |
| Reads | the site files |
| Since | 0.1.0 |

## What it reports

The check lists the top level of the site root, and of the core directory
when core is in a subdirectory. It does not look further down. Names are
compared without regard to case. It reports:

- `.git`, `.svn` and `.hg`: version control data;
- `.env` and any name beginning with `.env.`: environment files;
- a `.php` file whose first 64 KiB contain a call to `phpinfo()`, apart from
  the PHP files WordPress core puts in the root, such as `wp-login.php`, and
  `wp-config.php`;
- `readme.html`, the WordPress readme.

The first three are one warning, titled "likely" reachable, since the scan
does not test what the web server serves. `readme.html` is a separate info
finding, because it only shows the version. A symlink that leads out of the
root is judged by its name and not followed. Copies of `wp-config.php` are
reported by `disk.leftovers.web`, not here.

## Why it matters

A `.git` directory served over the web lets anyone download the site's source
and its history, often with passwords that were committed once. A `.env` file
usually holds database passwords and API keys in plain text. A phpinfo page
shows the PHP version, loaded modules, paths and environment variables. The
readme tells an attacker which WordPress version to look up vulnerabilities
for.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `paths` | list of objects | The files and directories reported, sorted by path. The warning lists the sensitive entries and the info finding lists `readme.html`. |
| `paths[].path` | string | The file or directory, relative to the site root. |
| `paths[].reason` | string | What it is: version control data, an environment file, a phpinfo page, or the WordPress readme. |

## Example

From the check's tests: a `readme.html` alone at the top of the web root.

```json
{
	"id": "config.exposed.files",
	"severity": "info",
	"title": "The WordPress readme shows the installed version",
	"evidence": {
		"paths": [
			{
				"path": "readme.html",
				"reason": "WordPress readme that shows the installed version"
			}
		]
	},
	"fix": "Delete readme.html from the web root. WordPress puts it back on each core update."
}
```

## When it skips

- Nothing was found, but not all of the top level was examined: a directory
  could not be listed in full, the walk stopped at `--max-files`, or a symlink
  to a `.php` file that is not a core file was not followed, so it was not
  read for `phpinfo()`.

## False positives

- A web server configured to deny dotfiles, as many hosts do by default, so
  `.git` or `.env` is present but cannot be downloaded.
- A PHP file that mentions `phpinfo(` without calling it, such as in a
  comment, or that calls it only behind a login or another check.
- A `.env.example` file with placeholder values and no real secrets.
