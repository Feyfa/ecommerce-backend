# TOK-30 Buyer Catalog Filters QA

## Purpose

This document preserves the backend QA evidence recorded for TOK-30 buyer
catalog filters on August 11, 2026, at commit
`eab494bc8a8fd349a876f8ee24065334fca9cc32`. It verifies the price and
recently-added filter contract that existed at that point in the implementation.

## Historical Scope

This is a historical QA record, not the source of truth for the current buyer
catalog contract. The recorded implementation used the former 200-product and
already-loaded-product exclusion behavior. Buyer catalog pagination has since
moved to Meilisearch with `page`, `per_page`, and `has_more`.

Keep the evidence below unchanged when the current implementation evolves.
Refer to [Buyer Belanja](../application/buyer/belanja.md) for the active API
contract and [Meilisearch](../architecture/meilisearch.md) for current search
architecture and verification instructions.

## Automated Verification

Run:

```bash
php artisan test tests/Feature/ProductListFilterTest.php
```

| ID | Status | Verification | Expected Result | Evidence |
| --- | --- | --- | --- | --- |
| TOK-30-BE-01 | ✅ | Request a minimum price only. | Products at or above the boundary are returned, including the exact boundary price. | `buyer_can_filter_products_by_inclusive_price_range` |
| TOK-30-BE-02 | ✅ | Request a maximum price only. | Products at or below the boundary are returned, including the exact boundary price. | `buyer_can_filter_products_by_inclusive_price_range` |
| TOK-30-BE-03 | ✅ | Request equal minimum and maximum prices. | Only products at that exact inclusive price are returned. | `buyer_can_filter_products_by_inclusive_price_range` |
| TOK-30-BE-04 | ✅ | Send a negative price or a minimum greater than the maximum. | The request returns `422` with the relevant validation error. | `buyer_rejects_invalid_price_filter_values` |
| TOK-30-BE-05 | ✅ | Request each supported `added_within` value: `7`, `14`, `30`, and `90`. | Each period filters from `products.created_at`; products outside the period do not return. | `buyer_can_filter_products_by_recently_added_period` |
| TOK-30-BE-06 | ✅ | Update an old product while keeping its original creation date outside the selected period. | The product remains excluded, proving the filter does not use `updated_at`. | `buyer_can_filter_products_by_recently_added_period` |
| TOK-30-BE-07 | ✅ | Send an unsupported `added_within` value. | The request returns `422` for `added_within`. | `buyer_rejects_invalid_price_filter_values` |
| TOK-30-BE-08 | ✅ | Combine search, price range, recently-added period, sorting, and excluded IDs. | Every condition narrows the same catalog query and excluded products do not return. | `buyer_can_combine_price_filter_with_search_sort_and_excluded_ids` |

## Not Covered

The visual Filter panel, chips, reset behavior, and responsive layouts are
manual frontend concerns. They are tracked in
[TOK-30 frontend QA](../../../frontend/docs/qa/tok-30-buyer-catalog-filters.md).
