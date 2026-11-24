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
