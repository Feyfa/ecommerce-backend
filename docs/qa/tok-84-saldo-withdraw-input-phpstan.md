# TOK-84 Saldo Withdraw Input PHPStan QA

Local verification used backend branch `task/jd-tok-84`, based on `de0b94f`.
The scope covers eight findings in `SaldoController::withdrawSaldo()` for
`POST /api/saldo-withdraw`.

## Scenarios

- **TOK-84-BE-01 — Record the original withdraw behavior.** Passed. The new
  `SaldoWithdrawInputTest` ran against the original controller from `main`.
  Six tests covering existing behavior passed. The four tests for the new
  behavior failed as predicted: the account number `1234` returned HTTP 400
  instead of 422, the amount `true` returned HTTP 400 instead of 422, and
  `"150000.5"` and `-5000` both returned HTTP 200.
- **TOK-84-BE-02 — Measure the original impact of negative and decimal
  amounts.** Passed. A temporary probe outside the repository ran the original
  controller with a mock provider that accepts every disbursement. `"-5000"`
  returned HTTP 200, sent `amount: -5000` to the provider with the description
  `Sebesar Rp-5.000`, raised `saldo_refund` from 0 to 5,000, and wrote a
  history row with price `-5000`. `"150000.5"` returned HTTP 200, sent
  `amount: 150000`, deducted 150,000 from the income, and described the
  transfer as `Sebesar Rp150.001`.
- **TOK-84-BE-03 — Withdraw valid amounts.** Passed. The JSON number `1`, the
  text `"100000"`, and the JSON number `1000000` each returned HTTP 200 with a
  `withdrawal` history item. Each provider request carried an integer
  `amount`, the account number as text, and a description formatted from the
  integer. The income decreased by each amount, and exactly one history row
  was written per withdrawal.
- **TOK-84-BE-04 — Keep the 401 responses.** Passed. A request without an
  identity and a request from a deleted local user both returned HTTP 401
  `Unauthorized`.
- **TOK-84-BE-05 — Keep the 400 empty-account response.** Passed. A missing,
  null, blank, whitespace-only, `false`, `0`, `"0"`, and `[]` account each
  returned HTTP 400 `Rekening Bank Tidak Boleh Kosong`.
- **TOK-84-BE-06 — Reject non-string accounts.** Passed. `1234`, `1.5`,
  `true`, and a list each returned HTTP 422 `Rekening Bank Harus Berupa Teks`.
- **TOK-84-BE-07 — Keep the 400 empty-amount response.** Passed. A missing,
  null, blank, whitespace-only, `false`, `0`, `"0"`, and `[]` amount each
  returned HTTP 400 `Nominal Tidak Boleh Kosong`.
- **TOK-84-BE-08 — Reject non-numeric amount types.** Passed. `true`, a list,
  and a JSON object each returned HTTP 422
  `Nominal Harus Berupa Angka Atau Teks`.
- **TOK-84-BE-09 — Reject amounts that are not whole rupiah.** Passed.
  `"150000.5"`, `"1e5"`, `"+5000"`, `"1.000"`, `"1 000"`, `"0x1A"`, `"abc"`,
  and the JSON number `150000.5` each returned HTTP 400
  `Format Nominal Tidak Valid`.
- **TOK-84-BE-10 — Reject amounts below the minimum.** Passed. `-5000`,
  `"-5000"`, `"00"`, and `"-99999999999999999999"` each returned HTTP 400
  `Nominal Minimal Rp1`.
- **TOK-84-BE-11 — Keep the maximum response.** Passed. `1000001`,
  `"1000001"`, and `"99999999999999999999"` each returned HTTP 400
  `Nominal Tidak Boleh Lebih Dari Rp1.000.000`.
- **TOK-84-BE-12 — Keep the insufficient balance message.** Passed. With an
  income of 50,000, `"100000"` returned HTTP 400
  `Saldo Anda Hanya Rp50.000, Tidak Bisa Tarik Saldo Sebesar 100.000`.
- **TOK-84-BE-13 — Leave rejected requests without side effects.** Passed.
  After every rejection in BE-04 through BE-12, the provider history stayed
  empty, the income kept its previous value, and no history row was written.
  The existing `PaymentReturnCallerCompatibilityTest` also passed for an
  unknown account, a zero balance, and a provider rejection.
- **TOK-84-BE-14 — Remove the scoped PHPStan findings.** Passed. After the
  controller change, analysis of `app/` at `level: max` without the baseline
  went from 14 to 6 findings with no new findings. The remaining six are in
  `SaldoService::saveSaldoAfterDisbursement()`. After deleting the seven
  `SaldoController` baseline entries, `composer analyse` returned
  `[OK] No errors`.
- **TOK-84-BE-15 — Verify readability and formatting.** Passed. Before and
  after the `beberes` pass, the saldo test classes passed with 23 tests and
  356 assertions. The pass moved rupiah formatting into one helper with a named
  decimal-places constant, moved the formatted amount next to the balance
  check, and documented who bears the cost of a negative withdrawal.
  `composer analyse` and `composer format:check` passed afterward for 188 PHP
  files.
- **TOK-84-BE-16 — Check the full Unit and Feature suites.** Passed.
  `php vendor/bin/phpunit --testsuite Unit,Feature` reported 360 tests and
  5,084 assertions on PostgreSQL 16.13. SQLite in memory reported 360 tests,
  5,077 assertions, and one skip for the PostgreSQL `ILIKE` search test.

## Local Coverage

PHPStan, Pint, and PHPUnit ran with PHP 8.3.31 in the backend checkout.
SQLite used the in-memory database from `phpunit.xml`. PostgreSQL used a
temporary cluster in a scratch directory, reachable only on loopback, with the
disposable database `ecommerce_testing`.

The withdraw tests bypass Clerk transport authentication and replace the
Xendit HTTP client with a Guzzle mock, so the real Xendit response to negative
or decimal amounts was not observed. `json_encode()` writes `100.0` as `100`,
so a JSON float without a fraction was not sent. The tests do not exercise
concurrent withdrawals; the balance check, disbursement, and deduction still
run without a database transaction or row lock, which remains outside this
task. The frontend was not changed or run.
