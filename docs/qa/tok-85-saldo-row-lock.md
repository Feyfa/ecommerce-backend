# TOK-85 Saldo Row Lock QA

Local verification used backend branch `task/jd-tok-85`, based on `4eb27c2`.
The scope covers the balance check, Xendit disbursement, and deduction in
`POST /api/saldo-withdraw`, the deduction in
`SaldoService::saveSaldoAfterDisbursement()`, and the seller income credit in
`TransactionService::transferSaldo()`.

## Scenarios

- **TOK-85-BE-01 — Deduct refund first, then income.** Passed. With a refund
  of 30,000 and an income of 100,000, a 50,000 deduction left the refund at 0
  and the income at 80,000. One `withdrawal` history row recorded the payment
  account, `saldo_before` 130,000, and `saldo_after` 80,000.
- **TOK-85-BE-02 — Keep income untouched for a refund-only deduction.**
  Passed. A 20,000 deduction lowered only the refund, from 30,000 to 10,000.
- **TOK-85-BE-03 — Reject amounts below Rp1 in the service.** Passed. `0` and
  `-5000` each returned `price must be at least 1` without a balance change or
  history row.
- **TOK-85-BE-04 — Reject an insufficient balance instead of clamping it.**
  Passed. A 200,000 deduction from a 130,000 balance returned
  `saldo is not enough` and left the balance and history unchanged.
- **TOK-85-BE-05 — Reject missing users and balance rows.** Passed. A null
  user ID and an unknown UUID returned `user not found`. A user without a
  `saldo_users` row returned `saldo not found`; the old code called `save()`
  on null in this case.
- **TOK-85-BE-06 — Roll back the deduction when the history write fails.**
  Passed. A `SaldoHistory::creating` listener threw after the balance row was
  saved. The exception reached the caller, and the balance kept its starting
  value with no history row.
- **TOK-85-BE-07 — Keep the existing withdraw responses.** Passed. The ten
  TOK-84 tests in `SaldoWithdrawInputTest` passed unchanged, including the
  insufficient balance message. Both `PaymentReturnCallerCompatibilityTest`
  tests passed after their `SaldoService` mock moved from `getSaldo()` to
  `lockSaldoTotal()`, including the `Saldo Anda Rp0` message and the provider
  rejection.
- **TOK-85-BE-08 — Report a rejected deduction after disbursement.** Passed.
  With `saveSaldoAfterDisbursement()` mocked to reject after the real locked
  balance check, the endpoint sent one disbursement and returned HTTP 500 with
  `Penarikan Sudah Diproses Tetapi Saldo Gagal Diperbarui, Jangan Ulangi Penarikan`.
  One critical log carried the user ID, amount, and the external ID sent to
  the mocked provider, without the account number. The balance and history
  stayed unchanged.
- **TOK-85-BE-09 — Report a failed deduction after disbursement.** Passed.
  With the history write failing, the endpoint sent one disbursement and
  returned HTTP 500. One critical log carried the same identifiers, and the
  balance and history were rolled back.
- **TOK-85-BE-10 — Read the balance only with a row lock during a
  withdrawal.** Passed on PostgreSQL 16.13. Every `saldo_users` select in a
  successful withdrawal ended with `for update`. Skipped on SQLite, which
  drops the lock clause.
- **TOK-85-BE-11 — Credit seller income in one transaction.** Passed. A
  150,000 credit raised the income from 50,000 to 200,000, kept the refund at
  10,000, and wrote one `incoming` history row from 60,000 to 210,000. When
  the history write failed, the exception reached the caller and the income
  stayed at 50,000. The seven `TransactionApprovalTest` tests also passed,
  including the first credit that creates the balance row.
- **TOK-85-BE-12 — Read the balance with a row lock during a credit.** Passed
  on PostgreSQL 16.13 with exactly one `saldo_users` select ending with
  `for update`. Skipped on SQLite.
- **TOK-85-BE-13 — Make a withdrawal wait for a held lock before Xendit.**
  Passed. In `tests/Integration/PostgresSaldoLockingTest.php`, a second
  connection locked the balance row and the application connection used a
  1-second `lock_timeout`. The withdrawal failed with SQLSTATE `55P03` on a
  query ending with `for update`, the mocked provider received no request,
  and the balance and history stayed unchanged. With `lockForUpdate()`
  temporarily removed from `lockSaldoTotal()`, the same test failed because
  the provider had received one request before the request waited.
- **TOK-85-BE-14 — Make an income credit wait for a held lock.** Passed in
  the same integration test. The credit failed with `55P03` on the locked
  read. With `lockForUpdate()` temporarily removed from `creditLockedSaldo()`,
  the test failed because the query that waited was
  `update "saldo_users" set "saldo_income" = ?, ...`. Both locks were restored
  and the integration test passed again: 2 tests and 11 assertions.
- **TOK-85-BE-15 — Remove the scoped PHPStan findings.** Passed. Typing the
  service result exposed `SaldoController.php:374`, which passed
  `string|null` to `getSaldoById()`. The controller fix removed it. After the
  four `SaldoService` entries were deleted, the baseline holds
  `ignoreErrors: []` and `composer analyse` returned `[OK] No errors`.
- **TOK-85-BE-16 — Verify readability and formatting.** Passed. Before and
  after the `beberes` pass, both full suites returned the same results as
  BE-17. The pass split long steps in `creditLockedSaldo()` and
  `withdrawWithLockedSaldo()`, renamed steps that joined two purposes, and
  added why-comments to single-stage tests. `vendor/bin/pint --test` passed
  for 191 files.
- **TOK-85-BE-17 — Check the full Unit and Feature suites.** Passed.
  `php vendor/bin/phpunit --testsuite Unit,Feature` reported 373 tests and
  5,133 assertions on PostgreSQL 16.13. SQLite in memory reported 373 tests,
  5,120 assertions, and four skips: the PostgreSQL `ILIKE` search test and the
  three `FOR UPDATE` checks.

## Local Coverage

PHPStan, Pint, and PHPUnit ran with PHP 8.3.31 in the backend checkout.
SQLite used the in-memory database from `phpunit.xml`. PostgreSQL used a
temporary cluster in a scratch directory, reachable only on loopback, with the
disposable database `ecommerce_testing`.

Xendit was replaced with a Guzzle mock, so no real disbursement was sent. The
integration test is opt-in and outside the `Unit` and `Feature` suites, so CI
does not run it. Concurrency was shown with two database connections, not
with two parallel HTTP requests against a running server. Only the
integration tests were run against code without the locks; the new Feature
tests were not run against `main`.

A failure of the outer commit after the transaction callback returns is not
covered by the critical log; it ends as the framework's HTTP 500. The
approval double-credit risk, the race when two first credits create the same
`saldo_users` row, and a Guzzle timeout for Xendit remain outside this task.
The frontend was not changed or run.
