# Docker Staging And Production Deployment

This document defines the first Docker deployment direction for the ecommerce backend and its related frontend runtime.

The goal is to keep local development simple while making staging and production reproducible. Docker is used as a deployment foundation, not as a required local development workflow.

## Deployment Decision

The current deployment decision is:

```text
Local development:
Native development without Docker.

Staging:
Docker is required.

Production:
Docker is required.
```

Local development stays native because the current local setup already uses local HTTPS domains, local PostgreSQL, and editor tooling that works well without Docker. This avoids maintaining multiple local Docker variants for different operating systems, filesystem behavior, dependency mounting, and autocomplete needs.

Staging and production use Docker because those environments need repeatable runtime configuration, clear service boundaries, predictable deployment commands, persistent data volumes, and a safer path for future services such as websocket servers.

## Environment Topology

The first deployment topology uses one VM per environment:

```text
Staging:
1 VM for the full staging stack.

Production:
1 VM for the full production stack.
```

Each VM runs the required services through Docker Compose.

```text
staging VM
  reverse proxy
  frontend
  backend API
  PostgreSQL
  websocket server later

production VM
  reverse proxy
  frontend
  backend API
  PostgreSQL
  websocket server later
```

This keeps staging and production isolated while avoiding early VM fragmentation.

## Why One VM Per Environment For Now

The project should split infrastructure only when there is a real operational reason.

Running one VM per environment is the first deployment baseline because:

- Staging and production remain fully separated.
- Docker Compose can still keep services separated inside each VM.
- Deployment, backup, logging, and troubleshooting stay easier to understand.
- PostgreSQL can use a persistent Docker volume from the start.
- The setup can later evolve into separate data, queue worker, websocket, or app VMs without changing the application architecture.

Splitting every responsibility into its own VM too early would create extra firewall rules, deploy targets, OS maintenance, networking complexity, and troubleshooting overhead before the application actually needs it.

## Service Responsibilities

The deployment stack is expected to use these services.

### Reverse Proxy

The reverse proxy routes incoming traffic to the correct container. The first private VM deployment uses HTTP over Tailscale; public HTTPS termination can be added later when public domain access is needed.

Expected responsibilities:

- Serve private HTTP traffic for Tailscale-based staging and personal production testing.
- Serve HTTPS for public staging and production domains when public access is added.
- Route frontend requests to the frontend container.
- Route API requests to the backend container.
- Route websocket requests to the websocket service when chat is implemented.

The selected reverse proxy for this deployment stack is Nginx.

### Frontend

The frontend service serves the built Vue application.

Staging and production should not use the Vite development server. The frontend image should build the Vue application and serve the generated static files.

Expected flow:

```text
npm ci
npm run build
serve dist
```

Frontend environment values that affect the bundle, such as the API URL, must be provided during the build or through a deliberate runtime configuration strategy.

### Backend API

The backend API service handles Laravel HTTP requests.

Expected responsibilities:

- Serve API endpoints.
- Read production or staging environment variables.
- Connect to PostgreSQL.
- Use persistent storage for files that must survive container restarts.

The backend image should install production dependencies and avoid development-only packages at runtime.

### PostgreSQL

PostgreSQL stores application data.

Expected responsibilities:

- Use a persistent Docker volume.
- Use separate database names, users, passwords, and volumes for staging and production.
- Never expose the database publicly unless there is a deliberate and secured operational reason.

Database backup and restore instructions should be added before production is considered ready for real use.

### Redis Future Direction

Redis is not part of the first Docker deployment stack because the current backend does not have active queued jobs and still uses synchronous queue execution.

Redis should be added later when the application needs:

- Laravel queue workers with `QUEUE_CONNECTION=redis`;
- cache storage with `CACHE_DRIVER=redis`;
- realtime infrastructure for websocket/chat features;
- higher-throughput async jobs that should not run inside HTTP requests.

When Redis is added, it should be a separate Docker Compose service and should stay internal to the Docker network by default.

### Scheduler Future Direction

The scheduler is not part of the first Docker deployment stack because the backend does not currently define active scheduled tasks.

When real schedules are added in `app/Console/Kernel.php`, add a separate scheduler service that uses the backend image and runs:

```text
php artisan schedule:work
```

### Websocket Server

The websocket service is planned for the future seller-buyer chat feature.

Expected direction:

```text
Laravel Reverb or another documented Laravel-compatible websocket runtime.
```

The websocket service should run separately from the backend API service because websocket connections are long-running and have different scaling and deployment behavior.

## Future Public Domain Plan

One VM can serve multiple domains or subdomains when the application is ready for public DNS and HTTPS. DNS records can point multiple hostnames to the same VM IP address, and the reverse proxy decides which container receives each request.

This section is a future public-access plan. The first VM deployment can stay private through Tailscale and ports `8080` and `8081`.

Suggested staging domains:

```text
staging-app.example.com
staging-api.example.com
staging-ws.example.com
```

Suggested production domains:

```text
app.example.com
api.example.com
ws.example.com
```

The websocket domain is reserved for the future chat feature. It does not need to be active until the websocket service exists.

Subdomains are preferred over path-based routing because they keep frontend, API, and websocket routing clearer for CORS, auth callbacks, and future realtime configuration.

## Private VM Access With Tailscale

Because the first staging and production VMs may run from a home Proxmox environment, the initial access path can use Tailscale instead of public DNS and router port forwarding.

Tailscale runs on the VM host. It does not need to run inside the frontend, backend, PostgreSQL, or reverse proxy containers.

The Docker stack can keep using HTTP internally while access stays private inside the tailnet.

Example staging URLs:

```text
Frontend: http://staging-vm:8080
Backend:  http://staging-vm:8081
```

Example private production URLs:

```text
Frontend: http://production-vm:8080
Backend:  http://production-vm:8081
```

Use the full MagicDNS hostname or Tailscale `100.x.x.x` IP if the short hostname does not resolve.

The deploy env files should be updated after the VM joins Tailscale:

```env
VITE_APP_BACKEND_BASE_URL=http://staging-vm:8081
APP_URL=http://staging-vm:8081
FRONTEND_URL=http://staging-vm:8080
```

This is a private-access deployment mode. Devices must join the same tailnet before they can open the application. Public HTTPS domains can be added later when the application needs public access.

## Environment File Strategy

Local native `.env` files should not be reused for staging or production.

Staging and production need their own environment files:

```text
deploy/env/staging/backend.env
deploy/env/staging/frontend.env
deploy/env/production/backend.env
deploy/env/production/frontend.env
```

Only examples should be committed:

```text
deploy/env/staging/backend.env.example
deploy/env/staging/frontend.env.example
deploy/env/production/backend.env.example
deploy/env/production/frontend.env.example
```

Real environment files must contain secrets and must stay outside git.

Set a real `APP_KEY` in each `backend.env` before starting the stack. Do not leave `APP_KEY` empty on staging or production.

Important backend values include:

```text
APP_ENV
APP_DEBUG
APP_URL
FRONTEND_URL
DB_CONNECTION
DB_HOST
DB_PORT
DB_DATABASE
DB_USERNAME
DB_PASSWORD
POSTGRES_DB
POSTGRES_USER
POSTGRES_PASSWORD
CACHE_DRIVER
QUEUE_CONNECTION
SESSION_DRIVER
```

Important frontend values include:

```text
VITE_APP_BACKEND_BASE_URL
VITE_SYMLINK_FOLDER
FRONTEND_HTTP_PORT
BACKEND_HTTP_PORT
```

When Clerk, websocket, and production payment settings are added later, their environment variables must be separated between staging and production.

## Docker Compose Strategy

Docker Compose should be split by environment:

```text
compose.staging.yml
compose.production.yml
```

The files can share the same service shape, but they should not share secrets, volume names, database names, or public domains.

Expected staging differences:

- `APP_ENV=staging`
- staging domains
- staging database credentials
- staging Docker volumes
- staging mail/payment credentials or sandbox providers
- debug settings appropriate for staging only

Expected production differences:

- `APP_ENV=production`
- production domains
- production database credentials
- production Docker volumes
- production mail/payment credentials
- `APP_DEBUG=false`
- stricter restart and operational rules

## Deployment Flow

The intended workflow is:

```text
1. Develop locally using the native local setup.
2. Commit changes in the correct repository.
3. Push changes.
4. Build Docker images for staging.
5. Deploy images to the staging VM with Docker Compose.
6. Test staging.
7. Create or select a production release.
8. Deploy production with Docker Compose.
```

Production should deploy from a deliberate release or selected commit. It should not automatically receive every feature that happens to be present in staging.

This avoids the problem where an unfinished feature remains in staging and accidentally ships to production.

## Production Safety Rules

Production deployment must follow these rules:

- Do not commit real secrets.
- Do not reuse staging credentials in production.
- Do not run production with `APP_DEBUG=true`.
- Do not use temporary database volumes for production data.
- Do not expose PostgreSQL publicly by default.
- Do not deploy unfinished staging-only features to production.
- Run migrations deliberately and verify whether the migration is safe for current production data.
- Prepare database backup and restore commands before treating production as real.

Production should pull or use known images/releases instead of relying on undocumented manual edits on the server.

## Future Scaling Path

The first topology can be expanded when there is a real need.

Possible future steps:

- Move PostgreSQL to a dedicated data VM when database resource usage, data safety, or backup needs become more serious.
- Add Redis and queue workers when the application has real queued jobs, cache pressure, or realtime infrastructure needs.
- Move the websocket server to a dedicated VM when realtime connection count grows or websocket deploys need to be isolated from API deploys.
- Serve frontend assets through CDN or static hosting when static traffic grows or frontend releases need a separate delivery path.
- Add multiple backend API instances behind a load balancer when HTTP traffic requires horizontal scaling.

The project should evolve toward these steps gradually. Infrastructure should be split because of bottlenecks, operational risk, or scaling needs, not just because a larger topology looks more advanced.
