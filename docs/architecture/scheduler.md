# Laravel Scheduler

TokShop runs recurring backend maintenance through Laravel Scheduler. In
staging and production, Docker keeps one `backend-scheduler` container alive;
that container runs:

```bash
php artisan schedule:work
```

This replaces a VM crontab for application schedules. It is separate from
`backend-worker`, which continuously consumes Redis queue jobs. Supervisor is
not used inside either container because Docker already monitors and restarts
each long-running process.

## Scheduled tasks

The backend currently registers:

| Schedule | Command | Purpose |
| --- | --- | --- |
| Every minute | `outbox:publish` | Publish due PostgreSQL outbox messages to Redis. |
| Daily at 02:00 | `outbox:prune --days=7` | Remove published messages older than the retention period. |

Laravel evaluates these schedules with the application timezone from
`config/app.php`, currently `Asia/Jakarta`. Therefore, the daily prune runs at
02:00 WIB even when the container or host operating system uses UTC.

Both tasks use overlap protection. The publisher also uses database row
claiming, so correctness does not depend only on the scheduler cache lock.

Only one scheduler container should be active per environment. Running an
extra scheduler is not expected deployment topology, although outbox locking
still protects active messages if processes briefly overlap during a rollout.

## Local development

For native local development, run the scheduler in its own terminal:

```bash
php artisan schedule:work
```

Keep the Redis worker in another terminal:

```bash
php artisan queue:work redis --queue=buyer-catalog-search --sleep=1 --tries=3 --backoff=5 --timeout=60
```

For immediate troubleshooting, run `php artisan outbox:publish` manually
instead of waiting for the next minute. `php artisan schedule:list` shows the
registered schedule and next run times.

## Docker operations

The scheduler uses the same backend image and environment as `backend-php`,
mounts the backend log volume, and depends only on healthy PostgreSQL. Redis or
Meilisearch downtime must not prevent the scheduler container from starting;
the outbox publisher records retryable delivery failure in PostgreSQL.

Inspect runtime state and logs from the deploy repository:

```bash
docker compose -f compose/compose.staging.yml ps backend-scheduler backend-worker
docker compose -f compose/compose.staging.yml logs --tail=200 backend-scheduler
docker compose -f compose/compose.staging.yml logs --tail=200 backend-worker
```

Production uses the corresponding production Compose file. Docker restart
policy is `unless-stopped`, and Compose grants a graceful stop period before
terminating the PHP process.

## Adding scheduled work

Register application schedules in `app/Console/Kernel.php`; do not add an
independent host crontab or a second scheduler container. New tasks must be
safe to retry, use overlap protection when concurrent execution is unsafe, and
document any database, network, retention, or operational impact.

Outbox delivery behavior and recovery commands are documented in
[Transactional Outbox](outbox.md). Redis worker behavior is documented in
[Laravel Queue](queue.md).
