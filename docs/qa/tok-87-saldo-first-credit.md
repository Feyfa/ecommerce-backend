# TOK-87 Saldo First Credit QA

Local verification used backend branch `bug/jd-tok-87`, based on `734bf45`.
The scope covers `TransactionService::creditLockedSaldo()`: a seller without a
`saldo_users` row now gets an empty row from `insertOrIgnore` before the
locked balance read, so the first income credits queue on the same row lock as
later credits and withdrawals.

## Scenarios

- **TOK-87-BE-01 — Create one balance row on the first credit.** Passed on
  SQLite and PostgreSQL 16.13. A seller without a balance row received a
  150,000 credit. Exactly one `saldo_users` row held `saldo_income` 150,000
  and `saldo_refund` 0, with a UUID `id` and both timestamps filled. One
  `incoming` history row recorded `saldo_before` 0 and `saldo_after` 150,000.
- **TOK-87-BE-02 — Create the row before the locked read.** Passed on
  PostgreSQL 16.13. The query log held exactly one
  `insert into "saldo_users" ... on conflict do nothing` and exactly one
  `saldo_users` select ending with `for update`, with the insert first.
  Skipped on SQLite, which drops the lock clause. With `TransactionService.php`
  temporarily restored from `main`, the test failed with zero idempotent
  inserts; the fix was restored.
- **TOK-87-BE-03 — Make a first credit wait for a concurrent first row.**
  Passed. In `tests/Integration/PostgresSaldoFirstCreditLockingTest.php`, a
  second connection inserted the seller's first balance row without
  committing, and the application connection used a 1-second `lock_timeout`.
  The credit failed with SQLSTATE `55P03` on the
  `insert ... on conflict do nothing` statement and left no balance or history
  row. With the old code from `main`, the same test failed because the query
  that waited was the plain `insert into "saldo_users"` that would hit the
  unique `user_id`; the fix was restored and the test passed again.
- **TOK-87-BE-04 — Keep the existing balance and approval locks.** Passed. The
  three existing `SaldoIncomeCreditTest` tests and all
  `TransactionApprovalTest` tests passed unchanged on SQLite and PostgreSQL.
  `PostgresSaldoLockingTest` still saw the credit and the withdrawal wait on
  the locked read, so the idempotent insert does not wait on a row lock held
  by a withdrawal. `PostgresTransactionApprovalLockingTest` and
  `PostgresOutboxLockingTest` also passed; the integration run reported 5
  tests and 28 assertions.
- **TOK-87-BE-05 — Verify static analysis and formatting.** Passed.
  `composer analyse` returned `[OK] No errors`. `vendor/bin/pint --test`
  passed for 193 files.
- **TOK-87-BE-06 — Verify readability.** Passed. Before and after the
  `beberes` pass, both full suites returned the same results as BE-07. The
  pass split the query-order test step that found two subjects, shortened a
  comment over 100 characters, reused one timestamp in the integration
  fixture, and dropped a redundant holder rollback from the integration test.
- **TOK-87-BE-07 — Check the full Unit and Feature suites.** Passed.
  `php vendor/bin/phpunit --testsuite Unit,Feature` reported 378 tests and
  5,165 assertions on PostgreSQL 16.13. SQLite in memory reported 378 tests,
  5,147 assertions, and six skips: the PostgreSQL `ILIKE` search test and the
  five `FOR UPDATE` checks.

## Local Coverage

PHPStan, Pint, and PHPUnit ran with PHP 8.3.31 in the backend checkout.
SQLite used the in-memory database from `phpunit.xml`. PostgreSQL used a
temporary cluster in a scratch directory, reachable only on loopback, with the
disposable database `ecommerce_testing`.

Concurrency was shown with two database connections, not with two parallel
HTTP requests. The integration test proves that the first credit waits for a
concurrent first-row insert. The continuation after the other connection
commits, where the insert does nothing and the locked read finds the row,
relies on PostgreSQL `ON CONFLICT DO NOTHING` behavior; one PHP process cannot
commit the holder while the credit is waiting. The integration test is opt-in
and outside the `Unit` and `Feature` suites, so CI does not run it.

The API response, the frontend, the migration, and withdrawals were not
changed. Staging checks for a regular approval credit and, if a seller without
income is available, two concurrent first approvals are still pending.
