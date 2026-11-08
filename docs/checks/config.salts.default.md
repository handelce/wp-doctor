# `config.salts.default`

Authentication keys and salts in `wp-config.php` that are missing, sample text, repeated or short.

| | |
|---|---|
| Category | config |
| Severity | critical; warning for short values |
| Reads | the site files |
| Since | 0.1.0 |

## What it reports

WordPress signs login cookies with eight keys and salts, from `AUTH_KEY` to
`NONCE_SALT`. The check reads each from `wp-config.php` and gives up to two
findings.

A critical finding names each one that is:

- not defined in `wp-config.php`;
- empty, or not a string;
- still the sample text `put your unique phrase here`;
- the same as one named before it.

A warning names each remaining value shorter than 32 characters, which is
likely guessable; the WordPress generator gives 64. Length is counted in
characters, or in bytes when the value is not valid UTF-8. The values are
compared and measured but never shown in the report.

## Why it matters

Anyone who knows the keys and salts can forge a login cookie for any user,
administrators included, without a password. The sample text and missing
values are public knowledge, a value repeated across constants removes part
of the secret, and a short one can be guessed.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `AUTH_KEY` | string | What is wrong with `AUTH_KEY`: `not defined in wp-config.php`, `empty`, `still the sample placeholder text` or `same value as` another constant in the critical finding, or its length, such as `9 characters, under 32`, in the warning. Present only when it has a problem. The other seven keys work the same way. |
| `SECURE_AUTH_KEY` | string | What is wrong with `SECURE_AUTH_KEY`. Present only when it has a problem. |
| `LOGGED_IN_KEY` | string | What is wrong with `LOGGED_IN_KEY`. Present only when it has a problem. |
| `NONCE_KEY` | string | What is wrong with `NONCE_KEY`. Present only when it has a problem. |
| `AUTH_SALT` | string | What is wrong with `AUTH_SALT`. Present only when it has a problem. |
| `SECURE_AUTH_SALT` | string | What is wrong with `SECURE_AUTH_SALT`. Present only when it has a problem. |
| `LOGGED_IN_SALT` | string | What is wrong with `LOGGED_IN_SALT`. Present only when it has a problem. |
| `NONCE_SALT` | string | What is wrong with `NONCE_SALT`. Present only when it has a problem. |
| `not_read` | string | The constants whose values could not be read, separated by commas. Present only when some could not be, in the critical finding when there is one and otherwise in the warning. |

## Example

From the check's tests: eight distinct values of nine characters each.

```json
{
	"id": "config.salts.default",
	"severity": "warning",
	"title": "Authentication keys or salts are likely too short to be secret",
	"evidence": {
		"AUTH_KEY": "9 characters, under 32",
		"SECURE_AUTH_KEY": "9 characters, under 32",
		"LOGGED_IN_KEY": "9 characters, under 32",
		"NONCE_KEY": "9 characters, under 32",
		"AUTH_SALT": "9 characters, under 32",
		"SECURE_AUTH_SALT": "9 characters, under 32",
		"LOGGED_IN_SALT": "9 characters, under 32",
		"NONCE_SALT": "9 characters, under 32"
	},
	"fix": "Generate fresh random values at https://api.wordpress.org/secret-key/1.1/salt/ and paste all eight define() lines over the old ones in wp-config.php. Everyone is logged out once when you do."
}
```

## When it skips

- Nothing was found wrong with the values that were read, but some could not
  be read: they are computed, such as from `getenv()`, or defined
  conditionally, or they are not defined in `wp-config.php` while the file
  includes another file or calls `eval()`, which could define them. In that
  last case a missing constant is not reported as missing.

## False positives

- A site that sets the keys in a file `wp-config.php` does not include, such
  as one loaded by a host's own bootstrap, so they look missing.
- A short value that is still random enough, such as one chosen by hand with
  care. The warning says "likely" for this reason.
