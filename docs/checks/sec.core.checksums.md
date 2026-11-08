# `sec.core.checksums`

WordPress core files that differ from the official release, and files that were likely added to the core directories.

| | |
|---|---|
| Category | security |
| Severity | critical |
| Reads | the site files, wordpress.org (not with --offline) |
| Since | 0.1.0 |

## What it reports

The check reads the WordPress version from `wp-includes/version.php` and the
package locale from its `$wp_local_package`, else `en_US`. The `WPLANG`
setting is not used, since it changes the display language, not the files. It
fetches the MD5 of every file in that release from wordpress.org and gives up
to two findings.

**Changed or missing files** (critical). Each release file on the site is
hashed and compared with its official checksum. Anything under `wp-content` is not compared, nor
are `readme.html`, `license.txt`, `wp-config-sample.php`, `wp-config.php` and
`wp-config-docker.php`. A broken symlink counts as missing. Files that are
excluded, behind a symlink that leaves the site root, or larger than 64 MiB
are listed as not compared, never as missing. Files that cannot be read are
counted.

**Files the release does not contain** (critical, or warning when all are at
the top). These are files at any depth inside `wp-admin` and `wp-includes`
apart from `error_log` and `.DS_Store`, so an `.htaccess`, `.user.ini` or
`php.ini` there is reported, and `.php` files at the top of the core directory
apart from the names above. When core is in a subdirectory, `.php` files at
the top of the web root are read too, apart from the `index.php` that loads
core. A file inside `wp-admin` or `wp-includes` makes the finding critical;
PHP at the top alone makes it a warning.

## Why it matters

Core files are replaced whole on each update, so a file that differs was
edited after it was installed, and injected code in a file such as
`wp-login.php` or one in `wp-includes` runs on every request. The core
directories hold only WordPress's own files, which makes a file added there
easy to overlook and a common place to hide a backdoor.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `wordpress_version` | string | Both findings. The version read from `wp-includes/version.php`. |
| `locale_used` | string | Changed-files finding. The package locale whose checksums were fetched. |
| `files_compared` | integer | Changed-files finding. Release files that were read and hashed: those present, readable and not passed over. |
| `modified_files` | integer | Changed-files finding. Files that differ from the release. |
| `missing_files` | integer | Changed-files finding. Release files that are not on the site. |
| `files_unreadable` | integer | Changed-files finding. Release files that could not be read. They are counted, not listed. |
| `files_not_compared` | integer | Changed-files finding. Release files that were excluded, behind a symlink that leaves the site root, or larger than 64 MiB. |
| `scan_incomplete` | boolean | Both findings. True when a release file could not be read or was not compared, or a walk of the core directories or the root stopped short. |
| `modified` | list of strings | Changed-files finding. The files that differ, relative to the site root, in path order, at most 20. |
| `missing` | list of strings | Changed-files finding. The missing files, in the same form. |
| `not_compared` | list of strings | Changed-files finding. The files that were not compared, in the same form. |
| `unknown_in_core_dirs` | integer | Added-files finding. Files inside `wp-admin` and `wp-includes` that the release does not contain. |
| `unknown_php_in_root` | integer | Added-files finding. PHP files at the top of the core directory, or of the web root, that the release does not contain. |
| `unknown` | list of strings | Added-files finding. Those files, relative to the site root: the ones in the core directories first, then the ones at the top, each in path order, at most 20. |

## Example

From the check's tests: a changed `wp-login.php`, in a scan that also found a
file added to `wp-includes`, which gave the second finding.

```json
{
	"id": "sec.core.checksums",
	"severity": "critical",
	"title": "WordPress core files differ from the official release",
	"evidence": {
		"wordpress_version": "6.5.2",
		"locale_used": "en_US",
		"files_compared": 5,
		"modified_files": 1,
		"missing_files": 0,
		"files_unreadable": 0,
		"files_not_compared": 0,
		"scan_incomplete": false,
		"modified": [
			"wp-login.php"
		],
		"missing": [],
		"not_compared": []
	},
	"fix": "Do not edit core files. Reinstall them from a clean copy of the same version (`wp core download --force --skip-content --version=6.5.2`), then look for how they were changed. If you did not change them, treat the site as compromised."
}
```

## When it skips

- The scan runs with `--offline`, or checksum lookups are not available.
- The WordPress version could not be read from `wp-includes/version.php`.
- The checksums for that version and locale could not be obtained from
  wordpress.org.
- `wp-admin`, `wp-includes` or the top of the root could not be read.
- Every file compared matched and nothing was added, but some files could not
  be read or were not compared, or a walk stopped short: a clean result would
  claim more than was examined.

## False positives

- A core file patched by hand or by the host, for example to work around a
  bug, differs from the release though nothing malicious was added.
- An update that stopped part way leaves files from two releases, so files
  differ from the checksums of the version `version.php` names.
- Some hosts and security plugins add an `.htaccess` to `wp-admin` to
  password-protect it. The fix text says which lines in such a file are signs
  of compromise.
- Hosts and deployment tools sometimes add PHP files to the site root, such as
  a health check script. These give the warning, not the critical finding.
