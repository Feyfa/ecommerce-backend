# Authentication Notes

This document is historical.

The active backend authentication direction is documented in:

```text
docs/auth/clerk-auth.md
```

## Current Decision

The backend no longer uses Laravel Sanctum as the active API authentication layer.

Current rules:

- Clerk owns identity authentication.
- Laravel verifies protected API requests through the `auth.api` middleware.
- `GET /api/auth/me` bootstraps the frontend application session after identity authentication succeeds.
- Local login, register, logout, token validation, password-change, and local TFA endpoints are not part of the active auth contract.

## Historical Context

Before the Clerk migration, the backend used a Sanctum-centered flow:

- email and password were submitted to local auth endpoints;
- the backend issued a Sanctum token;
- the frontend stored that token in browser storage;
- protected requests used that token as a bearer token.

That flow was removed during the Clerk cleanup.

## TFA Context

The old local `tfa` column may still exist in the historical `users` schema, but it no longer controls the active login flow.

MFA/TOTP is managed through Clerk instead of rebuilding the old local TFA model.
