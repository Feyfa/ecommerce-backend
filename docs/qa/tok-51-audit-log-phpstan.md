# TOK-51 Audit Log PHPStan Debt QA

## Purpose

This document tracks the focused removal of the 26 PHPStan findings previously
assigned to the audit-log controller, resource, and service. The change makes
the authenticated-user, configuration, database-connection, and persisted
context contracts explicit without changing audit queries, persistence,
ownership, cursor pagination, or valid API responses.

Implementation branch: `task/jd-tok-51`.

Status legend: ✅ verified.

## Implementation Contract

- Audit endpoints reuse the local `User` guaranteed by `auth.api`; no query or
  response branch is added to resolve the actor.
- Application timezone and concrete Laravel connection types are documented
  before they are passed to Carbon or used to select database timestamp format.
- Clerk session and request identifiers retain their existing casts and
  fallback values while exposing the middleware-provided string contract.
- Persisted audit changes remain defensive: missing `field` keys continue to
  use the existing null-coalescing fallback instead of assuming every legacy
  context has the current shape.
- Non-string phone and recipient-name values in legacy collection payloads are
  returned as `null` instead of being passed to string-only masking methods.
- Event normalization retains support for enum-cast values and legacy strings,
  while rejecting unsupported value types explicitly.

## Baseline Reduction

| Metric | Before | After |
| --- | ---: | ---: |
| Findings | 313 | 287 |
| Baseline entries | 225 | 210 |
| Files represented | 31 | 28 |
| Audit-log module findings | 26 | 0 |

The 15 removed baseline entries represented six controller findings, three
service findings, and 17 resource findings. No ignore rule was added or widened,
and the baseline was not regenerated.

## Verification Status

| ID | Status | Verification | Evidence |
| --- | --- | --- | --- |
| TOK-51-BE-01 | ✅ | Verify the focused baseline reduction with unmatched-ignore reporting enabled. | `composer analyse` passed with no errors. The baseline now contains 287 findings across 210 entries and 28 files. |
| TOK-51-BE-02 | ✅ | Review controller and service type narrowing without behavior changes. | Authenticated-user, timezone, request-attribute, and database-connection contracts preserve the existing query, timestamp, and persistence paths. |
| TOK-51-BE-03 | ✅ | Preserve defensive handling for persisted audit context. | Required-key fallbacks remain in place, and a regression test verifies that missing fields and non-string sensitive values do not produce a collection error. |
| TOK-51-BE-04 | ✅ | Run PHP syntax and scoped Pint checks. | PHP 8.3 syntax validation passed for every changed PHP file, and scoped Pint passed four files. |
| TOK-51-BE-05 | ✅ | Run focused audit regression suites. | Five audit feature suites passed 46 tests and 371 assertions. |
| TOK-51-BE-06 | ✅ | Run repository-wide formatting, tests, and diff validation. | `composer format:check` passed 172 files, the full backend suite passed 194 tests and 1,280 assertions, and `git diff --check` passed. |

## Release Status

No migration, seeder, frontend change, or deployment-configuration change is
required. CI, deployment, health-check, and final synchronization evidence is
tracked in Jira and GitHub rather than this local QA checklist.
