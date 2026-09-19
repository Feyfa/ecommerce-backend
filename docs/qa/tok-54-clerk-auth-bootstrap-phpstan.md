# TOK-54 Clerk Auth Bootstrap PHPStan Debt QA

## Purpose

This document tracks the focused removal of the five PHPStan findings previously
assigned to `AuthSessionController` and `ClerkBackendClientService`. The change
narrows request identity values and validates Clerk runtime configuration without
changing routes, successful API payloads, audit behavior, user synchronization,
or accepted token types.

Implementation branch: `task/jd-tok-54`.

Status legend: ✅ verified, ⬜ not verified yet.

## Implementation Contract

- Auth bootstrap accepts `clerk_user_id` only when the middleware attribute is a
  string; empty or malformed values return the existing unauthorized response.
- Logout sends only an authenticated local `User` to the audit service. A request
  without that user returns the same unauthorized payload as auth bootstrap.
- The Clerk secret key must be a non-empty string and is read once for each SDK or
  request-options construction path.
- Nullable string configuration no longer casts arrays, objects, booleans, or
  numeric values into credentials.
- Clerk authorized parties must be an array containing only strings. An empty list
  remains valid, while malformed configuration raises a controlled exception.
- `acceptsToken: ['session_token']`, successful responses, routes, audit behavior,
  user synchronization, and the existing `FRONTEND_URL` source remain unchanged.

## Baseline Reduction

| Metric | Before | After |
| --- | ---: | ---: |
| Findings | 258 | 253 |
| Baseline entries | 192 | 188 |
| Files represented | 25 | 23 |
| Scoped findings | 5 | 0 |

The four removed baseline entries represented two controller findings and three
service findings. No ignore rule was added or widened, and the baseline was not
regenerated.

## Verification Status

| ID | Status | Verification | Evidence |
| --- | --- | --- | --- |
| TOK-54-BE-01 | ✅ | Verify the focused baseline reduction with unmatched-ignore reporting enabled. | `composer analyse` passed with no errors after the four stale entries were removed. The baseline now contains 253 findings across 188 entries and 23 files. |
| TOK-54-BE-02 | ✅ | Verify bootstrap, logout, and Clerk configuration contracts without network requests. | The focused controller suite passed 8 tests and 26 assertions. The focused client-service suite passed 12 tests and 23 assertions. |
| TOK-54-BE-03 | ✅ | Run PHP syntax and scoped Pint checks for the changed implementation and tests. | PHP 8.3 syntax validation and scoped Pint checks passed for the controller, service, and both focused test files. |
| TOK-54-BE-04 | ✅ | Run the combined relevant tests, repository-wide formatting, full backend suite, and diff validation. | The combined relevant suites passed 33 tests and 167 assertions, `composer format:check` passed 175 files, the full backend suite passed 231 tests and 1,349 assertions, and `git diff --check` passed. |
| TOK-54-BE-05 | ✅ | Smoke test the local browser authentication lifecycle. | Auth bootstrap returned HTTP 200 with user and company data, the security page loaded, logout returned HTTP 200 with `recorded: true`, and Google login restored the authenticated session. No unexpected backend 401, 422, or 500 response was observed. |
| TOK-54-BE-06 | ⬜ | Run task-branch backend CI. | Pending push; task-branch CI cannot run until the local commit is published. |

## Release Status

No migration, environment-variable addition, frontend change, or deployment
configuration change is required. Jira TOK-54 remains In Progress because push, CI,
staging, production deployment, and final synchronization remain pending after the
local commit.
