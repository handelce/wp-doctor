# Data sources

Values built into wp-doctor that come from outside this repository, and where
to re-read them when they change.

## PHP end-of-life dates (`config.php.version`)

Source: `include/branches.inc` and `include/releases.inc` in the `php/web-php`
repository, which generate php.net/supported-versions.php.

- Security support ends at the initial release date plus 3 years for branches
  before 8.1.
- From 8.1 it ends at the initial release date plus 4 years, rounded up to 31
  December.
- `branches.inc` overrides 5.6 (2018-12-31) and 7.0 (2019-01-10).

| Branch | Initial release | Security support ends |
|---|---|---|
| 7.1 | 2016-12-01 | 2019-12-01 |
| 7.2 | 2017-11-30 | 2020-11-30 |
| 7.3 | 2018-12-06 | 2021-12-06 |
| 7.4 | 2019-11-28 | 2022-11-28 |
| 8.0 | 2020-11-26 | 2023-11-26 |
| 8.1 | 2021-11-25 | 2025-12-31 |
| 8.2 | 2022-12-08 | 2026-12-31 |
| 8.3 | 2023-11-23 | 2027-12-31 |
| 8.4 | 2024-11-21 | 2028-12-31 |
| 8.5 | 2025-11-20 | 2029-12-31 |

Add a row to `END_OF_LIFE` when a new branch is released.

## Autoload values (`perf.autoload.*`)

Source: `wp_autoload_values_to_autoload()` in `wp-includes/option.php` in
WordPress core. The values loaded on every request are `yes`, `on`, `auto-on`
and `auto`. `auto-off` and `off` are not loaded.

## WP-CLI commands in fix text

Source: the README of each WP-CLI package.

| Command | Package |
|---|---|
| `wp cron event delete`, `wp cron event run --due-now` | `wp-cli/cron-command` |
| `wp option delete` | `wp-cli/entity-command` |
| `wp plugin delete`, `wp plugin update --all` | `wp-cli/extension-command` |
| `wp transient delete --expired [--network]` | `wp-cli/cache-command` |
| `wp core download --force --skip-content --version=`, `wp core update` | `wp-cli/core-command` |

## Core option prefixes (`perf.autoload.orphaned`)

Source: option names created by `populate_options()` in
`wp-admin/includes/schema.php`, plus the options named in the core files that
use them:

- `cron`: `wp-includes/cron.php`
- `sidebars_widgets`: `wp-includes/widgets.php`
- `theme_mods_*` and `current_theme`: `wp-includes/theme.php`
- `nav_menu_options`: `wp-includes/nav-menu.php`
- `fresh_site`: `wp-admin/includes/upgrade.php`

Core options whose first segment is not in `CORE_PREFIXES` exist, for example
`blogname`, `siteurl` and `permalink_structure`. None of them is normally
larger than the 10 KiB threshold.

## Package locale (`sec.core.checksums`)

Translated WordPress packages record their locale in `wp-includes/version.php`
as `$wp_local_package`; the US English package has no such line. The checksum
list for a locale differs from the English one in `wp-includes/version.php`
and `wp-config-sample.php`, so the locale must match the installed package.
`WPLANG` is not used: it changes the display language, not the files on disk.
Locale variants such as `de_DE_formal` are valid lookups.

## Administrator account indicators (`sec.users.admins`)

Source: `data/rogue-admin-indicators.json`. Each name entry carries the public
report it comes from, so an entry can be checked or retired without touching
code. Fields:

- `login`: the lowercase login, compared exactly or as a prefix (`match`).
- `signal`: `strong` entries are reported on their own as critical. `soft`
  entries are reported as critical only together with their documented
  `email`, and otherwise as a warning when the account also shows two signs of
  programmatic creation.
- `campaign` and `source`: what the report described and where it is.

| Login | Match | Report |
|---|---|---|
| `wpenginer`, `wpadmins`, `wpengine_backup`, `se_brutal`, `segs_brutal` | exact, strong | Wordfence, "PSA: Unpatched Critical Privilege Escalation Vulnerability in Ultimate Member Plugin Being Actively Exploited" (2023). The Hacker News and WPSec list slightly different sets; Wordfence's five are used |
| `100010010` | exact, strong | Security Affairs, "Experts observed a new wave of wp-vcd malware attacks targeting WordPress sites" (2017); Sucuri's WP-VCD analysis names the same login |
| `mr_administartor` | exact, strong | Sucuri, "Malicious WordPress Plugin Creates Hidden Admin User Backdoor" (2025) |
| `xtw` | prefix, soft | WPScan, "New Malware Campaign Targets WP-Automatic Plugin" (2024), which lists "Administrator user with name starting with xtw" as an indicator; BleepingComputer and Censys repeat it. No source documents the suffix, so a short prefix that real names share is soft, not strong |
| `wpsvc_`, `wp_maint_` | prefix, strong | Nextend, "WordPress security advisory: Smart Slider 3 Pro 3.5.1.35 compromise" (2026) |
| `adminbackup` with `adminbackup@wordpress.org` | exact, soft | Sucuri, "Unauthorized Admin User Created via Disguised WordPress Plugin" (2025) |
| `help` | exact, soft | Sucuri, "Hidden WordPress Backdoors Creating Admin Accounts" (2025) |

Names reported in research but left out until the report is read: `wp_update-`
prefix (Sucuri 2023 threat report), `greeceman` (Balada Injector write-ups),
`wpsupp-user` and `wp-configuser` (LiteSpeed Cache exploitation, 2024).

`allowlist`: logins never judged. `wpengine` is the administrator WP Engine
creates on sites it hosts for support access. No WP Engine support article
stating this was found; third-party migration guides describe it, and both
research files named it. Logins in the allowlist still appear in the
administrator list.

`placeholder_domains` and `placeholder_tlds`: reserved names from RFC 2606 and
RFC 6761 (`example.com`, `.test`, `.invalid`, `.localhost`, `.example`) plus
`.local` and `.localdomain`, which WordPress's own forms would accept but no
real mailbox uses.

`mail_providers`: ordinary mail services that the upstream disposable list
includes. They are removed from that list when it is loaded, so a refresh does
not bring them back. `21cn.com` is a public mail service run by a large
Chinese telecom operator.

## Disposable email domains (`sec.users.admins`)

Source: `disposable_email_blocklist.conf` in the `disposable-email-domains`
project on GitHub, licensed CC0 1.0 (public domain dedication). The header of
`data/disposable-email-domains.txt` records the upstream commit, its date and
the domain count. Refresh it with:

```
php tools/update-disposable-domains.php
```

The script pins the newest upstream commit, refuses any line that is not a
domain name, and refuses a list under 1000 entries. The project's "strict"
variant is not used because it includes ordinary free-mail providers. A match
is reported as a warning worded "likely" because list membership goes stale in
both directions.

