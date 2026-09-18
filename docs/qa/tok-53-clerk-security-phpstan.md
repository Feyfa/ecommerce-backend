# TOK-53 Clerk Security PHPStan Debt QA

## Purpose

This document tracks the focused removal of the 17 PHPStan findings previously
assigned to `SecurityController` and `ClerkSecurityService`. The change narrows
authenticated request values and documents Clerk-owned security payloads without
changing routes, API responses, provider calls, ownership rules, or cleanup behavior.

Implementation branch: `task/jd-tok-53`.

Status legend: ✅ verified, ⬜ not verified yet.

## Implementation Contract

- Clerk user and session request attributes are accepted only when they are strings.
- A valid local `User` remains the fallback source for `clerk_user_id` when the
  middleware attribute is absent or malformed.
- Missing identity data continues to return the existing unauthorized response.
- `SessionPayload` is the single reusable PHPStan alias for formatted session rows;
  shorter response and collection shapes remain documented on their methods.
- Clerk session ordering, revocation, Google account validation, deletion-ID fallback,
  and cleanup verification retain their existing runtime paths.
- Non-string external-account metadata is ignored before the existing model-ID fallback
  and controlled exception are evaluated.

## Baseline Reduction

| Metric | Before | After |
| --- | ---: | ---: |
| Findings | 275 | 258 |
| Baseline entries | 208 | 192 |
| Files represented | 27 | 25 |
| Clerk security findings | 17 | 0 |

The 16 removed baseline entries represented three controller findings and 14 service
findings. No ignore rule was added or widened, and the baseline was not regenerated.

## Verification Status

| ID | Status | Verification | Evidence |
| --- | --- | --- | --- |
| TOK-53-BE-01 | ✅ | Verify the focused baseline reduction with unmatched-ignore reporting enabled. | `composer analyse` passed with no errors after the 16 stale entries were removed. The baseline now contains 258 findings across 192 entries and 25 files. |
| TOK-53-BE-02 | ✅ | Verify request identity narrowing and Clerk security payload contracts. | The focused unit suites passed 29 tests and 37 assertions covering valid and malformed attributes, local-user fallback, unauthorized behavior, session formatting, provider cleanup, and non-string deletion metadata. |
| TOK-53-BE-03 | ✅ | Run PHP syntax and scoped Pint checks. | PHP 8.3 syntax validation passed for the changed PHP files, and scoped Pint checks passed the controller, service, and focused tests. |
| TOK-53-BE-04 | ✅ | Run repository-wide formatting, tests, and diff validation. | `composer format:check` passed 173 files, the full backend suite passed 211 tests and 1,300 assertions, and `git diff --check` passed. |
| TOK-53-BE-05 | ⬜ | Run task-branch backend CI. | Pending push; task-branch CI cannot run until the local commit is published. |

## Release Status

No migration, seeder, frontend change, configuration-file change, or deployment
configuration change is required. Jira TOK-53 remains In Progress because push, CI,
staging, production deployment, and final synchronization remain pending after the
local commit.
