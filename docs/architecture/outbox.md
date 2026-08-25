# Transactional Outbox

TokShop uses a PostgreSQL transactional outbox to deliver buyer-catalog
synchronization work durably to Redis. A business mutation and its outbox
message are committed in the same database transaction. Redis and Meilisearch
may be temporarily unavailable without losing the intent to synchronize.

PostgreSQL remains the source of truth. Redis transports work, and Meilisearch
is a rebuildable buyer-catalog projection.

## Delivery flow

```text
HTTP mutation
  -> PostgreSQL business rows + outbox_messages in one transaction
  -> Laravel Scheduler runs outbox:publish every minute
  -> publisher sends a job to the Redis buyer-catalog-search queue
  -> backend-worker updates or removes the Meilisearch document
```

Product create, update, soft delete, checkout stock changes, relevant company
changes, and Clerk fallback-name changes record an outbox message. HTTP
mutation code never dispatches these search jobs directly.

## Message contract

The generic `outbox_messages` table initially supports:

| `event_type` | Aggregate | Redis job |
| --- | --- | --- |
| `buyer_catalog.product.sync` | `product` | `SyncBuyerProductSearchJob` |
| `buyer_catalog.seller.sync` | `seller` | `SyncSellerBuyerCatalogSearchJob` |

`aggregate_id` has no foreign key. The message must survive a product soft
delete or later aggregate removal. Each committed mutation records a new row;
version one intentionally performs no coalescing.

The JSONB payload starts with this versioned shape:

```json
{
  "schema_version": 1,
  "source": "product.updated"
}
```

Supported sources are `product.created`, `product.updated`, `product.deleted`,
`checkout.stock_changed`, `company.updated`, and `clerk.name_changed`. New
event types can add payload fields without adding table columns, but their
schema, validation, and publisher mapping must be implemented together.

## Transaction boundary

`OutboxRecorderService` refuses to record a message outside an active database
transaction. This guard prevents code from accidentally committing business
data without its delivery intent. A rollback removes both the mutation and its
outbox row.

Checkout records one product message per unique changed product. Company and
Clerk synchronization record seller messages only when their documented
projection-relevant condition is met.

## Lifecycle and concurrency

Messages move through these states:

```text
pending -> processing -> published
                   \-> pending with a later available_at
                   \-> failed
```

The publisher claims at most 100 rows per batch and at most 10 batches per
invocation by default. PostgreSQL claims use `FOR UPDATE SKIP LOCKED`, allowing
overlapping processes to claim different rows without processing one active
claim twice. The database transaction ends before any Redis network call.

`locked_at` is also the claim token. A publish outcome may update a row only
when both `processing` status and the same token still match. A processing
claim older than five minutes is stale and can be reclaimed.

There is an unavoidable at-least-once crash window: Redis may accept a job
before the publisher records `published`. Reclaiming that message can dispatch
a duplicate. Buyer-catalog jobs are idempotent and reload current PostgreSQL
state, so a duplicate converges safely instead of losing synchronization.

## Failure and retry policy

Transport failures return the message to `pending`, increment `attempts`, and
set a new `available_at`. Exponential backoff begins at 60 seconds and is
capped at six hours. The twentieth failed publish becomes `failed` and emits a
`critical` log.

Unsupported event types and invalid payloads are permanent failures. They move
directly to `failed` because retrying unchanged data cannot make them valid.
`last_error` stores a bounded exception class and safe message; the application
log keeps complete exception context.

Outbox failure and queue failure are different:

- `outbox_messages.failed` means the publisher could not create a Redis job.
- `failed_jobs` means Redis accepted the job, but the worker exhausted its
  attempts while processing it.

Inspect and repair both layers during an incident.

## Commands

```bash
php artisan outbox:status
php artisan outbox:publish --batch=100 --max-batches=10
php artisan outbox:retry <outbox-uuid>
php artisan outbox:prune --days=7
```

`outbox:retry` reactivates one failed message and resets its publish attempt
counter. Resolve invalid payloads or configuration failures before retrying.
The publish command exits safely when the table does not exist, which supports
the short application-image-before-migration rollout window.

## Retention and recovery

Published messages are retained for seven days for troubleshooting and are
then pruned. Pending and failed rows are never removed automatically.

For an outage:

1. Restore PostgreSQL, Redis, and the relevant containers.
2. Run `php artisan outbox:status` and inspect scheduler logs.
3. Retry repaired terminal outbox messages when appropriate.
4. Inspect `php artisan queue:failed` and worker logs.
5. Confirm the outbox has no overdue pending messages and the
   `buyer-catalog-search` queue
   drains.
6. Run `php artisan buyer-search:reindex` only when the projection needs a full
   emergency rebuild, then verify Meilisearch and buyer API results.

Environment tuning lives in `config/outbox.php` and the `OUTBOX_*` variables in
`.env.example`. Keep defaults unless capacity evidence justifies changing batch,
retry, locking, or retention limits.
