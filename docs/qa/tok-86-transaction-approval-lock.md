# TOK-86 Transaction Approval Lock QA

Local verification used backend branch `bug/jd-tok-86`, based on `b314656`.
The scope covers `POST /api/transaction/approved`: the payment check, the row
lock on `transaction_users`, and the single database transaction around the
status change and the seller income credit in
`TransactionService::approveSellerTransaction()`.

## Scenarios

- **TOK-86-BE-01 — Approve a paid transaction and credit it once.** Passed.
  With a `done` invoice, the approval returned HTTP 200 with the refreshed
  transaction list, set the row to `done`, and wrote one `saldo_users` income
  of 150,000 and one `incoming` history row.
- **TOK-86-BE-02 — Reject an unpaid transaction.** Passed. A row with a
  `pending` invoice and a row without an invoice each returned HTTP 400 with
  `{"status":"error","message":"Transaksi Belum Dibayar Pembeli"}`. Both rows
  stayed `approved_seller` with no balance or history row. With the invoice
  check temporarily bypassed, the same test failed because the request
  returned 200; the check was restored.
- **TOK-86-BE-03 — Keep the existing responses.** Passed. The six existing
  tests for 401, 400, 422, and 404 passed unchanged. The foreign and the
  already `done` rows now have paid invoices and still return 404, so
  ownership and status are checked before payment.
- **TOK-86-BE-04 — Roll the status back when the credit fails.** Passed. A
  `SaldoHistory::creating` listener threw once. The first request returned
  HTTP 500, the row stayed `approved_seller`, and no balance or history row
  remained. The retry returned 200, set the row to `done`, and credited the
  income once.
- **TOK-86-BE-05 — Read the transaction row with a row lock.** Passed on
  PostgreSQL 16.13 with exactly one `transaction_users` select ending with
  `for update`. Skipped on SQLite, which drops the lock clause. With
  `lockForUpdate()` temporarily removed, the test failed with zero locked
  reads.
- **TOK-86-BE-06 — Make an approval wait for a held transaction lock.**
  Passed. In `tests/Integration/PostgresTransactionApprovalLockingTest.php`, a
  second connection locked a paid `transaction_users` row and the application
  connection used a 1-second `lock_timeout`. The approval failed with SQLSTATE
  `55P03` on the `transaction_users` select ending with `for update`. The row
  stayed `approved_seller` with no balance or history row. With
  `lockForUpdate()` temporarily removed, the test failed because the query
  that waited was `update "transaction_users" set "status" = ?, ...`. The lock
  was restored and the test passed again.
- **TOK-86-BE-07 — Keep the balance locks from TOK-85.** Passed. The three
  `SaldoIncomeCreditTest` tests passed on PostgreSQL 16.13, and
  `tests/Integration/PostgresSaldoLockingTest.php` passed 2 tests. Together
  with BE-06, the integration run reported 3 tests and 18 assertions.
- **TOK-86-BE-08 — Verify static analysis and formatting.** Passed.
  `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`, the
  `composer analyse` command, returned `[OK] No errors` with the empty
  baseline. `vendor/bin/pint --test` passed for 192 files.
- **TOK-86-BE-09 — Verify readability.** Passed. Before and after the
  `beberes` pass, both full suites returned the same results as BE-10. The
  pass split the controller step that mixed the approval call with its error
  responses, separated requests from checks in the rollback test, and split
  integration test steps that built or checked more than one subject.
- **TOK-86-BE-10 — Check the full Unit and Feature suites.** Passed.
  `php vendor/bin/phpunit --testsuite Unit,Feature` reported 376 tests and
  5,154 assertions on PostgreSQL 16.13. SQLite in memory reported 376 tests,
  5,139 assertions, and five skips: the PostgreSQL `ILIKE` search test and the
  four `FOR UPDATE` checks.

## Local Coverage

PHPStan, Pint, and PHPUnit ran with PHP 8.3.31 in the backend checkout.
SQLite used the in-memory database from `phpunit.xml`. PostgreSQL used a
temporary cluster in a scratch directory, reachable only on loopback, with the
disposable database `ecommerce_testing`.

Concurrency was shown with two database connections, not with two parallel
HTTP requests against a running server. The re-read of a row that another
approval already set to `done` relies on PostgreSQL READ COMMITTED behavior
and the existing 404 test for `done` rows; it was not reproduced with two
live requests. The integration test is opt-in and outside the `Unit` and
`Feature` suites, so CI does not run it. The new Feature tests were not run
against `main`.

Rows that were approved before this change while their invoice was unpaid
were not inspected or changed. The frontend was not changed or run; it already
shows the backend error message. Staging checks for an unpaid approval, a paid
approval, and a repeated approval are still pending.
