# Contributing

Thanks for helping. The easiest contribution is a new check.

## Setup

```
git clone https://github.com/handelce/wp-doctor.git
cd wp-doctor
composer install
composer test
```

Integration tests need a MySQL or MariaDB server they may create databases on.
Set `WPD_TEST_DB_HOST` (and `WPD_TEST_DB_USER`, `WPD_TEST_DB_PASSWORD`) to run
them; without it they are skipped, and `WPD_REQUIRE_DB=1` makes that a failure.

Code follows the WordPress coding standards; `composer cs` reads
`phpcs.xml.dist`.

PHP 7.2 or newer is supported. Write code that parses on 7.2: no typed
properties, arrow functions, `match`, `?->`, or named arguments.

## Adding a check

1. Open an issue with the "Propose a new check" template, or pick an unclaimed
   row in `docs/CHECKS.md`.
2. Create `src/Check/<Category>/class-<name>-check.php` implementing `Check`.
3. Return `Result::pass()`, `Result::findings(...)`, or `Result::skipped($reason)`.
4. Every `Finding` needs evidence and a fix the user can act on.
5. Add the row to `docs/CHECKS.md`.
6. Add tests: a fixture that triggers the finding, a fixture that passes, and an
   assertion that the fixture held something to examine.
7. Register the check in `CheckRegistry`.

## Rules every change must keep

- Read-only: checks use `ReadOnlyConnection` and `FileScanner` only.
- Site code is never executed or included.
- No secrets in output, logs, or tests.
- No real customer, company, or host names in fixtures or docs.

## Pull requests

Keep them small and focused. `composer test`, `composer analyse`, and
`composer cs` must pass.

## Releasing

1. Set `Application::VERSION` and add a section for the version to
   `CHANGELOG.md`.
2. Tag the commit `vX.Y.Z` and push the tag.

The Release workflow builds `wp-doctor.phar`, runs it on PHP 7.2 and 8.4, and
publishes the release with the phar, its SHA-256 checksum, and the changelog
section as notes. It refuses a tag that does not match `Application::VERSION`.
To build the phar locally, run `composer build`; it is written to `build/`.

## Reporting a vulnerability

See `SECURITY.md`. Do not open a public issue for security problems.
