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

PHP 7.2 or newer is supported. Write code that parses on 7.2: no typed
properties, arrow functions, `match`, `?->`, or named arguments.

## Conventions

**Code.** The WordPress coding standards apply, through `phpcs.xml.dist`: tabs,
snake_case functions and variables, `Capitalized_Words` classes, long `array()`
syntax and Yoda conditions. Namespaces start with `WP_Doctor` and `src/`
mirrors them. Class files are `class-<name>.php` or `interface-<name>.php`;
test files are named after their class, such as `Admin_Users_Check_Test.php`.
Site data in an exception message goes through `esc_cli()`.

**Doc comments.** Every file, class, method, property and documented constant
in `src/`, `bin/` and `tools/` has a docblock with a summary and an
`@since` tag placed after the summary, as WordPress core does. New code takes
the version it will first ship in; existing tags never change. A parameter
description says something its type does not.

**Comments.** Describe the code as it is. Write one for a constraint, an
ordering or a trap the code cannot show, and keep it shorter than the code it
explains. History, dates and issue numbers belong in commit messages.

**Tests.** Name a test after the behaviour it checks, such as
`test_a_warning_is_raised_above_the_autoload_threshold`, and make it assert
that it found something to examine, so it cannot pass on an empty fixture.

**Commits.** One short imperative subject line, such as "Add the cache size
check".

**Changelog.** Add user-facing changes under `## [Unreleased]` in
`CHANGELOG.md`, in the Added, Changed or Fixed list.

## Adding a check

1. Open an issue with the "Propose a new check" template, or pick an unclaimed
   row in `docs/CHECKS.md`.
2. Create `src/Check/Category/<Area>/class-<name>-check.php` implementing `Check`.
3. Return `Result::pass()`, `Result::findings(...)`, or `Result::skipped($reason)`.
4. Every `Finding` needs evidence and a fix the user can act on.
5. Add the row to `docs/CHECKS.md`.
6. Add tests: a fixture that triggers the finding, a fixture that passes, and an
   assertion that the fixture held something to examine.
7. Register the check in `Check_Registry`.

## Rules every change must keep

- Read-only: checks use `ReadOnlyConnection` and `FileScanner` only.
- Site code is never executed or included.
- No secrets in output, logs, or tests.
- No real customer, company, or host names in fixtures or docs.

## Pull requests

Keep them small and focused. `composer test`, `composer analyse`, and
`composer cs` must pass.

## Releasing

1. Set `Application::VERSION`, and turn the `[Unreleased]` section of
   `CHANGELOG.md` into a dated section for the version.
2. Once CI passes on that commit, tag it `vX.Y.Z` and push the tag.

The Release workflow builds `wp-doctor.phar`, runs it on PHP 7.2 and 8.4, and
publishes the release with the phar, its SHA-256 checksum, and the changelog
section as notes. It refuses a tag that does not match `Application::VERSION`.
To build the phar locally, run `composer build`; it is written to `build/`.

## Reporting a vulnerability

See `SECURITY.md`. Do not open a public issue for security problems.
