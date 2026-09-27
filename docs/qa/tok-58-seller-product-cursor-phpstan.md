# TOK-58 Seller Product Cursor PHPStan Debt QA

## Purpose

This document records local verification for removing two PHPStan findings from
`SellerProductCursorService::primaryValue()`. The method still uses a compact
`match` with one line per arm. Its result is documented as `float|string|null`
because the selected database values for price, normalized name, and raw
timestamp are scalar or null.

Implementation branch: `task/jd-tok-58`.

## Contract Preserved

- Price cursors still contain a float or null. Name cursors still reuse the
  database's `LOWER(products.name)` result, which can be a string or null.
- Timestamp cursors still contain the raw database string or null. The removed
  string cast did not alter the tested non-null payload value.
- Cursor criteria binding, null-last ordering, UUID tie-breaking, response
  shape, and the internal attribute hiding remain unchanged.
- The Seller Product endpoint's existing search condition excludes products
  whose name is null, even for an empty search. The nullable-name regression
  therefore exercises the cursor service directly; this task does not change
  the endpoint query or its visible product set.
- No frontend, migration, or deployment configuration change is needed.

## Baseline Reduction

| Metric | Before | After |
| --- | ---: | ---: |
| Findings | 232 | 230 |
| Baseline entries | 170 | 168 |
| Files represented | 19 | 18 |
| `SellerProductCursorService` findings | 2 | 0 |

PHPStan reported exactly the two scoped ignore patterns as unmatched after the
service change. Only those entries were removed; the baseline was not
regenerated and no ignore was added.

## Verification Status

| ID | Status | Verification | Evidence |
| --- | --- | --- | --- |
| TOK-58-BE-01 | ✅ | Establish cursor behavior before changing the service. | Existing suite passed: 7 tests, 241 assertions. The two new regression tests also passed against the old service: 9 tests, 263 assertions. |
| TOK-58-BE-02 | ✅ | Preserve nullable-name keyset pagination and raw timestamp cursor values. | Focused suite passed after the service change: 9 tests, 263 assertions. Service-level name sorting reaches all four products without duplicates in both directions and encodes a null position; endpoint tests confirm non-null raw timestamp values for `latest` and `oldest`. |
| TOK-58-BE-03 | ✅ | Check configured static analysis and remove only stale entries. | PHP 8.3 `composer analyse` first reported exactly two unmatched cursor-service entries, then passed with `[OK] No errors` after their removal. The resulting baseline has 230 findings, 168 entries, and 18 files. |
| TOK-58-BE-04 | ✅ | Check syntax and formatting without rewriting PHP source. | PHP 8.3 syntax checks passed for the service and test; scoped Pint check passed for both files. |
| TOK-58-BE-05 | ✅ | Run the complete backend suite. | PHP 8.3 `artisan test` passed: 244 tests, 3,246 assertions. |
| TOK-58-BE-06 | ✅ | Review the scoped diff and whitespace. | The service change retains the `match` and only narrows its result; the baseline removes two proven-stale entries. Final `git diff --check` passed; the new QA document has no trailing whitespace and ends with a newline. |

## Verification Boundary And Release Status

The automated feature tests use the repository's in-memory SQLite test
configuration. They verify the cursor contract and timestamp representation in
that environment; they do not establish a production runtime observation.
This page records local implementation and validation before remote release.
The test and analysis results above are the local verification snapshot. Remote
CI, pull request, deployment, and runtime evidence is tracked on TOK-58.
