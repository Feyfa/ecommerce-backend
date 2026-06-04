# Account

This document explains the backend API contract used by the `Akun Saya` feature.

The goal is to keep backend-owned account behavior documented by domain: routes, request fields, response shape, validation rules, and data side effects. Frontend UI layout and styling rules live in the frontend repository documentation.

## Scope

The account feature currently covers:

- authenticated user profile;
- seller company profile;
- buyer addresses;
- withdrawal bank accounts;
- balance summary, history, and withdrawal;
- user and company image upload/delete;
- password change;
- two-factor authentication setting.

## Main Files

- `routes/api.php`
  Defines authenticated account, company, address, payment, and balance routes.

- `app/Http/Controllers/UserController.php`
  Handles user profile, user image, password, and TFA payload updates.

- `app/Http/Controllers/CompanyController.php`
  Handles seller company profile and company image updates.

- `app/Http/Controllers/AlamatController.php`
  Handles buyer address list, create, update, delete, and selected/default address.

- `app/Http/Controllers/PaymentController.php`
  Handles withdrawal bank account list, validation, create, and delete.

- `app/Http/Controllers/SaldoController.php`
  Handles balance summary, balance history, and withdrawal.

- `app/Services/CompanyService.php`
  Formats company data and merges seller address into the company response.

- `app/Services/PaymentService.php`
  Resolves withdrawal payment account data and generates fake validation names.

- `app/Services/SaldoService.php`
  Computes balance totals, balance history, and post-disbursement balance mutations.

## Documents

- [Profile](profile.md)
  User profile and user image upload/delete behavior.

- [Company Profile](company-profile.md)
  Seller company profile, seller address side effect, and company image upload/delete.

- [Address](address.md)
  Buyer address list, create, update, delete, search, and selected/default address behavior.

- [Balance](balance.md)
  Balance summary, balance history, and withdrawal behavior.

- [Bank Account](bank-account.md)
  Withdrawal bank account list, bank list, validation, create, and delete behavior.

- [Security](security.md)
  Password change and TFA backend behavior.

## Authentication

All account API routes are protected by `auth:sanctum`.

Most controllers validate the authenticated user through `optional(auth()->user())->id` and return an unauthorized response when the user cannot be found.

Unauthorized response examples:

```json
{
  "status": "error",
  "message": "Unauthorized"
}
```

Some address routes use `result` instead of `status` for the same concept:

```json
{
  "result": "error",
  "message": "Unauthorized"
}
```

Keep this response inconsistency in mind when changing frontend error handling.

## Response Shape Notes

The account API currently uses mixed response keys:

- `status` for user, company, payment, and saldo routes.
- `result` for address routes and password success/error routes.
- Numeric `status` values for some user endpoints.
- String `status` values for company/payment/saldo endpoints.

Future cleanup should standardize this, but frontend code should not assume one universal account response shape until the backend contract is changed.

## Backend-Owned Rules

- Keep account route changes inside authenticated route groups.
- Keep UUID route parameters validated where user-submitted ids are accepted.
- Keep user-owned resources scoped by authenticated user id before update or delete.
- Keep image replacement deleting old public-disk files when they exist.
- Keep seller company address stored through `alamats` with `type = seller`.
- Keep buyer addresses scoped through `alamats.type = buyer`.
- Coordinate frontend and backend changes before renaming request fields such as `wihtdrawPrice`.
