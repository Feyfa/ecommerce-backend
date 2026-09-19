# TOK-56 Buyer Catalog PHPStan Debt QA

## Purpose

This document tracks the focused removal of nine PHPStan findings previously
assigned to `BelanjaController` and `BuyerProductSearchService`. The change
declares the authenticated local user and narrows Eloquent relation, price,
timestamp, and configuration values without changing the buyer catalog API or
Meilisearch document contract.

Implementation branch: `task/jd-tok-56`.

Status legend: ✅ verified, ⬜ not verified yet.

## Implementation Contract

- The `/belanja` endpoint continues to use the local user installed by the
  required `auth.api` middleware as the buyer excluded from catalog results.
- Company name remains the preferred store name, with seller name and then an
  empty string as the existing fallbacks.
- Product price remains projected as an integer without adding a global model
  cast.
- Nullable Eloquent timestamps retain their ISO 8601 and Unix timestamp
  representations.
- Meilisearch fields, eligibility, filters, sorting, pagination, and API
  responses remain unchanged.
- Configured pagination limits and index names retain their existing scalar
  normalization.

## Baseline Reduction

| Metric | Before | After |
| --- | ---: | ---: |
| Findings | 249 | 240 |
| Baseline entries | 184 | 178 |
| Files represented | 22 | 20 |
| Scoped findings | 9 | 0 |

The six removed entries represented one nullable authenticated-user finding,
two dynamic relation-property findings, four dynamic timestamp-method
findings, and two mixed configuration-cast findings. No ignore rule was added
or widened, and the baseline was not regenerated.

## Verification Status

| ID | Status | Verification | Evidence |
| --- | --- | --- | --- |
| TOK-56-BE-01 | ✅ | Verify the focused document projection and buyer catalog behavior. | `BuyerProductSearchServiceTest` and `ProductListFilterTest` passed together with 29 tests and 241 assertions. |
| TOK-56-BE-02 | ✅ | Verify the focused baseline reduction with unmatched-ignore reporting enabled. | PHPStan passed with no errors, and the baseline contains 240 findings across 178 entries and 20 files. |
| TOK-56-BE-03 | ✅ | Run PHP syntax and scoped Pint checks for changed PHP files. | PHP 8.3 syntax checks passed for the controller, service, and unit test; scoped Pint passed for all three files. |
| TOK-56-BE-04 | ✅ | Run related buyer catalog synchronization tests. | Clerk catalog synchronization, seller catalog job, and disposable Meilisearch reindex tests passed with 6 tests and 28 assertions. |
| TOK-56-BE-05 | ✅ | Run repository-wide format, full backend tests, and diff validation. | PHP 8.3 Pint passed for 176 files, the full backend suite passed 236 tests and 1,376 assertions, and `git diff --check` passed. |
| TOK-56-BE-06 | ⬜ | Run task-branch backend CI. | Pending commit and push; remote CI is outside the current implementation scope. |

## Release Status

No migration, environment-variable addition, frontend change, deployment
configuration change, or external API contract change is required. Jira TOK-56
remains In Progress because commit, push, CI, staging, and production release
are outside the current local-validation scope.
