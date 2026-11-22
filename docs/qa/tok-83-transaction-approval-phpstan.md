# TOK-83 Transaction Approval PHPStan QA

Local verification used backend branch `task/jd-tok-83`, based on `6749fb1`.
The scope covers three findings in `TransactionController::approvedTransaction()`
for `POST /api/transaction/approved`.

## Scenarios

- **TOK-83-BE-01 — Record the original approval behavior.** Passed. The new
  `TransactionApprovalTest` ran against the original controller first. Five
  tests covering existing behavior passed. The two tests for the new behavior
  failed as predicted: `user_type: true` returned HTTP 200 and approved the
  transaction, and an array `transaction_user_id` returned HTTP 500 from a
  `trim()` TypeError.
- **TOK-83-BE-02 — Approve a valid seller transaction.** Passed. The row moved
  from `approved_seller` to `done`, `saldo_users.saldo_income` equaled the
  product price, exactly one `incoming` history row was written, and the
  response kept `status`, `transactions`, `counts`, and `pagination`.
- **TOK-83-BE-03 — Keep the 401 responses.** Passed. A request without an
  identity and a request from a deleted local user both returned HTTP 401
  `Unauthorized` without changing the transaction or the balance tables.
- **TOK-83-BE-04 — Keep the 400 seller-only response.** Passed. A missing
  perspective, `buyer`, and `["seller"]` returned HTTP 400
  `This Action Only For Seller` without changes.
- **TOK-83-BE-05 — Reject a boolean perspective.** Passed. `user_type: true`
  now returns HTTP 400 `This Action Only For Seller` without changes.
- **TOK-83-BE-06 — Keep the 400 empty-ID response.** Passed. A missing, null,
  blank, whitespace-only, `"0"`, `false`, `0`, and `[]` identifier each
  returned HTTP 400 `Transaction User ID Cannot Be Empty` without changes.
- **TOK-83-BE-07 — Reject non-string identifiers.** Passed. An array, `123`,
  `1.5`, and `true` each returned HTTP 422
  `Transaction User ID Must Be A String` before the transaction query, without
  changes.
- **TOK-83-BE-08 — Keep the 404 response.** Passed. An unknown UUID, another
  seller's transaction, and an already `done` transaction returned HTTP 404
  `Transaksi Tidak Ditemukan` without changes.
- **TOK-83-BE-09 — Remove the three scoped PHPStan findings.** Passed. With
  the original baseline, PHPStan reported exactly three unmatched
  `TransactionController` entries and no new findings. After deleting them,
  `composer analyse` returned `[OK] No errors`. Analysis of `app/` at
  `level: max` without the baseline went from 17 to 14 findings. The remaining
  findings are the eight `SaldoController` and six `SaldoService` findings from
  the original analysis.
- **TOK-83-BE-10 — Verify readability and formatting.** Passed. Before and
  after the `beberes` pass, the three transaction test classes passed with 21
  tests. The pass split the approval steps, wrapped long lines in the changed
  method, added the double-credit risk comment, and moved the no-change
  assertions into each request step. `composer analyse` and
  `composer format:check` passed afterward for 187 PHP files.
- **TOK-83-BE-11 — Check the full Unit and Feature suites.** Passed.
  `php vendor/bin/phpunit --testsuite Unit,Feature` reported 350 tests and
  4,856 assertions on PostgreSQL 16.13. SQLite in memory reported 350 tests,
  4,849 assertions, and one skip for the PostgreSQL `ILIKE` search test.
- **TOK-83-BE-12 — Confirm the PostgreSQL error for non-UUID identifiers.**
  Passed. A temporary probe outside the repository queried
  `transaction_users.id` on PostgreSQL 16.13 with `123`, `'1'`, `1.5`, and
  `'not-a-uuid'`, each inside its own savepoint. Every value raised
  `QueryException` with SQLSTATE `22P02`. `app/Exceptions/Handler.php` has no
  special handling for it, so these values returned HTTP 500 before this task.
  Non-UUID strings still do.

## Local Coverage

PHPStan, Pint, and PHPUnit ran with PHP 8.3.31 in the backend checkout.
SQLite used the in-memory database from `phpunit.xml`. PostgreSQL used a
temporary cluster in a scratch directory, reachable only on loopback, with the
disposable database `ecommerce_testing`. The project `.env` defines no
database URL, and the explicit test variables selected the temporary cluster.

The approval tests bypass Clerk transport authentication and use local user
and transaction fixtures without an invoice. They do not exercise concurrent
approvals. The status update and saldo transfer still run without a database
transaction or row lock, which remains outside this task.
