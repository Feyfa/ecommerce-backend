# TOK-32 Product Pagination QA

## Purpose

This document tracks backend verification for Seller Product completion
metadata and the Buyer Belanja 50-product default page size.

Revision under review:

- branch: `task/jd-tok-32`;
- commit: see the Git history for this QA document.

Status legend: ✅ verified, ⬜ not verified yet.

## Automated Verification

| ID | Status | Verification | Expected Result | Evidence |
| --- | --- | --- | --- | --- |
| TOK-32-BE-01 | ✅ | Run `php artisan test tests/Feature/ProductListFilterTest.php`. | Seller batches below, at, and above 50 return correct `has_more`; buyer uses configured 50 and rejects 51. | Passed 17 tests and 118 assertions on August 26, 2026. |
| TOK-32-BE-02 | ✅ | Run `php artisan test tests/Feature/ProductAvailabilityTest.php`. | The buyer page-size change does not weaken catalog, cart, or checkout availability behavior. | Passed 16 tests and 121 assertions on August 26, 2026. |
| TOK-32-BE-03 | ✅ | Run the complete backend test suite. | Existing API, persistence, queue, and authorization behavior remains green. | Passed 184 tests and 1,002 assertions on August 26, 2026. |
| TOK-32-BE-04 | ✅ | Run real-Meilisearch integration coverage. | Search, small-page lookahead, index settings, access beyond result 1,000, and reindex behavior remain correct. | Historical run: `MeilisearchBuyerProductSearchTest` and `BuyerProductReindexTest` passed 5 tests and 37 assertions on August 26, 2026. That run did not directly verify 50-product pages or retrieval at result 10,000; see BE-07 and BE-08 below. |

## Static Contract Verification

| ID | Status | Verification | Expected Result | Evidence |
| --- | --- | --- | --- | --- |
| TOK-32-BE-05 | ✅ | Review Seller Product query and response construction. | The query reads 51 records, returns at most 50, and exposes boolean `has_more`. | Working-tree review confirmed the fixed batch size and one-record lookahead. |
| TOK-32-BE-06 | ✅ | Review buyer configuration and examples. | Backend defaults to 50 while continuing to accept values from 1 through 50. | Config and backend environment example use 50; validation still rejects 51. |

## Additional Pagination Verification — September 9, 2026

| ID | Status | Verification | Expected Result | Evidence |
| --- | --- | --- | --- | --- |
| TOK-32-BE-07 | ✅ | Search 51 matching documents with `per_page=50` against real Meilisearch. | Page 1 returns the first 50 IDs with `has_more=true`; page 2 returns the final ID with `has_more=false`. Both pages have `limit_reached=false`, and their combined IDs exactly match the fixture order without omissions or duplicates. | `test_fifty_product_pages_return_all_fifty_one_products_without_duplicates` passed in the September 9 run below. |
| TOK-32-BE-08 | ✅ | Search pages 199 and 200 of 10,001 matching documents with `per_page=50`, then check page 201 without allowing engine access. | Page 199 returns IDs 9,901–9,950 with `has_more=true`; page 200 returns IDs 9,951–10,000 with `has_more=false` and `limit_reached=true`. Page 201 returns no products and does not access the engine. | `test_fifty_product_pages_stop_at_ten_thousand_results_without_querying_the_next_page` passed. Pages 199/200 use real Meilisearch, and document 10,001 is confirmed stored. Page 201 uses a client mock that rejects any `index()` call to verify the service short-circuit explicitly. |

Command executed from `backend/`:

```bash
/opt/homebrew/opt/php@8.3/bin/php artisan test tests/Integration/MeilisearchBuyerProductSearchTest.php
```

Result: **6 tests passed, 53 assertions**. Each test uses a unique testing index
and removes it during teardown. No application behavior was changed.

This run verifies the search service directly, not browser scrolling or the HTTP
endpoint. It does not walk all 200 pages or simulate concurrent catalog changes.

## Final Verification — September 10, 2026

- Full backend suite: **184 tests passed, 1,002 assertions**.
- `MeilisearchBuyerProductSearchTest` and `BuyerProductReindexTest` together:
  **7 tests passed, 64 assertions**, including BE-07 and BE-08.
- Pint `--test` passed for the five changed PHP files: the product controller,
  buyer search configuration, two feature tests, and search integration test.

## Configurable Seller Batch Size

Seller now accepts optional `per_page`, with `SELLER_PRODUCT_PER_PAGE` as the
fallback and `SELLER_PRODUCT_MAX_PER_PAGE` as the request validation ceiling.
Both default to 50. Empty input follows the fallback; invalid sizes return 422.
The existing ID-exclusion and lookahead response contracts remain in place.

- Focused product-list tests: **19 passed, 152 assertions**, covering explicit
  sizes, fallback, exact maximum, terminal batches, and invalid inputs.
- Full backend suite: **186 passed, 1,036 assertions**.
- Pint passed for the controller, seller configuration, and feature test.
- Real-Meilisearch tests were not rerun for this seller-only behavior change.
