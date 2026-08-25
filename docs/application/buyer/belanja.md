# Buyer Belanja

Buyer catalog search uses Meilisearch. PostgreSQL remains the source of truth
for products, carts, checkout, and the rebuildable search projection.

## API contract

```text
GET  /api/belanja
POST /api/keranjang
```

`GET /api/belanja` accepts:

- `page`: page number, minimum `1`, default `1`.
- `per_page`: cards per page, from `1` through `50`, default `24`.
- `search_product`: product or displayed-store keyword.
- `min_price` and `max_price`: inclusive Rupiah price limits.
- `added_within`: `7`, `14`, `30`, or `90` days.
- `sort_product`: `relevance`, `latest`, `oldest`, `price_lowest`,
  `price_highest`, `name_asc`, or `name_desc`.

A keyword without an explicit sort defaults to `relevance`; no keyword defaults
to `latest`. Relevance does not send a Meilisearch sort expression, so native
typo tolerance and ranking remain effective.

```json
{
  "status": 200,
  "products": [],
  "page": 1,
  "per_page": 24,
  "has_more": false,
  "limit_reached": false
}
```

`has_more` indicates whether an actual lookahead document exists. The additive
`limit_reached` flag distinguishes normal exhaustion from reaching the
configured 10,000-result browsing boundary. A boundary response returns
`has_more: false` and `limit_reached: true`; it does not claim that PostgreSQL
contains no other matching products.

Each product card contains `p_id`, `p_img`, `p_name`, `p_price`, `p_stock`,
`u_id`, and `u_name`. The old `products_current_id` request contract is removed.

When Meilisearch is unavailable, the endpoint returns `503` with
`BUYER_PRODUCT_SEARCH_UNAVAILABLE`. It intentionally does not fall back to a
PostgreSQL `LIKE` query.

## Search document and access rules

`BuyerProductSearchService` owns the buyer document: product and seller IDs,
product name, displayed store name, image, price, stock, timestamps, and
computed purchasability. Store name prioritizes `companies.name` and falls back
to `users.name`.

Only active, in-stock products from sellers with a verified map location are
indexed. Every search also excludes the requesting buyer's seller ID. Cart and
checkout still revalidate PostgreSQL, preventing stale search results from being
purchased.

Index settings are Laravel-owned in `config/buyer_product_search.php`. They
contain searchable, filterable, and sortable fields, ranking rules, a 10,000
`maxTotalHits` boundary, and these synonym groups:

```text
hp ↔ handphone ↔ ponsel
spt ↔ sepatu
lptp ↔ laptop
tv ↔ televisi
powerbank ↔ power bank
charger ↔ cas
sneakers ↔ snikers
```

Every explicit product sort uses `id:asc` as its final criterion. Relevance
keeps native text ranking and uses the same ID rule only after all relevance
rules tie. This gives offset pagination a deterministic order when products
share a price, normalized name, or timestamp.

## Synchronization and operations

Product creation, updates, availability changes, and soft deletion record an
outbox message in the same PostgreSQL transaction. Laravel Scheduler publishes
due messages to Redis, then `SyncBuyerProductSearchJob` loads current PostgreSQL
state and upserts or removes the document. `WithoutOverlapping` serializes work
for one product; terminal worker failures are stored in `failed_jobs` and
logged.

Store identity and seller-location updates record seller outbox messages that
eventually run `SyncSellerBuyerCatalogSearchJob` to reproject the seller catalog.

Configure and rebuild the index after Meilisearch is ready:

```bash
php artisan buyer-search:reindex
```

Run the dedicated worker:

```bash
php artisan queue:work redis --queue=buyer-catalog-search --sleep=1 --tries=3 --backoff=5 --timeout=60
```

Run `php artisan schedule:work` in another local terminal. Inspect publisher
state with `php artisan outbox:status`; inspect worker failures with
`php artisan queue:failed`. See
[Transactional Outbox](../../architecture/outbox.md) for retry and recovery
commands.

## Environment

Local native development uses Homebrew Redis and Meilisearch with
`QUEUE_CONNECTION=redis`, `REDIS_CLIENT=phpredis`, `MEILISEARCH_HOST`, and
`MEILISEARCH_KEY`. Laravel binds the official Meilisearch PHP SDK directly;
Scout is not used. Staging and production use internal Docker hostnames `redis`
and `meilisearch` through `deploy/env/*/backend.env`.
