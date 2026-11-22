# TOK-79 Checkout Data Contracts

Local QA covers how the backend loads checkout data and reuses the same
seller packages in the payment snapshot.

## Scenarios

- **TOK-79-BE-01 — Resolve the local buyer.** Controller review confirmed
  that the user record is checked before cart reconciliation and that
  unauthorized requests still receive the existing `401` body. The
  authenticated path passed in `CheckoutTest`.
- **TOK-79-BE-02 — Preserve availability and address handling.**
  `KeranjangServiceTest`, `AddressLocationTest`, and
  `ProductAvailabilityTest` passed, including missing or unverified
  addresses and checkout items whose availability changed.
- **TOK-79-BE-03 — Preserve the checkout representation.**
  `CheckoutTest::test_checkout_data_preserves_null_item_prices` passed
  before and after the checkout projection change on SQLite and
  PostgreSQL. Two items from one seller stay in one package, the JNT
  price is preserved, both legacy price fields remain `null`, and the
  product total reflects the item with a price.
- **TOK-79-BE-04 — Preserve the payment snapshot consumer.**
  `CheckoutTest` passed for successful checkout with a mocked virtual
  account response and for provider errors. Existing tests also exercise
  checks for quantity, price, and address changes before payment.
- **TOK-79-BE-05 — Reduce the baseline by 15 findings.**
  `composer analyse` passed with the repository config. The same
  PHPStan level and paths with Larastan and without the baseline reported
  67 findings on the starting code and 52 on the task branch. Comparing
  the reports showed only the scoped removals. `composer format:check` passed.
- **TOK-79-BE-06 — Reject a missing price before payment.**
  `CheckoutTest::test_null_price_blocks_virtual_account_creation` returned
  `409 CHECKOUT_INVALID`, kept the cart row, and asserted zero Xendit calls
  and zero invoices. `CheckoutTest::test_locked_checkout_rejects_price_that_became_null`
  verified that a price removed after the snapshot triggers
  `CheckoutAvailabilityException` during the locked check. Both regressions
  failed on the unguarded code and passed on SQLite and PostgreSQL.

## Local Coverage

The final Unit and Feature suites ran with PHP 8.3 using
`php vendor/bin/phpunit --do-not-cache-result`. SQLite in memory passed
329 tests with 4,671 assertions and skipped the PostgreSQL `ILIKE` test.
Isolated PostgreSQL 16 passed all 330 tests with 4,678 assertions.
`CheckoutTest` passed 20 tests with 97 assertions before and after the
final step renumbering in the locked check.

A local price projection probe returned a float on SQLite and a numeric
string on PostgreSQL for the same decimal value. Both drivers returned
`null` for an absent price and produced the same product total.

Endpoint tests use test users and mock provider responses. The local
checkout tests exercise the controller without a live Clerk session.
