# `config.perms.wpconfig`

A `wp-config.php` that other accounts on the server can read, or that the group or others can write.

| | |
|---|---|
| Category | config |
| Severity | warning |
| Reads | the site files |
| Since | 0.1.0 |

## What it reports

The check reads the permission bits of `wp-config.php` and reports the file
when any of these is set:

- read by others (`o+r`);
- write by the group (`g+w`);
- write by others (`o+w`).

Read by the group is allowed, since the web server often reads the file
through its group, so `640` and `440` pass, and so do `600` and `400`. The
file is read in the site root, or in the core directory when core is in a
subdirectory and `wp-config.php` sits there.

## Why it matters

`wp-config.php` holds the database password and the keys and salts. On a
shared server, any other account that can read it can take over the database
and forge login cookies. An account that can write it can add code that
WordPress runs on every request.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `path` | string | `wp-config.php`, relative to the site root, with the core directory in front when it was read there. |
| `mode` | string | The permission bits, in four-digit octal, such as `0644`. |

## Example

From the check's tests: a `wp-config.php` with mode `0666`.

```json
{
	"id": "config.perms.wpconfig",
	"severity": "warning",
	"title": "wp-config.php can be read or changed by other accounts on the server",
	"evidence": {
		"path": "wp-config.php",
		"mode": "0666"
	},
	"fix": "Run `chmod 640 wp-config.php`, or `chmod 600 wp-config.php` when the web server runs as the file owner. If the database password may have been read by someone else, change it. If others could write the file, compare it with a known good copy before trusting it."
}
```

## When it skips

- `wp-config.php` is not in the site root or the core directory, such as
  one directory above the root, where WordPress also looks for it.
- The scan runs on Windows, where permission bits have no meaning.
- `wp-config.php` could not be read for its details, or is not a regular
  file.

## False positives

- A server where the site has the machine to itself, so no other account
  could make use of the read bit. `0644` is common there and the risk is
  small, though the finding still stands.
- A host whose PHP runs every site in its own container or chroot, which
  limits who "others" can be.
