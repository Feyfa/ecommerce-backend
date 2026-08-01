# TOK-17 Product List Filtering QA

## Purpose

This document is the canonical backend QA record for TOK-17 buyer and seller
product-list filtering. No frontend QA document is required because the moved
checklist verifies API filtering, sorting, cursor validation, route removal,
and seller ownership rather than UI behavior.

## Automated Verification

Run:

```bash
php artisan test tests/Feature/ProductListFilterTest.php
```

| ID | Status | Verification | Expected Result | Evidence |
| --- | --- | --- | --- | --- |
| TOK-17-BE-01 | ✅ | Request a buyer catalog containing stock `1`, `0`, negative stock, the buyer's own seller product, and an unavailable seller product. | Only purchasable products from other verified sellers are returned. | `buyer_only_receives_purchasable_products` |
| TOK-17-BE-02 | ✅ | Run all supported buyer and seller sort options. | Both endpoints accept the shared update-date, price, and name sort contract and return correctly ordered results. | `buyer_and_seller_use_the_same_product_sort_options` |
| TOK-17-BE-03 | ✅ | Combine case-insensitive product or seller search, sorting, and excluded product IDs. | Search remains case-insensitive, excluded IDs do not return, and results keep the selected order. | `buyer_can_combine_case_insensitive_search_sort_and_excluded_ids` |
| TOK-17-BE-04 | ✅ | Send the legacy buyer `stock_filter` and a malformed cursor. | The legacy filter cannot weaken the purchasable invariant and the malformed cursor returns `422`. | `buyer_ignores_legacy_stock_filter_and_keeps_purchasable_invariant`; `buyer_rejects_a_malformed_product_cursor` |
| TOK-17-BE-05 | ✅ | Request the retired buyer route containing a user UUID. | The legacy route is unavailable. | `legacy_buyer_route_is_not_available` |
| TOK-17-BE-06 | ✅ | Request the seller list without `stock_filter`, then use `all`. | Both requests return every product owned by the authenticated seller and no foreign product. | `seller_stock_conditions_are_exclusive_and_all_is_the_default` |
| TOK-17-BE-07 | ✅ | Prepare stock `6`, `5`, `1`, `0`, and negative values; run `healthy`, `available`, `low`, and `empty`. | `healthy` and `available` return stock above 5, `low` returns 1–5, and `empty` returns 0 or below. | `seller_stock_conditions_are_exclusive_and_all_is_the_default` |
| TOK-17-BE-08 | ✅ | Run all supported sorts, then send legacy `stock_highest` and `stock_lowest`. | Supported sorts are ordered correctly and both legacy values return `422`. | `buyer_and_seller_use_the_same_product_sort_options`; `legacy_stock_sort_values_are_rejected` |
| TOK-17-BE-09 | ✅ | Combine seller search, stock condition, sorting, and excluded product IDs. | Every condition is applied together and excluded IDs do not return. | `seller_can_combine_search_stock_sort_and_excluded_ids` |
| TOK-17-BE-10 | ✅ | Access the seller list using another seller's UUID. | The request returns `403` without exposing the other seller's catalog. | `seller_cannot_read_another_sellers_product_list` |
