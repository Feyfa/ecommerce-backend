# Transaction

This document explains the current transaction feature from the backend side.

The goal is to document the shared transaction API used by buyer and seller pages, including filters, status mapping, pagination, seller approval, and the data returned to the frontend.

## Purpose

The transaction API lets authenticated buyers and sellers read transaction rows that belong to their account and lets sellers approve paid transactions.

Current supported actions:

- Load transaction rows for buyer or seller.
- Filter rows by transaction status.
- Search rows by invoice, transaction number, user name, seller company name, payment name, or product name.
- Filter rows by transaction date range.
- Sort rows by newest or oldest transaction date.
- Paginate transaction rows.
- Return status counts for transaction tabs.
- Return product rows for each transaction row.
- Return buyer and seller display names.
- Return seller company name when available.
- Let sellers mark waiting transactions as completed and receive income saldo.

## Main Files

- `routes/api.php`
  Defines transaction API routes inside the Clerk-authenticated group.

- `app/Http/Controllers/TransactionController.php`
  Handles request authentication, query parameter collection, transaction response shape, and seller approval.

- `app/Services/TransactionService.php`
  Builds the transaction query, applies user type filters, applies search/date/status filters, paginates results, maps date formatting, loads transaction products, and transfers seller saldo.

- `app/Models/TransactionInvoice.php`
  Stores invoice-level payment status, payment method, virtual account, buyer address, expiry date, and total invoice price.

- `app/Models/TransactionUser.php`
  Stores seller-specific transaction groups under an invoice.

- `app/Models/TransactionProduct.php`
  Stores purchased products inside each seller transaction group.

- `app/Models/SaldoUser.php`
  Stores seller saldo after seller approval.

- `app/Models/SaldoHistory.php`
  Stores seller saldo history records after seller approval.

## Routes

All transaction routes are inside the Clerk-authenticated API group.

```text
GET  /api/transaction
POST /api/transaction/approved
```

## Load Transactions

`GET /api/transaction`

Required query parameter:

- `user_type`: must be `buyer` or `seller`.

Optional query parameters:

- `status_filter`: `all`, `paid`, `pending_payment`, `waiting_seller`, or `done`.
- `search`: text search keyword.
- `sort`: `newest` or `oldest`.
- `page`: page number.
- `per_page`: page size. The backend clamps this between `1` and `20`.
- `date_from`: optional `YYYY-MM-DD` start date.
- `date_to`: optional `YYYY-MM-DD` end date.

Request example:

```text
GET /api/transaction?user_type=buyer&status_filter=paid&search=bca&sort=newest&page=1&per_page=5&date_from=2026-06-01&date_to=2026-06-30
```

High-level behavior:

1. Reads the authenticated user id.
2. Validates that the authenticated user exists.
3. Validates `user_type` in `TransactionService::getTransaction()`.
4. Builds a base query from `transaction_users`.
5. Joins `transaction_invoices`.
6. Joins buyer and seller users.
7. Left joins seller companies.
8. Applies buyer or seller ownership filter.
9. Applies search and date filters.
10. Builds status counts from the filtered base query.
11. Applies selected status filter.
12. Sorts by `transaction_users.created_at`.
13. Paginates the rows.
14. Formats transaction and expiry dates in `Asia/Jakarta`.
15. Loads products for each transaction row.
16. Returns transactions, counts, and pagination metadata.

## Response Shape

Successful response:

```json
{
  "status": "success",
  "transactions": [],
  "counts": {
    "all": 0,
    "paid": 0,
    "pending_payment": 0,
    "waiting_seller": 0,
    "done": 0
  },
  "pagination": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 5,
    "total": 0,
    "from": 0,
    "to": 0
  }
}
```

Each transaction row includes:

- `id`: `transaction_users.id`.
- `invoice_status`: `transaction_invoices.status`.
- `invoice_id`: `transaction_invoices.id`.
- `transaction_status`: `transaction_users.status`.
- `transaction_number`: seller transaction number.
- `buyer_name`: buyer user name.
- `seller_name`: seller company name when available, otherwise seller user name.
- `payment_name`: invoice payment display name.
- `payment_account`: buyer-only virtual account number.
- `transaction_date`: formatted transaction date.
- `kurir_type`: shipping courier.
- `kurir_estimate`: shipping estimate.
- `kurir_price`: shipping price for the seller transaction row.
- `product_price`: product subtotal for the seller transaction row.
- `noted`: buyer note for the seller transaction row.
- `alamat_buyer`: buyer address snapshot.
- `total_price`: invoice total price.
- `expired_at`: formatted payment expiry date.
- `products`: purchased product rows.

`payment_account` is only selected for buyer responses.

## Status Mapping

The API supports these status filters:

```text
all
  -> no status condition

paid
  -> transaction_invoices.status = done

pending_payment
  -> transaction_invoices.status = pending

waiting_seller
  -> transaction_invoices.status = done
  -> transaction_users.status = approved_seller

done
  -> transaction_invoices.status = done
  -> transaction_users.status = done
```

Frontend buyer `Semua` currently sends `paid`, so pending payments are not mixed into the default paid transaction history.

## Search

Search applies to:

- `transaction_invoices.id`
- `transaction_users.transaction_number`
- `buyer_users.name`
- `seller_users.name`
- `seller_companies.name`
- `transaction_invoices.payment_name`
- related `products.name`

The product search uses `whereExists` on `transaction_products` joined to `products`.

## Date Filter

Date filtering applies to `transaction_users.created_at`.

Accepted date format:

```text
YYYY-MM-DD
```

Invalid date strings are ignored.

Rules:

- valid `date_from` applies `whereDate(transaction_users.created_at, >= date_from)`;
- valid `date_to` applies `whereDate(transaction_users.created_at, <= date_to)`;
- empty values mean all dates.

## Pagination

`TransactionService::getTransaction()` uses Laravel pagination.

Rules:

- default `per_page` is `5`;
- minimum `per_page` is `1`;
- maximum `per_page` is `20`;
- default page is `1`;
- page values below `1` are normalized to `1`.

The buyer frontend sends a larger page size for `pending_payment`, but does not render pagination for that action queue.

## Seller Approval

`POST /api/transaction/approved`

Required body data:

- `transaction_user_id`: transaction row being approved.
- `user_type`: must be `seller`.

High-level behavior:

1. Reads the authenticated seller.
2. Rejects unauthenticated users with `401`.
3. Rejects non-seller requests with `400`.
4. Rejects empty `transaction_user_id` with `400`.
5. Finds a `transaction_users` row owned by the seller with `status = approved_seller`.
6. Rejects missing rows with `404`.
7. Updates the transaction row status to `done`.
8. Transfers seller product income into saldo through `TransactionService::transferSaldo()`.
9. Returns refreshed transactions, counts, and pagination.

Seller income uses `transaction_users.product_price`, not invoice total price, because the buyer invoice total can include shipping and other invoice-level amounts.

## Display Name Rule

The backend returns both buyer and seller display names:

- `buyer_name` uses `users.name` from the buyer account.
- `seller_name` uses `companies.name` when available.
- `seller_name` falls back to the seller `users.name` when no company name exists.

This lets buyer pages show a seller/company identity, while seller pages show the buyer identity.

## Xendit Relationship

Transaction rows are created by the checkout flow after a Xendit virtual account is created.

The transaction read API currently does not receive Xendit events directly. Payment status changes should eventually be synchronized by Xendit webhook endpoints. See `docs/integrations/xendit.md` for the broader Xendit integration notes and known webhook gap.

## Known Decisions

- Buyer and seller transaction reads share one endpoint, controlled by `user_type`.
- Filters, search, sort, date range, and pagination are backend-side.
- The default buyer history should show paid transaction rows only.
- Pending payment is treated as a buyer action queue, not ordinary history.
- Seller approval is currently a direct status transition from `approved_seller` to `done`.
- Seller saldo transfer happens during seller approval.
