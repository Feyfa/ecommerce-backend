# Seller Product

This document explains the current seller product feature from the backend side.

The goal is to keep a lightweight map of the API behavior, validation, and data side effects so future work can understand the product feature without reading every controller first.

## Purpose

The seller product API lets an authenticated seller manage products that belong to their account.

Current supported actions:

- List seller products.
- Search seller products by name.
- Filter seller products by stock condition.
- Sort seller products by update date, price, or name.
- Show one seller product.
- Create a product with one to five image uploads.
- Update product fields and manage image additions, removals, and ordering.
- Soft-delete a product without removing its cart or image history.

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
  Keeps cart rows that reference a soft-deleted product so the buyer can see its status.

- `app/Services/ProductAvailabilityService.php`
  Centralizes seller-location and product availability rules.

- `database/migrations/2026_07_21_000001_create_product_images_table.php`
  Creates the ordered image table and backfills legacy product covers as position 1.

- `tests/Feature/ProductImagesTest.php`
  Covers image limits, malformed manifests, ordering, cleanup, migration backfill, and seller ownership.

- `tests/Feature/ProductListFilterTest.php`
  Covers buyer availability, shared sorting, seller stock conditions, combined filters, cursor validation, and seller ownership.

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
- `stock_filter`: stock filter. Public values are `all`, `healthy`, `low`, and `empty`; deprecated `available` remains a compatibility alias for `healthy` during rollout.
- `sort_product`: sorting option. Allowed values are `latest`, `oldest`, `price_highest`, `price_lowest`, `name_asc`, and `name_desc`.

Behavior:

- Validates `user_id_seller` as UUID.
- Validates `stock_filter` and `sort_product` against allowed values when present.
- Excludes ids from `products_current_id`.
- Applies case-insensitive product-name matching with a normalized `LOWER(name) LIKE` expression.
- Applies stock filters:
  - `all`: no stock restriction.
  - `healthy`: `stock > 5`.
  - `available`: deprecated alias for `healthy` so the previous frontend remains safe during backend-first deployment.
  - `low`: `stock` between `1` and `5`.
  - `empty`: `stock <= 0`.
- Applies sorting:
  - `latest`: `updated_at DESC`.
  - `oldest`: `updated_at ASC`.
  - `price_highest`: `price DESC`.
  - `price_lowest`: `price ASC`.
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
- Returns `404` with `Product Not Found` when the authenticated seller's product does not exist.

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

- Requires the seller to have an active, verified Pinpoint store location.
- Returns `409` with `code = SELLER_LOCATION_UNVERIFIED` when that requirement is not met.
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
- Soft-deletes the product row by filling `products.deleted_at`.
- Preserves cart rows, image records, and stored image files for buyer status display and transaction history.
- The product immediately disappears from the buyer catalog and cannot be added to cart or checked out.

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

Seller list responses also include `seller_location_verified`. The frontend uses it to disable product creation and show the store-location warning without hiding existing products.

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
- Search normalizes the product name and keyword to lowercase so it remains case-insensitive and testable across supported database environments.
- Stock filtering and sorting use existing `products` columns, so they do not require extra database fields.
- Product deletion uses soft delete; existing cart rows and product images are intentionally retained.

## Known Decisions

- Product APIs are authenticated with Clerk-backed API auth.
- Seller product operations enforce the authenticated seller identity.
- The product list endpoint is seller-scoped by `user_id_seller`.
- Products require 1 to 5 images, limited to 1024 KB per image.
- Image position 1 is the primary product cover.
- Update can set stock to `0`; create cannot.
- Creating a product requires an active verified Pinpoint seller location; existing seller products remain manageable when that location later becomes invalid.
- Product list returns a maximum of 50 products per request.
- Product stock conditions are mutually exclusive: healthy is above 5, low is 1–5, and empty is 0 or below.
- Buyer and seller list queries share the product sorting scope so their accepted sort values cannot drift apart.
- The backend docs file name matches the frontend docs file name so the same feature can be compared across both repositories.

## QA Coverage

- [TOK-6 Product Images QA](../../qa/tok-6-product-images.md) tracks backend
  image validation, ordering, migration, storage, and ownership verification;
  the matching frontend checklist is available at
  `frontend-repo:/docs/qa/tok-6-product-images.md`.
- [TOK-17 Product List Filtering QA](../../qa/tok-17-product-list-filtering.md)
  tracks backend buyer and seller list filtering.
