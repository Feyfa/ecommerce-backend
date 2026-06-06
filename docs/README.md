# Backend Documentation

This folder contains the technical documentation for the Laravel backend.

The frontend and backend are stored in separate Git repositories, so backend-owned decisions should live here. Anything related to the database schema, API behavior, Laravel configuration, seeders, queues, payments, and backend deployment should be documented in this repository.

Frontend-specific documentation should live in the frontend repository.

## Current Documents

- [Local Native Development](setup/local-native-development.md)
  Explains how to run the frontend and backend locally without Docker by using local HTTPS domains and native app runtimes.

- [Database Architecture](architecture/database.md)
  Explains the current PostgreSQL database design, UUID strategy, table responsibilities, relationships, indexes, and required seed data.

- [ADR 0001: PostgreSQL and UUID Strategy](adr/0001-database-postgresql-uuid.md)
  Records the decision to move the backend database direction from MySQL-style local reconstruction to PostgreSQL with full UUID primary keys for application tables.

- [Authentication Notes](features/auth.md)
  Documents the current login flow, the removed legacy TFA provider, and the future direction toward Clerk.

- [Seller Product](features/seller/01-product.md)
  Documents the seller product API routes, validation, request behavior, and data side effects.

- [Seller Dashboard](features/seller/02-dashboard.md)
  Documents the seller dashboard API route, response shape, metric rules, and data decisions.

- [Buyer Belanja](features/buyer/01-belanja.md)
  Documents the buyer shopping API routes, search behavior, add-to-cart behavior, and data notes.

- [Buyer Cart](features/buyer/02-cart.md)
  Documents the buyer cart API routes, checked-state behavior, quantity validation, checkout validation, and stale-state recovery.

- [Buyer Checkout](features/buyer/03-checkout.md)
  Documents the buyer checkout API routes, backend snapshot validation, payment processing, idempotency, and checkout data side effects.

- [Transaction](features/transaction.md)
  Documents the shared buyer and seller transaction API, filters, status mapping, pagination, seller approval, and display-name rules.

- [Account](account/README.md)
  Documents the account API routes for user profile, company profile, address, bank account, balance, image upload/delete, password, and TFA behavior.

- [Xendit Integration](integrations/xendit.md)
  Documents the current Xendit payment, disbursement, webhook gap, and future integration notes.

- [Docker Staging And Production Deployment](deployment/docker-staging-production.md)
  Documents the decision to keep local development native while using Docker for staging and production deployment.

- [Branching Strategy](deployment/branching-strategy.md)
  Documents the shared frontend and backend branch roles, staging integration flow, production flow, and conflict rules.

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

## Suggested Future Structure

The documentation can grow using this structure:

```text
docs/
  README.md

  setup/
    local-native-development.md
    database.md

  deployment/
    branching-strategy.md
    docker-staging-production.md
    staging.md
    production.md
    ci-cd.md

  architecture/
    overview.md
    backend.md
    database.md
    payment-xendit.md

  integrations/
    xendit.md

  account/
    README.md
    profile.md
    company-profile.md
    address.md
    balance.md
    bank-account.md
    security.md

  features/
    _template.md
    auth.md
    transaction.md
    seller/
      01-product.md
      03-company-profile.md
    buyer/
      01-belanja.md
      02-cart.md
      03-checkout.md
      04-order.md

  adr/
    0001-database-postgresql-uuid.md
```

This structure does not need to be created all at once. Add new documents only when the project actually needs them.
