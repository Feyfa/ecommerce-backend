# Buyer Belanja

This document explains the current buyer belanja feature from the backend side.

The goal is to keep a lightweight map of the API behavior and cart side effects so future work can understand the buyer shopping feature without reading every controller first.

## Purpose

The buyer belanja API lets an authenticated buyer browse products from other sellers and add available products to their cart.

Current supported actions:

- List products available for the buyer.
- Search products by product name or seller name.
- Exclude products already loaded by the frontend.
- Add a product to the buyer cart.
- Increase cart quantity when the same product is added again.
- Prevent adding beyond current product stock.

## Main Files

- `routes/api.php`
  Defines the authenticated belanja and keranjang API routes.

- `app/Http/Controllers/BelanjaController.php`
  Handles buyer product list and search behavior.

- `app/Http/Controllers/KeranjangController.php`
  Handles add-to-cart behavior used from the belanja page.

- `app/Models/Product.php`
  Source product data for belanja cards.

- `app/Models/Keranjang.php`
  Stores buyer cart rows.

## Routes

All current buyer belanja routes are inside the Sanctum authenticated API group.

```text
GET  /api/belanja/{user_id_seller}
POST /api/keranjang
```

`POST /api/keranjang` is shared with the cart feature, but it is the write endpoint used by the belanja page.

## Request Behavior

### List Belanja Products

`GET /api/belanja/{user_id_seller}`

Required query/body data:

- `products_current_id`: JSON encoded array of product ids already loaded by the frontend.

Optional data:

- `search_product`: product or seller search keyword.

Behavior:

- Validates `user_id_seller` as UUID.
- Excludes products owned by `user_id_seller`.
- Excludes ids from `products_current_id`.
- Filters by `products.name ILIKE %search_product%` or `users.name ILIKE %search_product%`.
- Joins `products` with `users` to return seller identity for each card.
- Orders by `products.updated_at DESC`.
- Returns up to 200 products.

This endpoint is used by the frontend for initial list loading, search, and infinite scroll.

### Add To Cart

`POST /api/keranjang`

Required body data:

- `user_id_seller`: UUID.
- `user_id_buyer`: UUID.
- `product_id`: UUID.

Behavior:

- Creates a new cart row with `checked = 0` and `total = 1` when the product is not already in the buyer cart.
- If the same buyer already has the same seller/product in the cart, increments `total` by 1.
- Before incrementing an existing cart row, checks current product stock.
- Returns `422` with `stock_maximum` when cart total is already equal to or greater than product stock.

## Response Shape

Successful belanja list responses use this shape:

```json
{
  "status": 200,
  "products": []
}
```

The belanja product rows are selected with aliases used by the frontend:

```json
{
  "p_id": "product uuid",
  "p_img": "product-imgs/example.jpg",
  "p_name": "Product Name",
  "p_price": 25000,
  "p_stock": 10,
  "u_id": "seller uuid",
  "u_name": "seller name"
}
```

Successful add-to-cart responses use this shape:

```json
{
  "status": 200,
  "message": "Item Has Been Added To Basket"
}
```

Stock failures return `422` with `message.stock_maximum`.

## Data Notes

- Product ids and user ids are UUIDs.
- Product image paths are stored in the database and resolved by the frontend through the configured storage symlink/base URL.
- Buyer belanja pagination uses `products_current_id` instead of page numbers.
- Search uses PostgreSQL `ILIKE`, so it is case-insensitive.
- The route parameter is named `user_id_seller`, but in the belanja context it represents the current user's seller id to exclude their own products from the buyer list.

## Known Decisions

- Belanja APIs are authenticated with Sanctum.
- Buyer belanja intentionally excludes the current user's seller products.
- Product list returns a maximum of 200 products per request.
- Search covers both product name and seller name.
- Add-to-cart does not reject a new cart row for stock `0` directly in `store`; the frontend hides the cart action for sold-out products.
- The backend docs file name matches the frontend docs file name so the same feature can be compared across both repositories.
