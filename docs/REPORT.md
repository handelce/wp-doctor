# The JSON report

`wp-doctor scan <path> --format=json` prints one JSON document. Its shape is
version 1, given in `schema_version`, and is described exactly by
[report.schema.json](report.schema.json) (JSON Schema, draft-07). The test suite
validates real scans of every fixture site, and sample reports, against it.

## What version 1 promises

- Fields are not removed or renamed, and their types do not change, while
  `schema_version` is 1. A change that would break a parser raises it.
- New fields can appear, at the top level, in `summary`, in a check's entry,
  and in a finding's `evidence`. Ignore fields you do not know.
- New checks can appear, and a check can gain findings. Read the check id,
  not the position.
- Evidence keys are part of the promise in the same way: a check does not
  remove or rename an evidence key it gives in a 1.x release.

## The document

```json
{
	"schema_version": 1,
	"tool": { "name": "wp-doctor", "version": "1.0.0" },
	"site": { "wp_version": "6.5.2" },
	"summary": {
		"findings": { "critical": 1, "warning": 1, "info": 0 },
		"checks": { "pass": 30, "findings": 2, "skipped": 11, "error": 0, "ignored": 0 },
		"exit_code": 2
	},
	"findings": [
		{
			"id": "sec.plugins.unknown",
			"severity": "warning",
			"title": "PHP in the plugins directory likely belongs to no plugin",
			"evidence": {
				"directory": "wp-content/plugins",
				"flagged": 1,
				"scan_incomplete": false,
				"entries": [
					{
						"path": "wp-content/plugins/wp-zxq",
						"reason": "folder with PHP but no file with a Plugin Name header",
						"php_files": 1,
						"php_newest": "2026-11-08 13:16 UTC"
					}
				]
			},
			"fix": "Look at each entry listed. ..."
		}
	],
	"checks": [
		{ "id": "config.debug.enabled", "category": "config", "status": "pass" },
		{ "id": "sec.core.checksums", "category": "security", "status": "skipped", "reason": "--offline was given, so wordpress.org was not contacted" }
	],
	"notices": []
}
```

- `summary.exit_code` is the process exit code: 0 nothing above info, 1
  warnings, 2 critical findings, 3 a tool error.
- `findings` holds the findings of checks that are not silenced, most severe
  first. A check silenced with `ignore` in `wp-doctor.json` has
  `"ignored": true` in its entry in `checks`, with its findings beside it, and
  is not counted in `summary`.
- `checks` lists every check that ran. A skipped check has a `reason`, and a
  check that failed to run has a `message`.
- `site.wp_version` is null when `wp-includes/version.php` could not be read.

## Evidence

Each finding's `evidence` is an object whose keys are fixed names chosen by the
check. Names and paths from the scanned site are only ever values, never keys.
A value is one of:

- a string, number or boolean, such as `"flagged": 1`;
- a list of strings, numbers or booleans, such as `"plugins": ["akismet"]`;
- a list of objects whose values are strings, numbers or booleans, such as
  `"files": [{"path": "backup.sql", "bytes": 54}]`.

A list may be empty, holds at most 20 items, and keeps the order the check
gives; a count beside it, such as `"matching_files": 31`, says how many there
were in all. Lists do not nest. [CHECKS.md](CHECKS.md) describes what each
check reports.

Secrets read from `wp-config.php` are replaced with `[redacted]` in every
string of the document, including inside lists. Characters that can disguise
text, such as right-to-left marks, are written as `\u` escapes.
