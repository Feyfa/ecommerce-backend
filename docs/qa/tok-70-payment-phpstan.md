# TOK-70 Payment Contracts and Input Validation QA

## Purpose and Contract

Resolve 18 legacy findings in `PaymentController` and `PaymentService` while
preserving ownership, query projections, account ordering, catalogue filtering,
successful responses, the ten-account limit and simulation amount conversion.

Jira: [TOK-70](https://muhammadjidan-31088882.atlassian.net/browse/TOK-70).
Implementation branch: `task/jd-tok-70`.
This document records local verification only; CI, PR, deployment and deployed
smoke evidence belong in Jira/PR/Actions.

Authenticated text inputs now reject numbers, booleans, arrays and objects with
HTTP 422 and field errors under `message` before account mutation/provider calls.
Authentication remains first. Optional missing/null searches become empty
strings; existing business empty/unknown/duplicate/foreign/expired errors keep
their responses. Required creation fields use `required|string`.

Return types preserve list arrays and Eloquent collections, including nullable
database projections. A bounded caller adjustment in `SaldoController` reads
the now-guaranteed payment result `status` directly; no withdrawal algorithm,
balance mutation, provider implementation or schema is changed.

Simulation explicitly casts stored price to int, preserving legacy truncation
and the null-to-zero fallback. It introduces no rounding or new nominal rule.

## Baseline Reduction

| Metric | Before | After |
| --- | ---: | ---: |
| Findings | 117 | 99 |
| Patterns | 87 | 78 |
| Files represented | 8 | 6 |
| PaymentController findings | 14 | 0 |
| PaymentService findings | 4 | 0 |

Analysis with the original baseline identified exactly nine unmatched target
patterns. The precise service shape also exposed one redundant `status ?? ''`
in the withdrawal caller; the guaranteed-key access was adjusted and covered
by a compatibility test. No suppression was added or widened, no baseline was
regenerated, and all remaining baseline entries are unchanged.

## Local Verification

| ID | Status | Check | Evidence |
| --- | --- | --- | --- |
| TOK-70-BE-01 | Verified | Establish baseline and original-code regression coverage. | Application PHPStan passed before edits. New original-code coverage plus existing payment tests passed: SQLite 12 tests / 1,903 assertions, one ILIKE test skipped; PostgreSQL 13 tests / 1,910 assertions. |
| TOK-70-BE-02 | Verified | Preserve catalogue and account read contracts. | Tests cover empty results, nullable projections, collection type, selected aliases, descending order, user/withdrawal scope, account lookup errors and leading-zero numbers. |
| TOK-70-BE-03 | Verified | Preserve production search semantics. | PostgreSQL tests cover case-insensitive slug/bank/owner/account search, wildcard behavior, existing whitespace and string-zero behavior, and cross-user isolation. |
| TOK-70-BE-04 | Verified | Reject malformed inputs before mutation. | All text fields reject numeric/boolean/compound inputs with 422; account count and original row remain unchanged after every invalid create/delete request. |
| TOK-70-BE-05 | Verified | Keep authentication and business boundaries. | Tests cover missing/deleted local users, required/optional defaults, existing empty-field messages, duplicates, unknown banks, foreign deletion and the ten-account limit. |
| TOK-70-BE-06 | Verified | Preserve simulation and caller behavior. | Provider mocks cover ownership, expiry, failure, success and invoice status; amounts 150000.0, 150000.75 and null become 150000, 150000 and 0. Caller tests retain payment-error and zero-balance responses without disbursement. |
| TOK-70-BE-07 | Verified | Run final focused PostgreSQL verification. | Five payment suites passed: 17 tests / 2,472 assertions on isolated PostgreSQL 16.13. Database/user identity and 26 migration records verified. |
| TOK-70-BE-08 | Verified | Run the full configured local suite. | SQLite Unit/Feature suite passed: 296 tests / 4,430 assertions; one production-dialect ILIKE test skipped locally and passed on PostgreSQL. |
| TOK-70-BE-09 | Verified | Verify static analysis. | Application analysis with unmatched reporting passed; independent level-max PHP 8.3 Larastan analysis of PaymentController/PaymentService without any baseline passed. |
| TOK-70-BE-10 | Verified | Check formatting, syntax and final diff. | Pint check passed for 183 files; all six changed/new PHP files passed syntax checks. Comparison confirms all 78 remaining baseline entries unchanged. Final review and git diff check passed. |
| TOK-70-BE-11 | Verified | Review final edits before release preparation. | October 3, 2026: reviewed authentication resolver equivalence, validation before mutation, preserved query/response behavior, nominal conversion and caller compatibility. Model and array variables are separated for editor type clarity. Final focused SQLite suites passed: 16 tests / 2,465 assertions, one ILIKE test already verified on PostgreSQL. Application PHPStan and Pint check passed again; no defect found in the scoped diff. |

## Environment and Limitations

SQLite uses the configured in-memory testing database. PostgreSQL is a fresh
temporary cluster under `/private/tmp/tok70-postgres.QoNZYt`, listening only on
loopback port 55470 with UTF-8 database and role `tok70_testing`. Explicit test
environment overrides and shared queue fakes isolate application services.
Development/staging/production databases are not used.
The temporary PostgreSQL server was stopped after final verification.

Tests bypass Clerk transport while keeping normalization middleware. Only the
large invalid-input matrix disables throttle because it exceeds the endpoint
request limit; application throttle configuration remains unchanged. Live Clerk,
Xendit, payments and browser smoke are not exercised. Historical fractional
invoice values are not audited; the task preserves the existing conversion.

The first new-test run used an incorrect simulation URL; it was corrected to
the existing route before application edits. PHPStan's worker socket required
permitted host execution after a sandbox EPERM. No dependency upgrade was made.
