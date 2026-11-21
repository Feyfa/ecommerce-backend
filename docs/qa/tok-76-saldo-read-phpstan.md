# TOK-76 Saldo Read PHPStan QA

[TOK-76](https://muhammadjidan-31088882.atlassian.net/browse/TOK-76) addresses
nine PHPStan findings across the balance summary and history read endpoints.
The local test environment uses PHP 8.3 and SQLite in memory.

## Scenarios

- **TOK-76-BE-01 Balance summary.** Passed. The endpoint returned zeroes
  without a row and the expected income, refund, and total once populated.
- **TOK-76-BE-02 History query.** Passed. The endpoint returned the owner's
  unseen row and excluded the displayed, out-of-range, and other-owner rows.
- **TOK-76-BE-03 Empty filters.** Passed. Missing, null, blank, and an empty
  JSON list used the existing defaults in all four requests.
- **TOK-76-BE-04 Invalid filters.** Passed. Ten input variants returned
  HTTP 422 with a string message before the mocked read service could run.
- **TOK-76-BE-05 Unauthorized reads.** Passed. Guests and deleted users
  received HTTP 401 on both routes, including with an invalid history filter.
- **TOK-76-BE-06 Static analysis.** Passed. Exactly nine findings disappeared.
  The remaining findings matched the updated baseline.

## Local checks

- Before the change, `composer analyse` passed and
  `php artisan test --compact` reported 299 passed, one skipped, and
  4,470 assertions.
- `php artisan test --compact --filter=SaldoReadControllerTest` passed with
  5 tests and 55 assertions. The run including `SaldoHistoryTest` and
  `PaymentReturnCallerCompatibilityTest` passed with 8 tests and 85 assertions.
- With the original baseline and the code change, PHPStan reported seven
  unmatched patterns plus a reduced occurrence count for the controller user
  ID pattern. The baseline went from 74 to 67 entries and from 91 to 82
  occurrences. Direct analysis without the baseline confirmed the same nine
  removed findings and zero new findings.
- Before and after the readability pass, `composer analyse` passed,
  `composer format:check` passed for 185 files, and
  `php artisan test --compact` reported 304 passed, one skipped, and
  4,525 assertions. `git diff --check` passed.

## Local coverage

The feature tests use local user fixtures and bypass Clerk transport
authentication. The existing PostgreSQL ILIKE test is skipped under SQLite.
Tests exercise the HTTP API with synthetic rows and no payment-provider calls.
