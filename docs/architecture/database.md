# Database Architecture

This document explains the current database direction for the ecommerce backend.

The project is moving toward PostgreSQL with UUID primary keys for application-owned tables. The old MySQL export is treated as a reference for reconstructing the schema, not as production data that must be migrated.

The prose in this document is written in English. Some table and column names still use Indonesian words because those names are real database identifiers from the current application.

## Current Direction

The backend now targets PostgreSQL by default.

The practical goal is simple: the database should be easier to recreate from code, safer to evolve through migrations, and clearer to understand when the project grows.

The old database had a few common problems:

- The schema was not fully represented in Laravel migrations.
- Table engines and collations were inconsistent in MySQL.
- Some payment options were stored with enum-style constraints.
- Relationships existed in the data model, but the code did not clearly document them.
- Indexing was not planned from the application query patterns.

The new direction fixes those issues gradually without trying to rewrite the whole application at once.

## Database Connection

The default database connection is PostgreSQL.

Expected local database:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=ecommerce
DB_USERNAME=postgres
DB_PASSWORD=
```

The database name is intentionally `ecommerce`, not a temporary test database, because the old data is not being migrated into the new schema.

## UUID Strategy

Application-owned tables use UUID primary keys.

In this document, application-owned tables mean the ecommerce domain tables such as users, products, carts, payments, transactions, and balances. Laravel infrastructure tables such as `migrations` and `failed_jobs` keep their framework defaults unless there is a separate reason to customize the related package behavior.

This means the main tables use this shape:

```php
$table->uuid('id')->primary();
```

The related ID columns also use UUID columns:

```php
$table->uuid('user_id_buyer')->nullable();
$table->uuid('product_id')->nullable();
$table->uuid('transaction_invoice_id')->nullable();
```

The reason is consistency. If the project uses UUIDs, it should use them across the application tables instead of mixing UUID primary keys with integer foreign key references.

Laravel models use `HasUuids`, so new records can receive UUID values automatically through Eloquent.

## Data Migration Policy

The old MySQL export is only a reference.

No existing customer, product, transaction, or payment data is expected to be copied into the PostgreSQL database at this stage.

This keeps the migration simpler because the project is being rebuilt as a personal learning and maintenance project, not as a live production migration with existing customers.

If old data ever needs to be migrated later, it should be handled as a separate project:

- Export old MySQL data.
- Create a mapping plan from integer IDs to UUIDs.
- Import parent tables first.
- Import child tables using the mapped UUID values.
- Reconcile payments and transaction history carefully.

That work should not be mixed with the initial schema migration.

## Tables

### users

Stores application users. A user can act as a buyer, seller, or both depending on the application flow.

Important columns:

- `id`: UUID primary key.
- `img`: optional profile image.
- `name`: user display name.
- `jenis_kelamin`: optional gender value.
- `tanggal_lahir`: optional birth date.
- `email`: unique email address.
- `phone`: optional phone number.
- `clerk_user_id`: optional unique identity bridge to Clerk.

Indexes:

- Unique index on `email`.

Notes:

- The field `jenis_kelamin` uses a string instead of a database enum. This keeps PostgreSQL migration simpler and makes future values easier to change from application code.
- The old `account_type` column was removed by `2026_06_04_000001_drop_account_type_from_users_table.php` because active buyer/seller UI mode is stored per browser tab by the frontend.
- The old local-auth columns `password`, `remember_token`, `email_verified_at`, and `tfa`, together with the `password_reset_tokens` table, were removed by `2026_07_13_000001_remove_legacy_local_auth_schema.php`. Clerk owns authentication, password reset, email verification, and MFA.

### products

Stores products listed by sellers.

Important columns:

- `id`: UUID primary key.
- `user_id_seller`: UUID reference to the seller user.
- `img`: optional product image.
- `name`: product name.
- `price`: product price.
- `stock`: available stock.

Indexes:

- `user_id_seller` for seller product lists.
- `updated_at` for recently updated product sorting.

The legacy `img` column remains a compatibility cover and mirrors the path of `product_images.position = 1`.

### product_images

Stores the ordered image collection for each product.

Important columns:

- `id`: UUID primary key.
- `product_id`: UUID reference to the owning product.
- `path`: stored image path.
- `position`: display order from 1 to 5; position 1 is the primary cover.

Constraints and indexes:

- Foreign key to `products.id` with cascade delete.
- Index on `product_id`.
- Unique constraint on `product_id, position`.
- PostgreSQL check constraint limiting `position` to values from 1 through 5.

The table-creation migration copies non-empty legacy `products.img` paths into position 1. It copies only database references and does not move physical files.

### keranjangs

Stores cart items.

Important columns:

- `id`: UUID primary key.
- `user_id_seller`: UUID reference to the seller.
- `user_id_buyer`: UUID reference to the buyer.
- `product_id`: UUID reference to the product.
- `checked`: whether the cart item is selected.
- `checkout`: whether the cart item has entered checkout.
- `total`: quantity.

Indexes:

- `user_id_buyer` for loading a buyer cart.
- `product_id` for product-based lookups.
- `user_id_seller, user_id_buyer` for grouping cart items by seller and buyer.
- `user_id_buyer, product_id` for finding whether a buyer already has a product in the cart.
- `user_id_buyer, created_at` for showing the newest cart items first.

### companies

Stores seller company or shop profile information.

Important columns:

- `id`: UUID primary key.
- `user_id`: UUID reference to the owner user.
- `img`: optional company image.
- `name`: company name.
- `email`: company email.
- `phone`: company phone.
- `description`: short company description.

Indexes:

- `user_id` for loading the company profile of a user.
- `email` and `phone` for direct lookup.

### alamats

Stores user addresses.

Important columns:

- `id`: UUID primary key.
- `user_id`: UUID reference to the user.
- `type`: address type, such as buyer or seller.
- `place`: address label.
- `name`: receiver or contact name.
- `phone`: contact phone.
- `alamat`: full address text.
- `enable`: active flag.

Indexes:

- `user_id, type` for loading addresses by user and mode.
- `user_id, type, enable` for loading the active address by user and mode.
- `user_id, type, created_at` for selecting the newest address after an address is deleted.

### payment_lists

Stores available payment options supported by the application.

Important columns:

- `id`: UUID primary key.
- `type`: payment direction, such as `withdrawal` or `incoming`.
- `method`: payment method, such as `debit` or `va`.
- `slug`: provider slug, such as `bca`, `bni`, `bri`, or `mandiri`.
- `name`: human-readable payment name.

Indexes:

- Unique `type, method, slug` to prevent duplicate payment options.
- `type, method` for filtering payment methods by direction.
- `type, slug` for provider-based lookup.

Notes:

- `type`, `method`, and `slug` use strings instead of enums. This is intentional because payment providers can grow over time, especially with Xendit or future payment methods.

### payment_users

Stores user-owned payment accounts, such as bank accounts for withdrawal.

Important columns:

- `id`: UUID primary key.
- `user_id`: UUID reference to the user.
- `payment_id`: UUID reference to `payment_lists`.
- `name`: account holder name.
- `account`: account number or account identifier.

Indexes:

- `user_id` for loading user payment accounts.
- `payment_id` for loading accounts by payment type.
- `user_id, account` for checking a user's registered account.
- `user_id, created_at` for showing the newest withdrawal accounts first.

### transaction_invoices

Stores checkout invoice-level data. One invoice can contain transactions from one or more sellers.

Important columns:

- `id`: UUID primary key.
- `user_id_buyer`: UUID reference to the buyer.
- `alamat_buyer`: buyer address snapshot at checkout time.
- `payment_method`: selected payment method.
- `payment_slug`: selected payment provider slug.
- `payment_name`: selected payment display name.
- `payment_account`: payment account or virtual account number.
- `payment_reference`: external payment reference, such as from Xendit.
- `price`: total invoice price.
- `status`: invoice status.
- `expired_at`: payment expiration time.

Indexes:

- `user_id_buyer` for loading buyer invoices.
- `status` for invoice status filters.
- `expired_at` for expiration checks.
- `payment_method, payment_slug, payment_account, status` for payment callback or payment lookup flows.

### transaction_users

Stores seller-level transaction data under an invoice.

Important columns:

- `id`: UUID primary key.
- `user_id_seller`: UUID reference to the seller.
- `user_id_buyer`: UUID reference to the buyer.
- `transaction_invoice_id`: UUID reference to `transaction_invoices`.
- `transaction_number`: visible transaction number.
- `alamat_seller`: seller address snapshot.
- `kurir_type`: shipping courier type.
- `kurir_price`: shipping cost.
- `product_price`: product subtotal.
- `kurir_estimate`: shipping estimate.
- `noted`: buyer or seller note.
- `status`: seller-level transaction status.

Indexes:

- `user_id_seller` for seller order management.
- `user_id_buyer` for buyer order history.
- `transaction_invoice_id` for invoice detail loading.
- `transaction_number` for direct transaction lookup.
- `status` for order status filters.
- `created_at` for chronological order lists.

### transaction_products

Stores product-level transaction lines.

Important columns:

- `id`: UUID primary key.
- `user_id_seller`: UUID reference to the seller.
- `user_id_buyer`: UUID reference to the buyer.
- `product_id`: UUID reference to the product.
- `transaction_user_id`: UUID reference to `transaction_users`.
- `price`: product price snapshot.
- `total`: quantity.

Indexes:

- `user_id_seller` for seller reports.
- `user_id_buyer` for buyer history.
- `product_id` for product history.
- `transaction_user_id` for transaction detail loading.

### saldo_users

Stores the current user balance.

Important columns:

- `id`: UUID primary key.
- `user_id`: UUID reference to the user.
- `saldo_income`: available seller income balance.
- `saldo_refund`: available refund balance.

Indexes:

- Unique `user_id`, because each user should have one balance row.

### saldo_histories

Stores balance movement history.

Important columns:

- `id`: UUID primary key.
- `user_id`: UUID reference to the user.
- `transaction_user_id`: optional UUID reference to a seller transaction.
- `payment_user_id`: optional UUID reference to a withdrawal account.
- `type`: balance movement type.
- `price`: movement amount.
- `saldo_before`: balance before the movement.
- `saldo_after`: balance after the movement.

Indexes:

- `user_id` for user balance history.
- `transaction_user_id` for transaction-related balance history.
- `payment_user_id` for withdrawal-related balance history.
- `created_at` for chronological reports.
- `user_id, created_at` for user-specific chronological balance history.

## Logical Relationships

The application currently uses UUID columns to represent relationships.

Main relationship direction:

```text
users
  -> products
  -> companies
  -> alamats
  -> payment_users
  -> saldo_users
  -> saldo_histories

users as buyer
  -> keranjangs
  -> transaction_invoices
  -> transaction_users
  -> transaction_products

users as seller
  -> products
  -> keranjangs
  -> transaction_users
  -> transaction_products

transaction_invoices
  -> transaction_users
  -> transaction_products

payment_lists
  -> payment_users
```

The migrations do not add database-level foreign key constraints yet. This is intentional for the first PostgreSQL migration step because the old codebase still contains manual query patterns and needs to be stabilized gradually.

Foreign key constraints can be added later after the main checkout, payment, cart, and balance flows are tested.

## Required Seed Data

The `payment_lists` table has required seed data.

Without this seed data, checkout and withdrawal-related flows may not have available payment options.

Current required payment options:

| Type | Method | Slug | Name |
| --- | --- | --- | --- |
| withdrawal | debit | bca | PT. BCA (BANK CENTRAL ASIA) TBK |
| withdrawal | debit | bni | PT. BANK NEGARA INDONESIA (BNI) (PERSERO) |
| withdrawal | debit | bri | PT. BANK RAKYAT INDONESIA (BRI) (PERSERO) |
| withdrawal | debit | mandiri | PT. BANK MANDIRI |
| incoming | va | bca | BCA Virtual Account |
| incoming | va | bri | BRI Virtual Account |
| incoming | va | bni | BNI Virtual Account |
| incoming | va | mandiri | Mandiri Virtual Account |

The seeder uses stable lookup fields:

```text
type + method + slug
```

That means the seeder can be run more than once without creating duplicate payment options.

## Indexing Strategy

Indexes were added only for query patterns that are likely to be used by the application.

The current indexing strategy focuses on:

- User-owned data lookups.
- Buyer and seller transaction lists.
- Cart lookups.
- Payment option lookups.
- Payment callback or invoice lookup flows.
- Balance history reports.
- Chronological order lists.

The indexes are intentionally practical, not exhaustive. More indexes should be added only after a query becomes important or slow.

## PostgreSQL Notes

PostgreSQL does not use MySQL table engines such as InnoDB.

PostgreSQL also does not use MySQL collations such as `utf8mb4_general_ci`. The database should be created with UTF-8 encoding. Existing text searches use `ILIKE` so user-facing search remains case-insensitive after moving from MySQL to PostgreSQL.

The old MySQL settings are still useful for understanding the previous database, but PostgreSQL is now the default target.

## Safe Migration Checklist

Before running the real migration against the `ecommerce` database:

1. Make sure PostgreSQL is running locally.
2. Make sure the `ecommerce` database exists.
3. Confirm `.env` uses the PostgreSQL connection.
4. Run migrations on an empty database first.
5. Run required seeders.
6. Test auth, product listing, cart, checkout, payment, order, and balance flows.

Recommended commands:

```bash
php artisan migrate:fresh
php artisan db:seed
```

Only use `migrate:fresh` when the database can be safely dropped and recreated.

## Future Improvements

These improvements should be handled separately after the PostgreSQL migration is stable:

- Add database-level foreign key constraints.
- Convert important manual joins to Eloquent relationships step by step.
- Review money columns and consider using integer cents instead of floating-point numbers.
- Add dedicated indexes for case-insensitive search if those queries become slow with larger data.
- Add feature-level documentation for checkout and Xendit payment flows.
- Add tests for migration-critical flows.
