# TOK-77 Saldo History Formatting QA

[TOK-77](https://muhammadjidan-31088882.atlassian.net/browse/TOK-77) addresses
seven PHPStan findings in the balance history list and detail.

## Local scenarios

- **TOK-77-BE-01 Recognized history.** Passed. Incoming rows retain the invoice
  ID and transaction-number fallback. Withdrawals retain the bank, account
  name, leading zeroes, and the existing text for missing join data.
- **TOK-77-BE-02 Unknown type.** Passed. Null and unrecognized types have a
  neutral title and description. The original type and amount remain in the
  response. The list includes valid rows alongside them and the detail agrees.
- **TOK-77-BE-03 Missing amount.** Passed. A withdrawal with `price: null` uses
  `—` in its description. The stored nominal remains null. A recorded zero
  still formats as `Rp0`. The list and detail use the same description.
- **TOK-77-BE-04 Detail lookup.** Passed. A blank ID returns the existing
  error. A valid ID without a history record returns success with an empty array.
- **TOK-77-BE-05 Static analysis.** Passed. PHPStan identified exactly three
  stale ignore patterns. The baseline went from 67 to 64 entries and from
  82 to 75 findings. Direct analysis confirmed seven removed findings and
  zero new findings.
- **TOK-77-BE-06 Numeric strings.** Passed. A test connection configured to
  fetch numbers as strings returned `"450000"` in the authenticated history
  JSON. The detail retained the same value and formatted the description as
  `Rp450.000`. The PDO setting was restored after the test.

## Local checks

- On the original code, the new fixtures reproduced the unhandled match and
  the missing amount displayed as `Rp0`.
- `php artisan test --compact` on PHP 8.3 went from 304 passed and 4,525
  assertions to 307 passed and 4,554 assertions. One PostgreSQL ILIKE test
  was skipped in both runs on SQLite.
- The combined saldo history, saldo read, and withdrawal compatibility tests
  passed with 11 tests and 114 assertions.
- `composer analyse` and `composer format:check` passed.
- After documenting the numeric-string contract, the history and read
  controller suites passed with 11 tests and 112 assertions. The full backend
  suite passed with 308 tests and 4,562 assertions, with the same SQLite skip.

Local verification uses SQLite in memory and synthetic history rows. It covers
the read paths and the withdrawal response contract exercised by the existing
compatibility test.
