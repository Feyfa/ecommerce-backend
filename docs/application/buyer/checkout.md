# Buyer Checkout

This document explains the current buyer checkout feature from the backend side.

The goal is to document the checkout API behavior, validation rules, snapshot protection, payment processing, and data side effects so future work can change checkout without weakening stock safety, payment consistency, or duplicate-submit protection.

## Purpose

The buyer checkout API lets an authenticated buyer load checkout data for checked cart rows and process those rows into transaction records and a payment invoice.

Current supported actions:

- Load the buyer's active address.
- Load cart rows marked as checkout rows.
- Group checkout products by seller.
- Generate courier options per seller package.
- Return available checkout payment methods.
- Validate selected courier and payment choices.
- Compare frontend totals against a backend snapshot before payment creation.
- Create a Xendit virtual account for supported payment.
- Save invoice, seller transaction rows, and transaction product rows.
- Delete processed checkout cart rows.
- Decrement product stock after successful checkout.
- Prevent duplicate processing for the same checkout cart set.

## Main Files

- `routes/api.php`
  Defines the authenticated checkout API routes.

- `app/Http/Controllers/CheckoutController.php`
  Handles checkout data loading, request validation, snapshot comparison, Xendit payment creation, database transaction handling, and checkout response codes.

- `app/Services/CheckoutService.php`
  Builds checkout data, courier options, backend checkout snapshots, frontend refresh snapshots, idempotency keys, advisory locks, transaction records, cart cleanup, and stock changes.

- `app/Services/PaymentService.php`
  Returns checkout payment methods.

- `app/Services/XenditService.php`
  Creates the external virtual account payment.

- `app/Models/Keranjang.php`
  Stores buyer cart rows and checkout rows.

- `app/Models/Alamat.php`
  Stores buyer and seller addresses.

- `app/Models/PaymentList.php`
  Stores available payment methods.

- `app/Models/TransactionInvoice.php`
  Stores invoice-level payment details.

- `app/Models/TransactionUser.php`
  Stores seller-specific transaction groups under the invoice.

- `app/Models/TransactionProduct.php`
  Stores products purchased inside each seller transaction group.

- `app/Models/Product.php`
  Source of product stock and price data.

## Routes

All checkout routes are inside the Clerk-authenticated API group.

```text
GET  /api/checkout/data
POST /api/checkout/process
```

The cart page also calls this route before navigating to checkout:

```text
POST /api/keranjang/validate/checkout
```

That validation route is owned by the cart feature, but it is part of the checkout entry flow.

## Load Checkout Data

`GET /api/checkout/data`

Behavior:

1. Reads the authenticated user id from `auth()->user()`.
2. Rejects missing users with `401`.
3. Loads the active buyer address through `CheckoutService::getAlamatBuyer()`.
4. Rejects missing active address with `400`.
5. Reconciles current product availability and loads cart rows where `keranjangs.checkout = 1` and `keranjangs.total > 0`.
6. Groups checkout rows by seller.
7. Generates courier options for each seller group.
8. Calculates product total.
9. Rejects empty checkout rows with `409 CHECKOUT_INVALID` before loading payment methods.
10. Loads checkout payment methods through `PaymentService::getCheckoutPayment()`.
11. Rejects an empty payment list with `400`.
12. Returns address, grouped checkout rows, payments, and product total.

Successful response shape:

```json
{
  "status": "success",
  "alamat": {},
  "checkouts": [],
  "payments": [],
  "totalPrice": 1310000
}
```

Each checkout group has this shape:

```json
{
  "user_id_seller": "seller uuid",
  "user_name_seller": "store name, or seller account name as fallback",
  "keranjangs": [
    {
      "k_id": "cart uuid",
      "k_total": 1,
      "k_total_price": 60000,
      "p_id": "product uuid",
      "p_name": "Product Name",
      "p_price": 60000,
      "p_img": "product-imgs/example.jpg"
    }
  ],
  "kurirs": [
    {
      "name": "JNT",
      "price": 15000,
      "estimation": "01 Juni 2026 - 02 Juni 2026"
    }
  ]
}
```

## Courier Generation

`CheckoutService::generateFormatKurirs()` currently generates static courier options:

```text
JNT          day +1  price 15000
Anter Aja    day +2  price 10000
Si Cepat Halu day +3 price 5000
```

Dates are generated with `Carbon::now('Asia/Jakarta')` and Indonesian locale formatting.

The same generated options are used later when validating selected courier names during checkout processing.

## Process Checkout

`POST /api/checkout/process`

Required body data:

- `payment_slug`: selected payment slug.
- `shipping_options`: array of selected courier choices.
- `shipping_options.*.user_id_seller`: seller id for the selected package.
- `shipping_options.*.kurir_name`: selected courier name.
- `noteds`: array of seller notes.
- `client_snapshot`: frontend checkout comparison data.
- `client_snapshot.alamat_id`: active address reviewed by the buyer.
- `client_snapshot.alamat_updated_at`: version of that active address.
- `client_snapshot.cart_item_ids`: checkout cart row ids.
- `client_snapshot.total_product`: frontend product total.
- `client_snapshot.total_shipping`: frontend shipping total.
- `client_snapshot.total_all`: frontend final total.

Request example:

```json
{
  "payment_slug": "bca",
  "shipping_options": [
    {
      "user_id_seller": "seller uuid",
      "kurir_name": "JNT"
    }
  ],
  "noteds": [
    {
      "user_id_seller": "seller uuid",
      "noted": "optional note"
    }
  ],
  "client_snapshot": {
    "cart_item_ids": ["cart uuid"],
    "total_product": 1310000,
    "total_shipping": 30000,
    "total_all": 1340000
  }
}
```

High-level behavior:

1. Reads the authenticated buyer.
2. Validates request shape.
3. Builds a backend checkout snapshot from current database state.
4. Returns `409 SELLER_ADDRESS_REQUIRES_VERIFICATION` when the only newly unavailable checkout reason is a seller location that lost Pinpoint verification; other unrecoverable availability changes return `409 CHECKOUT_INVALID`.
5. Returns `400` when payment or courier choices are unavailable.
6. Compares backend snapshot with the frontend `client_snapshot`.
7. Returns `409 CHECKOUT_CHANGED` with a refresh snapshot when totals, cart ids, or the active address differ.
8. Generates a checkout idempotency key.
9. Acquires a PostgreSQL advisory lock for the checkout key.
10. Checks whether an invoice for the same checkout key already exists.
11. Starts a database transaction and locks the selected cart, product, active buyer-address, and seller-location rows.
12. Revalidates deletion, stock, seller ownership, checked state, and verified seller location while those rows are locked.
13. Compares locked cart quantity, product price, product and seller identity, and active buyer-address version with the initial server snapshot.
14. Returns `409 CHECKOUT_CHANGED` with current data when a payable snapshot changed, or the relevant invalid-checkout code when it is no longer payable.
15. Creates the supported Xendit virtual account only after the locked state matches the snapshot.
16. Saves invoice and transaction records, deletes processed cart rows, and decrements stock atomically.
17. If availability changed, rolls back the whole transaction and then unchecks affected cart rows in a separate update while preserving quantity.
18. Releases the advisory lock and returns the final result.

Successful response:

```json
{
  "status": "success",
  "message": "Pembayaran Berhasil",
  "transaction_invoice_id": "a270374b-c2cc-41aa-955d-00d034142d87"
}
```

`transaction_invoice_id` identifies the invoice created by this checkout so the frontend can mark the
resulting transactions instead of guessing the buyer's newest data. One checkout creates a single invoice
and one transaction per seller, so this one id already covers every store in a multi-store checkout.

## Backend Snapshot

`CheckoutService::buildCheckoutSnapshot()` rebuilds checkout from database state and selected frontend options.

The snapshot validates:

- active buyer address exists;
- checkout cart rows exist;
- checkout cart rows still have valid products;
- checkout products are not soft-deleted and their sellers still have verified locations;
- checkout quantity is at least `1`;
- checkout quantity is not greater than current product stock;
- payment method exists in `payment_lists`;
- payment method is currently supported for processing;
- selected courier exists for each seller package.

The returned snapshot includes:

```json
{
  "status": "success",
  "data": {
    "alamat": {},
    "checkouts": [],
    "kurirs": [],
    "noteds": [],
    "payment": {
      "method": "va",
      "slug": "bca",
      "name": "BCA Virtual Account"
    },
    "totals": {
      "product": 1310000,
      "shipping": 30000,
      "all": 1340000
    }
  },
  "clientComparable": {
    "cart_item_ids": ["cart uuid"],
    "total_product": 1310000,
    "total_shipping": 30000,
    "total_all": 1340000
  }
}
```

`clientComparable` is the backend source of truth used to compare against the frontend `client_snapshot`.

## Stale Checkout Handling

`CheckoutService::checkoutSnapshotChanged()` compares:

- sorted checkout cart ids;
- active buyer-address id and update version;
- product total;
- shipping total;
- final total.

If any value differs, the controller returns:

```json
{
  "status": "error",
  "code": "CHECKOUT_CHANGED",
  "message": "Checkout berubah, silakan cek ulang sebelum membayar",
  "checkout": {
    "alamat": {},
    "checkouts": [],
    "kurirs": [],
    "noteds": [],
    "totalPrice": 1310000,
    "totalShipping": 30000,
    "totalAll": 1340000
  }
}
```

The frontend can apply this snapshot and keep the buyer on checkout for review.

When checkout cannot be recovered on the checkout page, the controller returns:

```json
{
  "status": "error",
  "code": "CHECKOUT_INVALID",
  "message": "Keranjang berubah, silakan cek ulang"
}
```

The frontend should send the buyer back to cart.

Checkout never silently drops only the invalid item. One invalid selected item cancels the complete order attempt, no payment is created, no stock is decremented, and no partial transaction is stored.

## Payment Behavior

Checkout payment methods are loaded from `PaymentService::getCheckoutPayment()`.

Processing currently only supports:

```text
method = va
slug   = bca
name   = BCA Virtual Account
```

Any other method returns:

```json
{
  "status": "error",
  "message": "Pembayaran Harus Menggunakan BCA Virtual Account"
}
```

For BCA Virtual Account, the controller creates a closed, single-use Xendit virtual account with:

- `expected_amount` equal to backend final total;
- buyer name as VA name;
- expiration one day from processing time, rounded to the hour;
- `external_id` containing method, slug, buyer id, timestamp, and unique id.

Xendit errors are returned as `400` with the Xendit service message.

## Idempotency And Locking

`CheckoutService::generateCheckoutKey()` hashes:

- buyer id;
- sorted checkout cart item ids.

The key is stored on `transaction_invoices.checkout_key`.

When the database driver is PostgreSQL, checkout processing uses:

```text
pg_advisory_lock(hashtext(checkout_key))
pg_advisory_unlock(hashtext(checkout_key))
```

This prevents concurrent requests for the same checkout set from being processed at the same time.

Before creating payment and transaction data, the controller checks for an existing invoice with the same checkout key and status in:

```text
pending
done
```

If found, it returns:

```json
{
  "status": "error",
  "code": "CHECKOUT_ALREADY_PROCESSED",
  "message": "Checkout ini sudah diproses, silakan cek transaksi Anda"
}
```

## Database Side Effects

The invoice copies the buyer address text, latitude, longitude, and location
source. Each seller transaction row copies the equivalent seller values. These
snapshots remain stable if a master address is later edited or deleted.

New checkout flows require verified pinpoint addresses for both parties.
An active legacy manual buyer address returns `ADDRESS_REQUIRES_VERIFICATION`;
a legacy manual seller address returns `SELLER_ADDRESS_REQUIRES_VERIFICATION`.
The seller-specific response is preserved when cart read-repair detects the
concurrent change during cart validation, checkout reload, or payment
submission. These responses use HTTP `409` and are returned before payment
processing.

Geoapify place ids are not copied to transaction history, and location values
do not currently affect courier price or estimation.

Successful checkout writes these records inside a database transaction:

- `transaction_invoices`
  - buyer id
  - checkout key
  - buyer address
  - payment method, slug, name
  - virtual account number
  - external payment reference
  - final price
  - expiration timestamp

- `transaction_users`
  - seller id
  - buyer id
  - invoice id
  - generated transaction number
  - seller address
  - courier type, price, and estimate
  - seller note
  - product subtotal for that seller

- `transaction_products`
  - seller id
  - buyer id
  - product id
  - transaction user id
  - unit price
  - quantity

After transaction records are saved:

- processed checkout rows are deleted from `keranjangs`;
- product stock is decremented atomically with `where stock >= qty`.

If stock decrement fails, the database transaction throws and checkout returns an error.

## Known Decisions

- Checkout APIs are authenticated with Clerk-backed API auth.
- Checkout uses backend database state as the source of truth.
- Frontend totals are never trusted directly; they are only used for stale-checkout comparison.
- Seller notes are truncated to 200 characters when building the backend snapshot.
- Courier options are currently generated in backend code instead of using a courier provider table.
- Checkout processing currently supports only BCA Virtual Account even if the payment list contains more methods.
- The checkout key prevents duplicate processing for the same buyer and checkout cart rows.
- PostgreSQL advisory locks are used only when `config('database.default') == 'pgsql'`.

## QA Coverage

- [TOK-8 Pinpoint Address QA](../../qa/tok-8-pinpoint-address.md) tracks backend
  address and checkout verification; the matching frontend checklist is
  available at `frontend-repo:/docs/qa/tok-8-pinpoint-address.md`.
