# TOK-63 Outbox Publisher PHPStan Debt QA

## Purpose

This document records the local remediation of nine PHPStan findings in
`OutboxPublisherService`. The publisher keeps its existing claim, dispatch,
retry, failure, and status results for valid outbox messages and configuration.

Implementation branch: `task/jd-tok-63`. Jira issue: `TOK-63`.

## Contract

- Manual retry returns the freshly loaded message. If that row disappears
  before reload, `ModelNotFoundException` rolls the transaction back; the retry
  command already reports a missing message.
- Status counts retain the integer conversion of SQL `COUNT(*)`. The oldest
  pending timestamp retains its string representation for scalar database
  results and `null` when no pending row exists. An unexpected non-scalar
  aggregate value is rejected explicitly.
- The publisher reads the lock timeout, maximum attempts, retry base, and retry
  maximum before claiming any message. Scalar and `null` values retain their
  previous integer casts and bounds. Arrays and objects fail before changing a
  row or dispatching a job.
- Non-array JSON payloads and payloads whose `source` is not a non-empty string
  fail permanently without transport dispatch. Missing lock timestamps still
  produce `null` in the ignored-claim log context.
- No public API, migration, seeder, frontend, or deployment configuration change
  is required.

## Baseline Reduction

| Metric | Before | After |
| --- | ---: | ---: |
| Findings | 200 | 191 |
| Baseline entries | 144 | 139 |
| Files represented | 12 | 11 |
| `OutboxPublisherService` findings | 9 | 0 |

Analysis without the five Outbox baseline entries located the nine findings at
the original service lines 148, 171, 180, 205, 277, 316, 417, 433, and 434.
The string cast was the `MIN(created_at)` result, not the payload source. Only
the five proven Outbox baseline entries were removed. PHPStan remains at
`level: max` with `reportUnmatchedIgnoredErrors: true`.

## Local Verification

| ID | Status | Verification | Evidence |
| --- | --- | --- | --- |
| TOK-63-BE-01 | ✅ | Preserve normal publish, retry, stale-lock, command, and status behavior. | `OutboxPublisherServiceTest` passed: 15 tests, 97 assertions. |
| TOK-63-BE-02 | ✅ | Reject malformed configuration before any claim or dispatch. | The focused test covers arrays and objects across all four settings; the message stays pending with zero attempts and no lock. |
| TOK-63-BE-03 | ✅ | Preserve invalid payload and missing-row handling. | Scalar JSON payload and array or numeric `source` fail permanently without transport dispatch; missing row during manual-retry reload raises `ModelNotFoundException` and rolls back. |
| TOK-63-BE-04 | ✅ | Check static analysis at the configured application scope. | PHP 8.3 `composer analyse` passed with `[OK] No errors` after the five scoped baseline entries were removed. |
| TOK-63-BE-05 | ✅ | Check PHP formatting and the complete backend suite after the source validation fix. | PHP 8.3 `composer format:check` passed for 178 files; `artisan test` passed: 263 tests, 3,417 assertions. |
| TOK-63-BE-06 | ⚪ | Check PostgreSQL concurrent claim behavior in a PostgreSQL environment. | `PostgresOutboxLockingTest` was skipped locally because `DB_CONNECTION=sqlite`. No PostgreSQL concurrency result is claimed. |

## Verification Boundary

Local feature tests use in-memory SQLite and a mocked dispatcher. The
PostgreSQL `FOR UPDATE SKIP LOCKED` concurrency path needs a PostgreSQL test
environment. This page records local implementation evidence only. The branch
still requires CI, pull request review, staging, production, and Jira completion
as separate release steps.
