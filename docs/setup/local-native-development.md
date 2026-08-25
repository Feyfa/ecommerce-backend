# Local Native Development

This document explains how to run the Ecommerce frontend and backend for local native development without Docker.

Docker is reserved for staging and production deployment. Local development uses the host operating system, local HTTPS domains, local app runtimes, and local database services.

The local browser URLs are:

```text
https://app.ecommerce.dev
https://api.ecommerce.dev
```

The frontend and backend repositories are separate, but this setup needs both sides to use the same local domains.

## Architecture

The recommended local architecture is a reverse proxy in front of the app servers:

```text
Browser
  -> https://app.ecommerce.dev
  -> local reverse proxy on port 443
  -> Vite dev server on 127.0.0.1:43010

Browser
  -> https://api.ecommerce.dev
  -> local reverse proxy on port 443
  -> Laravel public directory through PHP-FPM or PHP FastCGI
```

The browser should use only the HTTPS domains. Internal ports are implementation details.

Current internal services:

```text
Frontend Vite: 127.0.0.1:43010
Backend PHP-FPM: 127.0.0.1:9002
```

The frontend uses `43010` instead of Vite's default `5173` so it can run beside other Vue projects without taking the common default port.

## Repository Configuration

Frontend `.env`:

```env
VITE_APP_BACKEND_BASE_URL="https://api.ecommerce.dev"
VITE_SYMLINK_FOLDER="storage"
```

Frontend Vite config:

```text
server.host = 127.0.0.1
server.port = 43010
server.strictPort = true
server.hmr.host = app.ecommerce.dev
server.hmr.clientPort = 443
```

Backend `.env`:

```env
APP_URL=https://api.ecommerce.dev
FRONTEND_URL=https://app.ecommerce.dev

QUEUE_CONNECTION=redis
QUEUE_FAILED_DRIVER=database-uuids
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null

MEILISEARCH_HOST=http://127.0.0.1:7700
MEILISEARCH_KEY=
BUYER_PRODUCT_SEARCH_INDEX=buyer_products
BUYER_PRODUCT_SEARCH_MAX_TOTAL_HITS=10000
```

Keep `MEILISEARCH_KEY` empty only when the local Meilisearch process also runs
without a master key. If local Meilisearch is started with a master key, the
same value must be present in the backend `.env`. Never reuse a staging or
production key for local development.

The backend uses the official Meilisearch PHP SDK directly. The connection is
defined in `config/meilisearch.php`; no Scout driver or prefix is required.

## Local Search Services

Buyer catalog search requires Redis, Meilisearch, a dedicated Laravel queue
worker, and Laravel Scheduler. PostgreSQL stores business data and durable
outbox messages; the scheduler publishes due messages to Redis; the worker
updates the rebuildable Meilisearch projection. PostgreSQL remains the source
of truth.

The backend uses the PhpRedis extension. Verify the CLI and the PHP runtime used
by PHP-FPM both load it:

```bash
php -m | grep -i redis
```

If the CLI and PHP-FPM use different `php.ini` files, enable the extension in
both environments before starting the worker.

### macOS

Install and run both services through Homebrew:

```bash
brew install redis meilisearch
brew services start redis
brew services start meilisearch
```

Verify the local endpoints:

```bash
redis-cli ping
curl http://127.0.0.1:7700/health
```

The expected responses are `PONG` and a Meilisearch JSON response whose status
is `available`.

### Windows

Redis Open Source does not provide a regular native Windows installation in its
current official installation guide. For the native-development workflow, run
Redis and Meilisearch inside WSL2 rather than installing an unofficial Windows
Redis build. Install WSL2 from an Administrator PowerShell terminal if needed:

```powershell
wsl --install
```

Inside the installed Ubuntu distribution, follow the Linux installation below.
Windows normally exposes services running inside WSL2 through `localhost`, so
the Windows-hosted Laravel runtime can keep `REDIS_HOST=127.0.0.1` and
`MEILISEARCH_HOST=http://127.0.0.1:7700`. If local forwarding has been disabled,
restore WSL localhost forwarding instead of committing a machine-specific WSL
IP address to project configuration.

Enable a PHP Redis extension build compatible with the installed Windows PHP
runtime, or use the extension manager provided by Laragon or Herd. Confirm it
with `php -m` before running Laravel queue commands.

### Linux (Ubuntu or Debian)

Install Redis from its official APT repository:

```bash
sudo apt-get install lsb-release curl gpg
curl -fsSL https://packages.redis.io/gpg | sudo gpg --dearmor -o /usr/share/keyrings/redis-archive-keyring.gpg
sudo chmod 644 /usr/share/keyrings/redis-archive-keyring.gpg
echo "deb [signed-by=/usr/share/keyrings/redis-archive-keyring.gpg] https://packages.redis.io/deb $(lsb_release -cs) main" | sudo tee /etc/apt/sources.list.d/redis.list
sudo apt-get update
sudo apt-get install redis
sudo systemctl enable --now redis-server
```

Install Meilisearch from its official APT repository:

```bash
echo "deb [trusted=yes] https://apt.fury.io/meilisearch/ /" | sudo tee /etc/apt/sources.list.d/fury.list
sudo apt-get update
sudo apt-get install meilisearch
```

Run Meilisearch in a dedicated development terminal:

```bash
meilisearch
```

Then verify `redis-cli ping` and `curl http://127.0.0.1:7700/health` as shown in
the macOS section. Other Linux distributions should follow the current official
Redis and Meilisearch installation guides instead of translating these APT
commands without checking their package manager.

Official installation references:

- [Redis Open Source installation](https://redis.io/docs/latest/operate/oss_and_stack/install/install-stack/)
- [Meilisearch local installation](https://www.meilisearch.com/docs/resources/self_hosting/getting_started/install_locally)
- [Microsoft WSL installation](https://learn.microsoft.com/windows/wsl/install)
- [Microsoft WSL networking](https://learn.microsoft.com/windows/wsl/networking)

### Initialize And Run Buyer Search

After PostgreSQL migrations are current and Redis and Meilisearch are healthy,
start the dedicated worker from the backend repository in a separate terminal:

```bash
php artisan queue:work redis --queue=buyer-catalog-search --sleep=1 --tries=3 --backoff=5 --timeout=60
```

Start Laravel Scheduler in another terminal so committed outbox messages are
published every minute:

```bash
php artisan schedule:work
```

For immediate local processing or troubleshooting, use:

```bash
php artisan outbox:status
php artisan outbox:publish
```

Build the buyer catalog index on first setup, after index loss, or after changing
Laravel-owned Meilisearch settings:

```bash
php artisan buyer-search:reindex
```

Keep the worker running until it processes all dispatched product jobs. Check
for terminal failures before testing the buyer catalog:

```bash
php artisan queue:failed
```

Open the buyer shopping page only after the worker has populated the index. An
empty index produces an empty catalog, while an unreachable Meilisearch process
causes `/api/belanja` to return `503` with
`BUYER_PRODUCT_SEARCH_UNAVAILABLE`.

## Automated Test Resource Safety

Local PHPUnit runs use an isolated SQLite in-memory database configured by `phpunit.xml`. They must never reuse the PostgreSQL development database from `.env`, because database-resetting traits such as `RefreshDatabase` recreate the active test schema.

`AppServiceProvider` also stops the testing application during bootstrap, before the first database connection is created, unless both conditions are satisfied. The guard resolves the final connection configuration, including a `DATABASE_URL` override, so a safe-looking `DB_DATABASE` value cannot hide an unsafe URL target:

- `APP_ENV` is `testing`;
- the connection uses SQLite `:memory:` or an external database whose name explicitly contains `test` or `testing`.

GitHub Actions remains configured to use the dedicated PostgreSQL database `ecommerce_testing`; explicit CI environment variables take precedence over the local SQLite defaults.

The standard suite also keeps Redis and Meilisearch isolated:

- queue jobs are faked by the base test case and use the `sync` connection;
- cache uses the in-memory `array` store;
- Redis fallbacks are limited to loopback databases `14` and `15` with the
  `ecommerce_testing_` prefix;
- Meilisearch is limited to loopback and the `buyer_products_testing` index.

The application refuses to bootstrap in `testing` when queue or cache uses an
external driver, Redis does not use the testing boundary, or Meilisearch points
to a remote host or non-testing index. Standard tests mock the Meilisearch
client and do not require Redis or Meilisearch to be running.

The explicit test in
`tests/Integration/MeilisearchBuyerProductSearchTest.php` uses a unique index
derived from `buyer_products_testing` and removes it during teardown. Run it
only when local Meilisearch is available:

```bash
php artisan test tests/Integration/MeilisearchBuyerProductSearchTest.php
```

The full-reindex integration test uses the same unique-index boundary plus the
SQLite in-memory database and fake queue from the standard test environment.
It processes the captured jobs only against its disposable index, so it never
clears the development `buyer_products` index or publishes to development
Redis:

```bash
php artisan test tests/Integration/BuyerProductReindexTest.php
```

The PostgreSQL locking integration test is opt-in because the standard suite
uses SQLite. Point it only at a disposable PostgreSQL database whose name
contains `test` or `testing`, then run:

```bash
php artisan test tests/Integration/PostgresOutboxLockingTest.php
```

It verifies that concurrent publishers use `FOR UPDATE SKIP LOCKED` to claim
different outbox rows. It skips automatically when the active testing driver is
not PostgreSQL.

Run the local suite normally:

```bash
php artisan test
```

Do not disable the PHPUnit resource settings or bootstrap guards to make a test
run against development PostgreSQL, Redis, or Meilisearch data.

## macOS Setup

This setup assumes Homebrew, Homebrew nginx, Homebrew PHP 8.3, and `mkcert`.

Install `mkcert` if needed:

```bash
brew install mkcert nss
mkcert -install
```

Create a local certificate from the Ecommerce project root:

```bash
cd "/Users/muhammadjidan/Documents/CODE LARAVEL10 AND VUEJS 3 VSC/Ecommerce"
mkdir -p .local-certs

mkcert \
  -cert-file .local-certs/ecommerce-dev.pem \
  -key-file .local-certs/ecommerce-dev-key.pem \
  app.ecommerce.dev api.ecommerce.dev localhost 127.0.0.1 ::1
```

Add local DNS entries:

```bash
grep -q 'app.ecommerce.dev' /etc/hosts || echo '127.0.0.1 app.ecommerce.dev' | sudo tee -a /etc/hosts
grep -q 'api.ecommerce.dev' /etc/hosts || echo '127.0.0.1 api.ecommerce.dev' | sudo tee -a /etc/hosts
sudo dscacheutil -flushcache
sudo killall -HUP mDNSResponder
```

Create the frontend nginx server block at:

```text
/opt/homebrew/etc/nginx/servers/app.ecommerce.dev.conf
```

```nginx
server {
    listen 80;
    server_name app.ecommerce.dev;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name app.ecommerce.dev;

    ssl_certificate "/Users/muhammadjidan/Documents/CODE LARAVEL10 AND VUEJS 3 VSC/Ecommerce/.local-certs/ecommerce-dev.pem";
    ssl_certificate_key "/Users/muhammadjidan/Documents/CODE LARAVEL10 AND VUEJS 3 VSC/Ecommerce/.local-certs/ecommerce-dev-key.pem";

    location / {
        proxy_pass http://127.0.0.1:43010;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
    }
}
```

Create the backend nginx server block at:

```text
/opt/homebrew/etc/nginx/servers/api.ecommerce.dev.conf
```

```nginx
server {
    listen 80;
    server_name api.ecommerce.dev;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl;
    server_name api.ecommerce.dev;

    ssl_certificate "/Users/muhammadjidan/Documents/CODE LARAVEL10 AND VUEJS 3 VSC/Ecommerce/.local-certs/ecommerce-dev.pem";
    ssl_certificate_key "/Users/muhammadjidan/Documents/CODE LARAVEL10 AND VUEJS 3 VSC/Ecommerce/.local-certs/ecommerce-dev-key.pem";

    root "/Users/muhammadjidan/Documents/CODE LARAVEL10 AND VUEJS 3 VSC/Ecommerce/backend/public";
    index index.php index.html;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9002;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_param HTTPS on;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Validate and reload nginx:

```bash
nginx -t
nginx -s reload
```

Run the frontend:

```bash
cd "/Users/muhammadjidan/Documents/CODE LARAVEL10 AND VUEJS 3 VSC/Ecommerce/frontend"
npm run dev
```

Open:

```text
https://app.ecommerce.dev
```

## Windows Setup

The repository configuration stays the same on Windows. Only the machine setup changes.

Install the required tools:

- Node.js 22, or nvm-windows with Node.js 22.
- PHP 8.3.
- Composer.
- PostgreSQL, if the backend database is local.
- `mkcert`.
- A local reverse proxy such as Caddy, nginx for Windows, Laragon, or Herd.

Add local DNS entries by opening this file as Administrator:

```text
C:\Windows\System32\drivers\etc\hosts
```

Add:

```text
127.0.0.1 app.ecommerce.dev
127.0.0.1 api.ecommerce.dev
```

Create the certificate from the Ecommerce project root:

```powershell
mkdir .local-certs

mkcert `
  -cert-file .local-certs\ecommerce-dev.pem `
  -key-file .local-certs\ecommerce-dev-key.pem `
  app.ecommerce.dev api.ecommerce.dev localhost 127.0.0.1 ::1
```

Run the frontend:

```powershell
cd frontend
npm install
npm run dev
```

Recommended Caddy example:

```caddyfile
app.ecommerce.dev {
    tls C:\path\to\Ecommerce\.local-certs\ecommerce-dev.pem C:\path\to\Ecommerce\.local-certs\ecommerce-dev-key.pem
    reverse_proxy 127.0.0.1:43010
}

api.ecommerce.dev {
    tls C:\path\to\Ecommerce\.local-certs\ecommerce-dev.pem C:\path\to\Ecommerce\.local-certs\ecommerce-dev-key.pem
    root * C:\path\to\Ecommerce\backend\public
    php_fastcgi 127.0.0.1:9002
    file_server
}
```

If PHP FastCGI is not already running on `127.0.0.1:9002`, start it from the PHP installation directory:

```powershell
php-cgi -b 127.0.0.1:9002
```

If using Laragon, Herd, or nginx instead of Caddy, keep the same domain mapping:

```text
app.ecommerce.dev -> 127.0.0.1:43010
api.ecommerce.dev -> backend/public through PHP 8.3
```

## Notes

- Do not commit local certificate private keys.
- `.dev` domains require HTTPS in modern browsers.
- Vite may still run internally on `127.0.0.1:43010`; the browser URL should remain `https://app.ecommerce.dev`.
- Keep `strictPort: true` so Vite fails clearly if `43010` is already used instead of silently moving to another port.
