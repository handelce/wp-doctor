# Changelog

Notable changes to wp-doctor, by release. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). Before 1.0.0, any
minor release may change the command-line options or the JSON report shape.

## [Unreleased]

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

[Unreleased]: https://github.com/handelce/wp-doctor/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/handelce/wp-doctor/releases/tag/v0.1.1
[0.1.0]: https://github.com/handelce/wp-doctor/releases/tag/v0.1.0
