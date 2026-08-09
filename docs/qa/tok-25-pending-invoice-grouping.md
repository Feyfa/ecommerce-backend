# TOK-25 Pending Invoice Grouping QA

## Purpose

This checklist verifies that an unpaid buyer invoice is represented once even when checkout creates several seller transactions. The invoice remains the payment unit while its seller packages remain available in the response for the buyer detail modal.

## Automated Verification

Run:

```bash
php artisan test tests/Feature/TransactionServiceTest.php tests/Feature/CheckoutTest.php
```

| ID | Status | Verification | Expected Result | Evidence |
| --- | --- | --- | --- | --- |
| TOK-25-BE-01 | ✅ | Create one pending invoice with two seller transactions. | Buyer pending response contains one invoice item with two `packages`. | `test_buyer_pending_transactions_are_grouped_by_invoice_with_all_store_packages` |
| TOK-25-BE-02 | ✅ | Search by a product in only the second package. | The matching invoice still includes both seller packages. | `test_buyer_pending_transactions_are_grouped_by_invoice_with_all_store_packages` |
| TOK-25-BE-03 | ✅ | Check the buyer pending count. | `counts.pending_payment` counts the invoice once, not its seller rows. | `test_buyer_pending_transactions_are_grouped_by_invoice_with_all_store_packages` |
| TOK-25-BE-04 | ✅ | Mark the invoice paid and reload buyer paid history. | Buyer receives one transaction row per seller again. | `test_buyer_pending_transactions_are_grouped_by_invoice_with_all_store_packages` |
| TOK-25-BE-05 | ✅ | Run the existing checkout suite. | Checkout still creates one invoice linked to seller transaction rows. | `CheckoutTest` |
| TOK-25-BE-06 | ✅ | Create one pending invoice with one seller transaction. | Buyer pending response keeps the regular transaction shape without `packages`. | `test_buyer_single_store_pending_transaction_keeps_the_regular_transaction_shape` |
