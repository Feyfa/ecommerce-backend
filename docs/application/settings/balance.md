# Balance

This document explains the backend API contract for balance behavior in `Pengaturan`.

## Applies To

Buyer and seller authenticated users.

## Main Files

- `routes/api.php`
- `app/Http/Controllers/SaldoController.php`
- `app/Services/SaldoService.php`
- `app/Services/PaymentService.php`
- `app/Services/XenditService.php`

## Routes

```text
GET /api/saldo
GET /api/saldo-history
POST /api/saldo-withdraw
```

## GET /api/saldo

Returns the authenticated user's balance summary.

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

- `startDate`
- `endDate`
- `saldo_history_current_ids`

`saldo_history_current_ids` is expected as JSON and is decoded into an array.

Filtering:

- When date fields are supplied, `SaldoService` filters by `DATE(sh.created_at)` between `startDate` and `endDate`.
- Existing ids in `saldo_history_current_ids` are excluded for incremental loading.

Description rules:

- Incoming balance rows are shown as `Pembelian Dari {buyer_name} - INV {invoice_id}`.
- `{invoice_id}` should come from `transaction_invoices.id` so it matches the invoice displayed in the seller transaction detail modal.
- If `transaction_invoices.id` cannot be resolved, `SaldoService` falls back to `transaction_users.transaction_number`.
- Withdrawal balance rows include the formatted withdrawal amount, payment slug, account number, and account holder name.

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

- `paymentAccount`
- `wihtdrawPrice`

The request field is currently spelled `wihtdrawPrice`. Keep the spelling unless both frontend and backend are changed together.

Validation rules:

- `paymentAccount` must not be empty.
- `wihtdrawPrice` must not be empty.
- `wihtdrawPrice` must be numeric.
- `wihtdrawPrice` must be at most `1000000`.
- The payment account must exist for the authenticated user.
- The authenticated user must have enough total balance.

Side effects:

- Calls Xendit disbursement using the saved payment account.
- Deducts balance after successful disbursement.
- Creates a withdrawal balance history row.
- Returns the new balance history item.

Success response:

```json
{
  "status": "success",
  "saldoHistory": {}
}
```
