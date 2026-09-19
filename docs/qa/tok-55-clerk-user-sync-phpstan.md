# TOK-55 Clerk User Sync PHPStan Debt QA

## Purpose

This document tracks the focused removal of four PHPStan findings previously
assigned to `ClerkUserSyncService`. The change reuses the existing primary-email
validation and handles a failed post-save reload without changing normal Clerk
identity matching, transaction callbacks, or seller synchronization behavior.

Implementation branch: `task/jd-tok-55`.

Status legend: ✅ verified, ⬜ not verified yet.

## Implementation Contract

- A missing or empty primary email continues to raise the existing controlled
  exception before the transaction starts.
- Once validated, the primary email is used directly for case-insensitive local
  user matching and identity synchronization.
- A post-save reload must produce a local `User`; a missing row raises a
  controlled exception and rolls back the transaction.
- Clerk ID matching, email fallback attachment, `was_created`, transaction
  callbacks, name-change detection, and durable seller outbox recording remain
  unchanged for successful synchronization.

## Baseline Reduction

| Metric | Before | After |
| --- | ---: | ---: |
| Findings | 253 | 249 |
| Baseline entries | 188 | 184 |
| Files represented | 23 | 22 |
| Scoped findings | 4 | 0 |

The four removed entries represented two redundant primary-email conditions and
two nullable reload findings. No ignore rule was added or widened, and the
baseline was not regenerated.

## Verification Status

| ID | Status | Verification | Evidence |
| --- | --- | --- | --- |
| TOK-55-BE-01 | ✅ | Verify the focused user-sync scenarios without Clerk network requests. | The focused feature suite passed 3 tests and 15 assertions for new-user creation, case-insensitive email attachment, and controlled reload failure with transaction rollback. |
| TOK-55-BE-02 | ✅ | Verify the focused baseline reduction with unmatched-ignore reporting enabled. | `composer analyse` passed with no errors, and the baseline contains 249 findings across 184 entries and 22 files. |
| TOK-55-BE-03 | ✅ | Run PHP syntax and scoped Pint checks for changed PHP files. | PHP 8.3 syntax checks passed for the service and focused test, and scoped Pint passed for both files. |
| TOK-55-BE-04 | ✅ | Run related audit and catalog synchronization regression tests. | The focused, audit-log, and Clerk catalog synchronization tests passed together with 18 tests and 147 assertions. |
| TOK-55-BE-05 | ✅ | Run repository-wide format, full backend tests, and diff validation. | `composer format:check` passed for 176 files, the full backend suite passed 234 tests and 1,364 assertions, and `git diff --check` passed. |
| TOK-55-BE-06 | ⬜ | Run task-branch backend CI. | Pending push; task-branch CI cannot run until the local commit is published. |

## Release Status

No migration, environment-variable addition, frontend change, deployment
configuration change, or external Clerk request is required. Jira TOK-55 remains
In Progress because commit, push, CI, staging, and production release are outside
the current local-validation scope.
