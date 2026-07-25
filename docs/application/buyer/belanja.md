# Buyer Belanja

This document explains the current buyer belanja feature from the backend side.

The goal is to keep a lightweight map of the API behavior and cart side effects so future work can understand the buyer shopping feature without reading every controller first.

## Purpose

The buyer belanja API lets an authenticated buyer browse products from other sellers and add available products to their cart.

Current supported actions:

- List products available for the buyer.
- Search products by product name or seller name.
- Always restrict the buyer catalog to products with purchasable stock.
- Sort products by update date, price, or name.
- Exclude products already loaded by the frontend.
- Add a product to the buyer cart.
- Increase cart quantity when the same product is added again.
- Prevent adding beyond current product stock.

## Main Files

- `routes/api.php`
  Defines the authenticated belanja and keranjang API routes.

- `app/Http/Controllers/BelanjaController.php`
  Handles buyer product list, purchasable-stock restriction, search, and sort behavior.

- `app/Http/Controllers/KeranjangController.php`
  Handles add-to-cart behavior used from the belanja page.

- `app/Models/Product.php`
  Source product data for belanja cards.

- `app/Models/Keranjang.php`
  Stores buyer cart rows.

## Routes

All current buyer belanja routes are inside the Clerk-authenticated API group.

```text
GET  /api/belanja
POST /api/keranjang
```

`POST /api/keranjang` is shared with the cart feature, but it is the write endpoint used by the belanja page.

## Request Behavior

### List Belanja Products

`GET /api/belanja`

Required query/body data:

- `products_current_id`: JSON encoded array of product ids already loaded by the frontend.

Optional data:

- `search_product`: product or seller search keyword.
- `sort_product`: sorting option. Allowed values are `latest`, `oldest`, `price_highest`, `price_lowest`, `name_asc`, and `name_desc`.

Behavior:

- Derives the current user id from the authenticated request instead of client input.
- Validates `products_current_id` as a JSON array and `sort_product` against allowed values when present.
- Excludes products owned by the authenticated user.
- Excludes ids from `products_current_id`.
- Applies case-insensitive matching against product or seller name with normalized `LOWER(...) LIKE` expressions.
- Always requires `products.stock > 0`, regardless of legacy or unknown stock-filter query parameters.
- Joins `products` with `users` to return seller identity for each card.
- Orders by the selected sort option, defaulting to `products.updated_at DESC`.
- Returns up to 200 products.

This endpoint is used by the frontend for initial list loading, search, sorting, and infinite scroll.

### Add To Cart

`POST /api/keranjang`

Required body data:

- `user_id_seller`: UUID of the seller whose product is added to the cart.
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
- Search normalizes both columns and keywords to lowercase so it remains case-insensitive and testable across supported database environments.
- Buyer stock availability is an API invariant rather than a frontend-selected filter.
- Sort options use direct `orderBy` clauses against product columns.
- The primary route does not accept a user id; the authenticated token owner is the source of truth.

## Known Decisions

- Belanja APIs are authenticated with Clerk-backed API auth.
- Buyer belanja intentionally excludes the current user's seller products.
- Product list returns a maximum of 200 products per request.
- Search covers both product name and seller name.
- Buyer does not receive sold-out products because there is no buyer workflow that can act on them.
- Buyer and seller share the same update-date, price, and name sort contract; stock management remains a seller workflow.
- Add-to-cart still rejects missing products and products with stock lower than `1` to handle stock changes after listing.
- The backend docs file name matches the frontend docs file name so the same feature can be compared across both repositories.

## TOK-17 Manual QA Checklist

| ID | Done | Action | Expected |
| --- | --- | --- | --- |
| TOK-17-B1 | ✅ | Request katalog berisi stok `1`, `0`, negatif, dan produk sendiri; coba juga URL lama yang menyertakan UUID. | Hanya produk seller lain dengan stok `> 0` yang dikembalikan; URL lama tidak tersedia. |
| TOK-17-B2 | ✅ | Jalankan `latest`, `oldest`, kedua urutan harga, dan kedua urutan nama. | Keenam nilai diterima dan urutan hasil sesuai pilihan. |
| TOK-17-B3 | ✅ | Kombinasikan pencarian nama produk/seller, sorting, dan `products_current_id`. | Pencarian tetap case-insensitive, ID yang sudah dimuat tidak muncul, dan hasil tetap terurut. |
| TOK-17-B4 | ✅ | Kirim cursor invalid/non-array dan ulangi request valid dengan parameter legacy `stock_filter=empty`. | Cursor invalid ditolak `422`; request valid tetap hanya mengembalikan stok `> 0`. |
