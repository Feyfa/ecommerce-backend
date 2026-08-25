# TOK-29 Buyer Catalog Search QA

## Purpose

This document is the active backend QA record for the TOK-29 buyer catalog
search architecture. It covers the Meilisearch API contract, Redis queue
synchronization, PostgreSQL source-of-truth boundary, numbered pagination,
testing isolation, recovery operations, and remaining end-to-end verification.

QA record date: August 25, 2026.

Revision under review:

- branch: `task/jd-tok-29`;
- base commit: `4989aa5093428dadba5e5c97aa917350536b0190`;
- TOK-29 changes: uncommitted working tree at the time this record was created.

Replace the working-tree note with the final commit after the reviewed changes
are committed. Statuses below describe only evidence available for this exact
local revision; they do not imply that staging or production has been tested.

Status legend: ✅ verified, ⬜ not verified yet.

## Automated Verification

Run the standard suite:

```bash
php artisan test --compact
```

Run the opt-in real-Meilisearch test separately:

```bash
php artisan test --compact tests/Integration/MeilisearchBuyerProductSearchTest.php
php artisan test --compact tests/Integration/BuyerProductReindexTest.php
```

Run the outbox locking test only with an isolated PostgreSQL testing database:

```bash
php artisan test --compact tests/Integration/PostgresOutboxLockingTest.php
```

| ID | Status | Verification | Expected Result | Evidence |
| --- | --- | --- | --- | --- |
| TOK-29-BE-01 | ✅ | Run the standard PHPUnit suites with isolated local resources. | Existing behavior, transactional outbox, deterministic sorting, and boundary metadata coverage pass without contacting development Redis or Meilisearch data. | `php artisan test --compact` passed during the final audit on August 25, 2026: 182 tests and 985 assertions. |
| TOK-29-BE-02 | ✅ | Request buyer pages through the mocked search boundary. | The endpoint validates `page` and `per_page`, forwards keyword/filter/sort criteria, returns `page`, `per_page`, and `has_more`, and no longer exposes the retired buyer route. | Covered by `ProductListFilterTest`, including `buyer_rejects_an_invalid_page_contract` and `legacy_buyer_route_is_not_available`; included in the passing standard suite. |
| TOK-29-BE-03 | ✅ | Evaluate page completion, deterministic sorting, and the configured browsing boundary. | The service uses an actual lookahead below the cap, clamps the final request to the remaining capacity, emits `limit_reached`, short-circuits offsets beyond 10,000, and uses ID as the final tie-breaker without adding runtime sort to relevance. | `BuyerProductSearchServiceTest` covers all explicit sorts, relevance, normal exhaustion, exact-boundary completion, and an offset after the boundary; included in the passing standard suite. |
| TOK-29-BE-04 | ✅ | Run the buyer search contract against a real local Meilisearch engine. | Typo tolerance, synonyms, filters, deterministic explicit/relevance ties, two-page lookahead, settings, and a document after position 1,000 behave as configured. | The combined real-Meilisearch and reindex integration run passed on August 25, 2026 with 5 tests and 37 assertions; the search-contract file contributed 4 tests and 26 assertions. Each test used a unique disposable testing index and cleanup completed. |
| TOK-29-BE-05 | ✅ | Verify integration-test cleanup. | The unique testing index is deleted after the real-engine test and no development index is touched. | A read-only `GET /indexes` after the test returned `total: 0`; the fixture index no longer existed. |
| TOK-29-BE-06 | ✅ | Check the new TOK-29 PHP files with Laravel Pint. | Commands, jobs, services, configuration, migration, and tests introduced by TOK-29 follow the repository formatter. | The scoped Laravel Pint check passed for all 30 new TOK-29 PHP files on August 25, 2026 after correcting nine style-only issues. |
| TOK-29-BE-07 | ✅ | Record mutations and exercise outbox publishing, retry, locking, terminal failure, manual retry, and cleanup. | Business data and outbox rows commit or roll back together; Redis failure retains retryable work; valid messages publish the correct job; active claims are not duplicated; stale claims recover; permanent or twentieth-attempt failures become `failed`; published retention is enforced. | `OutboxRecorderServiceTest`, `OutboxPublisherServiceTest`, `ProductAuditLogTest`, `CheckoutTest`, `CompanyAuditLogTest`, and `ClerkBuyerCatalogSynchronizationTest` are included in the passing standard suite. |
| TOK-29-BE-08 | ✅ | Boot tests with safe and intentionally unsafe external-service configurations. | Standard tests accept SQLite memory, sync queue, array cache, Redis testing namespaces, and a testing Meilisearch index; unsafe external targets fail during bootstrap. | `TestingExternalServicesSafetyTest` covers safe configuration plus external queue/cache, Redis host/prefix/database, Meilisearch host, and development-index rejection. |
| TOK-29-BE-09 | ✅ | Inspect the local `buyer-catalog-search` queue after QA execution. | No job created by QA remains pending in local Redis. | `php artisan queue:monitor redis:buyer-catalog-search --max=1` reported `[0] OK` on August 25, 2026 after the final queue-name validation; `php artisan queue:failed` reported no failed jobs. |

## Implementation Contract Review

| ID | Status | Verification | Expected Result | Evidence |
| --- | --- | --- | --- | --- |
| TOK-29-BE-10 | ✅ | Review search document ownership and failure handling. | `BuyerProductSearchService` owns settings, document projection, filtering, deterministic sorting, the 10,000-result boundary, and pagination; `/api/belanja` returns a safe `503` without falling back to PostgreSQL search. | Working-tree review of `BuyerProductSearchService`, `BelanjaController`, and `config/buyer_product_search.php`. |
| TOK-29-BE-11 | ✅ | Review product mutation synchronization. | Create, update, soft delete, and checkout record versioned product events inside their PostgreSQL transactions; the publisher dispatches only committed work and workers reload current state. | Working-tree review of `ProductController`, `CheckoutController`, `OutboxRecorderService`, `OutboxPublisherService`, `SyncBuyerProductSearchJob`, and `BuyerProductSearchService`. |
| TOK-29-BE-12 | ✅ | Review seller identity and location synchronization. | Company changes and applicable Clerk fallback-name changes record seller events inside their transactions; Redis downtime leaves durable pending work instead of reversing profile data. | Working-tree review of `CompanyController`, `ClerkUserSyncService`, `OutboxRecorderService`, `OutboxPublisherService`, and `SyncSellerBuyerCatalogSearchJob`. |
| TOK-29-BE-13 | ✅ | Review deployment topology. | Staging and production define Redis, Meilisearch, a dedicated `buyer-catalog-search` worker, and one Laravel Scheduler container; API startup depends only on PostgreSQL, while the worker waits for Redis and Meilisearch. | Working-tree review of `deploy-repo:/compose/compose.staging.yml`, `compose.production.yml`, deployment docs, and backend environment examples. Both Compose configurations resolve successfully; runtime deployment remains pending. |

## Missing Automated Coverage

| ID | Status | Verification | Expected Result | Evidence |
| --- | --- | --- | --- | --- |
| TOK-29-BE-14 | ✅ | Add explicit feature assertions for create, update, and soft-delete product recording. | Each committed mutation stores the correct product event and source; rejected or rolled-back mutations store no outbox row and push no Redis job from the HTTP flow. | `ProductAuditLogTest` covers exact aggregate IDs, sources, pending status, validation/ownership rejection, and transaction rollback in the passing standard suite. |
| TOK-29-BE-15 | ✅ | Add explicit assertions for company, Clerk, and checkout event recording. | Company and changed fallback names store seller events; unchanged Clerk names do not; checkout records one event per unique changed product; rollback leaves none. | `CompanyAuditLogTest`, `ClerkBuyerCatalogSynchronizationTest`, and `CheckoutTest` cover these boundaries in the passing standard suite. |
| TOK-29-BE-16 | ✅ | Exercise `buyer-search:reindex` against disposable database state, a captured queue, and a unique real Meilisearch index. | Settings are applied, the derived index is cleared, every active or soft-deleted product is queued, captured jobs are processed, and the final eligible document set matches the database fixture. | `php artisan test --compact tests/Integration/BuyerProductReindexTest.php` passed on August 25, 2026: 1 test and 11 assertions. The opt-in test used SQLite in memory, captured all four product jobs on `buyer-catalog-search`, removed a stale document, excluded stock-zero, soft-deleted, and unverified-seller products, retained the one eligible product, and deleted its unique `buyer_products_testing_reindex_*` index during teardown. Real Redis worker delivery is covered separately by TOK-29-BE-17 through TOK-29-BE-19. |

## Local End-To-End Verification

| ID | Status | Action | Expected Result | Evidence |
| --- | --- | --- | --- | --- |
| TOK-29-BE-17 | ✅ | Run the scheduler and a real Redis `buyer-catalog-search` worker, then create, update, and soft-delete a disposable product through the API. | Each commit creates a pending outbox row, the scheduler publishes it, the worker converges Meilisearch, and the row becomes `published`. | Controlled local API and browser QA passed on August 24, 2026. Seller create, update, and delete requests returned `200`; each committed mutation created the matching `product.created`, `product.updated`, or `product.deleted` outbox row, which moved from `pending` to `published` in one attempt. The real Redis worker added the product with price 29,000 and stock 5, updated it to price 39,000 and stock 7, then removed it after soft delete. A different buyer account observed the original card, the updated card, and finally `products: []` with the genuine empty state. The queue drained to zero, no failed job remained, and the disposable product, image file, audit rows, and outbox rows were removed after evidence capture. The final queue rename is covered by the August 25 automated queue assertions and deployment contract validation. |
| TOK-29-BE-18 | ✅ | Complete checkout for a disposable product while the scheduler and real worker run. | PostgreSQL stock commits with one outbox row per unique product; scheduler and worker converge Meilisearch; cart/checkout remains authoritative during propagation. | Controlled local API and browser QA passed on August 24, 2026. A buyer selected one unit from stock 2, reviewed the active address, JNT shipping, BCA Virtual Account, and an 18,000 total, then submitted `POST /api/checkout/process` once and received `200` with the successful transaction screen. PostgreSQL atomically reduced stock to 1, removed the processed cart, stored one invoice, seller transaction, and product item, and created exactly one `checkout.stock_changed` outbox row. The scheduler published it in one attempt, the real worker converged Meilisearch from stock 2 to 1, and the buyer catalog then displayed stock 1. The queue drained to zero, no failed job remained, no payment was made or simulated, and all local disposable product, image, audit, outbox, and transaction rows were removed after evidence capture. |
| TOK-29-BE-19 | ✅ | Change a disposable seller's company name, verified location, and fallback user name. | Seller outbox messages publish seller-wide jobs and buyer-facing store labels and availability converge to PostgreSQL. | Controlled local API, browser, and runtime QA passed on August 24, 2026. A seller changed the company name from `SpaceX Baru` to `TOK29 Manual QA Store`; the API returned `200`, one seller outbox published in one attempt, the real worker synchronized all four active and one soft-deleted product IDs, and a different buyer observed the new store label. Restoring the original name followed the same pipeline and returned all active documents to `SpaceX Baru`. The seller then selected a different Geoapify location and saved its formatted address and detail in two committed requests; both outboxes published once, two seller jobs and ten child product jobs completed, and the buyer still observed an available product because the new location remained verified. The original location was restored exactly from a guarded snapshot and synchronized once more. Separately, a synthetic Clerk model processed by the real `ClerkUserSyncService` on a disposable company-less seller produced `clerk.name_changed` and changed the fallback store label in Meilisearch. Direct provider-side Clerk profile editing was not performed. Every queue drained to zero, no failed job remained, original company/location state was restored, and QA-only audit and outbox rows were removed after evidence capture. |
| TOK-29-BE-20 | ✅ | Stop Meilisearch while requesting `/api/belanja`, then restore it. | The endpoint returns `503` with `BUYER_PRODUCT_SEARCH_UNAVAILABLE`; after recovery, requests succeed without a PostgreSQL search fallback. | Controlled local failure-and-recovery QA passed on August 22, 2026. Stopping the Homebrew Meilisearch service produced the expected `503` contract, restarting it restored `/health` to `available`, and `/api/belanja` returned `200` with the existing indexed products without a PostgreSQL fallback or reindex. |

## Staging Verification

| ID | Status | Verification | Expected Result | Evidence |
| --- | --- | --- | --- | --- |
| TOK-29-BE-21 | ⬜ | Commit, push, integrate, and deploy the frontend, backend, and deployment changes to staging using the documented maintenance rollout. | PostgreSQL, Redis, Meilisearch, backend API, `backend-worker`, and the single `backend-scheduler` become healthy using staging-only configuration. | Pending commit, push, staging integration, migration, and deployment. |
| TOK-29-BE-22 | ⬜ | Drain legacy queue work, run the migration, start scheduler/worker, perform the controlled reindex, and inspect both delivery layers. | Outbox has no overdue pending/failed rows, the queue reports `[0] OK`, no unresolved worker failure remains, settings report `maxTotalHits: 10000` with final `id:asc`, and buyer search matches the additive `limit_reached` contract. | Pending staging deployment; follow `deploy-repo:/docs/deployment.md`. |
| TOK-29-BE-23 | ⬜ | Stop Redis, update a product, and complete checkout; then restore Redis and observe recovery. | Mutations still succeed, outbox rows remain pending while Meilisearch is stale, scheduler publishes after Redis returns, worker converges the projection, rows become `published`, and both queue/failure lists are clear. | Pending staging end-to-end QA. Also verify company and Clerk seller events, container status, and worker/scheduler logs. |

## Not Covered

- Production rollout and verification are outside this local QA record.
- The opt-in PostgreSQL concurrency test exists but has not been executed
  against an isolated PostgreSQL testing database in this revision; SQLite
  skips it by design.
- Real Docker scheduler/outbox recovery with Redis stopped remains pending for
  staging. Automated tests cover its state machine without contacting
  development services.
- Meilisearch durability does not replace PostgreSQL backup verification; the
  index remains a rebuildable projection.
- The matching frontend browser checklist is maintained at
  `frontend-repo:/docs/qa/tok-29-buyer-catalog-search.md`.
- Historical TOK-30 evidence must not be used to mark the TOK-29 pagination,
  queue, or Meilisearch migration as verified.
