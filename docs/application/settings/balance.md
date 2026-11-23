# Balance

This document explains the backend API contract for balance behavior in `Pengaturan`.

## Applies To

Buyer and seller authenticated users.

## Main Files

- `routes/api.php`
- `app/Http/Controllers/SaldoController.php`
- `app/Services/SaldoService.php`
- `app/Services/PaymentService.php`
- `app/Services/TransactionService.php`
- `app/Services/XenditService.php`

## Routes

```text
GET /api/saldo
GET /api/saldo-history
POST /api/saldo-withdraw
```

## GET /api/saldo

Returns the authenticated user's balance summary.

Both balance read endpoints require a local user record for the authenticated
identity. The `auth.api` middleware can return HTTP 401 with numeric
`status: 401` before the controller runs. If the controller's local user check
fails, it returns HTTP 401 with `{"status":"error","message":"Unauthorized"}`.

Success response:

```json
{
  "status": "success",
  "saldoIncome": 0,
  "saldoRefund": 0,
  "saldoTotal": 0
}
```

## GET /api/saldo-history

Returns balance history rows for the authenticated user.

Optional request fields:

- `startDate` and `endDate` are strings. A missing, null, or blank value in
  either field skips the date range filter.
- `saldo_history_current_ids` is a JSON string containing an array of UUID
  strings. Missing, null, blank, or `[]` values exclude no IDs.

After authentication, a non-string date field or an invalid JSON UUID list
returns HTTP 422 with `status: "error"` and a string `message`. The service
does not run the history query for a rejected filter.

Filtering:

- When both dates are nonempty, `SaldoService` parses them and filters by
  `DATE(sh.created_at)` between `startDate` and `endDate`.
- Existing ids in `saldo_history_current_ids` are excluded for incremental loading.

Description rules:

- Incoming balance rows are shown as `Pembelian Dari {buyer_name} - INV {invoice_id}`.
- `{invoice_id}` should come from `transaction_invoices.id` so it matches the invoice displayed in the seller transaction detail modal.
- If `transaction_invoices.id` cannot be resolved, `SaldoService` falls back to `transaction_users.transaction_number`.
- Withdrawal balance rows include the formatted withdrawal amount, payment slug, account number, and account holder name.
- If a withdrawal has no recorded amount, its description uses `—`
  and the response keeps `price: null`.
- An unrecognized or null type uses the title `Mutasi Saldo` and the description
  `Jenis mutasi saldo tidak dikenali.` The response keeps the stored type and price.
- The `price` field preserves the database driver's representation as a JSON
  number, a numeric string such as `"450000"`, or `null` if no amount is recorded.

Success response:

```json
{
  "status": "success",
  "saldoHistory": []
}
```

## POST /api/saldo-withdraw

Withdraws balance to one saved withdrawal bank account.

Required request fields:

- `paymentAccount`: the saved account number, as text.
- `wihtdrawPrice`: the amount in whole rupiah, as a JSON integer or digit text.

The request field is currently spelled `wihtdrawPrice`. Keep the spelling unless both frontend and backend are changed together.

The frontend sends the JSON number `1` until the user types an amount, and
digit text after that. Both shapes are accepted.

Validation runs in this order. Each rejection returns `status: "error"` and a
string `message`:

1. A missing or deleted local user returns HTTP 401 `Unauthorized`.
2. A nonempty `paymentAccount` that is not text, such as a number, boolean,
   or list, returns HTTP 422 `Rekening Bank Harus Berupa Teks`.
3. A missing or empty account (`null`, `false`, `0`, `"0"`, `[]`, or blank
   text) returns HTTP 400 `Rekening Bank Tidak Boleh Kosong`.
4. A nonempty `wihtdrawPrice` that is neither a JSON number nor text, such as
   a boolean, list, or object, returns HTTP 422
   `Nominal Harus Berupa Angka Atau Teks`.
5. A missing or empty amount, using the same empty values as the account,
   returns HTTP 400 `Nominal Tidak Boleh Kosong`.
6. An amount that is not a whole number returns HTTP 400
   `Format Nominal Tidak Valid`. This covers decimals (`"150000.5"` or
   `150000.5`), exponents (`"1e5"`), a leading `+`, grouping separators
   (`"1.000"`), and other text. Digit text may start with `-`, so negative
   amounts reach the minimum check instead.
7. An amount below `1` returns HTTP 400 `Nominal Minimal Rp1`.
8. An amount above `1000000` returns HTTP 400
   `Nominal Tidak Boleh Lebih Dari Rp1.000.000`.
9. The payment account must exist for the authenticated user.
10. The authenticated user must have a nonzero total balance of at least the
    amount. The balance is read under the row lock described below. A user
    without a balance row has a total of Rp0.

Every rejection happens before the Xendit request and before any balance or
history change. The parsed integer is the only amount used for the response
messages, the Xendit request, and the balance deduction.

Side effects:

- Calls Xendit disbursement using the saved payment account.
- Deducts balance after successful disbursement, refund first and then income.
- Creates a withdrawal balance history row.
- Returns the new balance history item.

An Xendit rejection returns HTTP 400 with the message from the
[Xendit adapter](../integrations/xendit.md#adapter-result-contract) before the
controller attempts to change the balance or write a history row.

Concurrency:

- After the account lookup, the balance check, the Xendit request, and the
  balance deduction run inside one database transaction. The transaction locks
  the user's `saldo_users` row with `FOR UPDATE`.
- A second withdrawal for the same user waits until the first transaction
  ends, then reads the reduced balance.
- A seller income credit from transaction approval
  (`TransactionService::transferSaldo()`) locks the same row. It waits for a
  running withdrawal instead of overwriting the deduction with a stale
  income value.
- The lock is held during the Xendit HTTP request. The Xendit client has no
  timeout, so a hanging request also delays other balance writes for that
  user.
- SQLite ignores the lock clause. The opt-in
  `tests/Integration/PostgresSaldoLockingTest.php` verifies the wait on
  PostgreSQL.

Failure after disbursement: when the balance deduction fails after Xendit
accepted the disbursement, the transaction rolls back and the balance and
history stay unchanged, although the provider may still send the money. The
endpoint logs a critical event with the user ID, the amount, and the Xendit
external ID, without the account number or holder name. A rejected deduction
returns HTTP 500 with
`Penarikan Sudah Diproses Tetapi Saldo Gagal Diperbarui, Jangan Ulangi Penarikan`.
A database error returns the framework's HTTP 500 response. Reconciliation
with Xendit is manual.

The returned history item uses the same description and amount representation
as the history list.

Success response:

```json
{
  "status": "success",
  "saldoHistory": {}
}
```
