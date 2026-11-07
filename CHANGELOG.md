# Changelog

Notable changes to wp-doctor, by release. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). Before 1.0.0, any
minor release may change the command-line options or the JSON report shape.

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

[0.1.0]: https://github.com/handelce/wp-doctor/releases/tag/v0.1.0
