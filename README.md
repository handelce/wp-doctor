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

The path is the directory holding `wp-includes`, or the web root when WordPress
core is in a subdirectory of it (an `index.php` that loads `wp/wp-blog-header.php`).

On macOS, use `shasum -a 256 -c wp-doctor.phar.sha256`.

The build is reproducible, so you can also check the phar against the source:
build the release tag yourself and compare the checksum. Use PHP 7.2 or later
with the phar extension.

```
git clone --branch vX.Y.Z https://github.com/handelce/wp-doctor.git && cd wp-doctor
composer install
composer build
sha256sum build/wp-doctor.phar
```

## GitHub Action

If the site's files are in a repository, the action scans them in a workflow.
It downloads the release phar named by the tag you use and checks its
checksum, writes the Markdown report to the job summary, and uploads the JSON
and Markdown reports as an artifact. By default it fails the job on critical
findings; set `fail-on` to `warning` or `never` to change that.

```yaml
name: wp-doctor
on:
  schedule:
    - cron: "0 6 * * 1"
  workflow_dispatch:

jobs:
  scan:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v7
      - uses: handelce/wp-doctor@vX.Y.Z
        with:
          path: public_html
          fail-on: critical
```

The database checks skip unless the job can reach the database. To give it,
add `args: --db-from-env` and set `WPD_DB_HOST`, `WPD_DB_NAME`, `WPD_DB_USER`
and `WPD_DB_PASSWORD` from secrets in the step's `env`. Other inputs:
`version` (a release such as `v0.3.0`), `args` (more options, such as
`--offline`), `php-version` and `artifact-name`. Without `--offline` the scan
fetches checksums from wordpress.org.

## Platforms

Linux, macOS and Windows. Continuous integration runs the tests on Linux
with PHP 7.2 to 8.4 and on Windows with PHP 8.3. On Windows the two file
permission checks (`config.perms.wpconfig` and `config.perms.writable`) skip,
since Windows has no Unix file modes.

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
