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
- Validate the checked cart state before checkout.

## Main Files

- `routes/api.php`
  Defines the authenticated keranjang routes.

- `app/Http/Controllers/KeranjangController.php`
  Handles cart API requests, quantity validation, checked state, and checkout validation.

- `app/Services/KeranjangService.php`
  Builds grouped cart data, calculates `totalPrice`, checks sold-out products, checks checked cart existence, and marks checkout rows.

- `app/Models/Keranjang.php`
  Stores buyer cart rows.

- `app/Models/Product.php`
  Source of product stock and price data.

## Routes

All cart routes are inside the Sanctum authenticated API group.

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
  "u_seller_name": "seller name",
  "p_id": "product uuid",
  "p_name": "Product Name",
  "p_price": 75000,
  "p_stock": 10,
  "p_img": "product-imgs/example.jpg"
}
```

`totalPrice` is calculated from checked cart items only.

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
- Returns grouped cart rows and checked-item `totalPrice`.

### Add To Cart

`POST /api/keranjang`

Required body data:

- `user_id_seller`: UUID.
- `user_id_buyer`: UUID.
- `product_id`: UUID.

Behavior:

- Validates that the product exists.
- Rejects products with stock lower than `1`.
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
- Checks the item only when `checked = true` and the product is not sold out.
- Unchecks the item when `checked = false`.
- Returns `404` with the latest cart state when the cart row no longer exists.

### Check Seller Group

`POST /api/keranjang/checked/group`

Required body data:

- `user_id_buyer`: UUID.
- `user_id_seller`: UUID.
- `checked`: boolean.

Behavior:

- Iterates all cart rows for the seller and buyer.
- Checks only products that are not sold out.
- Returns the latest cart state.

### Check All

`POST /api/keranjang/checked/all`

Required body data:

- `user_id_buyer`: UUID.
- `checked`: boolean.

Behavior:

- Always resets all cart rows for the buyer to `checked = 0`.
- When `checked = true`, checks only rows whose product stock is greater than `0`.
- Returns the latest cart state.

This route exists so the frontend can select all available cart items with one request instead of sending one request per seller.

### Plus Quantity

`POST /api/keranjang/total/plus`

Required body data:

- `user_id_buyer`: UUID.
- `product_id`: UUID.

Behavior:

- Validates that the cart row exists.
- Validates that the product exists.
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
- Validates that the product exists.
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
2. Validates that the buyer has an enabled address.
3. Validates that at least one cart item is checked.
4. Validates stale checked state by comparing request `product_ids` with the database checked product ids.
5. Validates that checked products are not sold out.
6. Validates checked quantities:
   - product still exists
   - cart `total >= 1`
   - cart `total <= products.stock`
7. Updates checkout rows through `KeranjangService::updateCheckoutKeranjang()`.

If checked products are sold out, the affected cart rows are updated to:

```json
{
  "checked": 0,
  "total": 0
}
```

Checkout validation returns `409` when the frontend state is stale or checked quantities are invalid. These responses include the latest `keranjangs` and `totalPrice` so the frontend can sync without a full page reload.

## Error Behavior

The cart API avoids `500` responses for common stale UI cases:

- Missing cart row returns `404` with `message = "Keranjang tidak ditemukan"`.
- Missing product returns `404` with `message = "Produk tidak ditemukan"`.
- Quantity lower than `1` returns `422`.
- Quantity greater than stock returns `422`.
- Checkout stale state returns `409`.
- Checkout invalid quantity returns `409`.

## Data Notes

- Product ids and user ids are UUIDs.
- Cart rows are grouped by `k_user_id_seller` for frontend rendering.
- `totalPrice` is intentionally based on checked rows only.
- Sold-out products can still appear in the cart read response if a row already exists, but they should not be checkable for checkout.
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

## Known Decisions

- `checked/all` is implemented as one backend route instead of many frontend requests.
- Checkout validation trusts the database as the source of truth.
- The frontend still sends selected `product_ids` so the backend can detect stale UI state before marking checkout rows.
- Error responses include `keranjangs` and `totalPrice` when the frontend can use them to recover.
