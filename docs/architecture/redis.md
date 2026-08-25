# Redis

Redis is TokShop's internal Laravel queue backend. PostgreSQL remains the
source of truth for application data; Redis stores pending and reserved jobs.

## Local development

Local native development uses Homebrew Redis at `127.0.0.1:6379`. Laravel uses
the PhpRedis extension and these `.env` values:

```env
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
REDIS_QUEUE=default
```

Confirm that the extension is available before running queue workers:

```bash
php -m | grep redis
```

## Staging and production

Staging and production run an internal Docker Redis service. The backend uses
`REDIS_HOST=redis`; no Redis port is published to the host or public network.
Redis uses append-only persistence in its dedicated Docker volume.

Redis queue state is operational data, not a replacement for PostgreSQL
backups. Do not expose an unauthenticated Redis service outside the internal
Docker network.
