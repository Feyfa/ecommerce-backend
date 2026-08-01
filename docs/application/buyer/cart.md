# Buyer Cart

This document explains the current buyer cart feature from the backend side.

The goal is to document the cart API behavior, validation rules, and checkout safety checks so future work can change the cart flow without accidentally weakening stock or stale-state protection.

## Purpose

The buyer cart API lets an authenticated buyer manage products before checkout.

Current supported actions:

- Read the buyer cart grouped by seller.
- Add products to the cart from buyer belanja.
- Delete cart items.
- Check or uncheck one item.
- Check or uncheck all items from one seller.
- Check or uncheck all available cart items in one request.
- Increase, decrease, or directly change item quantity.
- Keep unavailable items visible with their saved quantity and a clear reason.
- Validate the checked cart state before checkout.

## Main Files

- `routes/api.php`
  Defines the authenticated keranjang routes.

- `app/Http/Controllers/KeranjangController.php`
  Handles cart API requests, quantity validation, checked state, and checkout validation.

- `app/Services/KeranjangService.php`
  Builds grouped cart data, reconciles availability, calculates `totalPrice`, checks checked cart existence, and marks checkout rows.

- `app/Models/Keranjang.php`
  Stores buyer cart rows.

- `app/Models/Product.php`
  Source of product stock and price data.

## Routes

All cart routes are inside the Clerk-authenticated API group.

```text
GET    /api/keranjang/{user_id_buyer}
POST   /api/keranjang
DELETE /api/keranjang/{user_id_buyer}/{product_id}
POST   /api/keranjang/checked
POST   /api/keranjang/checked/group
POST   /api/keranjang/checked/all
POST   /api/keranjang/total/plus
POST   /api/keranjang/total/minus
POST   /api/keranjang/total/change
POST   /api/keranjang/validate/checkout
```

Every route also verifies that the route or body `user_id_buyer` matches the
authenticated user id. A mismatch returns `403` with
`code = "CART_FORBIDDEN"` before any cart row is read or mutated.

## Response Shape

Most cart read/write responses include the latest cart state:

```json
{
  "status": 200,
  "keranjangs": {},
  "totalPrice": 1375000
}
```

`keranjangs` is grouped by seller id. Each item row uses aliases returned by `KeranjangService::getKeranjangs()`:

```json
{
  "k_id": "cart uuid",
  "k_user_id_seller": "seller uuid",
  "k_checked": true,
  "k_total": 1,
  "k_total_price": 75000,
  "u_seller_name": "store name, or seller account name as fallback",
  "p_id": "product uuid",
  "p_name": "Product Name",
  "p_price": 75000,
  "p_stock": 10,
  "p_img": "product-imgs/example.jpg",
  "is_purchasable": true,
  "is_selectable": true,
  "unavailable_reason": null,
  "stock_issue": null
}
```

`unavailable_reason` can be `PRODUCT_DELETED`, `OUT_OF_STOCK`, or `SELLER_LOCATION_UNVERIFIED`, in that priority order. When a positive stock value is lower than the saved quantity, `stock_issue.code` is `QUANTITY_EXCEEDS_STOCK`, `is_purchasable` stays true so quantity can be edited, and `is_selectable` becomes false. `totalPrice` is calculated from checked and selectable cart items only.

Seller groups and `stock_issue.seller_name` use the company/store name. Legacy sellers without a populated company name fall back to the seller account name.

Validation failures commonly use:

```json
{
  "status": 422,
  "message": {
    "stock_maximum": ["This product stock is a maximum of 10"]
  }
}
```

When an error can leave the frontend stale, the response also includes `keranjangs` and `totalPrice` so the UI can refresh itself.

## Cart Actions

### Get Cart

`GET /api/keranjang/{user_id_buyer}`

Behavior:

- Validates `user_id_buyer` as UUID.
- Recalculates availability for every item, including soft-deleted products.
- Automatically sets `checked = 0` and `checkout = 0` for unavailable rows and rows whose quantity exceeds positive stock while preserving `total`.
- Returns grouped cart rows and checked-item `totalPrice`.

### Add To Cart

`POST /api/keranjang`

Required body data:

- `user_id_seller`: UUID.
- `user_id_buyer`: UUID.
- `product_id`: UUID.

Behavior:

- Validates that the seller owns the submitted product.
- Rejects soft-deleted, sold-out, and seller-location-unverified products.
- Creates a new cart row with `checked = 0` and `total = 1` when the product is not already in the buyer cart.
- If the same buyer already has the same seller/product in the cart, increments `total` by 1.
- Rejects increments when the cart total is already equal to or greater than product stock.

### Delete Cart Item

`DELETE /api/keranjang/{user_id_buyer}/{product_id}`

Behavior:

- Deletes the matching cart row if it exists.
- Returns the latest cart state.

Deleting a missing row is currently idempotent from the API perspective. The response is still successful with the latest cart state.

### Check One Item

`POST /api/keranjang/checked`

Required body data:

- `user_id_buyer`: UUID.
- `product_id`: UUID.
- `checked`: boolean.

Behavior:

- Validates that the cart row exists.
- Checks the item only when `checked = true` and the product is purchasable.
- Unchecks the item when `checked = false`.
- Returns `404` with the latest cart state when the cart row no longer exists.

### Check Seller Group

`POST /api/keranjang/checked/group`

Required body data:

- `user_id_buyer`: UUID.
- `user_id_seller`: UUID.
- `checked`: boolean.

Behavior:

- Resets the seller group and checks only purchasable products.
- Returns the latest cart state.

### Check All

`POST /api/keranjang/checked/all`

Required body data:

- `user_id_buyer`: UUID.
- `checked`: boolean.

Behavior:

- Always resets all cart rows for the buyer to `checked = 0`.
- When `checked = true`, checks only rows whose product is active, in stock, and owned by a seller with a verified location.
- Returns the latest cart state.

This route exists so the frontend can select all available cart items with one request instead of sending one request per seller.

### Plus Quantity

`POST /api/keranjang/total/plus`

Required body data:

- `user_id_buyer`: UUID.
- `product_id`: UUID.

Behavior:

- Validates that the cart row exists.
- Rejects unavailable products with `409` while preserving quantity.
- A stock-zero product returns `code = "OUT_OF_STOCK"`, clears stale
  `checked` and `checkout` flags through cart reconciliation, and preserves
  the stored quantity.
- Rejects the request when current cart total is already equal to or greater than current stock.
- Increments `total` by 1.
- Returns the latest cart state.

### Minus Quantity

`POST /api/keranjang/total/minus`

Required body data:

- `user_id_buyer`: UUID.
- `product_id`: UUID.

Behavior:

- Validates that the cart row exists.
- Rejects unavailable products with `409` while preserving quantity.
- A stock-zero product returns `code = "OUT_OF_STOCK"`, clears stale
  `checked` and `checkout` flags through cart reconciliation, and preserves
  the stored quantity.
- Rejects totals lower than `1`.
- Decrements `total` by 1.
- Returns the latest cart state.

The backend no longer allows quantity to drop below `1` through this endpoint.

### Change Quantity

`POST /api/keranjang/total/change`

Required body data:

- `user_id_buyer`: UUID.
- `product_id`: UUID.
- `total`: integer, minimum `1`.

Behavior:

- Validates that the cart row exists.
- Rejects unavailable products with `409` while preserving quantity.
- A stock-zero product returns `code = "OUT_OF_STOCK"`, clears stale
  `checked` and `checkout` flags through cart reconciliation, and preserves
  the stored quantity.
- Rejects totals greater than product stock.
- Updates the row quantity.
- Returns the latest cart state.

## Checkout Validation

`POST /api/keranjang/validate/checkout`

Required body data:

- `user_id_buyer`: UUID.
- `product_ids`: array of UUIDs.

Validation order:

1. Validates request shape.
2. Validates that the buyer has an enabled address; a missing address returns `400 BUYER_ADDRESS_REQUIRED` without mutating cart state.
3. Reconciles unavailable products and saved quantities against current stock.
4. Validates that at least one cart item is checked.
5. Validates stale checked state by comparing request `product_ids` with the database checked product ids.
6. Validates that checked products remain active, in stock, and owned by sellers with verified locations.
7. Validates checked quantities:
   - product still exists
   - cart `total >= 1`
   - cart `total <= products.stock`
8. Updates checkout rows through `KeranjangService::updateCheckoutKeranjang()`.

If selected products become unavailable, the affected cart rows are updated to:

```json
{
  "checked": 0,
  "checkout": 0
}
```

The stored `total` is intentionally unchanged.

Stock changes return `409 CART_STOCK_CHANGED` with `issues`, the latest `keranjangs`, and `totalPrice`. Each issue identifies the cart, product, seller, saved quantity, available stock, and either `QUANTITY_EXCEEDS_STOCK` or `OUT_OF_STOCK`. The first attempt is cancelled, invalid rows are unchecked, and valid rows stay checked so a second request may proceed with only valid products.

If every newly unavailable selected item is unavailable because its seller lost a verified Pinpoint location, validation returns `409 SELLER_ADDRESS_REQUIRES_VERIFICATION` with the latest `keranjangs` and `totalPrice`. Read-repair clears the affected selection while preserving quantity; the frontend remains on the cart and explains the seller-specific cause.

## Error Behavior

The cart API avoids `500` responses for common stale UI cases:

- Missing cart row returns `404` with `message = "Keranjang tidak ditemukan"`.
- Missing product returns `404` with `message = "Produk tidak ditemukan"`.
- Quantity lower than `1` returns `422`.
- Quantity greater than stock returns `422`.
- Checkout stale state returns `409`.
- Checkout invalid quantity returns `409`.
- Missing buyer address returns `400` with `code = "BUYER_ADDRESS_REQUIRED"`.
- Changed stock returns `409` with `code = "CART_STOCK_CHANGED"` and structured `issues`.
- An authenticated user targeting another buyer's cart returns `403` with
  `code = "CART_FORBIDDEN"`.

## Data Notes

- Product ids and user ids are UUIDs.
- Cart rows are grouped by `k_user_id_seller` for frontend rendering.
- `totalPrice` is intentionally based on checked rows only.
- Unavailable products remain visible in the cart with a reason, but cannot be selected or have their quantity mutated.
- The `checkout` column is reset and recalculated during checkout validation.

## Tested Scenarios

The current behavior was verified through browser and database-assisted edge testing:

- Select all available cart items.
- Unselect all cart items.
- Plus and minus quantity for an item with stock greater than `1`.
- Normal checkout validation navigates to checkout.
- Checkout rejects a checked row whose `total` is greater than stock.
- Checkout rejects stale checked state when the UI selection no longer matches the database.
- Plus quantity on a cart row deleted after page load does not crash the frontend and returns a syncable error response.
- All quantity endpoints reject a stock-zero product without changing its saved quantity.
- Cart reads repair injected stock-zero `checked = 1` and `checkout = 1` flags while preserving quantity.
- Cart reads expose `QUANTITY_EXCEEDS_STOCK` and repair injected selection flags without reducing quantity.
- A multi-seller checkout with one invalid and one valid item rejects the first request, preserves the valid selection, and allows the second request to continue with the valid item.
- Every cart endpoint rejects an authenticated user targeting another buyer's cart without mutating either cart.

## Known Decisions

- `checked/all` is implemented as one backend route instead of many frontend requests.
- Checkout validation trusts the database as the source of truth.
- The frontend still sends selected `product_ids` so the backend can detect stale UI state before marking checkout rows.
- Error responses include `keranjangs` and `totalPrice` when the frontend can use them to recover.

## QA Coverage

- [TOK-8 Pinpoint Address QA](../../qa/tok-8-pinpoint-address.md) tracks backend
  cart and checkout availability verification; the matching frontend checklist
  is available at `frontend-repo:/docs/qa/tok-8-pinpoint-address.md`.
