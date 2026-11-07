# wp-doctor

Read-only health and compromise scanner for WordPress. Point it at a site
directory and get a ranked report of what is slow, bloated, misconfigured, or
compromised, with a fix for every finding.

> Status: early releases (0.x); options and the JSON shape may still change.
> Changes are recorded in [CHANGELOG.md](CHANGELOG.md).

```
$ php wp-doctor.phar scan /var/www/example.test
wp-doctor 0.1.0, WordPress 6.5.2

CRITICAL (1)
  [config.salts.default] Authentication keys and salts are missing, sample text or repeated
    NONCE_KEY: not defined in wp-config.php
    Fix: Generate fresh random values at https://api.wordpress.org/secret-key/1.1/salt/ and paste all eight define() lines over the old ones in wp-config.php. Everyone is logged out once when you do.

WARNING (1)
  [config.perms.wpconfig] wp-config.php can be read or changed by other accounts on the server
    path: wp-config.php
    mode: 0644
    Fix: Run `chmod 640 wp-config.php`, or `chmod 600 wp-config.php` when the web server runs as the file owner. ...
```

## Why

- Runs **outside** WordPress, so it still works when the site is down or
  compromised.
- **Read-only**: the database layer accepts only `SELECT`, `SHOW`, and `EXPLAIN`.
- **Never executes site code**: `wp-config.php` is parsed, not included.
- **Works on old servers**: 64-bit PHP 7.2+, no WP-CLI, no Composer at
  runtime. The database checks need MySQL 5.6.5 or MariaDB 10.0 or later,
  which can make a session read-only; on an older server they skip and say
  why.
- **Honest**: a check that cannot run says `skipped` and why.

## What it checks

Performance and database, disk and logs, compromise indicators, and
configuration hardening. See [docs/CHECKS.md](docs/CHECKS.md) for the full list.

## Install

Download the phar and its checksum from the latest release, check it, and run
it. It needs no Composer and nothing installed on the site.

```
curl -LO https://github.com/handelce/wp-doctor/releases/latest/download/wp-doctor.phar
curl -LO https://github.com/handelce/wp-doctor/releases/latest/download/wp-doctor.phar.sha256
sha256sum -c wp-doctor.phar.sha256
php wp-doctor.phar scan /path/to/site
```

On macOS, use `shasum -a 256 -c wp-doctor.phar.sha256`.

## Platforms

Linux and macOS are tested. Windows is not supported yet: the two file
permission checks skip there, and the rest has only a continuous-integration
run that is allowed to fail.

## Output formats

`--format=json` carries a `schema_version` of 0: the JSON shape may change in
any release before v1.0, so check the field before relying on it.

## Docs

- [Architecture](docs/ARCHITECTURE.md)
- [Check catalogue](docs/CHECKS.md)
- [Changelog](CHANGELOG.md)
- [Contributing](CONTRIBUTING.md)

## Responsible use

Use it on sites you own or administer.

## License

MIT
