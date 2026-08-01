# TOK-6 Product Images QA

## Purpose

This document is the canonical backend QA record for the TOK-6 product image
contract. The matching UI checklist is tracked at
`frontend-repo:/docs/qa/tok-6-product-images.md`.

The backend rows use automated feature tests because request validation,
database ordering, storage cleanup, migration backfill, and ownership are more
reliable and reproducible at this layer than through manual request editing.

## Automated Verification

Run:

```bash
php artisan test tests/Feature/ProductImagesTest.php
```

| ID | Status | Verification | Expected Result | Evidence |
| --- | --- | --- | --- | --- |
| TOK-6-BE-01 | ✅ | Create products with one and five images. | The API accepts the supported range and stores ordered images with position 1 as the primary image. | `seller_can_create_a_product_with_one_image`; `seller_can_create_a_product_with_five_images` |
| TOK-6-BE-02 | ✅ | Submit empty, oversized, non-image, over-1-MB, and malformed image collections. | Invalid collections return validation responses without a server error or partial product write. | `create_rejects_an_empty_or_oversized_image_collection`; `create_rejects_non_image_and_files_larger_than_one_megabyte`; `create_rejects_malformed_image_manifests_without_server_errors` |
| TOK-6-BE-03 | ✅ | Backfill a legacy `products.img` value. | The legacy image becomes `product_images.position = 1`. | `migration_backfills_a_legacy_product_image_as_position_one` |
| TOK-6-BE-04 | ✅ | Reorder retained images, add a new image, and remove another. | Final positions persist, the new file is stored, and the removed file is cleaned up. | `seller_can_reorder_keep_add_and_remove_product_images` |
| TOK-6-BE-05 | ✅ | Submit an update with zero images or an image owned by another product. | The update is rejected and existing product data remains unchanged. | `update_rejects_zero_images_and_images_from_another_product` |
| TOK-6-BE-06 | ✅ | Soft-delete a product referenced by existing cart context. | The product is hidden from active listings while its image data remains available for retained cart context. | `soft_deleting_a_product_keeps_images_for_existing_cart_context` |
| TOK-6-BE-07 | ✅ | Attempt to create or update a product for another seller. | The request is forbidden or not found and no foreign product data changes. | `seller_cannot_create_or_update_products_for_another_seller` |

The soft-delete expectation supersedes the former manual checklist statement
that product deletion removes every image immediately. Current product
availability behavior intentionally retains images for existing cart context.
