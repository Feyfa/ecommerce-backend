# Backend Documentation

This folder contains the technical documentation for the Laravel backend.

The frontend and backend are stored in separate Git repositories, so backend-owned decisions should live here. Anything related to the database schema, API behavior, Laravel configuration, seeders, queues, payments, and backend runtime behavior should be documented in this repository.

Frontend-specific documentation should live in the frontend repository.

Deployment, branching, staging, production, Docker Compose, and server runbooks are owned by the deploy repository docs:

```text
../deploy/docs/
```

## Repository Path Aliases

Cross-repository documentation uses logical aliases so references do not depend
on each developer's local workspace layout:

- `frontend-repo:/` means the root of the frontend repository.
- `backend-repo:/` means the root of the backend repository.
- `deploy-repo:/` means the root of the deploy repository.

Use normal relative Markdown links for files inside this repository. Use an
alias path as inline code for a file owned by another repository.

## Current Documents

- [Local Native Development](setup/local-native-development.md)
  Explains how to run the frontend and backend locally without Docker by using local HTTPS domains and native app runtimes.

- [Redis](architecture/redis.md)
  Documents the Redis runtime role, local configuration, and deployment boundary.

- [Laravel Queue](architecture/queue.md)
  Documents buyer-search worker operation, retries, failed jobs, and recovery.

- [Transactional Outbox](architecture/outbox.md)
  Documents durable PostgreSQL-to-Redis delivery, message lifecycle, locking, retry, retention, and recovery.

- [Laravel Scheduler](architecture/scheduler.md)
  Documents recurring commands, local operation, and the dedicated Docker scheduler process.

- [Meilisearch](architecture/meilisearch.md)
  Documents Laravel-owned buyer search settings, index rebuilding, and availability behavior.

- [Clerk Authentication](application/auth/clerk-auth.md)
  Documents the backend Clerk migration direction, local user bridge, request verification flow, dashboard setup decisions, and post-migration cleanup rules.

- [Database Architecture](architecture/database.md)
  Explains the current PostgreSQL database design, UUID strategy, table responsibilities, relationships, indexes, and required seed data.

- [ADR 0001: PostgreSQL and UUID Strategy](adr/0001-database-postgresql-uuid.md)
  Records the decision to move the backend database direction from MySQL-style local reconstruction to PostgreSQL with full UUID primary keys for application tables.

- [Historical Sanctum Authentication](history/sanctum-auth.md)
  Records the retired Sanctum authentication flow. The active authentication direction is documented in `application/auth/clerk-auth.md`.

- [Seller Product](application/seller/product.md)
  Documents the seller product API routes, validation, request behavior, and data side effects.

- [Seller Dashboard](application/seller/dashboard.md)
  Documents the seller dashboard API route, response shape, metric rules, and data decisions.

- [Buyer Belanja](application/buyer/belanja.md)
  Documents the buyer shopping API routes, search behavior, add-to-cart behavior, and data notes.

- [Buyer Cart](application/buyer/cart.md)
  Documents the buyer cart API routes, checked-state behavior, quantity validation, checkout validation, and stale-state recovery.

- [Buyer Checkout](application/buyer/checkout.md)
  Documents the buyer checkout API routes, backend snapshot validation, payment processing, idempotency, and checkout data side effects.

- [Transaction](application/transaction.md)
  Documents the shared buyer and seller transaction API, filters, status mapping, pagination, seller approval, and display-name rules.

- [Settings](application/settings/README.md)
  Documents the settings API routes for user profile, company profile, address, bank account, balance, image upload/delete, and security behavior.

- [Xendit Integration](application/integrations/xendit.md)
  Documents the current Xendit payment, disbursement, webhook gap, and future integration notes.

- [TOK-6 Product Images QA](qa/tok-6-product-images.md)
  Tracks automated backend verification for product image validation, ordering, migration, storage, and ownership.

- [TOK-8 Pinpoint Address QA](qa/tok-8-pinpoint-address.md)
  Tracks backend verification for address validation, provider handling, authorization, cart availability, and checkout snapshots.

- [TOK-16 Product Audit Log QA](qa/tok-16-product-audit-log.md)
  Tracks automated backend verification for product audit persistence, rollback, ownership, filtering, and regressions.

- [TOK-17 Product List Filtering QA](qa/tok-17-product-list-filtering.md)
  Tracks automated backend verification for buyer and seller product-list filtering.

- [TOK-21 Address Audit Log QA](qa/tok-21-address-audit-log.md)
  Tracks automated backend verification for buyer address audit persistence, personal-data masking, rollback, and ownership.

- [TOK-22 Profile Audit Log QA](qa/tok-22-profile-audit-log.md)
  Tracks profile change, image, masking, ownership, and rollback audit verification.

- [TOK-23 Company Audit Log QA](qa/tok-23-company-audit-log.md)
  Tracks company profile, location, image, masking, ownership, and rollback audit verification.

- [TOK-24 Checkout Transaction Identity QA](qa/tok-24-checkout-transaction-redirect.md)
  Tracks the checkout invoice identifier returned for frontend transaction highlighting.

- [TOK-25 Pending Invoice Grouping QA](qa/tok-25-pending-invoice-grouping.md)
  Tracks pending buyer invoice grouping across one or more seller transactions.

- [TOK-29 Buyer Catalog Search QA](qa/tok-29-buyer-catalog-search.md)
  Tracks Meilisearch contracts, Redis synchronization, testing isolation, recovery, and pending end-to-end buyer-catalog verification.

- [TOK-30 Buyer Catalog Filters QA](qa/tok-30-buyer-catalog-filters.md)
  Preserves historical backend evidence for the original price and recently-added buyer filters.

- [TOK-32 Product Pagination QA](qa/tok-32-product-pagination.md)
  Tracks seller lookahead metadata and the 50-product Buyer Belanja default.

- [Commit Guidelines](development/commit-guidelines.md)
  Explains how to keep commits focused on one purpose and separate unrelated changes.

## Documentation Rules

Use English for every Markdown document in this folder.

Existing table names, column names, route names, class names, and other code identifiers should keep their real names even when they use Indonesian words.

Write documents for humans first:

- Start with the purpose of the document.
- Explain the practical reason behind each decision.
- Prefer clear sections over long paragraphs.
- Include commands only when they are useful and safe to repeat.
- Keep historical context when it helps future maintenance.
- Update the relevant document whenever a feature changes the database, payment flow, authentication flow, or deployment process.

## Documentation Structure

```text
docs/
  README.md

  architecture/
    database.md
    redis.md
    queue.md
    outbox.md
    scheduler.md
    meilisearch.md

  application/
    auth/
      clerk-auth.md
    buyer/
      belanja.md
      cart.md
      checkout.md
    seller/
      product.md
      dashboard.md
    settings/
      README.md
      profile.md
      company-profile.md
      address.md
      balance.md
      bank-account.md
      security.md
      audit-log.md
    integrations/
      xendit.md
    transaction.md

  adr/
    0001-database-postgresql-uuid.md

  qa/
    tok-6-product-images.md
    tok-8-pinpoint-address.md
    tok-16-product-audit-log.md
    tok-17-product-list-filtering.md
    tok-21-address-audit-log.md
    tok-22-profile-audit-log.md
    tok-23-company-audit-log.md
    tok-24-checkout-transaction-redirect.md
    tok-25-pending-invoice-grouping.md
    tok-29-buyer-catalog-search.md
    tok-30-buyer-catalog-filters.md
    tok-32-product-pagination.md

  development/
    commit-guidelines.md

  history/
    sanctum-auth.md

  setup/
    local-native-development.md
```

Keep documentation directly related to Laravel implementation inside
`application/`. Keep task-specific backend verification, status, and evidence
inside `qa/`. Keep architecture, ADR, historical, setup, and
development-process documentation in their dedicated top-level folders.
