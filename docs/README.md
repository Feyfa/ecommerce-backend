# Backend Documentation

This folder contains the technical documentation for the Laravel backend.

The frontend and backend are stored in separate Git repositories, so backend-owned decisions should live here. Anything related to the database schema, API behavior, Laravel configuration, seeders, queues, payments, and backend deployment should be documented in this repository.

Frontend-specific documentation should live in the frontend repository.

## Current Documents

- [Database Architecture](architecture/database.md)
  Explains the current PostgreSQL database design, UUID strategy, table responsibilities, relationships, indexes, and required seed data.

- [ADR 0001: PostgreSQL and UUID Strategy](adr/0001-database-postgresql-uuid.md)
  Records the decision to move the backend database direction from MySQL-style local reconstruction to PostgreSQL with full UUID primary keys for application tables.

- [Authentication Notes](features/auth.md)
  Documents the current login flow, the pending TFA/Messend issue, and the future direction toward Clerk.

- [Seller Product](features/seller/01-product.md)
  Documents the seller product API routes, validation, request behavior, and data side effects.

- [Buyer Belanja](features/buyer/01-belanja.md)
  Documents the buyer shopping API routes, search behavior, add-to-cart behavior, and data notes.

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
    local.md
    docker.md
    database.md

  deployment/
    staging.md
    production.md
    ci-cd.md

  architecture/
    overview.md
    backend.md
    database.md
    payment-xendit.md

  features/
    _template.md
    auth.md
    seller/
      01-product.md
      02-transaction.md
      03-company-profile.md
    buyer/
      01-belanja.md
      02-cart.md
      03-checkout.md
      04-order.md
    shared/
      01-payment-xendit.md

  adr/
    0001-database-postgresql-uuid.md
```

This structure does not need to be created all at once. Add new documents only when the project actually needs them.
