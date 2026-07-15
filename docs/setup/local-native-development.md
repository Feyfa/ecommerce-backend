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
```

## Automated Test Database Safety

Local PHPUnit runs use an isolated SQLite in-memory database configured by `phpunit.xml`. They must never reuse the PostgreSQL development database from `.env`, because database-resetting traits such as `RefreshDatabase` recreate the active test schema.

`AppServiceProvider` also stops the testing application during bootstrap, before the first database connection is created, unless both conditions are satisfied. The guard resolves the final connection configuration, including a `DATABASE_URL` override, so a safe-looking `DB_DATABASE` value cannot hide an unsafe URL target:

- `APP_ENV` is `testing`;
- the connection uses SQLite `:memory:` or an external database whose name explicitly contains `test` or `testing`.

GitHub Actions remains configured to use the dedicated PostgreSQL database `ecommerce_testing`; explicit CI environment variables take precedence over the local SQLite defaults.

Run the local suite normally:

```bash
php artisan test
```

Do not disable the PHPUnit database settings or the bootstrap database guard to make a test run against the development database.

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
