# TOK-57 Seller Dashboard PHPStan Debt QA

## Purpose

This document records the local verification for removing eight PHPStan findings
from `SellerDashboardService`. The change describes the dashboard response with
accurate PHPDoc types and narrows two aliases selected by the recent-transactions
query. It does not change the seller dashboard queries, metric rules, or public
`GET /api/dashboard` response.

Implementation branch: `task/jd-tok-57`.

## Contract Preserved

- The dashboard still returns `summary`, `performance`, `recent_transactions`,
  and `product_snapshot` for the authenticated seller.
- Summary and performance keep their existing paid/completed transaction rules;
  product snapshot counts use the same stock and date filters.
- Recent transactions remain seller-scoped, newest-first, and limited to five.
  Buyer name and invoice status still come from the existing inner joins.
- `buyer_name` is a non-null string because the joined `users.name` column is
  required. `invoice_status`, seller transaction `status`, and
  `transaction_number` remain nullable according to their database columns.
- The mapped recent-transaction rows contain only scalar or null values.
  Retrieving them with `all()` preserves the response data while retaining the
  mapped item type for PHPStan.
- No model-wide property annotation, new ignore rule, query rewrite, migration,
  frontend change, or deployment configuration change was needed.

## Baseline Reduction

| Metric | Before | After |
| --- | ---: | ---: |
| Findings | 240 | 232 |
| Baseline entries | 178 | 170 |
| Files represented | 20 | 19 |
| `SellerDashboardService` findings | 8 | 0 |

The eight removed entries represented five missing iterable value types, two
joined aliases not known as model-wide properties, and one unresolvable
Eloquent collection `map()` type. PHPStan reported each entry as unmatched
after the corresponding service change; the baseline was not regenerated.

## Verification Status

| ID | Status | Verification | Evidence |
| --- | --- | --- | --- |
| TOK-57-BE-01 | ✅ | Establish dashboard response behavior before editing the service. | The new focused suite passed against the existing service after correcting numeric JSON assertions: 3 tests, 40 assertions. |
| TOK-57-BE-02 | ✅ | Check the final response, joined aliases, nullable fields, seller scope, five-row limit, and metric rules. | `SellerDashboardTest` passed: 3 tests, 41 assertions. The fixture includes null invoice status and null transaction number. |
| TOK-57-BE-03 | ✅ | Verify PHPStan at the configured `app/` scope with stale-baseline reporting enabled. | PHP 8.3 `composer analyse` completed with `[OK] No errors`; the eight scoped entries were removed without new ignores. |
| TOK-57-BE-04 | ✅ | Check PHP syntax and formatting without rewriting source files. | PHP 8.3 syntax checks passed for the service and test. Scoped Pint check passed for 2 files; repository-wide Pint check passed for 179 files. |
| TOK-57-BE-05 | ✅ | Run the complete backend test suite. | PHP 8.3 `artisan test` passed: 242 tests, 3,224 assertions. |
| TOK-57-BE-06 | ✅ | Review the working diff and check whitespace. | Service and baseline diffs contain only the scoped contract changes and eight entry removals. `git diff --check` passed; the new test and QA document have no trailing whitespace and end with newlines. |
| TOK-57-BE-07 | ✅ | Observe the seller dashboard in the local browser. | The provided screenshots show `GET /api/dashboard` returning `200 OK`, the summary and recent transactions rendering, and no console errors. The screenshots do not establish which backend commit served the request. |

## Release Evidence

This page records local verification. Record task-branch CI, the staging pull
request, deployment workflow, health checks, and staging browser observations
on Jira TOK-57 as they complete. Do not infer staging readiness from the local
browser screenshots alone.
