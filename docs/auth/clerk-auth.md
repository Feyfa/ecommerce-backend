# Clerk Authentication

This document explains the backend authentication direction after the project decided to move its main identity layer from Laravel Sanctum to Clerk.

The goal is to keep one practical reference for Clerk verification, local `users` table behavior, request bootstrap flow, sync rules, dashboard setup decisions, and cleanup rules after Sanctum auth routes were removed.

## Purpose

The backend Clerk migration exists to separate identity concerns from business concerns.

After the migration:

- Clerk is responsible for user identity authentication.
- Laravel remains responsible for business APIs, local domain data, and request authorization based on the local application user.

This separation is important because the project already has business domains such as:

- buyer and seller mode;
- company;
- saldo;
- payment account;
- transaction;
- future chat and websocket features.

## Current Phase

The current backend phase is the post-implementation cleanup phase.

What already changed:

- backend Clerk configuration exists;
- Clerk middleware and request verification flow exist;
- `GET /api/auth/me` exists to bootstrap the frontend after Clerk authentication;
- local users can be resolved and linked through `clerk_user_id`.
- legacy Sanctum login, register, logout, token validation, and personal access token runtime paths have been removed.

## Main Decisions

The backend migration follows these main decisions:

- Clerk is the main identity provider.
- Laravel remains the main business API.
- The local `users` table remains part of the application architecture.
- The local user model becomes an application user representation, not the main password-auth source.
- The main auth verification path shifts from Sanctum to Clerk token verification.
- Protected requests must verify identity before any business logic runs.
- Local users are created or upserted through a hybrid strategy, with `GET /api/auth/me` as the main bootstrap path.
- Sync means preserving identity linkage and relevant fields, not mirroring all Clerk fields into the database.
- The main identity bridge is `clerk_user_id`, not email.
- Webhooks are a supplement for identity-change sync, not the primary user-creation mechanism.

## Main Files

The current backend Clerk-related files are:

- `config/clerk.php`
  Clerk runtime configuration and allowed request settings.

- `app/Http/Middleware/AuthenticateApiRequest.php`
  Middleware that verifies request authentication and resolves the local app user.

- `app/Http/Controllers/AuthSessionController.php`
  Backend bootstrap endpoint for frontend session restoration.

- `app/Services/Clerk/ClerkBackendClientService.php`
  Shared Clerk backend request verification helper.

- `app/Services/Clerk/ClerkUserSyncService.php`
  Local user resolution and sync helper.

- `database/migrations/2026_06_20_000001_add_clerk_user_id_to_users_table.php`
  Schema bridge between local `users` and Clerk identity.

- `routes/api.php`
  API routes that now include the Clerk-aware auth bootstrap and protected route handling.

## Main Architecture

The target backend architecture is:

```text
Clerk = identity authentication source
Laravel = application business API
users table = local application user representation
clerk_user_id = stable identity bridge
```

This means Laravel should not own the primary login identity anymore.

Laravel should instead:

- accept the authenticated Clerk-backed request;
- verify that request server-side;
- map the identity to the local user row;
- continue the normal business flow through `auth()->user()`.

## Local Users Table

The `users` table remains required because the application still needs local rows for:

- model relations;
- company ownership;
- saldo and payment data;
- transaction ownership;
- buyer and seller business behavior.

The local user table is not being kept as a duplicate auth system.

It is being kept as the application-owned representation of the user.

## Local User Model

The agreed target user fields are:

```text
id
clerk_user_id
name
email
phone nullable
img nullable
jenis_kelamin nullable
tanggal_lahir nullable
created_at
updated_at
```

The old auth-centered fields no longer own authentication behavior:

```text
password
remember_token
email_verified_at
tfa
```

If the project removes those columns later, it must happen through new migrations, not by silently editing old migrations.

## Identity Bridge Rules

The main identity bridge is:

```text
users.clerk_user_id
```

Email is still useful for display, lookup fallback, and some migration-safe attach rules, but email is not the long-term primary bridge.

Why email is not the main bridge:

- email can change;
- a user may add more than one login method;
- identity mapping should rely on a stable provider identifier.

## Google and Manual Identity Rules

Google login and manual email/password login that use the same verified email should resolve to one user identity.

The backend should not intentionally create two separate local users for the same real identity just because the login method changed.

During the migration phase, email may still be used once as a safe attach path when:

- a local user exists;
- `clerk_user_id` is still empty;
- the verified Clerk email matches that local user.

After the linkage is established, `clerk_user_id` becomes the primary bridge.

## Request Authentication Flow

The intended backend request flow is:

1. The frontend gets a Clerk session token from the browser session.
2. The frontend sends that token as a bearer token to the backend.
3. The backend verifies the request against Clerk and only accepts Clerk `session_token` requests for this user API boundary.
4. The backend resolves the Clerk user identity.
5. Normal business API middleware finds the local `users` row by `clerk_user_id` without fetching Clerk user data again.
6. The backend binds that local user into the normal Laravel request auth flow.
7. Business logic continues using the resolved local user.

This keeps `auth()->user()` useful for the rest of the application while moving primary identity auth away from Sanctum.

## Why `GET /api/auth/me` Exists

The purpose of `GET /api/auth/me` is to let the frontend bootstrap a business-ready application session after Clerk authentication succeeds.

This endpoint is typically called:

- after Google login succeeds;
- after manual login succeeds;
- after manual register and email verification succeed;
- during route bootstrap when the frontend wants to restore the authenticated application session.

This endpoint is not only a token check.

It is the backend bridge that returns:

- resolved local user;
- resolved company snapshot if relevant;

It is also the main place where the backend fetches Clerk user data and synchronizes local identity fields.

Normal business endpoints should not fetch and sync Clerk profile data on every request.

## Local User Create and Upsert Strategy

The project chose a hybrid strategy:

- `GET /api/auth/me` is the main create or upsert trigger;
- Clerk webhook is a later supplement for identity updates.

This design is intentional because it keeps the first migration phase simpler and more resilient.

The auth bootstrap request should be able to recover even if:

- a webhook did not arrive yet;
- a webhook failed;
- local development data was refreshed;
- a local user row was removed but the Clerk identity is still valid.

## Auto Recreate and Safe Recovery

If the Clerk identity is valid but the local user row is missing, the backend should safely recreate or upsert the user instead of failing immediately.

This matters most in local development, but the rule is still healthy in general because it reduces unnecessary manual repair.

This does not mean blindly creating all downstream business data.

It only means recovering the local user identity bridge safely.

## Minimum Fields Copied From Clerk

During the first create or upsert flow, the backend only needs minimum identity data.

Required fields:

- `clerk_user_id`
- `email`
- `name`

The current local `users` schema requires an email value, so Clerk identities must expose a primary email address before the backend can create or update the local user row.

Optional field:

- `img`

Fields such as `phone`, `jenis_kelamin`, and `tanggal_lahir` should remain application-owned unless the product later decides otherwise.

## Ongoing Sync Rules

Sync from Clerk to the local user table should stay minimal.

The agreed sync focus is:

- `email`
- `name`

Optional if useful:

- `img`

The backend should not treat all Clerk profile fields as mandatory sync targets.

This avoids over-coupling the local application user model to Clerk profile behavior.

## Webhook Usage

Webhook is still useful, but its role is limited and specific.

Webhook is not the primary create-user mechanism for phase one.

Webhook is mainly useful for:

- email change sync;
- name change sync;
- selected identity field updates that the application also stores locally.

Webhook is not necessary for:

- first local user creation;
- simple authenticated bootstrap after login;
- basic local recovery after development resets.

## Failure Handling

The backend should fail safely when Clerk verification is unavailable or uncertain.

The rule is:

- protected requests may only continue when identity is still verifiable;
- protected requests must be rejected when the backend can no longer trust the auth state.

Practical interpretation:

- if the request is not authenticated yet, backend rejects the protected flow;
- if the request comes with a token that is still valid and verifiable, it may continue;
- if verification fails and identity cannot be trusted, the backend returns an auth failure and the frontend should end the session.

## Logout Responsibility

In the new architecture, backend is no longer the main owner of logout.

The main logout responsibility shifts to:

- Clerk;
- frontend session cleanup.

Backend should not expose a duplicate local logout endpoint for the current auth flow.

## Clerk Dashboard Setup

The Clerk dashboard setup decisions for the backend-facing architecture are:

### Sign-In Methods

Phase-one enabled methods:

- Google login
- manual email and password login

Both methods are intentionally enabled from the beginning so identity-linking behavior can be tested immediately.

### Email Verification

Manual email/password registration requires email verification before the account is treated as fully active.

This should stay mandatory in Clerk dashboard configuration.

### Google Provider

Google login is enabled in development.

Development may use Clerk shared credentials where allowed, but production should be prepared to use project-owned Google credentials.

### Domains and Paths

The Clerk setup must know where the frontend application lives and where auth pages live.

Current frontend and backend local domains are:

```text
Frontend: https://app.ecommerce.dev
Backend:  https://api.ecommerce.dev
```

The project should maintain correct Clerk domain and path settings for:

- sign in
- sign up
- sign out redirect
- callback or post-auth redirect flow

### Environments

Clerk configuration should stay separated per environment:

- local
- staging
- production

This avoids mixing keys, domains, redirects, and callback behavior.

### MFA

MFA is not part of the phase-one requirement.

The project previously agreed that local legacy `tfa` should not continue as the main design.

If MFA is enabled later, it should be managed through Clerk rather than rebuilding the old local TFA model.

### User Self-Delete

For the current phase, allowing end users to delete their own account directly from Clerk UI should remain disabled unless the application has a complete business-side deletion policy.

### Webhooks

Webhook setup may be delayed until the basic auth flow is stable, but the need should stay documented from the beginning.

## Required Backend Environment Variables

The current backend Clerk configuration expects:

```text
CLERK_SECRET_KEY=
```

Supporting application URLs still matter:

```text
APP_URL=https://api.ecommerce.dev
FRONTEND_URL=https://app.ecommerce.dev
```

Notes:

- `FRONTEND_URL` is used directly as the backend Clerk authorized party source and CORS allowed origin source.
- The backend no longer requires a separate `CLERK_AUTHORIZED_PARTIES` env because that value would only duplicate `FRONTEND_URL` in the current architecture.
- The current backend runtime only requires `CLERK_SECRET_KEY` for Clerk request authentication in this project.

## Cleanup Notes

The backend should not reintroduce these removed auth paths:

- Sanctum-first request authentication;
- local login, register, logout, or token validation endpoints;
- personal access token runtime usage;
- local password-change endpoints for identity-provider accounts;
- local TFA controls that duplicate identity-provider MFA.

The important current boundary is:

```text
Clerk owns identity authentication.
Laravel owns business APIs and local user resolution.
The users table remains local because business relations remain local.
clerk_user_id is the stable bridge between both systems.
```
