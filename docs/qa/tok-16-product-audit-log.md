# TOK-16 Product Audit Log QA

## Purpose

This document is the canonical backend QA record for TOK-16 product audit
events. The matching UI checklist is tracked at
`frontend-repo:/docs/qa/tok-16-product-audit-log.md`.

Automated coverage owns event persistence, snapshots, image metadata,
authorization, rollback, filter reset, and regression safety. Keep any
additional manual evidence redacted; never capture credentials, authorization
headers, or raw storage paths.

## Automated Verification

Run:

```bash
php artisan test tests/Feature/ProductAuditLogTest.php tests/Feature/AuditLogTest.php
```

| ID | Status | Verification | Expected Result | Evidence |
| --- | --- | --- | --- | --- |
| TOK-16-BE-01 | ✅ | Create a product with images. | One owner-scoped `product.created` event stores the permitted initial snapshot without raw image paths. | `successful_create_records_an_owner_scoped_product_snapshot` |
| TOK-16-BE-02 | ✅ | Update product values and image metadata. | One `product.updated` event records only real before/after and image changes. | `update_records_only_real_value_changes_and_image_metadata` |
| TOK-16-BE-03 | ✅ | Submit an identical successful update. | One stable event is recorded without false value or image changes. | `identical_update_is_recorded_without_false_changes` |
| TOK-16-BE-04 | ✅ | Delete a product and retrieve its audit detail. | The final snapshot remains readable after the product is gone. | `delete_keeps_the_last_snapshot_after_the_product_is_gone` |
| TOK-16-BE-05 | ✅ | Change a product event filter after loading another page. | A fresh filtered collection starts without reusing the previous cursor. | `changing_the_product_event_filter_after_pagination_starts_a_fresh_collection` |
| TOK-16-BE-06 | ✅ | Submit invalid, missing, and foreign product writes. | No audit row or product mutation is created for failed operations. | `failed_validation_and_foreign_product_writes_do_not_create_audit_rows`; `failed_update_and_missing_product_do_not_mutate_data_or_create_audit_rows` |
| TOK-16-BE-07 | ✅ | Force audit persistence failure during create, update, and delete. | Database changes roll back and uploaded or retained files return to the correct pre-request state. | `audit_failure_rolls_back_product_database_and_uploaded_files`; `audit_failure_rolls_back_update_and_preserves_the_previous_images`; `audit_failure_rolls_back_delete_and_keeps_product_files` |
| TOK-16-BE-08 | ✅ | Run the authentication audit, owner scoping, privacy, filtering, and mixed-pagination regression suite. | Existing audit behavior remains owner-scoped, validated, ordered, complete, and duplicate-free. | `Tests\\Feature\\AuditLogTest` |
