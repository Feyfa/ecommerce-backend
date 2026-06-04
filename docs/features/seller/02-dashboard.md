# Seller Dashboard

This document explains the current seller dashboard feature from the backend side.

The goal is to keep a lightweight map of the dashboard API, data sources, and query decisions so future work can update dashboard metrics without guessing how each number is calculated.

## Purpose

The seller dashboard API gives an authenticated seller one consolidated response for the dashboard page.

Current dashboard data:

- Store summary metrics.
- Sales performance for the last 30 days.
- Recent seller transactions.
- Product snapshot metrics.

## Main Files

- `routes/api.php`
  Defines the authenticated dashboard API route.

- `app/Http/Controllers/SellerDashboardController.php`
  Validates the authenticated seller and returns dashboard data.

- `app/Services/SellerDashboardService.php`
  Builds the dashboard response from product and transaction queries.

- `app/Models/Product.php`
  Used for product count, active stock, low stock, empty stock, and new product metrics.

- `app/Models/TransactionUser.php`
  Used for seller transaction summary, recent transactions, and completed sales metrics.

- `app/Models/TransactionProduct.php`
  Used for product quantity and item-level revenue aggregation.

## Route

The seller dashboard route is inside a Sanctum authenticated API group.

```text
GET /api/dashboard
```

The route intentionally follows the existing project API style, such as `/api/product` and `/api/belanja`, instead of using `/api/seller/dashboard`.

## Authorization

The endpoint uses the authenticated Sanctum user.

Behavior:

- Returns `401` when there is no authenticated user.
- Returns `403` when the authenticated user is not in seller mode.
- Does not accept `user_id_seller` from the frontend, so dashboard data cannot be requested for another seller through a client-provided id.

## Response Shape

Successful response:

```json
{
  "status": "success",
  "summary": {
    "total_products": 0,
    "new_orders": 0,
    "total_sold": 0,
    "monthly_revenue": 0
  },
  "performance": {
    "period": "30_days",
    "labels": [],
    "sales": [],
    "revenue": [],
    "total_sold": 0,
    "total_revenue": 0
  },
  "recent_transactions": [],
  "product_snapshot": {
    "active_products": 0,
    "low_stock_products": 0,
    "empty_stock_products": 0,
    "new_products": 0
  }
}
```

## Metric Rules

### Summary

- `total_products`
  Counts products owned by the authenticated seller.

- `new_orders`
  Counts transactions where the invoice is paid and the seller transaction is still `approved_seller`.

- `total_sold`
  Sums `transaction_products.total` for completed seller transactions.

- `monthly_revenue`
  Sums `transaction_users.product_price` for completed seller transactions in the current month.

### Performance

Performance uses the last 30 days, including today.

- Only completed transactions are included.
- A completed transaction means `transaction_users.status = done` and `transaction_invoices.status = done`.
- Daily `sales` sums `transaction_products.total`.
- Daily `revenue` sums `transaction_products.price * transaction_products.total`.

### Recent Transactions

Recent transactions return up to 5 latest seller transactions.

Each item includes:

- transaction id
- transaction number
- buyer name
- product names
- seller transaction status
- invoice status
- product price
- formatted transaction date in `Asia/Jakarta`

### Product Snapshot

- `active_products`
  Products with stock greater than `0`.

- `low_stock_products`
  Products with stock between `1` and `5`.

- `empty_stock_products`
  Products with stock less than or equal to `0`.

- `new_products`
  Products created in the last 30 days.

## Data Notes

- Dashboard queries are scoped to the authenticated seller id.
- The service keeps query logic out of the controller because the dashboard combines product, transaction, invoice, and transaction product data.
- Recent transaction product names are loaded per recent transaction. This is acceptable for now because the list is limited to 5 transactions.
- Date formatting uses `Asia/Jakarta` for dashboard labels and recent transaction dates.

## Known Decisions

- The dashboard route is `/api/dashboard` to match the project route style.
- The dashboard is seller-only even though the route name is generic.
- The frontend should call this endpoint without sending a seller id.
- Chart data is returned as plain arrays so the frontend can render a lightweight SVG chart without adding a chart dependency.
