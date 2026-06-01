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

## Disbursement

Saldo withdrawal flow can call Xendit disbursement code through `XenditService`.

Expected local flow:

1. Seller requests saldo withdrawal.
2. Backend validates seller saldo and bank data.
3. Backend creates a Xendit disbursement request.
4. Backend updates local saldo and saldo history according to the withdrawal flow.

Important local areas:

- `SaldoController`
- `SaldoUser`
- `SaldoHistory`
- `XenditService::disbursement()`

The exact disbursement status persistence should be reviewed before adding webhook handling, especially if a separate withdrawal or payout table exists later.

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

Future Xendit webhook endpoints should not use normal Sanctum user authentication.

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

- `routes/api.php` webhook routes outside the Sanctum user-authenticated group.
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
