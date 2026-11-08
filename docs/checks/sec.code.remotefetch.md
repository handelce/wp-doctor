# `sec.code.remotefetch`

PHP that likely downloads code from another server and runs it.

| | |
|---|---|
| Category | security |
| Severity | critical ("likely") |
| Reads | the site files |
| Since | 0.2.0 |

## What it reports

The check reads the PHP files as `sec.code.obfuscated` does: every PHP file
under the site root, as tokens, and `wp-config.php` too when it sits above the
root. It reports `eval()`, `assert()`, `create_function()`, `include` or
`require` (and their `_once` forms) whose argument comes from:

- a function that always reads from another server: `curl_exec`,
  `curl_multi_getcontent`, `download_url`, `fsockopen`,
  `stream_socket_client`, or the WordPress HTTP API (`wp_remote_get`,
  `wp_remote_post`, `wp_remote_request`, `wp_remote_retrieve_body` and the
  `wp_safe_remote_` forms);
- `file_get_contents`, `file`, `fopen`, `fread`, `fgets` or
  `stream_get_contents`, when the same argument also holds a URL literal
  (`http`, `https`, `ftp` or `ftps`) or a decoder such as `base64_decode`;
- for `include` and `require`, a URL literal alone, since PHP fetches it.

As with `sec.code.obfuscated`, these may sit in the argument itself, inside
string functions that pass their input on, or behind a variable assigned in
the same file.

PHP means `.php`, `.php3`, `.php4`, `.php5`, `.php7`, `.php8`, `.phtml` and
`.pht`. A function or method that only shares a name with these does not
count, and neither does an argument that is a comparison or a logical test,
since its value is a boolean. Each file is listed once, at its first such
place, with a count of the others.

## Why it matters

Code that runs what it downloads lets whoever controls the other server run
anything on the site, and change it at any time without touching the site's
files again. That makes it a common form of backdoor.

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
| `files[].code` | string | What runs and what feeds it, such as `eval() of wp_remote_retrieve_body(), wp_remote_get()` or `include of a URL`. |
| `files[].more` | integer | Further places found in the same file. |

## Example

From the check's tests: a `wp-config.php` that includes a URL.

```json
{
	"id": "sec.code.remotefetch",
	"severity": "critical",
	"title": "PHP files likely download code from another server and run it",
	"evidence": {
		"php_files_examined": 3,
		"files_flagged": 1,
		"scan_incomplete": false,
		"files": [
			{
				"path": "wp-config.php",
				"line": 3,
				"code": "include of a URL",
				"more": 0
			}
		]
	},
	"fix": "Open each file at the line given. Code that runs what it downloads lets whoever controls that server run anything on this site, and is a common backdoor. If you cannot account for it, treat the site as compromised: replace the file with a clean copy from where it came from, change the passwords and the keys and salts, and look for how it was added."
}
```

## When it skips

- The site root could not be walked.
- No PHP files were found, for example because every file is excluded.
- Nothing was found, but some PHP was not read in full: a file larger than 4
  MiB, a file that could not be read, or a part of the tree the walk did not
  reach. A clean result would claim more than was examined.

## False positives

- A plugin that downloads code from its vendor and runs it, such as licensed
  add-ons fetched at runtime. That is still risky, but it may be what the site
  owner agreed to.
- A file function given a decoded path that is in fact a local file. A decoder
  in the same argument counts as a sign of a download, since a URL built at
  runtime cannot always be told apart from a local path.
