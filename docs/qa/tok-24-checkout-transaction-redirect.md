# TOK-24 Checkout Transaction Identity QA

## Purpose

This document is the canonical backend QA record for the TOK-24 checkout response contract. The change is
additive: `POST /api/checkout/process` now returns the invoice id created by the checkout so the frontend can
highlight the resulting transactions instead of guessing the buyer's newest data. One checkout produces one
invoice covering every seller, so the invoice id alone is enough for single-store and multi-store checkouts.

## Automated Verification

Run:

```bash
php artisan test tests/Feature/CheckoutTest.php
```

| ID | Status | Verification | Expected Result | Evidence |
| --- | --- | --- | --- | --- |
| TOK-24-BE-01 | ✅ | Process a valid single-seller checkout with a faked virtual account provider. | The response returns `200` with `status = success` and `message = Pembayaran Berhasil`. | `successful_checkout_returns_the_created_invoice_id` |
| TOK-24-BE-02 | ✅ | Compare the returned `transaction_invoice_id` with the stored invoice row. | The returned id matches the `transaction_invoices` row created for the buyer. | `successful_checkout_returns_the_created_invoice_id` |
| TOK-24-BE-03 | ✅ | Confirm the seller transaction rows are linked to that invoice. | At least one `transaction_users` row carries the returned invoice id. | `successful_checkout_returns_the_created_invoice_id` |
| TOK-24-BE-04 | ✅ | Run the existing checkout conflict, availability, and row-lock scenarios. | Every previous rejection path and rollback behavior is unchanged. | `CheckoutTest`; `ProductAvailabilityTest` |

## Evidence Notes

TOK-24-BE-01 through TOK-24-BE-03 are covered by one test that mocks `XenditService::createVirtualAccount`,
so no request reaches the payment provider. The test asserts the response body against rows read back from
the database rather than against literal values, which keeps it meaningful if id generation changes.

TOK-24-BE-04 is covered by the existing suite. The added test is the only new case.

## Not Covered

Multi-seller checkout is not covered automatically. The existing fixture builds a single-seller cart. The
response shape does not change with seller count, because the id returned belongs to the invoice rather than
to the per-seller rows, so the multi-seller case is verified through the frontend checklist instead.

Style checks are unchanged by this task. `./vendor/bin/pint --test` reports pre-existing `unary_operator_spaces`,
`braces_position`, and `phpdoc_align` issues in `CheckoutController.php`, `CheckoutService.php`, and
`CheckoutTest.php`. The same issues are reported on the unmodified files, so they are not caused by TOK-24 and
were left untouched.
