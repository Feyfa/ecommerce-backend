# TOK-81 Checkout Persistence PHPStan QA

Local verification used the backend task branch `task/jd-tok-81`, based on
`c393e92`. The scope covers 18 type findings in the server-built checkout data
used to save the invoice, delete cart rows, reduce stock, and record product
outbox entries.

## Scenarios

- **TOK-81-BE-01 — Preserve the buyer identity check.** `processCheckout()`
  now narrows the authenticated model to `User` and still loads the database
  record whose name is used for the Virtual Account. New `CheckoutTest` cases
  for a request without a buyer and for a buyer deleted after authentication
  returned `401` with `{"result":"error","message":"Unauthorized"}` and no
  Virtual Account request. Both cases also passed against the `main`
  controller in an isolated copy, so the response is existing behavior.
- **TOK-81-BE-02 — Preserve invoice persistence with partial inputs.**
  `AddressLocationTest::checkout_copies_buyer_and_seller_location_snapshots`
  passed with its existing partial checkout fixture. The new shapes mark keys
  read with `??` as optional, so the service still applies its defaults.
  Review confirmed that the buyer address guard runs after the requirement
  loop and returns the same message, so the first error for several empty
  inputs is unchanged.
- **TOK-81-BE-03 — Preserve checkout responses and side effects.**
  `successful_checkout_returns_the_created_invoice_id` returned the stored
  invoice ID, kept both payment identifiers, and recorded one pending product
  outbox message. `test_checkout_rejects_unusable_provider_responses` kept
  HTTP `400` with `Payment creation failed`, `Data Xendit Empty`, and
  `Unknown error`, and left invoices, outbox, cart, and stock unchanged. The
  removed `??` fallbacks were unreachable because each service result shape
  always carries its message or invoice ID.
- **TOK-81-BE-04 — Preserve the checkout key.** A PHP 8.3 probe hashed a buyer
  UUID and two sorted cart UUIDs with and without `JSON_THROW_ON_ERROR`. Both
  hashes were identical, so keys stored on existing invoices still match.
  The failing-encode path cannot be reached with UUID and cart ID strings and
  was reviewed, not tested.
- **TOK-81-BE-05 — Remove the 18 scoped findings.** `composer analyse` passed
  with existing unmatched-ignore reporting. A temporary config with the same
  level and paths but without the baseline reported 45 findings before the
  change and 27 after it. The comparison showed 13 removals in
  `CheckoutService`, 5 in `CheckoutController`, and no new findings. The 18
  removed baseline entries each had a count of one.
- **TOK-81-BE-06 — Verify the readability pass.** The `beberes` pass reviewed
  the changed application functions and made no edits, so the analysis and
  test results stayed the same. The regression tests added afterwards were
  checked against the same rules. `composer format:check` passed for 186 files.
- **TOK-81-BE-07 — Verify checkout on PostgreSQL.** The full Unit and Feature
  suites passed on PostgreSQL 16.13 with 332 tests and 4,684 assertions. This
  run included the `ILIKE` test that SQLite skips. SQLite in memory reported
  332 tests, 4,677 assertions, and one skip, for 331 passing tests.

## Local Coverage

PHPStan and PHPUnit ran with PHP 8.3.31 in an isolated copy of the backend
working tree without `.env`. Laravel used a synthetic app key and the public
test disk. PostgreSQL ran as a temporary cluster on `127.0.0.1:55432` with a
random password, because the scratch path is too long for a macOS Unix
socket. Pint ran in the backend working tree.

Checkout feature tests use test users and mocked Xendit responses. They bypass
Clerk transport authentication. No automated test covers the
`CHECKOUT_ALREADY_PROCESSED` lookup by checkout key; TOK-81-BE-04 relies on the
hash probe for that path.
