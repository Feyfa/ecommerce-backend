# TOK-30 Buyer Catalog Filters QA

## Purpose

This document is the canonical backend QA record for TOK-30 buyer catalog
filters. It verifies that price boundaries and recently-added periods are
validated and applied to the same query before sort and the 200-product limit.

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
