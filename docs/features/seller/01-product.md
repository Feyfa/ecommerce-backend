# Seller Product

This document explains the current seller product feature from the backend side.

The goal is to keep a lightweight map of the API behavior, validation, and data side effects so future work can understand the product feature without reading every controller first.

## Purpose

The seller product API lets an authenticated seller manage products that belong to their account.

Current supported actions:

- List seller products.
- Search seller products by name.
- Filter seller products by stock condition.
- Sort seller products by update date, price, stock, or name.
- Show one seller product.
- Create a product with image upload.
- Update product fields and optionally replace the image.
- Delete a product and its stored image.

## Main Files

- `routes/api.php`
  Defines the authenticated product API routes.

- `app/Http/Controllers/ProductController.php`
  Handles product list, detail, create, update, and delete behavior.

- `app/Models/Product.php`
  Product model and relationships.

- `app/Models/Keranjang.php`
  Used during product deletion to remove cart rows that reference the deleted product.

## Routes

All current seller product routes are inside the Clerk-authenticated API group.

```text
GET    /api/product/{user_id_seller}
GET    /api/product/{user_id_seller}/{id}
POST   /api/product
PUT    /api/product/{id}
DELETE /api/product/{user_id_seller}/{id}
```

## Request Behavior

### List Products

`GET /api/product/{user_id_seller}`

Required query/body data:

- `products_current_id`: JSON encoded array of product ids already loaded by the frontend.

Optional data:

- `search_product`: product name search keyword.
- `stock_filter`: stock filter. Allowed values are `all`, `available`, `low`, and `empty`.
- `sort_product`: sorting option. Allowed values are `latest`, `oldest`, `price_highest`, `price_lowest`, `stock_highest`, `stock_lowest`, `name_asc`, and `name_desc`.

Behavior:

- Validates `user_id_seller` as UUID.
- Validates `stock_filter` and `sort_product` against allowed values when present.
- Excludes ids from `products_current_id`.
- Filters by `name ILIKE %search_product%`.
- Applies stock filters:
  - `all`: no stock restriction.
  - `available`: `stock > 0`.
  - `low`: `stock` between `1` and `5`.
  - `empty`: `stock <= 0`.
- Applies sorting:
  - `latest`: `updated_at DESC`.
  - `oldest`: `updated_at ASC`.
  - `price_highest`: `price DESC`.
  - `price_lowest`: `price ASC`.
  - `stock_highest`: `stock DESC`.
  - `stock_lowest`: `stock ASC`.
  - `name_asc`: `name ASC`.
  - `name_desc`: `name DESC`.
- Returns up to 50 products.

This endpoint is used by the frontend for initial list loading, search, stock filtering, sorting, and infinite scroll.

### Show Product

`GET /api/product/{user_id_seller}/{id}`

Behavior:

- Validates `user_id_seller` and `id` as UUID.
- Finds one product matching both seller id and product id.
- Returns the product in `product`.

This endpoint is used before opening the edit form with existing product data.

### Create Product

`POST /api/product`

Required form data:

- `user_id_seller`: UUID.
- `img`: required image file, max 1024 KB.
- `name`: required, minimum 3 characters.
- `price`: required integer, minimum 1.
- `stock`: required integer, minimum 1.

Behavior:

- Stores the uploaded image in `product-imgs`.
- Creates a product row with the stored image path.
- Returns the created product.

### Update Product

`PUT /api/product/{id}`

Required form data:

- `oldImg`: current stored image path.
- `name`: required, minimum 3 characters.
- `price`: required integer, minimum 1.
- `stock`: required integer.

Optional form data:

- `img`: replacement image file, max 1024 KB.

Behavior:

- Validates the product id as UUID.
- Updates product name, price, and stock.
- If a replacement image is uploaded, deletes `oldImg`, stores the new image in `product-imgs`, and updates the product image path.
- Returns the updated product.

Current validation allows update `stock` to be `0`, while create requires stock to be at least `1`.

### Delete Product

`DELETE /api/product/{user_id_seller}/{id}`

Behavior:

- Validates `user_id_seller` and `id` as UUID.
- Deletes cart rows in `keranjangs` where `product_id` matches the product id.
- Deletes the stored product image.
- Deletes the product row.

## Response Shape

Successful responses use this general shape:

```json
{
  "status": 200
}
```

List responses include:

```json
{
  "status": 200,
  "products": []
}
```

Create and update responses include:

```json
{
  "status": 200,
  "message": "Add Product Success",
  "product": {}
}
```

Validation failures return `422` with `message` containing validator messages.

## Data Notes

- Product ids are UUIDs.
- Product image paths are stored in the database and resolved by the frontend through the configured storage symlink/base URL.
- Product list pagination uses `products_current_id` instead of page numbers.
- Search uses PostgreSQL `ILIKE`, so it is case-insensitive.
- Stock filtering and sorting use existing `products` columns, so they do not require extra database fields.
- Product deletion has a cart side effect because related cart rows are removed before deleting the product.

## Known Decisions

- Product APIs are authenticated with Clerk-backed API auth.
- The product list endpoint is seller-scoped by `user_id_seller`.
- Image upload size is currently limited to 1024 KB.
- Update can set stock to `0`; create cannot.
- Product list returns a maximum of 50 products per request.
- The backend docs file name matches the frontend docs file name so the same feature can be compared across both repositories.
