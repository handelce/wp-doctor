# Changelog

Notable changes to wp-doctor, by release. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). Before 1.0.0, any
minor release may change the command-line options or the JSON report shape.

## [Unreleased]

### Added

- `sec.plugins.checksums` (warning): plugin files that differ from, or were
  added to, the release wordpress.org publishes, as `wp plugin verify-checksums`
  reports them. Plugins wordpress.org does not host are listed as not checked.
  This sends each plugin's folder name and version to downloads.wordpress.org;
  `--offline` turns it off.
- `sec.wpcron.persistence`: scheduled events whose hook is named only by PHP
  outside any plugin, theme or WordPress core, such as a file in uploads
  (warning), and events whose hook no installed code names, likely left by a
  removed plugin (info).
- `sec.content.injected`: published posts holding a script from another host
  or an inline script that hides what it does (warning), and posts changed in
  the last 30 days that hold defacement text such as "hacked by" (info).

### Changed

- The phar build is reproducible: building a release tag gives the same bytes
  as the published phar, so its checksum can be checked against the source.
  The README says how. The Release workflow builds each release twice and
  stops if the builds differ. The scanner code did not change for this.

## [0.2.0] - 2026-11-08

### Added

- A site whose WordPress core is in a subdirectory of the web root, with an
  `index.php` that loads it from there, is scanned as one site. Give the web
  root as the path. `wp-config.php` is read from the core directory or the
  web root, and the content directory, `UPLOADS` and `upload_path` are found
  as WordPress finds them.
- `sec.code.obfuscated` (warning): PHP that runs decoded or encoded data with
  `eval()`, `assert()` or `create_function()`, a common form of injected
  code. It reads every PHP file in the site and `wp-config.php`.
- `sec.code.remotefetch` (critical): PHP that downloads code from another
  server and runs it, including an `include` of a URL.
- `sec.plugins.unknown` and `sec.themes.unknown` (warning): PHP in the plugins
  or themes directory that WordPress would not load as a plugin or theme, such
  as a folder with no plugin header or no `style.css`, or a stray PHP file at
  the top. Such code can still be requested over the web.
- `sec.recent.changes` (info): PHP files written in the last 7 days apart
  from the rest of their plugin, theme or WordPress core, judged by the time
  the file was last written on disk, which `touch` cannot set back. Set the
  number of days with an `info` threshold in `wp-doctor.json`:
  `{"thresholds": {"sec.recent.changes": {"info": 14}}}`. Skipped on Windows
  and on a site whose files were all written within the same hour.
- `perf.cron.disabled` (info): `DISABLE_WP_CRON` is on, so scheduled events
  depend on a system cron job.
- `perf.revisions.count` (warning): more than 10000 post revisions, with the
  posts that have the most and what `WP_POST_REVISIONS` keeps.
- `perf.tables.engine` (info): site tables that still use MyISAM.
- `disk.uploads.size` (info): the size of the uploads directory and its
  largest folders.
- `disk.cache.size` (warning): a `cache`, `litespeed` or `wpo-cache` folder
  above 1 GiB.
- `config.xmlrpc.enabled` (info): `xmlrpc.php` with no `.htaccess` rule that
  blocks it.
- `config.db.version` (warning): a MySQL or MariaDB series past the end of
  support its vendor publishes.
- The `disk.cache.size` and `perf.revisions.count` limits can be set in
  `wp-doctor.json`.

### Changed

- Files are read up to 4 MiB instead of 512 KiB, so large plugin files are
  examined in full.

## [0.1.1] - 2026-11-08

### Changed

- Windows is supported. Continuous integration runs the test suite on Windows
  with PHP 8.3, alongside Linux with PHP 7.2 to 8.4. On Windows the
  `config.perms.wpconfig` and `config.perms.writable` checks skip, since
  Windows has no Unix file modes. The scanner code is the same as in 0.1.0.

### Fixed

- `composer build` failed on Windows, because the build script compared
  backslash paths with the slash paths in Composer's class map.

## [0.1.0] - 2026-11-07

First release.

### Added

- `wp-doctor scan <path>` reads a WordPress site's files and database and
  prints a ranked report, with evidence and a fix for every finding.
- Read-only by construction: the database layer accepts only `SELECT`, `SHOW`
  and `EXPLAIN` in a read-only session, and `wp-config.php` is parsed as
  tokens, never included.
- Report formats: `text`, `json` (with `schema_version` 0) and `markdown`.
- Exit codes: 0 nothing above info, 1 warnings, 2 critical findings, 3 tool
  error.
- Options: `--format`, `--offline`, `--config`, `--db-from-env`,
  `--max-files`, `--exclude`, `--redact-emails`, `--color` and `--no-color`.
- A `wp-doctor.json` file to exclude paths, ignore checks and change
  thresholds. Unknown keys are refused.
- Database credentials, salts and keys are removed from every report.
- Released as a single `wp-doctor.phar`, with a SHA-256 checksum.
- Checks, described in [docs/CHECKS.md](docs/CHECKS.md):
  - Performance and database: `perf.autoload.size`, `perf.autoload.top`,
    `perf.autoload.orphaned`, `perf.objectcache.missing`,
    `perf.transients.expired`, `perf.cron.overdue`, `perf.cron.duplicates`,
    `perf.tables.largest`, `perf.tables.bloat`.
  - Disk and logs: `disk.debuglog.size`, `disk.errorlog.size`,
    `disk.leftovers.web`.
  - Security and compromise indicators: `sec.users.admins`,
    `sec.core.checksums`, `sec.uploads.php`, `sec.uploads.disguised`,
    `sec.updates.outdated`, `sec.plugins.inactive`.
  - Configuration and hardening: `config.debug.enabled`,
    `config.fileedit.enabled`, `config.multisite.scope`,
    `config.salts.default`, `config.perms.wpconfig`, `config.perms.writable`,
    `config.exposed.files`, `config.php.version`, `config.php.settings`.

### Requirements

- 64-bit PHP 7.2 or later, with `mysqli` and `json`.
- For the database checks: MySQL 5.6.5 or MariaDB 10.0 or later.
- Linux or macOS. Windows is not supported yet.

[Unreleased]: https://github.com/handelce/wp-doctor/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/handelce/wp-doctor/releases/tag/v0.2.0
[0.1.1]: https://github.com/handelce/wp-doctor/releases/tag/v0.1.1
[0.1.0]: https://github.com/handelce/wp-doctor/releases/tag/v0.1.0
