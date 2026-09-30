# TOK-64 Transaction PHPStan and Filter Validation QA

## Purpose

This task resolves the 20 PHPStan findings in `TransactionService` and validates
filter types at `GET /api/transaction`. Transaction reads retain ownership,
query structure, invoice grouping, amounts, dates, and batch product loading.

Jira: [TOK-64](https://muhammadjidan-31088882.atlassian.net/browse/TOK-64).
Implementation branch: `task/jd-tok-64`.

## Contract

- Authentication and local user existence checks precede filter validation.
- Text filters accept nullable strings; pagination accepts nullable numeric
  values, including numeric strings and decimals. Compound values and incorrect
  scalar types return `422` with `status: error` and field errors under `message`
  before the transaction read service is called.
- Optional missing, null, and middleware-normalized empty fields retain existing
  defaults. Missing or unsupported string user perspectives retain the existing
  `400` business error. Unknown status and sort strings and invalid-format string
  dates retain the service's fallbacks.
- Page and page-size integer casts and bounds remain in the service. Pending
  buyer invoices retain their non-paginated action queue.
- Regular and single-store pending rows remain `TransactionUser` models.
  Multi-store pending rows remain objects with all matching invoice packages,
  including packages that did not individually match the search keyword.
- Joined attributes are accessed locally through Eloquent attribute methods;
  no model-wide declarations, DTO conversion, or persistence change is added.
  PHPDoc describes concrete collection items and success/error result shapes.
- Nullable expiry values retain Carbon's existing parsing behavior. Seller
  results do not expose buyer virtual account numbers. Product loading remains
  one batch query for the displayed rows.
- Approval, balance transfer, checkout, migrations, frontend, and deployment
  configuration are unchanged.

## Baseline Reduction

| Metric | Before | After |
| --- | ---: | ---: |
| Findings | 191 | 169 |
| Baseline entries | 139 | 124 |
| Files represented | 11 | 10 |
| TransactionService findings | 20 | 0 |
| TransactionController findings | 5 | 3 |

PHPStan first reported all 15 service baseline entries as unmatched. It also
proved that the controller's mixed-user ID and mixed-user perspective findings
each fell from two occurrences to one. Only those entries and counts changed;
the three remaining controller findings belong to the untouched approval method.

An independent `level: max`, PHP 8.3 analysis of `TransactionService` using
Larastan without any baseline returned `[OK] No errors`. The full application
`composer analyse` command subsequently passed with unmatched reporting enabled.
The temporary diagnostic configuration was outside the repository and is not
part of the implementation.

## Verification Status

| ID | Status | Verification | Evidence |
| --- | --- | --- | --- |
| TOK-64-BE-01 | ✅ | Reject malformed filter types before read-service calls. | Controller tests cover every filter with arrays/objects, text fields with boolean/numeric values, and pagination with boolean/nonnumeric values; mocked service calls are forbidden. |
| TOK-64-BE-02 | ✅ | Preserve authentication priority and filter defaults. | Tests cover absent authentication, a deleted local user, missing/null/empty optional filters, and existing 400 perspective errors. |
| TOK-64-BE-03 | ✅ | Accept frontend query shapes and preserve pagination/fallback behavior. | Buyer/seller query strings, numeric strings and decimals, bounds, unknown status/sort, invalid-format dates, and valid date filtering are covered. |
| TOK-64-BE-04 | ✅ | Preserve grouping, ownership, projections, and product loading. | Tests cover single/multi-store pending invoices, search retaining all packages, paid per-store totals, empty lists, seller isolation, private payment fields, dates, nullable expiry, and one product batch query. |
| TOK-64-BE-05 | ✅ | Run the focused transaction suites. | PHP 8.3 `artisan test --filter='TransactionControllerTest\|TransactionServiceTest'` passed: 14 tests, 259 assertions. |
| TOK-64-BE-06 | ✅ | Validate scoped and full application static analysis. | No-baseline service analysis and PHP 8.3 `composer analyse` both passed with no errors. |
| TOK-64-BE-07 | ✅ | Check PHP formatting and the configured backend suite. | PHP 8.3 `composer format:check` passed for 179 files; `artisan test` passed: 275 tests, 3,657 assertions. |
| TOK-64-BE-08 | ✅ | Check syntax and the working diff. | PHP 8.3 syntax checks passed for both application files and both transaction test files; `git diff --check` passed. |
| TOK-64-BE-09 | ✅ | Review the final change and verify transaction behavior on PostgreSQL. | On September 30, 2026, final review found no defect in the scoped diff. Both transaction suites passed on an isolated PostgreSQL 16.13 database: 14 tests, 259 assertions. The temporary server was stopped after verification. |
| TOK-64-BE-10 | ✅ | Run task-branch and staging-integration CI. | Backend CI passed for the task branch, staging integration branch, and staging PR. Release Branch Policy passed before PR #159 was merged; run links are recorded below. |
| TOK-64-BE-11 | ⬜ | Validate the deployed staging transaction feature before production promotion. | Deployment and health checks passed. Authenticated buyer/seller reads, filtering, details, single/multi-store pending invoices, account isolation, and malformed-filter responses remain pending. An unauthenticated 401 probe does not verify these cases. |
| TOK-64-BE-12 | ⬜ | Verify the approved production release. | Pending production approval, deployment, health checks, a bounded read-only transaction smoke test, and required repository synchronization. |
| TOK-64-BE-13 | ✅ | Verify the initial staging deployment and repository synchronization. | Deploy Staging run 36747363496 succeeded, activating immutable images after both HTTP checks and all nine service checks passed. Frontend/backend staging and main plus deploy main were refreshed; all three local repositories finished clean on main. |

## Initial Staging Release Evidence

The implementation was pushed as commit `36f7aa21945265b16e060aca8c42669c8ea0a29c`.
The following remote checks completed successfully:

- [Task-branch Backend CI](https://github.com/Feyfa/ecommerce-backend/actions/runs/36746044369).
- [Staging-integration push Backend CI](https://github.com/Feyfa/ecommerce-backend/actions/runs/36746621635).
- [Staging PR Backend CI](https://github.com/Feyfa/ecommerce-backend/actions/runs/36746866061).
- [Staging PR Release Branch Policy](https://github.com/Feyfa/ecommerce-backend/actions/runs/36746866036).

[Backend PR #159](https://github.com/Feyfa/ecommerce-backend/pull/159) merged
`task/jd-tok-64-staging` into `staging` as
`d419a00d6deabb72484f7b5ef921a8a2a60d2a78`.
[Deploy Staging run 36747363496](https://github.com/Feyfa/ecommerce-deploy/actions/runs/36747363496)
then completed successfully using these source revisions:

| Repository | Source revision |
| --- | --- |
| Backend staging | `d419a00d6deabb72484f7b5ef921a8a2a60d2a78` |
| Frontend staging | `d2eb8dfe16114f1271eea9cf8dec211db51e4312` |
| Deploy main | `e3038ba8475179f84b52b3af58075e802c30b065` |

The workflow built and activated immutable frontend, backend PHP, and backend
Nginx images. The staging VM's HTTP checks on ports 8080 and 8081 passed, and
all nine services were running: reverse-proxy, frontend, backend-nginx,
backend-php, backend-worker, backend-scheduler, postgres, redis, and meilisearch.
No separate migration or seeder workflow was dispatched for this release.

A public read-only probe of
`GET /api/transaction?user_type=buyer` without authentication returned
`401 application/json`. This confirms route reachability and an authentication
response; it does not exercise the authenticated transaction controller or
prove buyer/seller data, filtering, or 422 validation behavior on staging.

## Verification Boundary and Release Status

The full configured Unit/Feature suite was rerun during final review using the
isolated SQLite database: 275 tests and 3,657 assertions passed. Controller tests
bypass only Clerk transport authentication while retaining request normalization
middleware; live Clerk token verification remains a staging smoke-test boundary.

The 14 transaction tests were also run on a new temporary PostgreSQL 16.13
cluster with a dedicated `tok64_review_testing` database. Explicit environment
overrides selected that database and retained the isolated queue, cache, Redis,
and Meilisearch testing settings. The test schema was verified on PostgreSQL,
and the temporary server was stopped afterward. Development, staging, and
production databases were not used. The broader PostgreSQL suite was not run
locally; the configured Unit/Feature suite subsequently passed in remote
Backend CI using PostgreSQL. Separate Integration suites were not run.

No additional manual local smoke test is required before staging preparation.
Task-branch CI, staging integration CI, PR checks, and the initial staging
deployment have completed. Smoke testing the deployed revision with real
authentication is required before production promotion. Use existing
transaction fixtures and GET requests; approval, payments, and withdrawals are
outside this smoke scope. Browser automation requires explicit user permission.

Browser testing and authenticated transaction smoke testing have not run.
Production has not been promoted or deployed. Jira records the verified staging
release and was moved to Done for that scope; this does not establish production
readiness or mark the remaining smoke cases as verified. The public request
probe and deployment health checks are the only live HTTP verification claimed.
