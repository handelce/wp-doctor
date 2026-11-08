# `sec.users.admins`

Administrator accounts, and those among them that were likely created by an attacker.

| | |
|---|---|
| Category | security |
| Severity | info; warning; critical |
| Reads | the database |
| Since | 0.1.0 |

## What it reports

The check reads the accounts whose `<prefix>capabilities` user meta holds the
`administrator` role: the newest 200 by registration date, each with the
number of posts it authored. It always raises an info finding that lists
them (rule `administrator_list`), then judges them by these rules:

- `registration_cluster`, critical: 3 or more administrators registered
  within one 24-hour window. An account whose registration date is not a
  valid date is never part of a cluster. The size is the check's `critical`
  threshold, 3 by default. Change it in a `wp-doctor.json` passed with
  `--config`, for example
  `{"thresholds": {"sec.users.admins": {"critical": 5}}}`.
- `name_indicator`, critical: the login matches a strong indicator from
  `data/rogue-admin-indicators.json`, exactly or as a prefix, in any case, or
  matches a soft indicator together with the email address its report gives.
- `soft_indicator`, warning: the login matches a soft indicator, and the
  account is both the newest administrator and has no posts.
- `email_address`, warning: the email address is empty, is not a valid
  address, has a placeholder domain (`example.com`, `example.net`,
  `example.org`, `localhost`, a name without a dot, or one ending in
  `.example`, `.invalid`, `.local`, `.localdomain`, `.localhost` or `.test`),
  or has a disposable-mail domain from `data/disposable-email-domains.txt`, or
  a subdomain of one.
- `odd_one_out`, info: among 3 or more administrators whose email domains
  fall into exactly two groups, the one account alone on its domain is also
  the newest and has no posts.

Logins in the allowlist, such as `wpengine`, are listed but never judged by
any rule. The display name and website are not judged, since an account added
in Users > Add New has the login as display name and no website.
`docs/DATA-SOURCES.md` lists each indicator and the report it comes from.

## Why it matters

An administrator account is the usual way an attacker keeps control of a
site after exploiting a flaw, because it survives a plugin update and a
clean-up of the files. Exploits and malware campaigns tend to create
accounts with the same names, several at once, or with throwaway addresses,
which is what these rules look for.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `rule` | string | The rule that raised the finding: `administrator_list`, `registration_cluster`, `name_indicator`, `soft_indicator`, `email_address` or `odd_one_out`. |
| `administrators` | integer | On the list finding, the administrators read. On the cluster finding, the accounts in the cluster. |
| `list_truncated` | boolean | On the list finding, true when there are more than 200 administrators and the oldest were left out. Always false on the cluster finding. |
| `admins` | list of objects | The accounts, at most 20. The list and cluster findings give `login`, `registered`, `email` and `posts`; the other rules give `login`, `email`, `registered` and `reason`, with `campaign` and `source` for a name match. |
| `admins[].login` | string | The login. |
| `admins[].registered` | string | The `user_registered` value, in UTC, or `unknown` when it is not a valid date. |
| `admins[].email` | string | The email address as stored. |
| `admins[].posts` | integer | Rows in the posts table the account authored, of any type. On the list and cluster findings only. |
| `admins[].reason` | string | What matched. On the `name_indicator`, `soft_indicator`, `email_address` and `odd_one_out` findings. |
| `admins[].campaign` | string | What the report behind the indicator described. On the `name_indicator` and `soft_indicator` findings. |
| `admins[].source` | string | Where that report is published. On the `name_indicator` and `soft_indicator` findings. |
| `cluster_size` | integer | Accounts in the cluster. On the cluster finding only. |
| `cluster_rule` | string | The rule applied, such as `3 or more within 86400 seconds`. On the cluster finding only. |
| `threshold_basis` | string | Whether the cluster size is the built-in default or was set in a config file, which it names. On the cluster finding only. |
| `possible_cause` | string | Always the same note: an import or a site move gives accounts the same registration time. On the cluster finding only. |
| `matches` | integer | Accounts matched. On the `name_indicator`, `soft_indicator`, `email_address` and `odd_one_out` findings. |
| `indicator_basis` | string | Always the same note: the indicators come from the published reports listed in `docs/DATA-SOURCES.md`, and a match means likely, not proven. On the same findings as `matches`. |

## Example

From the check's tests: two administrators registered an hour apart, with the
cluster size lowered to 2 in a config file.

```json
{
	"id": "sec.users.admins",
	"severity": "critical",
	"title": "Several administrator accounts were likely created within a day of each other",
	"evidence": {
		"rule": "registration_cluster",
		"cluster_size": 2,
		"cluster_rule": "2 or more within 86400 seconds",
		"threshold_basis": "set in custom.json",
		"possible_cause": "an import or migration (the WordPress Importer, wp user import-csv, a site move) gives accounts the same registration time",
		"administrators": 2,
		"list_truncated": false,
		"admins": [
			{
				"login": "b",
				"registered": "2024-05-01 02:00:00",
				"email": "b@example.test",
				"posts": 0
			},
			{
				"login": "a",
				"registered": "2024-05-01 01:00:00",
				"email": "a@example.test",
				"posts": 0
			}
		]
	},
	"fix": "First check whether these accounts came from an import or a site move on that date. If not, and you did not create them, treat the site as compromised: remove them, change every administrator password and the keys and salts, and look for how the accounts were created (access logs, plugin and theme changes)."
}
```

## When it skips

- There is no database connection, or the table prefix could not be read.
- No administrator accounts were found, which usually means the table prefix
  or the database is wrong.

A row from the database without the expected fields stops the check with an
error rather than a skip.

## False positives

- An import, a site move or a staging copy that gave several accounts the
  same registration time, or a team given accounts on the same day. The
  cluster finding says so.
- A real person whose login equals a soft indicator, such as `help`, and who
  was added most recently and has not written anything yet.
- A placeholder address on a development or staging site, or on a test
  account such as one at `.test` or `.local`.
- A contractor or agency account added recently on its own domain, which is
  exactly the `odd_one_out` pattern. The fix text says so.
- A disposable-mail list that is out of date, so that a domain that changed
  hands is still listed.
