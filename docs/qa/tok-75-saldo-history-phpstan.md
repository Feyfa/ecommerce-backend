# TOK-75 Saldo History Join Attributes QA

[TOK-75](https://muhammadjidan-31088882.atlassian.net/browse/TOK-75) covers
the eight undefined-property findings in `SaldoService::getSaldoHistory()`
and `getSaldoById()`. Both readers access the same four nullable attributes
selected by their existing LEFT JOINs. The response shape and description
text retain their existing behavior.

## Local regression scenarios

Both service methods ran against the same SQLite in-memory fixtures before
and after the change.

| Scenario | Expected result | Local result |
| --- | --- | --- |
| Incoming balance with a linked invoice | List and detail show the buyer name and invoice ID. | Passed |
| Incoming balance with no linked invoice | Both readers use the transaction number as the invoice reference. | Passed |
| Withdrawal with a linked bank account | Both readers show the formatted amount, bank slug, account number with leading zeroes, and account name. | Passed |
| Withdrawal with no linked bank account | Both readers preserve the existing description, including whitespace in the empty bank fields. | Passed |

## Local checks

- `composer analyse` passed at `level: max` on PHP 8.3. With the original
  baseline and the fix applied, PHPStan reported exactly four unmatched
  patterns for these attributes. Removing them reduced the baseline from
  78 to 74 entries and from 99 to 91 occurrences.
- `composer format:check` passed for 184 files.
- `php artisan test --compact --filter=SaldoHistoryTest` passed before and
  after the fix with 2 tests and 20 assertions.
- `php artisan test --compact` passed with 299 tests and 4,470 assertions.
  The PostgreSQL ILIKE test was skipped on SQLite as in the baseline run.
- `git diff --check` passed.

Local tests invoke the service read methods with synthetic rows. They use no
live account data or payment-provider calls.
