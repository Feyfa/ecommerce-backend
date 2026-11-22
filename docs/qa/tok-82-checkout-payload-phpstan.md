# TOK-82 Checkout Payload PHPStan QA

Local verification used backend branch `task/jd-tok-82`, based on `81c25fc`.
The scope covers ten findings in the checkout request and its snapshot
consumers.

## Scenarios

- **TOK-82-BE-01 — Reject fields with invalid types.** Passed. Eight
  `CheckoutTest` cases covered the payment slug, courier rows, seller IDs,
  courier name, note rows, note text, and cart item IDs. The endpoint returned
  HTTP 422 with the field error before cart reconciliation or payment.
- **TOK-82-BE-02 — Accept the frontend's checkout payload.** Passed. The
  checkout fixtures used the frontend field names and structure. A request
  with three numeric-string totals and a blank seller note succeeded with
  normal request normalization, created an invoice, and saved an empty note.
- **TOK-82-BE-03 — Return a refreshed quote for a stale client snapshot.**
  Passed. An outdated total returned HTTP 409 `CHECKOUT_CHANGED` and the
  current server total before any Virtual Account request.
- **TOK-82-BE-04 — Rebuild the quote after checkout changes under the lock.**
  Passed. With a simulated exception during locked validation, the endpoint
  returned HTTP 409 `CHECKOUT_CHANGED`, the selected courier, and the buyer's
  note. The test verified this path without a Virtual Account request.
- **TOK-82-BE-05 — Remove the ten scoped PHPStan findings.** Passed.
  Comparing analyses with the same `app/` scope and `level: max` without the
  baseline showed 27 findings before and 17 after. Seven findings disappeared
  from `CheckoutController` and three from `CheckoutService`. The remaining
  finding messages and counts match the original analysis. With the original
  baseline, PHPStan reported exactly seven unmatched entries. After deleting
  them, `composer analyse` returned `[OK] No errors`.
- **TOK-82-BE-06 — Verify readability and formatting.** Passed. Before and
  after the `beberes` pass, `composer analyse` and `composer format:check`
  passed, and `CheckoutTest` with `AddressLocationTest` passed with 50 tests
  and 245 assertions. The final format check covered 186 PHP files.
- **TOK-82-BE-07 — Check the full Unit and Feature suites.** Passed.
  `php vendor/bin/phpunit --testsuite Unit,Feature` reported 343 tests and
  4,746 assertions on PostgreSQL 16.13. SQLite in memory reported 343 tests,
  4,739 assertions, and one skip for the PostgreSQL `ILIKE` search test.
- **TOK-82-BE-08 — Review the final checkout diff.** The review checked the
  frontend request and response handlers, the validated field types, the
  seller note fallback, and the ordering of snapshot checks before payment.
  The PHPStan and Pint checks passed again. Both full test suites reproduced
  the results in TOK-82-BE-07.

## Local Coverage

PHPStan and PHPUnit ran with PHP 8.3.31 in an isolated copy without the
project `.env`. PostgreSQL used a temporary cluster reachable only through a
local Unix socket. Pint ran on the backend checkout.

The checkout feature tests use local user fixtures and mock Xendit. They
bypass Clerk transport authentication. The lock-conflict controller test
simulates a change under the lock and verifies snapshot rebuilding. The
existing checkout service tests exercise changed quantity, price, and
address against database state.
