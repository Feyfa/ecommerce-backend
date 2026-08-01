# Buyer Belanja

This document explains the current buyer belanja feature from the backend side.

The goal is to keep a lightweight map of the API behavior and cart side effects so future work can understand the buyer shopping feature without reading every controller first.

## Purpose

The buyer belanja API lets an authenticated buyer browse products from other sellers and add available products to their cart.

Current supported actions:

- List products available for the buyer.
- Search products by product name or store name.
- Restrict the buyer catalog to active products with stock and a verified seller location.
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

- `search_product`: product or store search keyword.
- `sort_product`: sorting option. Allowed values are `latest`, `oldest`, `price_highest`, `price_lowest`, `name_asc`, and `name_desc`.

Behavior:

- Derives the current user id from the authenticated request instead of client input.
- Validates `products_current_id` as a JSON array and `sort_product` against allowed values when present.
- Excludes products owned by the authenticated user.
- Excludes ids from `products_current_id`.
- Applies case-insensitive matching against product or store name with normalized `LOWER(...) LIKE` expressions.
- Always requires an active non-deleted product, `products.stock > 0`, and an active verified Pinpoint location for its seller.
- Joins the seller account and company profile to return the store name for each card. The seller account name remains the fallback when the company profile has no usable name.
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

- Confirms that the submitted seller id owns the product.
- Rejects soft-deleted, sold-out, and seller-location-unverified products with `409` and a machine-readable availability `code`.
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
  "u_name": "store name, or seller account name as fallback"
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
- Buyer product availability is an API invariant rather than a frontend-selected filter.
- Sort options use direct `orderBy` clauses against product columns.
- The primary route does not accept a user id; the authenticated token owner is the source of truth.

## Known Decisions

- Belanja APIs are authenticated with Clerk-backed API auth.
- Buyer belanja intentionally excludes the current user's seller products.
- Product list returns a maximum of 200 products per request.
- Search covers both product name and the displayed store identity.
- Buyer-facing seller identity prioritizes `companies.name` and falls back to `users.name` for legacy sellers without a populated company profile.
- Buyer does not receive soft-deleted, sold-out, or unverified-seller products because none can be purchased.
- Buyer and seller share the same update-date, price, and name sort contract; stock management remains a seller workflow.
- Add-to-cart revalidates all availability rules to handle changes after listing.
- The backend docs file name matches the frontend docs file name so the same feature can be compared across both repositories.

## QA Coverage

- [TOK-17 Product List Filtering QA](../../qa/tok-17-product-list-filtering.md)
  tracks backend product-list filtering verification.
