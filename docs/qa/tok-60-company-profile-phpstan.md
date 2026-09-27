# TOK-60 Company Profile PHPStan Debt QA

## Purpose

This document records local verification for removing nine PHPStan findings
from `CompanyController` and `CompanyService`. The profile and image endpoints
retain their successful response shape, owner scope, audit events, and storage
cleanup order.

Implementation branch: `task/jd-tok-60`.

## Contract Preserved

- The four company endpoints require a local `User` that still exists in the
  database and return the existing `401` response otherwise.
- `getCompany()` returns the same status and company attributes; its PHPDoc now
  declares the established outer array shape.
- Upload accepts one validated image and gives every replacement a distinct
  path before changing the company row. The previous file is removed only
  after database and audit changes succeed, even when the same extension is
  uploaded twice in one second.
- A `putFileAs()` failure produces a server error before any company or audit
  mutation, leaving the previous image untouched. If the later database or
  audit transaction fails, the new file is removed and the previous one stays.
- No frontend, migration, or deployment configuration change is needed.

## Baseline Reduction

| Metric | Before | After |
| --- | ---: | ---: |
| Findings | 222 | 213 |
| Baseline entries | 161 | 155 |
| Files represented | 17 | 15 |
| Scoped company profile findings | 9 | 0 |

PHPStan reported the six scoped ignore patterns as unmatched after their
respective code changes. Only those patterns were removed; the baseline was not
regenerated and no ignore was added.

## Verification Status

| ID | Status | Verification | Evidence |
| --- | --- | --- | --- |
| TOK-60-BE-01 | ✅ | Preserve profile and image endpoint behavior. | Focused `CompanyAuditLogTest` passed: 9 tests, 80 assertions, covering profile read, access without a local user, same-extension uploads in a frozen second, replacement, deletion, audit events, and invalid updates. |
| TOK-60-BE-02 | ✅ | Keep the previous image on storage failure. | The mocked `putFileAs()` false result returned a server error with the old company image and file intact and no audit row. |
| TOK-60-BE-03 | ✅ | Roll back image mutation when audit persistence fails. | With a legacy timestamp path and frozen time, the audit failure test retained the old company image and file, removed the newly written file, and left no audit row. |
| TOK-60-BE-04 | ✅ | Check the configured static analysis and baseline. | PHP 8.3 `composer analyse` passed with `[OK] No errors` after removing the six unmatched entries. |
| TOK-60-BE-05 | ✅ | Check PHP formatting and the complete backend suite. | Scoped Pint and repository-wide `composer format:check` passed for 178 PHP files. PHP 8.3 `artisan test` passed: 247 tests, 3,275 assertions. |
| TOK-60-BE-06 | ✅ | Review the final diff and whitespace. | The diff contains only the scoped controller, service, baseline, test, and documentation changes; `git diff --check` passed and the new QA document has no trailing whitespace. |

## Verification Boundary And Release Status

The automated feature tests use the repository's in-memory SQLite test
configuration and a fake public disk. They do not establish a production
runtime observation. This document records local implementation and validation;
task-branch CI, pull requests, deployment, and runtime verification are pending.
