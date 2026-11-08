# Architecture

## Constraints that shape the design

- WordPress coding standards (`phpcs.xml.dist`): `Capitalized_Words` class
  names under the `WP_Doctor` namespace, `class-*.php` and `interface-*.php` file
  names (test classes are named after the class, `*_Test.php`), snake_case methods,
  tabs. Autoloading is a Composer classmap.
- PHP 7.2 syntax floor. No typed properties, arrow functions, `match`, named
  arguments, nullsafe operator, or enums.
- 64-bit PHP only. A scan on 32-bit PHP is refused with exit code 3, since
  sizes above 2 GiB do not fit in its integers.
- Distributed as a single `wp-doctor.phar`, also installable with Composer.
- No WordPress boot, no WP-CLI, no plugin to install.
- Read-only by construction.

## Layout

```
bin/wp-doctor                      entry point (thin: builds the Application, runs it)
src/
  class-application.php            argument parsing, wiring, exit code
  Cli/
    class-argument-parser.php      --format, --offline, --config, --db-from-env, --max-files, --exclude, --redact-emails, --color
    class-tool-config.php          strict reader for wp-doctor.json; ids checked against the registry
    interface-console.php          stdout, stderr and TTY detection; the only place the tool writes
  Site/
    class-site-locator.php         finds wp-config.php and the WordPress root
    class-config-parser.php        token-based reader for wp-config.php: defines, $table_prefix, ini_set() (never includes it)
    class-site.php                 immutable description: paths, table prefix, version
  Db/
    class-read-only-connection.php the only way checks reach the database
    class-statement-gate.php       allow-list applied to every statement
    interface-database-driver.php runs accepted statements
    class-mysqli-driver.php        the one class that calls mysqli
  Fs/
    class-file-scanner.php         bounded, read-only directory walker
    class-file-info.php            one entry: path, size, mtime, mode, type
  Check/
    interface-check.php            interface
    class-check-registry.php       the explicit, ordered list of checks
    class-runner.php               runs every check; a thrown exception becomes an error result
    class-evidence.php             numbered evidence lists shared by checks
    class-thresholds.php           warning and critical limits, built in or from wp-doctor.json
    class-option-value.php         one options row, size-limited, for checks
    Category/                      Performance, Disk, Security, Config
      Security/class-rogue-admin-indicators.php  loads and matches data/ for the administrator check
  Report/
    class-report.php               every check's outcome plus notices
    class-report-entry.php         one check's id, category and result
    class-redacted-report.php      a report that has passed through the Redactor
    class-exit-code.php            exit code for a finished scan
    class-finding.php              id, severity, title, evidence, fix
    class-result.php               pass | finding(s) | skipped(reason) | error
    class-severity.php             critical | warning | info
    Formatter/                     Text, Json, Markdown, and Markdown_Escape for site strings
    class-redactor.php             strips secrets from every formatter's input
  Util/
    class-serialized-reader.php    reads serialized arrays and scalars; never unserialize()
    class-cron-schedule.php        walks the cron option's events
    class-update-transients.php    pending updates from the stored update data
data/
  rogue-admin-indicators.json      login names from public incident reports, each with its source
  disposable-email-domains.txt     disposable-mail domains (CC0 upstream list), header records the commit
tools/
  update-disposable-domains.php    maintainer script that refreshes the domain list; never runs in a scan
  Http/
    interface-checksum-source.php  where core checksums come from
    class-checksum-client.php      wordpress.org checksum API (disabled by --offline)
    interface-http-transport.php   the only way the tool reaches the network
    class-stream-transport.php     HTTPS through PHP streams, when allow_url_fopen is on and PHP has an https wrapper
    class-curl-transport.php       HTTPS through ext-curl otherwise; same guards
    class-transport-choice.php     picks one, or a transport that refuses with the reason
    class-stream-transport.php     HTTPS GET with certificate checks and a size limit
tests/
  fixtures/                        tiny fake WordPress trees, SQL seeds
```

## Core types

```php
interface Check
{
    public function id(): string;            // "perf.autoload.size"
    public function category(): string;      // "performance"
    public function run(Context $context): Result;
}
```

`Context` hands a check its collaborators: `Site`, `ReadOnlyConnection`,
`FileScanner`, the loaded `Config`, the `Thresholds`, and (unless `--offline`)
a `Checksum_Source`, normally the wordpress.org client. The database is opened
the first time a check asks for it and shared by every later check, so a scan
whose checks never ask leaves it closed. A check receives only what it needs through `Context`; there
are no globals and no direct `mysqli` access.

`Result` has four shapes, and a check must return one of them:

- `Result::pass()` : the check ran and found nothing.
- `Result::findings(Finding ...)` : one or more problems.
- `Result::skipped(string $reason)` : the check could not run (no database
  access, offline, directory unreadable).
- Exceptions are caught by the runner and recorded as `error`, never as pass.
  A `Read_Only_Violation_Exception` is recorded as a wp-doctor bug to report,
  since no check should ever produce a refused statement.

Formatters and tests read a result's findings with `finding_list()`; the name
`findings` belongs to the factory, and PHP does not allow a static and an
instance method to share a name.

`Finding` is immutable and requires every field at construction:
`id`, `severity`, `title`, `evidence` (ordered key/value pairs), `fix`
(string, may contain a copyable command). A finding without a fix does not
compile into a valid object.

## Read-only guarantee

`ReadOnlyConnection` is the only database class checks can obtain.

- Opens the connection and immediately issues `SET SESSION TRANSACTION READ ONLY`.
  On MySQL 8.0 and later it also sets `information_schema_stats_expiry = 0`, so
  table sizes are read live rather than from the server's daily cache. A failure
  of that second setting is ignored; a failure of the first stops the connection,
  and the skip reason says the server needs MySQL 5.6.5 or MariaDB 10.0 or
  later. The setting applies to wp-doctor's own session only, so the site's
  connections keep writing as usual.
- Every statement is checked against an allow-list (`SELECT`, `SHOW`,
  `DESCRIBE` of one table, `EXPLAIN SELECT` and `DESCRIBE SELECT`) before it
  reaches `mysqli`. Anything else throws.
- Multi-statement strings are rejected, as are comments, backslashes, `INTO`,
  locking reads, variable assignment, and functions such as `SLEEP` and
  `LOAD_FILE`. `EXPLAIN ANALYZE` is refused because it executes the statement. A call of a
  function whose name is quoted, such as `` `sleep`(1) ``, is refused whatever
  the name, and so is MariaDB sequence access.
- A query returns at most 100000 rows; a larger result throws.
- The client gives up on connecting after 10 seconds and on waiting for a
  reply after 60, so a server that stops answering ends in an error rather
  than a scan that never finishes.
- The database user in `wp-config.php` is used as is; the tool never asks for
  other credentials.

Site data that PHP stores serialized (the cron option) is read with
`Serialized_Reader`, which accepts only arrays and scalars. `unserialize` is
forbidden under `src/`, so reading a site can never instantiate a class.

A unit test enumerates registered checks and runs each against a recording
connection to assert that after the session setup only allow-listed statements
are issued. A source test asserts that nothing under `src/` writes files, runs
commands, or touches `mysqli` outside the driver.

`FileScanner` exposes only reads (`walk`, `stat`, `read` up to a byte limit,
`hash`, `presence`). No method writes, moves, or deletes. Every path is resolved
and must stay inside the root, and `read` and `hash` check after opening that the
open file is still the resolved path, so a path swapped for a symlink in between
is refused. `hash` refuses files above 64 MiB with `File_Too_Large_Exception`.
`Site_Locator` reads `wp-includes/version.php` through a scanner too. Its one
direct read is `wp-config.php`, which WordPress allows in the parent of the core
directory, outside any scanner; it is read as text with a 1 MiB cap and never
executed.

The only network access is the checksum lookup: HTTPS only, certificates verified,
no redirects, a size limit, and the version and locale from the site are validated
before they go into the URL. `--offline` rules it out.

A check asks `Context::database_unavailable()` before using the database and
returns `Result::skipped()` with that reason when it is not null.

## wp-config.php parsing

`ConfigParser` uses `token_get_all()` to extract:

- `define('NAME', <literal>)` where the second argument is a string, number,
  boolean, or `null` literal;
- `$table_prefix = '<literal>';`;
- `ini_set('setting', <literal>)`, where the last call wins and a later
  conditional call or an unread include makes the setting unresolved;
- `define('NAME', __DIR__ . '<literal>')`, `dirname( __FILE__ ) . '<literal>'`
  and `ABSPATH . '<literal>'`, kept as a base plus suffix so `Context` can
  resolve a moved `WP_CONTENT_DIR`. Any other arithmetic is unresolved.

Anything computed (function calls, concatenation of variables, environment
lookups) is recorded as `unresolved`. When a needed value is unresolved, the
dependent checks return `skipped` with the reason, and the report states which
constant could not be read. A flag `--db-from-env` lets the user supply
`WPD_DB_HOST`, `WPD_DB_NAME`, `WPD_DB_USER`, `WPD_DB_PASSWORD` instead, and
`WPD_DB_TABLE_PREFIX`, when set, replaces `$table_prefix`. A define wrapped in
`if ( ! defined( 'NAME' ) )` for the same name counts as a plain define; any
other conditional define is unresolved.

A static call such as Bedrock's `Config::define( 'DB_NAME', ... )` defines the
constant later, through code that is not read, so its name is recorded as
unresolved with that reason.

Includes are never followed. PHP keeps the first definition of a constant, so a
define that comes after an include other than `wp-settings.php` or a Composer
`autoload.php` (matched as the whole file name, so `my-wp-settings.php` counts
as unread) is unresolved, and so is a `$table_prefix` assigned before such an
include. `eval()` is never run either and is treated exactly like such an
include, since the code it runs may define constants or change the prefix. `$GLOBALS['table_prefix']` makes the prefix unresolved, and so does
any `$$name`, `${...}`, `extract()` or one-argument `parse_str()` anywhere in
the file, because those can assign `$table_prefix` without naming it.
So does any statement that may write the variable other than a plain `=` or
`.=` (destructuring, a reference, `++`, `unset()`, a foreach target, or passing
it to a function other than `define()`).

Alternative-syntax blocks (`if: ... endif;` and the other `end*` forms) count as
blocks, exactly like braces. A `b''` binary string prefix is read as part of the
literal's syntax, not its value. A `const` statement after `namespace X;`
defines a namespaced constant and is not recorded. The
`WPD_DB_TABLE_PREFIX` variable with `--db-from-env` supplies the prefix then.

### When wp-config.php cannot be read

On hardened hosts the file is often readable only by the web server's account.
`Site_Locator` then returns the site with `Config::unreadable()`, which holds no
values and says why. The `Runner` runs only checks that implement
`Runs_Without_Config`; every other check is skipped with that reason, and the
report opens with a "Partial scan" notice that says to run wp-doctor as the
account that owns the site. A new check is skipped by default, so it cannot
silently guess values it never read. `Context` reports every configurable
directory (content, uploads, plugins, the debug log) as unavailable rather than
assuming the default locations. The checks that still run are the core
checksums, error logs, world-writable files, exposed files, the PHP version from
`.htaccess`, and the permissions of `wp-config.php` itself.

## Moved directories

`Context` resolves `WP_CONTENT_DIR` from a literal or one of the path shapes
above, and `UPLOADS` from a literal path relative to the core directory, when
the result stays inside the site root, and a string `WP_DEBUG_LOG` the same way.
`ABSPATH . '<literal>'` starts at the core directory and `__DIR__` at the
directory holding `wp-config.php`. A location outside
the root, a parent segment, the root itself, or a computed value makes the
dependent checks skip with that reason.
`WP_PLUGIN_DIR` and `WPMU_PLUGIN_DIR` are not followed: when either is defined
in any form, the checks that read plugins skip rather than read the default
location.

An absolute literal is resolved with `real_path()` before it is compared with
the root, so a document root reached through a symlink still matches. When
`UPLOADS` is not set, WordPress takes the uploads directory from the
`upload_path` option, so `Context` reads it (absolute, or relative to the core
directory) before falling back to `uploads` in the content directory. Without the database that option
cannot be read: `uploads_dir_unconfirmed()` gives the reason, the uploads checks
still report what they find, and a clean result becomes a skip.

## Core in a subdirectory

WordPress can live in a subdirectory of the web root, with an `index.php` in the
web root that loads `<dir>/wp-blog-header.php`. The path given on the command
line is always the root that is scanned. When it holds no
`wp-includes/version.php`, `Site_Locator` takes the core directory from that
`index.php`, or, when `wp-config.php` is in the root, from the only child that
holds `wp-includes/version.php`. `Site::core_dir()` names it, empty when core is
in the root, and `Site::core_path()` turns a path inside core into one relative
to the root.

The core directory is ABSPATH. `wp-config.php` is looked for there first and
then in its parent, as WordPress does; the content directory defaults to
`wp-content` inside it. Checks that compare or list core files
(`sec.core.checksums`, `config.exposed.files`) read it through `core_path()` and
also look at the top of the web root, which serves files too. Every evidence path
stays relative to the scanned root, such as `wp/wp-includes/functions.php`.

## Reading PHP code

`sec.code.obfuscated` and `sec.code.remotefetch` judge PHP source without
running it. `Php_Sources` walks the PHP files and passes on only those whose
text holds the words a check needs, such as `eval`, so most files are never
tokenized. `Php_Tokens` splits a file with `token_get_all()`, dropping
whitespace and comments, so code in a comment or a string is never mistaken
for a call. `Code_Sinks` then finds each `eval()`, `assert()`,
`create_function()`, `include` and `require`, and records what feeds its
argument: a decoder, a long encoded string, or a fetch from another server. A
variable is followed to its assignments in the same file, but only through
calls that return their argument changed (decoders, fetchers, string
functions); `is_array( $x )` or a method call stops it. Files are read whole
up to the scanner's 4 MiB cap, and a file larger than that is reported as read
in part.

## Walking the filesystem safely

- Skips symlinks that leave the site root.
- Caps files visited (`--max-files`, default 200000) and bytes read per file
  (default 4 MiB for content checks). Hitting a cap adds a notice to the report.
- Honours `--exclude` globs from config. An excluded directory excludes
  everything under it, for `stat` and `read` as well as for walks.
- `File_Scanner::walk()` returns a `Walk`, which yields one entry at a time;
  there is no method that returns the whole tree. Once iterated to the end, the
  `Walk` says whether it saw everything under its directory: `incomplete_reason()`
  names a cap, an unreadable path, or a symlink that is broken or leaves the
  root, and `listing_gap()` ignores symlinks, whose paths `skipped_links()`
  lists. Checks decide between pass and skipped from their own walk, never from
  the report's notices, which are shared by every check. Excluded paths do not
  make a walk incomplete when it still yielded something, because the user chose
  them and the report lists them. A walk that yielded nothing is incomplete:
  `incomplete_reason()` says every entry was excluded or the directory is
  empty, and `listing_gap()` says so only when everything was excluded. Walking
  a directory that is itself excluded throws `Path_Unavailable_Exception` with
  that reason.
- `presence()` tells a file that is absent from one that was not examined
  (excluded, outside the root, a broken link, unreadable), so no check calls an
  unexamined file missing. The first full walk that finishes without
  hitting the entry cap is kept when it has at most 100,000 entries, and later
  walks of the tree or any directory in it replay that record in the same
  order, together with the gaps that walk met under that directory. A directory
  is replayed only when its path, spelled exactly, is its own real path; one
  reached through a symlink or a non-canonical spelling is walked afresh. Above that size, or after a capped or abandoned walk, every walk reads
  the disk again, so memory stays bounded.

## Output

- **text** (default): grouped by severity, colour when attached to a TTY, a
  summary line at the end.
- **json**: a `schema_version` field (0 until frozen at v1.0); findings include id,
  severity, title, evidence, fix, and check status counts. While the version is
  0 the shape may change in any release, so parsers should check the field.
  Non-ASCII text is written as is, except the characters in
  `Hidden_Characters` (C1 controls, the soft hyphen, zero-width and
  directional marks, line and paragraph separators, bidi controls, the word
  joiner and invisible operators, the byte order mark), which are written as
  `\u` escapes so raw JSON cannot disguise a file name. Decoding gives the
  original string. Text, Markdown and JSON use the same list.
- **markdown**: paste-ready for tickets and issues, with the text report's
  sections. Every site-derived string is escaped by `Markdown_Escape`: inline
  markup and HTML characters are backslash-escaped, leading list, heading and
  quote markers are escaped where a line starts, evidence values sit in code
  spans whose fence outlasts any backtick run inside, and each fix is a fenced
  block whose fence does the same. A bare URL, `www.` host or email address in
  prose goes in a code span too, because GitHub would otherwise turn it into a
  link.

All formatters receive data that has passed through `Redactor`, which removes
configured secret values (DB password, salts, any `*_KEY`/`*_SECRET` constant
value found in `wp-config.php`) and the database host, user and name, from
`wp-config.php` or the `--db-from-env` variables. A host's name and socket path
are masked on their own as well. Defaults that say nothing about the site
(`localhost`, `127.0.0.1`, `::1`, `[::1]`, `root`, `wordpress`) are left readable,
because masking them would garble every mention of the site root. The client
address in an "Access denied for user 'name'@'host'" message is not in
`wp-config.php`, so the driver masks it before the message leaves. Email local
parts are masked when `--redact-emails` is set.

Formatters accept only a `Redacted_Report`, whose constructor runs the
`Redactor`, so unredacted data cannot reach one. The redactor removes each secret
in its raw, URL-encoded (upper- and lowercase hex), JSON-escaped, slash-escaped,
HTML-escaped and base64 forms. Base64 covers the unpadded and URL-safe
alphabets, and a secret inside a longer encoded string such as a Basic auth
header. The mask covers the whole token around a match, up to whitespace, quotes
or the separators `= & , ; : < > ( ) [ ] { }`, so a secret that is a common word
cannot be read back from the text around the mask. Messages stay raw until
output, so redaction sees them unescaped. Text and Markdown output then pass
every site-derived string through `esc_cli()`, which makes control characters,
the invisible and reordering characters in `Hidden_Characters`, and bytes that
are not valid UTF-8 visible.

Three limits follow from the design. A secret shorter than four characters is
not redacted, because replacing every occurrence of one or two characters would
destroy the report. A secret that `wp-config.php` computes at runtime, for
example from `getenv()`, is never known to the tool and so cannot be redacted;
such a value is unresolved, the dependent checks skip, and no check prints it. Matching is exact in case: a copy of a secret in another case is not masked,
because masking case-insensitively would garble every report whose password is
a common word such as `wordpress`.

Exit codes: `0` no findings above `info`, `1` warnings present, `2` critical
findings present, `3` tool error: could not locate a site, bad arguments, or any
check that ended in `error` (even when findings exist). Skipped checks do not
change the code.

## Configuration

An optional `wp-doctor.json` passed with `--config`. A file inside the scanned
site is never read, because the site under suspicion must not be able to hide
paths from its own scan: a `--config` path that resolves inside the site root,
through a symlink or not, is refused with exit code 3, and a `wp-doctor.json`
in the site root is reported as not used.
An exclude is a glob relative to the site root. One that is absolute, climbs
out with `..`, or starts with `./` can never match a walked path, so it is
refused, from `--exclude` with exit code 3 and from the file as a config error.
Every active exclude is listed in the report's notes.

```json
{
  "thresholds": { "perf.autoload.size": { "warning": 1048576, "critical": 3145728 } },
  "ignore": ["config.xmlrpc.enabled"],
  "exclude": ["wp-content/cache/**"]
}
```

Unknown keys are an error, so typos do not silently disable a check, and a
check id that no registered check has is an error too. A key given twice in
one object is an error, naming the key, since JSON would otherwise keep the
last silently. A leading UTF-8 byte order mark is ignored. All three keys apply:

- `exclude` removes paths from every walk and is listed in the report's notes.
- `ignore` names checks whose outcomes are listed under "Ignored by
  configuration" in the text report and marked `ignored` in JSON, but never
  count towards findings, status counts or the exit code. It silences what a
  check found, not a check that failed: an ignored check that ends in an error
  is listed under "Failed to run" and still gives exit code 3.
- `thresholds` replaces a check's built-in limit. Only the checks below accept
  them, each at the levels shown (built-in limit in brackets), and a finding's
  `threshold_basis` then says which file set the value. A check with both
  levels may set just one: the other keeps its built-in limit, the basis says
  which came from where, and the warning must still be below the critical, so
  `{"perf.autoload.size": {"warning": 5242880}}` is refused because the
  built-in critical is 3145728. `"thresholds": null`, a list in place of an
  object, and a check with no level set (`{}`) are refused too.

| Check | `warning` | `critical` |
|---|---|---|
| `perf.autoload.size` | bytes of autoloaded options (1048576) | bytes (3145728) |
| `perf.autoload.orphaned` | bytes an orphaned option must exceed (10240) | |
| `perf.tables.bloat` | bytes a log or session table must exceed (104857600) | |
| `perf.transients.expired` | expired transient rows (5000) | |
| `perf.cron.duplicates` | | copies of one event (50) |
| `perf.cron.overdue` | seconds an event is late (3600) | |
| `disk.debuglog.size` | bytes (104857600) | bytes (1073741824) |
| `disk.errorlog.size` | bytes (52428800) | |
| `sec.users.admins` | | administrators registered within 24 hours (3) |

`Thresholds` holds both the units and the built-in limits, and the checks read
their limits from it, so this table has one source. Info-level rules such as
the memory limit keep their defaults.

## Network use

The only network call is to `api.wordpress.org` for core checksums (and, from
v0.2, plugin checksums at `downloads.wordpress.org`). `--offline` disables it and
the affected checks report `skipped`. The tool sends the WordPress version and
locale only.

Both transports allow HTTPS only, verify the certificate, follow no redirects
and cap the body. The request must finish within 15 seconds in total: cURL
sets `CURLOPT_TIMEOUT`, and the stream transport reads in chunks against one
deadline, since its own timeout applies to each read and a server sending a
byte every few seconds would never trip it. cURL options are set all at once,
and a refused option stops the request rather than sending it without that
guard. libcurl honours `https_proxy`, `HTTPS_PROXY`, `all_proxy` and
`ALL_PROXY` and PHP streams do not, so when one is set and cURL is loaded,
cURL is used. Without cURL the streams connect directly, and a failed
connection names the proxy variable they did not use.

## Testing strategy

- Unit tests per check with in-memory fakes of `ReadOnlyConnection` and
  `FileScanner`.
- Integration tests against a MariaDB service container seeded from
  `tests/fixtures/*.sql` (an autoload-bloated site, a site with rogue admins).
- Fixture trees under `tests/fixtures/sites/` containing planted disguised
  files and tampered core files, built from inert strings that match the
  patterns but execute nothing.
- Every check has at least one test that expects a finding and one that expects
  a pass. Tests assert that the fixture produced something to check, so they
  cannot pass vacuously.
- CI matrix across PHP 7.2 to 8.4. The quality job also runs `composer lint`
  and `composer cs` on PHP 7.2, so every file is parsed by the oldest supported
  PHP, including files no test loads.
