# TOK-34 Seller Cursor Pagination QA

## Purpose

This document tracks backend verification for replacing the growing Seller
Product UUID exclusion list with an opaque, criteria-bound keyset cursor.

Revision under review:

- branch: `task/jd-tok-34`;
- commit: see the Git history for this QA document.

Status legend: ✅ verified, ⬜ not verified yet.

## Automated Verification

| ID | Status | Verification | Expected Result | Evidence |
| --- | --- | --- | --- | --- |
| TOK-34-BE-01 | ✅ | Run `php artisan test tests/Feature/SellerProductCursorPaginationTest.php`. | Cursor batches, fixed-size criteria hashing, nullable positions, all six deterministic sorts, database-native Unicode name normalization, criteria and seller binding, malformed or modified payloads, version rejection, and a 1,000-product catalog remain correct. | Passed 7 tests and 241 assertions on September 13, 2026. |
| TOK-34-BE-02 | ✅ | Run focused product and cursor feature tests. | Existing Seller Product filtering, page-size validation, ownership, image behavior, and cursor coverage remain green. | Passed 36 tests and 437 assertions after removing the Seller Product exclusion-list contract on September 13, 2026. |
| TOK-34-BE-03 | ✅ | Run the complete backend suite and focused Pint check. | No regression exists outside focused pagination coverage and all changed PHP follows repository style. | Full suite passed 193 tests and 1,275 assertions; Pint passed for all changed PHP files on September 13, 2026. |

## PostgreSQL Query-Plan Evidence

A session-local PostgreSQL 16 temporary table copied the current `products`
schema and indexes, then loaded 1,000 active products for one seller. No
application table or persistent schema was changed.

| ID | Status | Verification | Expected Result | Evidence |
| --- | --- | --- | --- | --- |
| TOK-34-BE-04 | ✅ | Run `EXPLAIN (ANALYZE, BUFFERS)` for the midpoint cursor of all six sorts. | Establish whether the current indexes create a material problem at the Jira baseline. | All queries completed below 4 ms; observed execution times ranged from 0.162 ms through 3.802 ms. |
| TOK-34-BE-05 | ✅ | Compare composite partial candidates for seller/update, seller/price, and seller/lower-name ordering. | Add a migration only when the candidate consistently improves the measured workload. | Candidates did not consistently improve both ascending and descending forms; the measured maximum included a 2.692 ms name-desc plan. No migration was justified for the 1,000-product baseline. |

The application test suite uses SQLite, so this PostgreSQL check is retained as
separate runtime evidence. A production-scale data distribution should be
rechecked before adding indexes later; TOK-34 intentionally avoids six
direction-specific indexes without measured need.

A read-only PostgreSQL runtime check on September 13, 2026 also confirmed that
`LOWER(CAST('İSTANBUL' AS TEXT))` returns `istanbul`, while PHP
`mb_strtolower('İSTANBUL')` returns the distinct value `i̇stanbul`. Name-sort
cursors therefore select and reuse PostgreSQL's exact `LOWER(products.name)`
value, and Seller Product search lowercases both the column and bound keyword
inside its main database query without an extra normalization round trip.

## Local Browser Verification

| ID | Status | Verification | Expected Result | Evidence |
| --- | --- | --- | --- | --- |
| TOK-34-BE-06 | ✅ | Paginate a 51-product local PostgreSQL catalog through the Seller Product UI while inspecting HTTP responses. | The initial response exposes a bounded cursor, the next request resumes after the first batch, and the final response returns `next_cursor: null` with `has_more: false`. | Chrome Network screenshots on September 13, 2026 showed a 513-character version 1 cursor and the expected terminal metadata without an empty follow-up batch. |

## Coordinated Staging Verification

| ID | Status | Verification | Expected Result | Evidence |
| --- | --- | --- | --- | --- |
| TOK-34-BE-07 | ⬜ | Deploy the cursor-capable backend and frontend as one coordinated release. | Seller infinite scroll advances with `next_cursor`; no request depends on a product UUID exclusion list. | Requires staging. |
| TOK-34-BE-08 | ⬜ | Exercise malformed, modified, criteria-incompatible, and foreign-seller cursors through staging HTTP. | Invalid cursor use returns safe 422 or ownership 403 without internal details. | Requires staging. |
| TOK-34-BE-09 | ⬜ | Inspect Seller Product requests and query logs during staging pagination. | Requests remain bounded and the endpoint executes keyset boundaries without UUID exclusion-list processing. | Requires staging. |

## Rollback

Rollback the backend and frontend together to their previous compatible
versions. The pre-TOK-34 frontend depends on the removed UUID exclusion-list
contract and must not run against the final cursor-only backend.
