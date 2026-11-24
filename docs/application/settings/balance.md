# Balance

This document explains the backend API contract for balance behavior in `Pengaturan`.

## Applies To

Buyer and seller authenticated users.

## Main Files

- `routes/api.php`
- `app/Http/Controllers/SaldoController.php`
- `app/Services/SaldoService.php`
- `app/Services/WithdrawalService.php`
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
- A `withdrawal_refund` row returns the balance of a failed withdrawal. Its
  title is `Pengembalian Saldo` and its description is
  `Pengembalian Penarikan Saldo Sebesar {amount} Dari Bank {slug} {account} ({name})`.
- An unrecognized or null type uses the title `Mutasi Saldo` and the description
  `Jenis mutasi saldo tidak dikenali.` The response keeps the stored type and price.
- The `price` field preserves the database driver's representation as a JSON
  number, a numeric string such as `"450000"`, or `null` if no amount is recorded.

Every row also carries `withdrawal_status`:

| Value | Meaning |
| --- | --- |
| `processing` | The balance is held while Xendit has not confirmed the transfer. |
| `completed` | Xendit reported the transfer as `COMPLETED`. |
| `failed` | The withdrawal failed and a `withdrawal_refund` row returned the balance. |
| `null` | Not a withdrawal, or a withdrawal made before TOK-88, which has no tracked status. |

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

Every rejection above happens before the Xendit request and before any balance
or history change. The parsed integer is the only amount used for the response
messages, the Xendit request, and the balance deduction.

Withdrawal flow since TOK-88:

1. **Hold.** One short transaction locks the balance row, checks the balance,
   deducts the amount (refund first, then income), writes a `withdrawal`
   history row, and creates a `saldo_withdrawals` row with status `processing`.
   The row stores a new `external_id` (`dis-{slug}-{ulid}`) before anything is
   sent. See [saldo_withdrawals](../../architecture/database.md#saldo_withdrawals).
2. **Send.** After the transaction commits, the controller calls Xendit with
   that `external_id` as the body field and the `X-IDEMPOTENCY-KEY`. No
   database lock is held during the request.
3. **Apply the answer.** The answer is classified by the
   [Xendit adapter](../integrations/xendit.md#disbursement-results):

| Xendit answer | Withdrawal | Balance | Response |
| --- | --- | --- | --- |
| `success`, status `PENDING` | `processing`; the Xendit ID is stored | held | HTTP 200 |
| `success`, status `COMPLETED` | `completed` | deducted | HTTP 200 |
| `success`, status `FAILED` | `failed` | returned | HTTP 400 `Penarikan Gagal, Saldo Sudah Dikembalikan` |
| `error` (4xx) | `failed`; the error code is stored | returned | HTTP 400 with the Xendit message |
| `unknown` (timeout, 5xx, reused key) | `processing` | held | HTTP 200 |

A returned balance goes back to the same refund and income buckets it came
from, through a `withdrawal_refund` history row. A rejected request therefore
leaves two history rows: the failed withdrawal and its refund.

If saving the answer fails with a database error, the endpoint logs an `error`
with the withdrawal ID and external ID and still returns HTTP 200 with
`processing`. The balance is already held, and `withdrawal:check` reads the
real status later. A Xendit answer can therefore no longer leave money sent
without a deduction.

The success response contains the withdrawal history row, including
`withdrawal_status`. Xendit normally answers `PENDING`, so a new withdrawal
usually shows `processing` until `withdrawal:check` sees `COMPLETED`.

Concurrency:

- The hold transaction locks the user's `saldo_users` row with `FOR UPDATE`
  only while it checks and deducts the balance.
- A second withdrawal for the same user waits until the first hold commits,
  then reads the reduced balance.
- The Xendit request runs after the hold commits. Before TOK-88 the lock was
  held during the request, which had no timeout.
- Applying an answer locks the `saldo_withdrawals` row and changes it only
  while it is still `processing`. The endpoint and `withdrawal:check` can
  therefore never return the same balance twice. A refund then locks the
  `saldo_users` row. The hold only inserts a new withdrawal row, so this order
  cannot deadlock with a hold or an income credit.
- A seller income credit from transaction approval
  (`TransactionService::transferSaldo()`) locks the same row. It waits for a
  running withdrawal instead of overwriting the deduction with a stale
  income value. The credit runs inside the approval transaction after the
  `transaction_users` row is locked, so locks are always taken in the order
  `transaction_users`, then `saldo_users`. See
  [Seller Approval](../transaction.md#seller-approval).
- `FOR UPDATE` only locks a row that exists. Before the locked read, the
  credit inserts an empty `saldo_users` row for a seller who has none, with
  `insertOrIgnore` (`INSERT ... ON CONFLICT DO NOTHING` on PostgreSQL). When
  another approval is creating the same seller's first row and has not
  committed, the insert waits for it and then does nothing, so the first
  credits also queue on the row lock. Before this, the losing approval of two
  concurrent first credits failed on the unique `user_id` and returned HTTP
  `500`. Withdrawals never create the row.
- SQLite ignores the lock clause. The opt-in
  `tests/Integration/PostgresSaldoLockingTest.php` verifies the wait on
  PostgreSQL, and `tests/Integration/PostgresSaldoFirstCreditLockingTest.php`
  verifies that a first credit waits for a concurrent first row.

The returned history item uses the same description and amount representation
as the history list.

Success response:

```json
{
  "status": "success",
  "saldoHistory": {}
}
```

## Withdrawal Status Checks

`php artisan withdrawal:check` reads the Xendit status of `processing`
withdrawals. The `backend-scheduler` container runs it every minute with
`withoutOverlapping(10)`, so a slow run never overlaps the next one. Settings
live in `config/withdrawal.php`.

Each run:

1. Claims at most `check_batch_size` (20) due withdrawals in a short
   transaction. PostgreSQL uses `FOR UPDATE SKIP LOCKED`, so a manual run next
   to the scheduler claims different rows.
2. Schedules the next check before calling Xendit. The delays after each check
   are 1, 2, 5, 15, and then 60 minutes (`check_backoff_minutes`). A crashed run
   therefore waits for the next delay instead of retrying every minute.
3. Calls `GET /disbursements?external_id=` without holding a database lock and
   applies the answer:

| Lookup result | Action |
| --- | --- |
| `COMPLETED` | `completed` |
| `FAILED` | `failed`, balance returned, Xendit `failure_code` stored |
| `PENDING` or another status | stays `processing` (an unknown status also logs `warning`) |
| Not found, withdrawal older than 10 minutes | `failed` with `NOT_FOUND`, balance returned |
| Not found, withdrawal 10 minutes old or less | stays `processing` |
| No usable answer (timeout, 5xx, other 4xx) | stays `processing` |
| Several disbursements for one external ID | stays `processing`, logs `critical` |

The 10-minute grace (`not_found_grace_minutes`) covers a request Xendit had not
finished storing. After it, a missing disbursement means Xendit never received
the request, for example when the connection failed or the process stopped
after the hold. If Xendit did receive a request but still answers not-found
after 10 minutes, the balance is returned while the money may be sent. This
risk was accepted for TOK-88.

Time alone never returns a balance. A withdrawal still `processing` after
`overdue_hours` (24) writes one `critical` log with the withdrawal ID, user ID,
amount, external ID, and check count. The balance stays held until Xendit
confirms a result. Logs never contain the account number or holder name.

`php artisan withdrawal:status` is the read-only command for that manual check.
It shows the count per status, the oldest `processing` withdrawal, and a table
of withdrawals processing longer than 24 hours with their IDs, amount, external
ID, creation time, check count, and last check. It does not call Xendit.

Both commands skip with a warning when the `saldo_withdrawals` table does not
exist yet, for example while a deploy has not run its migration.
