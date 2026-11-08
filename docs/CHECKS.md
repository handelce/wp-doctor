# Check catalogue

Each check has an id of the form `category.topic.detail`, and a page in
[checks/](checks/) that describes exactly what it reports, every evidence key,
an example finding, when it skips and its known false positives. The Ver
column is the release a check first ships in; a row marked later is not built
yet. Thresholds are defaults and can be changed in `wp-doctor.json`. The JSON
report is described in [REPORT.md](REPORT.md).

## Performance and database (`perf.*`)

| Id | Reports | Default severity | Needs | Ver |
| --- | --- | --- | --- | --- |
| [`perf.autoload.size`](checks/perf.autoload.size.md) | The total size of the options WordPress loads on every request, when it is above 1 MiB. | warning above 1 MiB (1048576 bytes), critical above 3 MiB; skipped when the table has no autoloaded rows | DB | 0.1 |
| [`perf.autoload.top`](checks/perf.autoload.top.md) | The ten largest options that WordPress loads on every request. | info | DB | 0.1 |
| [`perf.autoload.orphaned`](checks/perf.autoload.orphaned.md) | Large autoloaded options whose names match no installed plugin, must-use plugin or theme. | warning | DB, FS | 0.1 |
| [`perf.objectcache.missing`](checks/perf.objectcache.missing.md) | No persistent object cache drop-in in the content directory. | warning | FS | 0.1 |
| [`perf.transients.expired`](checks/perf.transients.expired.md) | More expired transients than the limit are still stored in the options table. | warning above 5000 rows | DB | 0.1 |
| [`perf.cron.overdue`](checks/perf.cron.overdue.md) | Scheduled events that are more than an hour late. | warning | DB | 0.1 |
| [`perf.cron.duplicates`](checks/perf.cron.duplicates.md) | The same cron event, with the same arguments, scheduled more than 50 times. | critical above 50 copies; warning when unreadable | DB | 0.1 |
| [`perf.cron.disabled`](checks/perf.cron.disabled.md) | `DISABLE_WP_CRON` is set to a true value in `wp-config.php`, so scheduled events need a system cron job. | info | FS | 0.2 |
| [`perf.tables.largest`](checks/perf.tables.largest.md) | The ten largest tables that carry the site's table prefix. | info | DB | 0.1 |
| [`perf.tables.bloat`](checks/perf.tables.bloat.md) | Large tables whose names suggest they hold logs, sessions or queued jobs. | warning | DB | 0.1 |
| [`perf.revisions.count`](checks/perf.revisions.count.md) | The posts table holds more post revisions than the limit. | warning | DB | 0.2 |
| [`perf.tables.engine`](checks/perf.tables.engine.md) | Site tables that use the MyISAM storage engine. | info | DB | 0.2 |
| [`perf.slowquery.indexcheck`](checks/perf.slowquery.indexcheck.md) | Joins in supplied queries that likely compare columns of different types or collations, so the server cannot use an index. | warning ("likely") | DB | 0.3 |

## Disk and logs (`disk.*`)

| Id | Reports | Default severity | Needs | Ver |
| --- | --- | --- | --- | --- |
| [`disk.debuglog.size`](checks/disk.debuglog.size.md) | The WordPress debug log when it is larger than 100 MiB. | warning above 100 MiB, critical above 1 GiB | FS | 0.1 |
| [`disk.errorlog.size`](checks/disk.errorlog.size.md) | Files named `error_log` anywhere under the site that are larger than 50 MiB. | warning | FS | 0.1 |
| [`disk.leftovers.web`](checks/disk.leftovers.web.md) | Database dumps, copies of `wp-config.php`, archives and backup copies of files left under the site root. | critical for dumps and config copies, warning for the rest | FS | 0.1 |
| [`disk.uploads.size`](checks/disk.uploads.size.md) | The total size and file count of the uploads directory, with its largest folders. | info | FS | 0.2 |
| [`disk.cache.size`](checks/disk.cache.size.md) | A cache folder in the content directory that has grown above the size limit. | warning | FS | 0.2 |

## Security and compromise indicators (`sec.*`)

| Id | Reports | Default severity | Needs | Ver |
| --- | --- | --- | --- | --- |
| [`sec.users.admins`](checks/sec.users.admins.md) | Administrator accounts, and those among them that were likely created by an attacker. | info; warning; critical | DB | 0.1 |
| [`sec.core.checksums`](checks/sec.core.checksums.md) | WordPress core files that differ from the official release, and files that were likely added to the core directories. | critical | FS, network | 0.1 |
| [`sec.uploads.php`](checks/sec.uploads.php.md) | PHP files in the uploads directory, and server settings there that make files run as PHP. | critical; warning | FS, DB | 0.1 |
| [`sec.uploads.disguised`](checks/sec.uploads.disguised.md) | Files in the uploads directory with an image extension that likely hold PHP code. | critical ("likely") | FS, DB | 0.1 |
| [`sec.code.obfuscated`](checks/sec.code.obfuscated.md) | PHP that likely decodes a hidden string and runs it. | warning ("likely") | FS | 0.2 |
| [`sec.code.remotefetch`](checks/sec.code.remotefetch.md) | PHP that likely downloads code from another server and runs it. | critical ("likely") | FS | 0.2 |
| [`sec.plugins.unknown`](checks/sec.plugins.unknown.md) | PHP in the plugins directory that WordPress would not load as a plugin. | warning ("likely") | FS | 0.2 |
| [`sec.themes.unknown`](checks/sec.themes.unknown.md) | PHP in the themes directory that WordPress would not run as part of a theme. | warning ("likely") | FS | 0.2 |
| [`sec.plugins.checksums`](checks/sec.plugins.checksums.md) | Plugin files that differ from the wordpress.org release of the installed version. | warning | FS, network | 0.3 |
| [`sec.content.injected`](checks/sec.content.injected.md) | Published posts that likely hold injected scripts, and recently changed posts that likely hold defacement text. | warning ("likely"); info | DB | 0.3 |
| [`sec.recent.changes`](checks/sec.recent.changes.md) | PHP files written in the last few days apart from the rest of their plugin, theme or WordPress release. | info ("likely") | FS | 0.2 |
| [`sec.wpcron.persistence`](checks/sec.wpcron.persistence.md) | Scheduled WP-Cron hooks that no plugin, theme or WordPress core file accounts for. | warning ("likely"); info | DB, FS | 0.3 |
| [`sec.updates.outdated`](checks/sec.updates.outdated.md) | Plugins, themes and WordPress core with an update that WordPress knows about and that is not installed. | warning | DB | 0.1 |
| [`sec.plugins.inactive`](checks/sec.plugins.inactive.md) | Plugins in the plugins directory that are not active. | info | DB, FS | 0.1 |

## Configuration and hardening (`config.*`)

| Id | Reports | Default severity | Needs | Ver |
| --- | --- | --- | --- | --- |
| [`config.debug.enabled`](checks/config.debug.enabled.md) | `WP_DEBUG` switched on in `wp-config.php`. | warning | FS | 0.1 |
| [`config.fileedit.enabled`](checks/config.fileedit.enabled.md) | The dashboard's theme and plugin code editor left available. | info | FS | 0.1 |
| [`config.multisite.scope`](checks/config.multisite.scope.md) | A multisite network, of which only the main site's database tables were scanned. | info | FS | 0.1 |
| [`config.salts.default`](checks/config.salts.default.md) | Authentication keys and salts in `wp-config.php` that are missing, sample text, repeated or short. | critical; warning for short values | FS | 0.1 |
| [`config.perms.wpconfig`](checks/config.perms.wpconfig.md) | A `wp-config.php` that other accounts on the server can read, or that the group or others can write. | warning | FS | 0.1 |
| [`config.perms.writable`](checks/config.perms.writable.md) | Files and directories under the site root that any account on the server can write. | warning | FS | 0.1 |
| [`config.exposed.files`](checks/config.exposed.files.md) | Files at the top of the web root that give away secrets, server details or the WordPress version. | warning; info for readme.html | FS | 0.1 |
| [`config.php.version`](checks/config.php.version.md) | A PHP version past the end of its security support, and plugins that need a newer PHP than the site selects. | warning; info for plugin mismatch | FS, runtime | 0.1 |
| [`config.php.settings`](checks/config.php.settings.md) | PHP settings in the site's own files that show errors to visitors or leave too little memory or time. | warning; info | FS | 0.1 |
| [`config.db.version`](checks/config.db.version.md) | A MySQL or MariaDB server whose release series is past the end of its vendor's support. | warning ("likely") | DB | 0.2 |
| [`config.xmlrpc.enabled`](checks/config.xmlrpc.enabled.md) | An `xmlrpc.php` endpoint that no `.htaccess` rule blocks. | info ("likely") | FS | 0.2 |
| `config.userenum.enabled` | Author archive and REST user enumeration reachable (`--url` mode) | info | HTTP | later |

## Check template for contributors

1. Create `src/Check/Category/<Area>/class-<name>-check.php` implementing `Check`.
   Implement `Runs_Without_Config` instead only when the check reads nothing
   from `wp-config.php`, the database, or a directory `wp-config.php` can move;
   such a check still runs when `wp-config.php` cannot be read.
2. Return `Result::pass()`, `Result::findings(...)`, or `Result::skipped($reason)`.
3. Build every `Finding` with evidence and a fix.
4. Add the row to this file, linked to a page `checks/<id>.md` with the same
   sections as the others.
5. Add tests: one failing fixture, one passing fixture, and an assertion that
   the fixture contained rows or files to examine.
6. Register the class in `CheckRegistry`.

See `CONTRIBUTING.md` for the full checklist.
