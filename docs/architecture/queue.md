# Laravel Queue

TokShop uses Laravel's Redis queue for asynchronous buyer product search-index
synchronization. Search requests query Meilisearch directly; PostgreSQL
business mutations record delivery intent through the transactional outbox.

## Configuration

Set the Laravel queue and failed-job backends in `.env`:

```env
QUEUE_CONNECTION=redis
QUEUE_FAILED_DRIVER=database-uuids
REDIS_QUEUE_RETRY_AFTER=180
```

Buyer catalog jobs use the dedicated `buyer-catalog-search` queue. `outbox:publish` maps due
product and seller events to `SyncBuyerProductSearchJob` and
`SyncSellerBuyerCatalogSearchJob` after their business transaction has committed.
HTTP mutation paths do not contact Redis directly.

`REDIS_QUEUE_RETRY_AFTER` must remain longer than the longest queue job timeout.
The current 180-second value exceeds the 120-second timeout used by
`SyncSellerBuyerCatalogSearchJob`, preventing Redis from releasing the same job
while its original worker may still be processing it. Re-evaluate both values
together whenever a job timeout changes.

## Worker operation

Run a dedicated local worker in a separate terminal:

```bash
php artisan queue:work redis --queue=buyer-catalog-search --sleep=1 --tries=3 --backoff=5 --timeout=60
```

Staging and production run this process in `backend-worker`. Docker is its
process monitor and restarts the container; Supervisor is not installed inside
the container.

The worker is separate from `backend-scheduler`. The scheduler publishes
PostgreSQL outbox messages to Redis every minute, while the worker consumes the
resulting jobs and writes Meilisearch. See
[Laravel Scheduler](scheduler.md) and [Transactional Outbox](outbox.md).

## Idempotency and fan-out

`SyncBuyerProductSearchJob` reloads the latest PostgreSQL state and upserts or
removes the corresponding Meilisearch document. Duplicate or retried jobs
therefore converge on current state. `WithoutOverlapping` prevents concurrent
writes for one product.

`SyncSellerBuyerCatalogSearchJob` reads one seller's products in 200-record chunks
and dispatches one product job per row. The query includes soft-deleted products
so child jobs can remove stale documents.

This fan-out bounds database memory but not the total job count. A seller with
5,000 active or soft-deleted products can create one seller job plus 5,000
product jobs. Future optimization is tracked in
[TOK-31](https://muhammadjidan-31088882.atlassian.net/browse/TOK-31), including
projection-relevant change detection, deduplication, and bulk synchronization.

## Failure boundaries

There are two independent retry layers:

- Outbox publishing covers PostgreSQL-to-Redis delivery. Inspect it with
  `php artisan outbox:status`; repaired terminal messages can be activated with
  `php artisan outbox:retry <outbox-uuid>`.
- Queue processing covers Redis-to-Meilisearch delivery. Terminal worker
  failures are stored in `failed_jobs` and application logs.

Synchronization jobs log product or seller identifiers and the Redis queue job
ID when available. Inspect and recover worker failures with:

```bash
php artisan queue:failed
php artisan queue:retry <failed-job-id>
php artisan queue:forget <failed-job-id>
```

Resolve Redis, Meilisearch, or configuration failure before retrying. Review
worker logs before forgetting a failed job.

## Recovery verification

After repairing an outage, verify both delivery layers:

```bash
php artisan outbox:status
php artisan queue:monitor redis:buyer-catalog-search --max=1
php artisan queue:failed
```

Recovery is complete when there are no overdue pending outbox messages, the
`buyer-catalog-search` queue has drained, no unresolved search job remains in
`failed_jobs`,
and the authenticated buyer-catalog checks in
[Meilisearch](meilisearch.md) succeed.

Use `php artisan buyer-search:reindex` for initial index creation, index loss,
or an emergency full rebuild. A reindex dispatches jobs directly as an explicit
operator action and still requires Redis, the worker, and Meilisearch to be
healthy.
