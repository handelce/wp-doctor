# wp-doctor

Read-only health and compromise scanner for WordPress. Point it at a site
directory and get a ranked report of what is slow, bloated, misconfigured, or
compromised, with a fix for every finding.

> Status: pre-release. Changes are recorded in [CHANGELOG.md](CHANGELOG.md).

```
$ wp-doctor scan /var/www/example.test

CRITICAL  perf.autoload.size     Autoloaded options total 13.9 MB
          3 options account for 12.8 MB: plugin-x_cache (5.2 MB), ...
          Fix: wp option set-autoload plugin-x_cache no

CRITICAL  sec.users.admins       20 administrator accounts created within 22 days
          ...
```

(Sample output; the real format is defined during v0.1.)

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

Not yet released. Planned:

```
curl -L -o wp-doctor.phar https://github.com/handelce/wp-doctor/releases/latest/download/wp-doctor.phar
php wp-doctor.phar scan /path/to/site
```

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
