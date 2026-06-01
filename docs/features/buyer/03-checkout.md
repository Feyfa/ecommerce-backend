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

All checkout routes are inside the Sanctum authenticated API group.

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
5. Loads cart rows where `keranjangs.checkout = 1` and `keranjangs.total > 0`.
6. Groups checkout rows by seller.
7. Generates courier options for each seller group.
8. Calculates product total.
9. Rejects empty checkout rows with `400`.
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
  "user_name_seller": "seller name",
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
4. Returns `409 CHECKOUT_INVALID` when checkout is no longer payable.
5. Returns `400` when payment or courier choices are unavailable.
6. Compares backend snapshot with the frontend `client_snapshot`.
7. Returns `409 CHECKOUT_CHANGED` with a refresh snapshot when totals or cart ids differ.
8. Generates a checkout idempotency key.
9. Acquires a PostgreSQL advisory lock for the checkout key.
10. Checks whether an invoice for the same checkout key already exists.
11. Creates the supported Xendit virtual account.
12. Saves invoice and transaction records inside a database transaction.
13. Deletes processed checkout cart rows.
14. Decrements product stock.
15. Releases the advisory lock.
16. Returns success.

Successful response:

```json
{
  "status": "success",
  "message": "Pembayaran Berhasil"
}
```

## Backend Snapshot

`CheckoutService::buildCheckoutSnapshot()` rebuilds checkout from database state and selected frontend options.

The snapshot validates:

- active buyer address exists;
- checkout cart rows exist;
- checkout cart rows still have valid products;
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

- Checkout APIs are authenticated with Sanctum.
- Checkout uses backend database state as the source of truth.
- Frontend totals are never trusted directly; they are only used for stale-checkout comparison.
- Seller notes are truncated to 200 characters when building the backend snapshot.
- Courier options are currently generated in backend code instead of using a courier provider table.
- Checkout processing currently supports only BCA Virtual Account even if the payment list contains more methods.
- The checkout key prevents duplicate processing for the same buyer and checkout cart rows.
- PostgreSQL advisory locks are used only when `config('database.default') == 'pgsql'`.
