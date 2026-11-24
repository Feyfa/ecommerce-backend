# Xendit Integration

This document records the current Xendit integration notes for the ecommerce backend.

The goal is to document the payment and disbursement touchpoints in one general Xendit document, including the known webhook gap that still needs a separate implementation pass.

## Current Scope

The backend currently uses Xendit-related code for:

- creating virtual account payments during checkout;
- creating disbursement requests from saldo withdrawal flow;
- storing transaction invoice data locally;
- storing seller saldo changes locally.

Relevant source areas:

- `app/Services/XenditService.php`
- `app/Http/Controllers/CheckoutController.php`
- `app/Http/Controllers/SaldoController.php`
- `app/Models/TransactionInvoice.php`
- `app/Models/TransactionUser.php`
- `app/Models/SaldoUser.php`
- `app/Models/SaldoHistory.php`
- `routes/api.php`

## Virtual Account Payments

Checkout creates a Xendit virtual account through `XenditService`.

Expected local flow:

1. Buyer checks out selected cart rows.
2. Backend validates checkout state and selected payment method.
3. Backend creates a Xendit virtual account.
4. Backend stores invoice data in `transaction_invoices`.
5. Backend stores seller transaction rows in `transaction_users`.
6. Backend stores purchased product rows in `transaction_products`.
7. Buyer sees payment information on the transaction page.

Important local fields:

- `transaction_invoices.id`
- `transaction_invoices.status`
- `transaction_invoices.payment_name`
- `transaction_invoices.payment_account`
- `transaction_invoices.price`
- `transaction_invoices.expired_at`
- `transaction_users.status`

## Adapter Result Contract

The Xendit adapter returns a result with keys determined by its status:

- Success returns `status` and the decoded provider payload in `data`.
  VA creation retains the legacy status `suceess`. Pool and fixed VA simulations
  use `success`. Disbursement has its own contract, described below.
- Failure returns `status: error` and a string `message`. For HTTP client errors,
  a string from the provider, including an empty string, is returned as received.
  A missing or non-string message, an empty body, or a non-JSON body yields
  `Unknown error`.
- For the virtual account operations (creation and both payment simulations),
  a failure without a usable answer returns
  `Layanan Pembayaran Sedang Bermasalah, Silakan Coba Lagi`. This covers a
  timeout, a refused or failed connection, a 5xx response, and any other Guzzle
  transfer error after 4xx handling. The adapter writes a `warning` log with the
  operation, exception class, HTTP status, and cURL error code and text. It does
  not log the exception message, because Guzzle copies part of a 5xx body into it
  and transport messages carry the Xendit URL.
Checkout requires a payload containing `account_number` and `external_id` as
non-blank strings before saving the invoice. Missing or invalid fields are
handled as a payment error within the checkout transaction. See
[Buyer Checkout](../buyer/checkout.md#payment-behavior).

### Disbursement Results

`XenditService::disbursement()` sends the withdrawal's `external_id` as the
`X-IDEMPOTENCY-KEY` header and returns one of three results:

| `status` | Other keys | Meaning |
| --- | --- | --- |
| `success` | `disbursement` with `id`, `status`, `failure_code` | Xendit accepted the request. The status is usually `PENDING`. |
| `error` | `message`, `error_code` | A clear 4xx rejection, including `429 RATE_LIMIT_EXCEEDED`. The caller may return the balance. |
| `unknown` | none | The outcome is unknown, and the money may already be sent. |

`unknown` covers a timeout, a failed connection, a 5xx response, any other
Guzzle transfer error, and `400 DUPLICATE_TRANSACTION_ERROR`. Xendit returns
the duplicate error when an idempotency key was used before; it does not replay
the first answer. A reused key therefore means the first request was accepted,
so it must not return the balance.

`XenditService::findDisbursementByExternalId()` calls
`GET /disbursements?external_id=` and returns:

- `found` with a list of the same `id`, `status`, and `failure_code` fields;
- `not_found` only for HTTP 404 with `DIRECT_DISBURSEMENT_NOT_FOUND_ERROR`;
- `unknown` for any other answer, including a timeout, a 5xx, another 4xx such
  as 401 or 429, an empty list, or a body that is not a list.

Both methods log `unknown` results as `warning` with the same fields as the
virtual account operations. The summaries leave out the account number and
holder name.

These shapes were checked against Xendit Test Mode during TOK-88. The probe
created one Rp10.000 disbursement in the local business. Its POST returned
HTTP 200 with `status: PENDING`. Sending it again with the same key returned
`400 DUPLICATE_TRANSACTION_ERROR`. A lookup returned a JSON array, and an
unknown external ID returned `404 DIRECT_DISBURSEMENT_NOT_FOUND_ERROR`. The
lookup still showed `PENDING` a few minutes after creation and showed
`COMPLETED` in a later lookup. Test Mode therefore completes disbursements on
its own, but not instantly.

### Request Timeouts

Every Xendit request sends a connect timeout and a total request timeout:

| Variable | Default | Meaning |
| --- | --- | --- |
| `XENDIT_CONNECT_TIMEOUT_SECONDS` | 5 | Time allowed to open the connection |
| `XENDIT_REQUEST_TIMEOUT_SECONDS` | 20 | Time allowed for the whole request |

The defaults live in `XenditService` and apply when the variables are unset.
Container registration rejects zero, negative, and non-numeric values with a
`RuntimeException`. Guzzle reads `0` as "wait forever", and a blank or mistyped
value would otherwise cast to `0`.

Without these limits Guzzle waits forever for an answer. Checkout calls the
provider inside its database transaction while it holds row locks on the cart,
products, and addresses, so a hanging request would also block other checkouts
of the same products. Neither nginx layer overrides its 60-second read timeout,
so 20 seconds leaves room for the checkout queries before the buyer would see a
504. The values are starting points, not measured Xendit latency.

Disbursement uses the same limits. The withdrawal balance is held before the
request, and a lost answer is `unknown` rather than a rejection, so a timeout
cannot cause a double payment. Before TOK-88 a lost answer was treated as a
rejection without a deduction, so the user could withdraw again while the money
was already sent.

## Fixed Virtual Account Simulation

`POST /api/payment/simulate/charge-virtual-account` is the existing authenticated
simulation operation. It uses `XenditService::simulateVirtualAccountFixed()`.

Authentication/local user existence is checked first. `payment_slug` and
`payment_account` must be nullable strings; other types return HTTP 422 with
field errors under `message` before the provider is called. Missing/null/empty
values retain the existing HTTP 400 messages. The invoice must belong to the
buyer, use VA payment, remain pending and not be expired.

The stored invoice price is explicitly cast to `int` with a null fallback of
zero before the provider call. This preserves the existing weak integer-parameter
conversion, including truncation of fractional legacy values; it adds no new
rounding or amount-validation rule. Checkout already converts its VA and saved
invoice amounts to integers. Historical fractional invoice data is not audited
by this change.

Provider business failure retains HTTP 400 and the invoice status. Success marks
the invoice `done` and returns `Success Charge Virtual Account`. Automated
verification uses provider mocks; no live simulation or payment is performed.

A simulation without a usable answer also returns HTTP 400 with the adapter's
safe message and leaves the invoice `pending`. If Xendit processed the
simulation but the answer was lost, the local invoice stays `pending` while the
virtual account is already paid at Xendit. Nothing reconciles that state yet;
the FVA paid webhook below is the intended fix.

## Disbursement

Saldo withdrawal flow calls Xendit disbursement code through `XenditService`.

Local flow since TOK-88:

1. Seller requests saldo withdrawal.
2. Backend validates seller saldo and bank data.
3. Backend holds the balance and stores a `processing` row in
   `saldo_withdrawals` with a new `external_id`.
4. Backend sends the disbursement without a database lock.
5. Backend applies the answer. A rejection returns the balance, and a lost
   answer keeps the balance held.
6. `withdrawal:check` reads the status of `processing` withdrawals until Xendit
   reports `COMPLETED` or `FAILED`.

Important local areas:

- `SaldoController`
- `WithdrawalService`
- `SaldoUser`
- `SaldoHistory`
- `SaldoWithdrawal`
- `XenditService::disbursement()`
- `XenditService::findDisbursementByExternalId()`

See [Balance](../settings/balance.md#post-apisaldo-withdraw) for the full
withdrawal contract. Disbursement status now lives in `saldo_withdrawals`, so a
future "Disbursement terkirim" webhook can update the same rows by
`external_id`.

## Webhook Gap

The Xendit dashboard has been seen with these intended webhook paths:

```text
/api/webhook/virtual-accounts/paid
/api/webhook/disbursement/sent
```

Current source scan status:

- No Laravel route was found for `FVA terbayarkan`.
- No Laravel route was found for `Disbursement terkirim`.
- Existing Xendit service code can create virtual accounts and disbursements, but webhook receivers are not documented as implemented.

This means the dashboard configuration should be treated as a pending integration note until routes, controllers, security validation, and idempotent handlers exist.

## FVA Paid Webhook

Xendit product/event:

```text
FIXED VIRTUAL ACCOUNTS / FVA terbayarkan
```

Expected purpose:

- Receive notification when a buyer pays a virtual account.
- Match the Xendit payload to `transaction_invoices`.
- Mark the invoice as paid.
- Move related seller transaction rows into the seller-waiting state.
- Prevent duplicate processing when Xendit retries the same event.

Likely application data affected:

- `transaction_invoices.status`
- `transaction_users.status`
- future webhook event log table, if added

Implementation notes for future work:

- Validate the Xendit webhook token or signature before mutating data.
- Use a stable external id, invoice id, virtual account id, or callback id for idempotency.
- Wrap status changes in a database transaction.
- Return a success response for already-processed duplicate events.
- Log unknown invoice references without changing unrelated data.

## Disbursement Sent Webhook

Xendit product/event:

```text
DISBURSEMENT / Disbursement terkirim
```

Expected purpose:

- Receive notification when a disbursement has been sent or completed by Xendit.
- Match the Xendit payload to the local withdrawal or saldo payout record.
- Update payout status.
- Prevent duplicate processing when Xendit retries the same event.

Likely application data affected:

- saldo payout or withdrawal table, if present
- `saldo_histories`
- `saldo_users`
- future webhook event log table, if added

Implementation notes for future work:

- Identify the local table that stores disbursement requests.
- Store Xendit disbursement ids and external ids when requests are created.
- Validate webhook authenticity before status updates.
- Make the handler idempotent.
- Decide whether a failed disbursement should return balance to `saldo_users`.

## Security Requirements

Future Xendit webhook endpoints should not use normal user-session authentication.

Recommended approach:

- Put webhook routes outside normal authenticated user routes.
- Add dedicated Xendit webhook middleware.
- Validate Xendit callback token or signature.
- Reject invalid callbacks before reading or mutating business data.
- Store enough provider event metadata to debug retries.

## Future Route Sketch

The final route names can change, but they should be explicit and isolated from normal user APIs.

```text
POST /api/webhook/virtual-accounts/paid
POST /api/webhook/disbursement/sent
```

Recommended backend pieces:

- `routes/api.php` webhook routes outside the normal user-authenticated API group.
- `app/Http/Middleware/XenditWebhookMiddleware.php` for token/signature validation.
- `app/Http/Controllers/Webhook/XenditVirtualAccountController.php`.
- `app/Http/Controllers/Webhook/XenditDisbursementController.php`.
- webhook event logging for replay protection and debugging.

## Why This Matters

Without webhook receivers, backend state may depend only on synchronous API responses.

That is risky because payment providers can update asynchronously:

- buyer payment may complete after the checkout request;
- Xendit may retry callbacks;
- disbursement status can change after the request is accepted;
- network failures can hide the final provider state from the application.

## Known Decisions

- This is a general Xendit integration document, not only a webhook document.
- The webhook endpoint notes do not mean those endpoints currently exist.
- Transaction UI work does not depend on these endpoints yet.
- Webhook implementation should be handled as a separate backend task.
