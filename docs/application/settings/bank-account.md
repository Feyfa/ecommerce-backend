# Bank Account

This document explains the backend API contract for withdrawal bank account behavior in `Pengaturan`.

## Applies To

Buyer and seller authenticated users.

## Main Files

- `routes/api.php`
- `app/Http/Controllers/PaymentController.php`
- `app/Services/PaymentService.php`
- `app/Models/PaymentList.php`
- `app/Models/PaymentUser.php`

## Routes

```text
GET /api/payment
GET /api/payment/list
POST /api/payment/account/validate
POST /api/payment
DELETE /api/payment/{id}
```

Bank account routes use payment records with withdrawal payment type.

## Input Types and Validation Order

The five payment operations requiring a local user check authentication before
payload validation. Missing or deleted local users retain HTTP 401.

Text fields accept strings only. Numbers (including zero), booleans, arrays and
objects return HTTP 422 with `status: error` and field errors under `message`.
Type validation precedes account insertion/deletion or provider calls. Account
numbers are strings so leading zeroes are preserved.

- `searchPayment` on GET, POST and DELETE payment accepts missing/null/empty
  values as no search. The service retains its existing `ILIKE` search, wildcard
  behavior, ordering and special no-filter behavior for the string `"0"`.
- Account validation accepts nullable string `paymentAccount` and `paymentSlug`;
  missing/null/empty strings still reach the existing HTTP 400 business errors.
- Account creation requires string `paymentName`, `paymentSlug`, `paymentAccount`
  and `paymentUsername`. Missing/null/empty required values return HTTP 422.
- Deletion rejects malformed search before deleting an account. A valid request
  for an absent or foreign account retains the existing HTTP 400 response.

These checks do not change account ownership, the ten-account limit, duplicate
account validation, successful response shapes or the synthetic owner-name flow.

## GET /api/payment

Returns withdrawal bank accounts for the authenticated user.

Optional query/request fields:

- `searchPayment`

Success response:

```json
{
  "status": "success",
  "payments": []
}
```

## GET /api/payment/list

Returns available withdrawal bank/payment types.

Success response:

```json
{
  "status": "success",
  "paymentList": []
}
```

Each payment list item selects:

- `id`
- `slug`
- `name`

## POST /api/payment/account/validate

Validates a withdrawal bank account number before adding it.

Required request fields:

- `paymentAccount`
- `paymentSlug`

Validation rules:

- `paymentAccount` must not be empty.
- `paymentSlug` must not be empty.
- `paymentSlug` must exist in the current payment list slugs.
- The same authenticated user cannot reuse the same account number for the same payment slug.

Current behavior:

- A fake owner name is generated through `PaymentService::generateFakeUser()`.

Success response:

```json
{
  "status": "success",
  "username": "Generated Name"
}
```

## POST /api/payment

Adds a withdrawal bank account.

Required request fields:

- `paymentName`
- `paymentSlug`
- `paymentAccount`
- `paymentUsername`

Optional request fields:

- `searchPayment`

Validation rules:

- All required fields must be present.
- A user may have at most 10 payment accounts.
- `paymentName` and `paymentSlug` must match an available withdrawal `payment_lists` row.

Side effects:

- Creates a `payment_users` row.
- The response returns the refreshed withdrawal payment list.

Success response:

```json
{
  "status": "success",
  "payments": [],
  "message": "Rekening Berhasil Ditambah"
}
```

## DELETE /api/payment/{id}

Deletes one withdrawal bank account owned by the authenticated user.

Optional request fields:

- `searchPayment`

Validation rules:

- The payment account must belong to the authenticated user.

Side effects:

- Deletes the `payment_users` row.
- The response returns the refreshed withdrawal payment list.

Success response:

```json
{
  "status": "success",
  "payments": [],
  "message": "Rekening Berhasil Dihapus"
}
```
