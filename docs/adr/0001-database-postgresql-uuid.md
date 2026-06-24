# ADR 0001: PostgreSQL and UUID Strategy

## Status

Accepted.

## Context

The project started with a MySQL database that was managed mostly by manual SQL work. The last known database export was useful as a reference, but it should not become the long-term source of truth.

The backend needs a schema that can be recreated from code. Laravel migrations are the right place for that because they make the database structure visible, repeatable, and easier to review.

During the migration planning, three decisions became important:

- Whether to keep auto-increment integer IDs or move to UUIDs.
- Whether to keep MySQL or move to PostgreSQL.
- Whether to migrate old data or only use the old export as schema reference.

## Decision

The backend will use PostgreSQL as the default database target.

Application-owned tables will use UUID primary keys.

Laravel infrastructure tables, such as `migrations` and `failed_jobs`, will keep their framework defaults for now. Changing those primary keys can affect Laravel package behavior and should be handled only if there is a clear need.

The old MySQL export will be used only as a reference for table structure and expected fields. Old rows will not be migrated into the new PostgreSQL database during this phase.

## Reasoning

### PostgreSQL

PostgreSQL is a strong default for a growing Laravel project because it has reliable relational features, good indexing support, and predictable behavior for production-style applications.

Moving to PostgreSQL now is acceptable because the old data does not need to be preserved.

### UUIDs

UUIDs avoid depending on sequential integer IDs as public or internal identifiers.

For this project, the main benefit is consistency. If UUIDs are introduced, the application tables should use UUIDs consistently instead of mixing UUID primary keys with integer relationship columns.

### No Old Data Migration

The old export is useful, but the project is not currently preserving a live production database.

Skipping data migration keeps the first migration focused on schema quality. If data migration is needed later, it should be planned as a separate task with explicit ID mapping from old integer IDs to new UUIDs.

## Consequences

### Positive

- The database can be rebuilt from Laravel migrations.
- New records use UUID primary keys consistently.
- PostgreSQL becomes the default direction for future development.
- Payment methods are easier to extend because `payment_lists` uses strings instead of enum columns.
- Indexes are documented and tied to expected application queries.

### Tradeoffs

- Existing manual queries must be reviewed for PostgreSQL compatibility.
- Any code that assumes numeric IDs must be changed to accept UUIDs.
- Old MySQL data cannot be imported directly without an ID mapping plan.
- Database-level foreign keys are not added yet, so application testing is still important.

## Implementation Notes

The first implementation step includes:

- Changing the default Laravel database connection to PostgreSQL.
- Updating `.env.example` for PostgreSQL defaults.
- Creating migrations for previously manual tables.
- Changing application table primary keys to UUID.
- Updating related ID columns to UUID.
- Adding `HasUuids` to application models.
- Adding practical indexes for common lookup paths.
- Adding required `payment_lists` seed data.
- Updating request validation where IDs are expected to be UUID values.

## Future Decisions

The following decisions are intentionally not included in this ADR:

- Whether to add strict database-level foreign keys.
- Whether money columns should be changed from floating-point values to integer cents.
- Whether search should use case-insensitive PostgreSQL indexes.
- Whether manual join queries should be fully replaced by Eloquent relationships.
- Whether Clerk should replace the current authentication flow.

Each of those topics should have its own implementation task and documentation update.
