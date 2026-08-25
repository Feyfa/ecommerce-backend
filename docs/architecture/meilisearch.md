# Meilisearch

Meilisearch provides typo-tolerant buyer product search. Its data is a
rebuildable projection of PostgreSQL, never the source of truth for carts,
checkout, or product mutations.

## Configuration

Local native development uses the Homebrew Meilisearch binary at
`http://127.0.0.1:7700`. Configure Laravel with:

```env
MEILISEARCH_HOST=http://127.0.0.1:7700
MEILISEARCH_KEY=<local-development-key>
BUYER_PRODUCT_SEARCH_INDEX=buyer_products
```

Staging and production use the internal Docker hostname `meilisearch` and a
distinct high-entropy `MEILISEARCH_KEY`. The key must never appear in frontend
variables or committed environment files.

Laravel communicates with Meilisearch through the official PHP SDK bound as a
singleton `Meilisearch\Client`. `config/meilisearch.php` owns the connection
host and API key. Laravel Scout is intentionally not part of this architecture;
document publication and deletion are controlled explicitly by the outbox,
queue jobs, and buyer search service.

## Laravel-owned settings

`config/buyer_product_search.php` is the source of truth for the index name,
searchable attributes, filters, sortable attributes, ranking rules, and product
synonyms. Do not make manual dashboard changes because the next re-index will
replace them.

The index explicitly sets `pagination.maxTotalHits` to 10,000. This is a
browsing boundary, not a PostgreSQL product limit. Search and filters can still
reach products outside one broad result set by narrowing the query. Increasing
this value further requires measured latency and resource evidence because
Meilisearch ranks more matching documents as the boundary grows.

`id:asc` is the final tie-breaker. Explicit price, name, and timestamp sorts
send ID as their second criterion, while relevance applies ID only after all
native ranking rules tie.

The buyer document contains product and seller IDs, product/store names, image,
price, stock, timestamps, and computed purchasability. Only active in-stock
products from sellers with verified locations are projected.

## Re-indexing

After Meilisearch starts for the first time, after index loss, or after changing
Laravel-owned index settings, run:

```bash
php artisan buyer-search:reindex
```

The command configures the index, clears the existing derived documents, and
queues every product for synchronization. Keep the `buyer-catalog-search`
worker running
until the queue drains. During this reconstruction buyer search can be
temporarily empty, so run it during a controlled maintenance window when the
catalog is large.

Routine product, stock, company, and fallback seller-name changes do not depend
on this command. They record transactional outbox messages that Laravel
Scheduler publishes to Redis. A full reindex remains the emergency recovery
path for index loss or projection-wide inconsistency.

Do not treat the reindex command's success message as proof that indexing has
finished: it confirms that synchronization jobs were dispatched. Verify all of
the following before declaring recovery complete:

```bash
php artisan queue:monitor redis:buyer-catalog-search --max=1
php artisan queue:failed
php artisan tinker --execute="dump(app(\\Meilisearch\\Client::class)->index(config('buyer_product_search.index'))->stats());"
```

- the `buyer-catalog-search` queue reports `[0] OK`;
- no unresolved buyer-search job appears in `failed_jobs`;
- Meilisearch index statistics report the expected non-zero document count
  when eligible products exist;
- an authenticated `/api/belanja` smoke test returns expected product cards,
  filters, and pagination metadata.

If failed jobs exist, resolve the underlying Redis, Meilisearch, or
configuration error before retrying them. Re-run the full reindex only when the
projection itself must be rebuilt; do not use repeated rebuilds as a substitute
for diagnosing a failed worker.

If Meilisearch is unavailable, `/api/belanja` returns a safe `503` response.
It intentionally does not fall back to the legacy PostgreSQL `LIKE` search.

## Local integration test

The standard PHPUnit suites mock Meilisearch and never touch a real index. To
verify typo tolerance, synonyms, filters, sorting, buyer exclusion, and
lookahead pagination against the local engine, start Homebrew Meilisearch and
run the explicit search integration file:

```bash
brew services start meilisearch
php artisan test tests/Integration/MeilisearchBuyerProductSearchTest.php
```

To verify the full reindex contract, including settings application, stale
document cleanup, all-product dispatch, soft-delete cleanup, and the final
eligible document set, run:

```bash
php artisan test tests/Integration/BuyerProductReindexTest.php
```

`tests/Integration/` is intentionally outside the `Unit` and `Feature` suites
declared in `phpunit.xml`, so `php artisan test` does not run this external
service check. The integration test derives a unique index from
`buyer_products_testing`, inserts only its own fixtures, and deletes that unique
index during teardown. The reindex test uses the standard SQLite in-memory
database and fake queue, then executes the captured product jobs against its
real disposable Meilisearch index. Redis delivery remains covered by the
controlled local worker scenarios in the TOK-29 QA record. Neither integration
test may use or delete the development `buyer_products` index.
