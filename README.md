# wp-doctor

Read-only health and compromise scanner for WordPress. Point it at a site
directory and get a ranked report of what is slow, bloated, misconfigured, or
compromised, with a fix for every finding.

> Status: 1.x. The command-line options and the JSON report follow Semantic
> Versioning, so nothing that breaks them changes before 2.0. Changes are
> recorded in [CHANGELOG.md](CHANGELOG.md).

An excerpt of a real scan of a WordPress 7.1.4 test site with WooCommerce,
Yoast SEO, Contact Form 7 and a few other plugins, run with its database:

```
$ php wp-doctor.phar scan /var/www/example.test --offline
wp-doctor 1.0.0, WordPress 7.1.4

WARNING (5)
  [perf.cron.overdue] Scheduled events are overdue
    overdue_events: 14
    scheduled_events: 14
    oldest_overdue_seconds: 334602
    hooks:
      - hook: action_scheduler_run_queue
        overdue: 1
      ...
    Fix: WordPress only runs scheduled events when someone visits the site. Add a system cron job that requests wp-cron.php every few minutes (or runs `wp cron event run --due-now`), ...
  [sec.updates.outdated] Updates are available and not yet installed
    plugin_updates: 2
    theme_updates: 3
    plugins:
      - akismet/akismet.php -> 5.7.2
      - contact-form-7/wp-contact-form-7.php -> 6.2.4.1
    ...
  [sec.users.admins] Administrator accounts with an email address that is likely not a real person's
    admins:
      - login: siteowner
        email: owner@example.test
        reason: placeholder email domain
    ...

INFO (9)
  [sec.wpcron.persistence] Scheduled events are likely left by plugins that were removed
    hooks_scheduled: 14
    hooks_not_named: 1
    hooks:
      - hook: updraftplus_clean_temporary_files
        events: 1
    Fix: No installed PHP names these hooks, so their events run nothing. If you recognise a hook from a plugin you removed, delete its events with `wp cron event delete <hook>`. ...
  ...

14 findings (0 critical, 5 warning, 9 info). Checks: 24 passed, 13 with findings, 6 skipped, 0 failed to run.
```

A scan of a demo site with planted problems: a theme running encoded code,
PHP in uploads, a stray plugin folder, a database dump in the web root and the
sample keys and salts from `wp-config-sample.php`.

![A wp-doctor scan of the demo site, in a terminal](docs/demo.svg)

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
configuration hardening. See [docs/CHECKS.md](docs/CHECKS.md) for the full
list, with a page for each check that explains what it reports and every
field of its evidence.

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

Or install it with Composer, which puts `wp-doctor` in Composer's global `bin`
directory (add that directory, shown by `composer global config bin-dir --absolute`,
to your `PATH`):

```
composer global require handelce/wp-doctor
wp-doctor scan /path/to/site
```

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

`--format=json` prints a document with `"schema_version": 1`, described in
[docs/REPORT.md](docs/REPORT.md) and by the JSON Schema
[docs/report.schema.json](docs/report.schema.json). Fields are not removed or
renamed while the version is 1; new ones may appear.

## Docs

- [Check catalogue](docs/CHECKS.md), with a page for each check in [docs/checks/](docs/checks/)
- [JSON report](docs/REPORT.md)
- [Architecture](docs/ARCHITECTURE.md)
- [Changelog](CHANGELOG.md)
- [Contributing](CONTRIBUTING.md)

## Responsible use

Use it on sites you own or administer.

## License

MIT
