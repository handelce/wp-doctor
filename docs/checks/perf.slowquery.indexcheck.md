# `perf.slowquery.indexcheck`

Joins in supplied queries that likely compare columns of different types or collations, so the server cannot use an index.

| | |
|---|---|
| Category | performance |
| Severity | warning ("likely") |
| Reads | the database |
| Since | 0.3.0 |

## What it reports

The check runs only when `--queries=FILE` names a file of `SELECT` statements,
one per line. Blank lines and lines starting with `#` are passed over, and the
file may hold up to 500 statements and 1 MiB. It must lie outside the scanned
site. For each statement the check:

1. runs `EXPLAIN <statement>` through the read-only gate, which refuses
   anything but a `SELECT` and never runs the statement itself;
2. reads the tables named after `FROM` and `JOIN`, with their aliases, and
   each condition `a.x = b.y` between columns of two of them;
3. looks up both columns' types and collations in
   `information_schema.columns`.

A join is reported when a number column meets a text column, or two text
columns have different collations, and `EXPLAIN` shows either table read in
full: access type `ALL` or `index`, or no key. When the server refuses the
statement for an illegal mix of collations (error 1267), the joins between
columns of different collations are reported with that refusal as the plan.

The statements are read as text, not parsed, so derived tables and other
unusual forms are passed over. The report names each statement by its line
number and shows the joins, never the statement.

## Why it matters

When the two sides of a join only compare after one is converted, the server
cannot look rows up through an index on the converted side. It reads that
table in full instead, which gets slower as the table grows. A common case is
a plugin table that stores a post ID as text and joins it to the numeric ID of
the posts table.

## Evidence

| Key | Type | Meaning |
|---|---|---|
| `statements_given` | integer | Statements in the file. |
| `statements_examined` | integer | Statements the server explained, or refused only for the mix of collations. |
| `joins_flagged` | integer | Joins reported, across all statements. |
| `scan_incomplete` | boolean | True when some statements could not be examined. |
| `not_examined` | string | The statements that could not be examined, by line, with the reason: refused by the read-only gate, or `EXPLAIN` failed with the server's error number. At most five are named. Present only when `scan_incomplete` is true. |
| `joins` | list of objects | The joins reported, in the order of the file, at most 20. |
| `joins[].line` | integer | Line of the file that holds the statement. |
| `joins[].join` | string | Both columns as `table.column (type, collation)`, named by table rather than alias. |
| `joins[].plan` | string | The tables `EXPLAIN` reads in full, with their alias and access type, or that the server refuses the statement for an illegal mix of collations. |

## Example

From the integration tests: six statements against tables whose join columns
differ, one of them a `DELETE` and one malformed.

```json
{
	"id": "perf.slowquery.indexcheck",
	"severity": "warning",
	"title": "Queries likely join columns that cannot share an index",
	"evidence": {
		"statements_given": 6,
		"statements_examined": 4,
		"joins_flagged": 3,
		"scan_incomplete": true,
		"not_examined": "line 5: refused by the read-only gate (only SELECT, SHOW, DESCRIBE of a table and EXPLAIN SELECT are allowed); line 6: EXPLAIN failed with error 1064",
		"joins": [
			{
				"line": 1,
				"join": "wptest_order_items.order_ref (varchar, utf8mb4_unicode_ci) = wptest_orders.ref (varchar, utf8mb4_general_ci)",
				"plan": "the server refuses the statement: illegal mix of collations (error 1267)"
			},
			{
				"line": 2,
				"join": "wptest_legacy_refunds.order_ref (varchar, utf8mb3_general_ci) = wptest_order_items.order_ref (varchar, utf8mb4_unicode_ci)",
				"plan": "EXPLAIN reads in full: wptest_legacy_refunds (r), type INDEX; wptest_order_items (i), type INDEX"
			}
		]
	},
	"fix": "Give both columns of each join the same type, and for text the same collation, so the server can use an index. After a backup, change the column that differs, for example `ALTER TABLE <table> MODIFY <column> BIGINT UNSIGNED NOT NULL` or `ALTER TABLE <table> CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci`. If the table belongs to a plugin, report it to the plugin's author instead."
}
```

## When it skips

- No file was given with `--queries`.
- There is no database access.
- No join was reported, but a statement was refused by the read-only gate,
  could not be explained, or was refused for a mix of collations in a form
  whose joins were not read.

## False positives

- A full scan of a small table is cheap. The server may read a table of a few
  rows in full whatever the column types, and the finding then points at a
  mismatch that costs little.
- The full scan may have another cause, such as a missing index or a query
  with no filter. The check reports a mismatch whenever either table of the
  join is read in full, without proving that the mismatch is the reason.
