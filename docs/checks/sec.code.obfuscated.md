# `sec.code.obfuscated`

PHP that likely decodes a hidden string and runs it.

| | |
|---|---|
| Category | security |
| Severity | warning ("likely") |
| Reads | the site files |
| Since | 0.2.0 |

## What it reports

The check reads every PHP file under the site root as tokens, never running
it, and `wp-config.php` too when it sits above the root. It reports `eval()`,
`assert()` or `create_function()` whose argument comes from:

- a decoder: `base64_decode`, `gzinflate`, `gzuncompress`, `gzdecode`,
  `str_rot13`, `strrev`, `hex2bin` or `convert_uudecode`;
- a string literal of 200 or more base64 or hex characters, or one holding 50
  or more `\x` escapes.

The decoder or string may sit in the argument itself, inside string functions
that pass their input on (such as `str_replace`, `trim`, `implode` or
`sprintf`), or behind a variable assigned in the same file, followed through a
few assignments. A variable passed through any other function call or a method
is not followed, since that call returns something else.

PHP means `.php`, `.php3`, `.php4`, `.php5`, `.php7`, `.php8`, `.phtml` and
`.pht`. A function or method that only shares a name with these does not
count, and neither does an argument that is a comparison or a logical test,
since its value is a boolean. Each file is listed once, at its first such
place, with a count of the others.

## Why it matters

Injected code is usually packed so that it cannot be read or searched for, and
running a decoded string is the commonest way to unpack it. Plugins and themes
seldom need it, so it is a strong sign that a file was changed by someone
else.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `php_files_examined` | integer | PHP files read, in full or in part. |
| `files_flagged` | integer | Files reported. |
| `scan_incomplete` | boolean | True when some PHP was not read in full. |
| `not_examined` | string | What was not read: files larger than 4 MiB that were read only in part (up to five named), files that could not be read, or parts of the tree the walk did not reach, such as symlinks that were not followed or a walk that stopped at `--max-files`. Present only when `scan_incomplete` is true. |
| `files` | list of objects | The files reported, in path order, at most 20. |
| `files[].path` | string | The file, relative to the site root. A `wp-config.php` above the root is `../wp-config.php`. |
| `files[].line` | integer | Line of the first place in the file that was found. |
| `files[].code` | string | What runs and what feeds it, such as `eval() of base64_decode(), gzinflate()` or `eval() of a long encoded string`. |
| `files[].more` | integer | Further places found in the same file. |

## Example

From the check's tests: a planted decoder in `a.php`, beside a plugin file too
large to read in full.

```json
{
	"id": "sec.code.obfuscated",
	"severity": "warning",
	"title": "PHP files likely run obfuscated code",
	"evidence": {
		"php_files_examined": 5,
		"files_flagged": 1,
		"scan_incomplete": true,
		"not_examined": "1 PHP file(s) larger than 4194304 bytes were read only in part (wp-content/plugins/example/big.php)",
		"files": [
			{
				"path": "a.php",
				"line": 2,
				"code": "eval() of base64_decode()",
				"more": 0
			}
		]
	},
	"fix": "Open each file at the line given. Code that decodes a hidden string and runs it is a common form of injected malware, and plugins and themes seldom need it. Compare the file with a clean copy from where it came from: WordPress itself, or the plugin or theme download. If the code is not in the clean copy, treat the site as compromised: replace the file, change the passwords and the keys and salts, and look for how it was added."
}
```

## When it skips

- The site root could not be walked.
- No PHP files were found, for example because every file is excluded.
- Nothing was found, but some PHP was not read in full: a file larger than 4
  MiB, a file that could not be read, or a part of the tree the walk did not
  reach. A clean result would claim more than was examined.

## False positives

- A commercial plugin or theme that ships its code encoded, to protect a
  licence, and decodes it when it runs.
- A plugin that stores generated PHP, such as compiled templates, in encoded
  form and runs it with `eval()`.
- The test files or sample sets of a security plugin, which hold packed code
  to check their own scanner against.
