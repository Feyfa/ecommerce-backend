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
- Create a product with one to five image uploads.
- Update product fields and manage image additions, removals, and ordering.
- Delete a product and all of its stored images.

## Main Files

- `routes/api.php`
  Defines the authenticated product API routes.

- `app/Http/Controllers/ProductController.php`
  Handles product list, detail, create, update, and delete behavior.

- `app/Models/Product.php`
  Product model and relationships.

- `app/Models/ProductImage.php`
  Ordered image records owned by a product.

- `app/Models/Keranjang.php`
  Used during product deletion to remove cart rows that reference the deleted product.

- `database/migrations/2026_07_21_000001_create_product_images_table.php`
  Creates the ordered image table and backfills legacy product covers as position 1.

- `tests/Feature/ProductImagesTest.php`
  Covers image limits, malformed manifests, ordering, cleanup, migration backfill, and seller ownership.

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

- `products_current_id`: JSON encoded array of product ids already loaded by the frontend. Malformed JSON and non-array JSON values are rejected.

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
- `images[]`: required image files, between 1 and 5 files, max 1024 KB each.
- `image_order[]`: required ordered tokens. New uploads use `new:{index}`, matching the zero-based index in `images[]`.
- `name`: required, minimum 3 characters.
- `price`: required integer, minimum 1.
- `stock`: required integer, minimum 1.

Behavior:

- Stores every uploaded image in `product-imgs`.
- Creates ordered `product_images` rows and keeps the first path in `products.img` as a compatibility cover.
- Returns the created product.

### Update Product

`PUT /api/product/{id}`

Required form data:

- `name`: required, minimum 3 characters.
- `price`: required integer, minimum 1.
- `stock`: required integer.
- `image_order[]`: required final image order containing between 1 and 5 tokens.

Optional form data:

- `images[]`: optional new image files, max 1024 KB each. New files are referenced from `image_order[]` with `new:{index}`.

Behavior:

- Validates the product id as UUID.
- Resolves the product through the authenticated seller so another seller cannot update it by UUID.
- Existing image tokens are UUIDs from the product `images` response.
- Updates product fields and rebuilds the final image order in one database transaction.
- The first image becomes `position = 1` and is synchronized to `products.img`.
- Removed physical files are deleted only after the database transaction succeeds.
- Returns the updated product.

Current validation allows update `stock` to be `0`, while create requires stock to be at least `1`.

### Delete Product

`DELETE /api/product/{user_id_seller}/{id}`

Behavior:

- Validates `user_id_seller` and `id` as UUID.
- Deletes cart rows in `keranjangs` where `product_id` matches the product id.
- Deletes every stored product image.
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

Each returned product keeps the legacy `img` cover and includes its ordered image collection:

```json
{
  "img": "product-imgs/main.jpg",
  "images": [
    { "id": "uuid", "path": "product-imgs/main.jpg", "position": 1 }
  ]
}
```

Validation failures return `422` with `message` containing validator messages.

## Data Notes

- Product ids are UUIDs.
- Product image paths are stored in `product_images`; `products.img` mirrors position 1 for existing buyer, cart, checkout, and transaction consumers.
- The product-images migration backfills every non-empty legacy `products.img` as position 1 without moving the physical file.
- Product list pagination uses `products_current_id` instead of page numbers.
- Search uses PostgreSQL `ILIKE`, so it is case-insensitive.
- Stock filtering and sorting use existing `products` columns, so they do not require extra database fields.
- Product deletion has a cart side effect because related cart rows are removed before deleting the product.

## Known Decisions

- Product APIs are authenticated with Clerk-backed API auth.
- Seller product operations enforce the authenticated seller identity.
- The product list endpoint is seller-scoped by `user_id_seller`.
- Products require 1 to 5 images, limited to 1024 KB per image.
- Image position 1 is the primary product cover.
- Update can set stock to `0`; create cannot.
- Product list returns a maximum of 50 products per request.
- The backend docs file name matches the frontend docs file name so the same feature can be compared across both repositories.

## TOK-6 Manual QA Checklist

### Phase A — Main Flow

| ID | Done | Action | Expected |
| --- | --- | --- | --- |
| TOK-6-A1 | ✅ | Create a product with 5 valid images. | Card uses image 1; edit reloads all 5; database positions are 1–5. |
| TOK-6-A2 | ✅ | Drag another image to position 1 and save. | Card cover and `products.img` change to the new position 1. |

### Phase B — Edit Images

| ID | Done | Action | Expected |
| --- | --- | --- | --- |
| TOK-6-B1 | ✅ | Remove 1 image, add 1 new image, reorder, and save. | Final order persists; removed file is deleted; new file is stored. |
| TOK-6-B2 | ✅ | Remove images until 1 remains, then save. | Product saves with exactly 1 primary image. |
| TOK-6-B3 | ✅ | Change previews, then cancel and reopen edit. | No update is sent; the last saved state returns. |

### Phase C — Validation

| ID | Done | Action | Expected |
| --- | --- | --- | --- |
| TOK-6-C1 | ✅ | Remove every image and press save. | Submit is rejected; saved data stays unchanged. |
| TOK-6-C2 | ✅ | Select more files than the available 5 slots. | UI remains at no more than 5 images. |
| TOK-6-C3 | ✅ | Select a non-image and an image larger than 1 MB. | Both files are rejected and not uploaded. |

### Phase D — Compatibility and Cleanup

| ID | Done | Action | Expected |
| --- | --- | --- | --- |
| TOK-6-D1 | ✅ | Open a legacy product after migration. | Legacy `products.img` appears as `product_images.position = 1`. |
| TOK-6-D2 | ✅ | Check buyer card, cart, checkout, and transaction. | Each view still displays the current primary image. |
| TOK-6-D3 | ✅ | Delete a disposable product with multiple images. | Product rows and all physical image files are removed. |
| TOK-6-D4 | ✅ | Try modifying another seller's product UUID. | The request is forbidden or returns not found; no data changes. |
