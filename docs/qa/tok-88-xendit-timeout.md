# TOK-88 Xendit Timeout QA

Local verification used backend branch `bug/jd-tok-88`, based on `a8f1133`.
TOK-88 is delivered in two batches on one branch and released once.

## Batch 1: Virtual Account Request Timeouts

Batch 1 covers `XenditService` virtual account operations: checkout virtual
account creation and both payment simulations. They now send a connect timeout
(5 seconds) and a total request timeout (20 seconds), configurable through
`XENDIT_CONNECT_TIMEOUT_SECONDS` and `XENDIT_REQUEST_TIMEOUT_SECONDS`. A timeout,
failed connection, 5xx, or other transfer failure returns
`Layanan Pembayaran Sedang Bermasalah, Silakan Coba Lagi` and writes a `warning`
log without the response body or URL. Disbursement is deliberately unchanged
until batch 2.

### Scenarios

- **TOK-88-BE-01 — Confirm the installed Guzzle exception classes.** Passed. A
  probe script used `vendor/` Guzzle 7.8.1 against a local `php -S` fake
  provider; no request reached Xendit. With `timeout=1`, a delayed answer
  raised `ConnectException` (cURL 28) after 1.0 seconds. A 503 raised
  `ServerException`. A refused port raised `ConnectException` (cURL 7). All
  three extend `TransferException`, which the adapter now catches after its
  existing `ClientException` (4xx) handling.
- **TOK-88-BE-02 — Send the configured timeouts on virtual account requests.**
  Passed. `XenditServiceTest` checked the options Guzzle received for creation,
  pool simulation, and fixed simulation: each carried the configured
  `connect_timeout` and `timeout`.
- **TOK-88-BE-03 — Keep disbursement without a timeout.** Passed. The
  disbursement request carried neither option, so a lost answer cannot become
  more frequent before batch 2 adds pending withdrawals.
- **TOK-88-BE-04 — Hide unanswered failures behind a safe message.** Passed. For
  a timeout, a 503, and a connection reset, all three virtual account operations
  returned the safe message. Each wrote one `warning` with the operation,
  exception class, HTTP status, and cURL code and text. The logged fields
  contained neither the Xendit host nor the 503 body. The existing 4xx tests,
  which return the provider message, passed unchanged.
- **TOK-88-BE-05 — Roll back checkout when the provider does not answer.**
  Passed. `CheckoutTest` returned HTTP 400 with the safe message for a timeout
  and for a 503. No invoice or outbox row was written, the cart row stayed
  selected, and product stock did not change.
- **TOK-88-BE-06 — Keep the invoice pending when a simulation does not answer.**
  Passed. `PaymentControllerTest` returned HTTP 400 with the safe message on a
  timeout, and the invoice stayed `pending`.
- **TOK-88-BE-07 — Reject settings that remove the limit.** Passed.
  `XenditServiceRegistrationTest` resolved the service from the default
  configuration. It accepted an integer and a numeric string, and rejected zero,
  a negative number, text, a blank env value, and a missing value with a
  `RuntimeException`.
- **TOK-88-BE-08 — Prove the tests catch a regression.** Passed. With
  `catch (TransferException)` temporarily removed from `createVirtualAccount()`,
  the unit and checkout tests failed because cURL text and the 503 body reached
  the caller. With the timeout options temporarily removed, the options test
  failed. Both changes were restored.
- **TOK-88-BE-09 — Verify static analysis and formatting.** Passed.
  `composer analyse` returned `[OK] No errors`. `vendor/bin/pint --test` passed
  for 194 files.
- **TOK-88-BE-10 — Verify readability.** Passed. The `beberes` pass split test
  stages that checked two subjects, split `AppServiceProvider::register()` into
  one stage per service, and wrapped lines over 100 characters. The SQLite suite
  returned the same result before and after the pass.
- **TOK-88-BE-11 — Check the full Unit and Feature suites.** Passed. SQLite in
  memory reported 388 passed and six skips (the PostgreSQL `ILIKE` search test
  and the five `FOR UPDATE` checks) with 5,211 assertions; the baseline before
  the change was 372 passed. PostgreSQL 16.13 reported 394 tests and 5,229
  assertions.

### Local Coverage

PHPStan, Pint, and PHPUnit ran with PHP 8.3.31 in the backend checkout. SQLite
used the in-memory database from `phpunit.xml`. PostgreSQL used a temporary
cluster in a scratch directory, reachable only on loopback, with the disposable
database `ecommerce_testing`.

No request reached Xendit. The probe used a local fake provider and the tests
used Guzzle `MockHandler`, so the real latency of Xendit and the chosen limits
were not measured. A simulation that Xendit processed but whose answer was lost
still leaves the local invoice `pending`; nothing reconciles it yet.

The frontend, the controllers, and the deploy repository were not changed. The
frontend already shows `response.data.message` for checkout errors.

## Batch 2: Withdrawals Held As Processing

Batch 2 holds the withdrawal balance before Xendit is called and records the
withdrawal in the new `saldo_withdrawals` table with status `processing`. The
disbursement request now uses the batch 1 timeouts and sends the stored
`external_id` as `X-IDEMPOTENCY-KEY`, and no database lock is held during it.
A clear 4xx rejection returns the balance through a `withdrawal_refund` history
row. A lost answer keeps the balance held. `withdrawal:check` runs every minute
and reads the Xendit status of due withdrawals with per-withdrawal delays.
`withdrawal:status` is the read-only command for the internal manual check.

### Scenarios

- **TOK-88-BE-12 — Confirm the disbursement API shapes in Xendit Test Mode.**
  Passed. A scratch PHP script used `vendor/` Guzzle and the local Test Mode
  key, which it refused to run without the `xnd_development_` prefix and never
  printed. It masked the account fields in its output. An unknown external ID
  returned `404 DIRECT_DISBURSEMENT_NOT_FOUND_ERROR`. One Rp10.000 disbursement
  to a sandbox BCA account returned HTTP 200 with `status: PENDING`. Sending it
  again with the same key returned `400 DUPLICATE_TRANSACTION_ERROR`, so Xendit
  rejects a reused key instead of replaying the first answer. Lookups by
  external ID returned a JSON array with `PENDING` for a few minutes and then
  `COMPLETED`.
- **TOK-88-BE-13 — Classify disbursement answers in the adapter.** Passed.
  `XenditServiceTest` checked that both disbursement requests carry the
  timeouts and that the create request carries the idempotency key. A 2xx
  returns `success` with the ID, status, and failure code. A 400 or 429 returns
  `error` with the Xendit message and code. A timeout, a 503, a connection
  reset, and a reused key return `unknown` with one `warning`. A lookup returns
  `found`, `not_found` only for the 404 not-found code, and `unknown` for a bare
  404, a 401, an empty list, an object, or a timeout.
- **TOK-88-BE-14 — Hold the balance before Xendit is called.** Passed.
  `SaldoWithdrawInputTest` showed an accepted withdrawal returns
  `withdrawal_status: processing`, keeps the deduction, and stores the Xendit
  ID. The stored `external_id` matches `dis-bca-{ulid}`, the body field, and
  the idempotency header. A Guzzle tap showed Xendit was called at the test's
  own transaction depth, outside the balance transaction.
- **TOK-88-BE-15 — Keep the balance held when the answer is lost.** Passed. A
  timeout returned HTTP 200 with `processing`. The balance stayed deducted and
  no refund row was written.
- **TOK-88-BE-16 — Return the balance when Xendit rejects the request.**
  Passed. A 400 returned the Xendit message with HTTP 400. The withdrawal was
  `failed` with `API_VALIDATION_ERROR`, a `withdrawal_refund` row was written,
  and the income was back to its starting value. `PaymentReturnCallerCompatibilityTest`
  kept the `Unknown error` message for a non-string provider message.
- **TOK-88-BE-17 — Keep the withdrawal processing when its outcome cannot be
  saved.** Passed. With the withdrawal update failing, the endpoint returned
  HTTP 200 with `processing` and wrote one `error` log naming the withdrawal and
  external ID without the account number.
- **TOK-88-BE-18 — Deduct and refund per balance bucket.** Passed.
  `SaldoWithdrawalDeductionTest` showed the deduction reports its refund and
  income portions, and the refund restores each bucket and continues the
  `saldo_before`/`saldo_after` chain. A refund without a balance row throws.
- **TOK-88-BE-19 — Report the withdrawal status in history.** Passed.
  `SaldoHistoryTest` showed a tracked withdrawal reports its status, while a
  refund row and a withdrawal without a status row report `null`. The refund
  row has the title `Pengembalian Saldo` and a description naming the bank.
- **TOK-88-BE-20 — Settle due withdrawals with `withdrawal:check`.** Passed.
  `WithdrawalCheckCommandTest` covered every lookup result: `COMPLETED`
  completes the withdrawal, and `FAILED` fails it and returns the balance.
  `PENDING` stays `processing` with checks spaced 1, 2, 5, 15, 60, and
  60 minutes. Not-found keeps the balance held inside the 10-minute grace and
  returns it with `NOT_FOUND` after it. A timeout stays `processing`. Two
  disbursements for one external ID stay `processing` with one `critical`
  log.
- **TOK-88-BE-21 — Limit what one run touches.** Passed. Withdrawals that
  were not due or were already settled were not looked up. `--limit=2` checked
  two of three due withdrawals, and `--limit=0` exited with `INVALID`.
- **TOK-88-BE-22 — Never settle a withdrawal twice.** Passed. Failing the same
  withdrawal twice and then applying `COMPLETED` kept the first `failed`
  result and wrote one refund only.
- **TOK-88-BE-23 — Log overdue withdrawals once.** Passed. A withdrawal still
  `processing` 24 hours after the hold wrote one `critical` log across two
  checks and kept the balance held.
- **TOK-88-BE-24 — Show the internal status.** Passed. `withdrawal:status`
  printed the count per status and listed only the overdue withdrawal by
  external ID, without the account number.
- **TOK-88-BE-25 — Schedule the check without overlap.** Passed. A test
  confirmed `withdrawal:check` runs every minute with `withoutOverlapping`.
  `php artisan schedule:list` on the temporary PostgreSQL database listed it
  next to the outbox commands. On that empty table, `withdrawal:check` reported
  `claimed=0` and `withdrawal:status` printed zero counts.
- **TOK-88-BE-26 — Verify the PostgreSQL locks.** Passed. The opt-in
  `PostgresWithdrawalClaimTest` held one due withdrawal locked from a second
  connection. A run with a one-second lock timeout skipped it, claimed the other
  withdrawal, and looked up only that one. The opt-in `PostgresSaldoLockingTest`
  still showed a withdrawal waiting on the locked balance read without reaching
  Xendit or writing a withdrawal row.
- **TOK-88-BE-27 — Prove the tests catch a regression.** Passed. Three changes
  were made one at a time and restored. Treating `unknown` as a rejection failed
  the timeout test with HTTP 400. Removing the `processing` guard in
  `WithdrawalService::settle()` failed the double-settle test with `completed`.
  Ignoring the not-found grace failed the not-found test inside the grace
  period.
- **TOK-88-BE-28 — Verify static analysis, formatting, and readability.**
  Passed. `vendor/bin/phpstan analyse --no-progress --memory-limit=1G`, the
  command behind `composer analyse`, returned `[OK] No errors` with PHP 8.3.
  `vendor/bin/pint --test` passed for 204 files. The `beberes` pass wrapped long
  lines, split stages that read two subjects, added WHY comments to new
  single-stage helpers, and restructured the first
  `PaymentReturnCallerCompatibilityTest` test into steps. The SQLite suite
  passed before and after the pass.
- **TOK-88-BE-29 — Check the full Unit and Feature suites.** Passed. SQLite in
  memory reported 420 passed and six skips with 5,432 assertions. PostgreSQL
  16.13 reported 426 tests and 5,450 assertions.

### Local Coverage

The checks ran with PHP 8.3.31 in the backend checkout. PostgreSQL used the same
temporary loopback-only cluster as batch 1, with the disposable database
`ecommerce_testing`.

The only requests to Xendit were the probe in TOK-88-BE-12, against the local
Test Mode business. The tests used Guzzle `MockHandler`. Real Xendit latency,
the 429 rate limit, and the `FAILED` status were not seen live. The not-found
grace of 10 minutes is an agreed limit, not a measured Xendit delay.

The local and browser flow, including `withdrawal:check` moving a real Test Mode
withdrawal from `processing` to `completed`, is covered by the frontend QA after
the frontend change. Webhooks for disbursements remain out of scope.
