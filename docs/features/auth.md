# Authentication Notes

This document explains the current authentication state of the backend and records the pending work around TFA and the future Clerk migration.

The goal is not to redesign authentication immediately. The goal is to make the current behavior visible so future work can be planned without assuming that backend TFA is already implemented.

## Current Login Flow

The backend currently uses Laravel Sanctum for API authentication.

The normal login flow is:

1. The frontend sends email and password to `POST /api/login`.
2. The backend validates the credentials.
3. The backend creates a Sanctum token.
4. The frontend stores the token, user data, and company data in local storage.
5. Authenticated requests use the Sanctum bearer token.

This flow currently works even when the user's `tfa` value is not `F`, because backend TFA enforcement is not implemented yet.

## TFA State

The user table has a `tfa` column.

Current known values:

- `F`: TFA is disabled.
- `Email`: reserved for email OTP behavior.
- `Phone`: reserved for phone OTP behavior.

The frontend can still expose TFA-related UI state, but the backend currently does not enforce OTP verification during login.

The previous Messend-based OTP implementation has been removed because it was not active in the current login flow and depended on a legacy external service.

## Removed Legacy Messend Flow

The old backend TFA flow delegated OTP generation, email sending, and OTP verification to the personal Messend package.

That flow is no longer part of the backend codebase.

Removed pieces:

- `jedun/messend` Composer dependency.
- Messend config and environment variables.
- Messend controller wiring.
- Messend injection and OTP branches in `AuthController`.

## Current Backend Behavior

The backend currently ignores the `tfa` value during login.

This means:

- A user can still have `tfa = Email` in the database.
- A user can still have other reserved `tfa` values.
- The login endpoint will not send an OTP.
- The login endpoint will not verify an OTP.
- The login endpoint will return a Sanctum token after valid email and password credentials.

This keeps the normal login flow working while database, product, cart, checkout, and payment flows are tested.

This is temporary behavior, not the final authentication design.

## Why This Is Pending

Fixing TFA locally is possible, but it would create a new authentication-related flow.

A local implementation would need to handle:

- OTP generation.
- OTP hashing.
- OTP expiration.
- OTP resend behavior.
- Email delivery.
- Error handling when email fails.
- Abuse prevention, such as rate limiting.
- Tests for successful, invalid, and expired OTP attempts.

That work is useful only if the project keeps the current custom login system.

The planned future direction is to use Clerk. If Clerk replaces the current login flow, building a new local TFA system now may become throwaway work.

For that reason, TFA repair is intentionally pending.

## Future Direction

The preferred future direction is:

1. Finish the PostgreSQL and UUID migration validation first.
2. Keep backend TFA enforcement disabled during local testing.
3. Migrate authentication to Clerk in a dedicated task.
4. Revisit email OTP only if the application still needs a custom OTP flow after Clerk integration.

## Decision For Now

Do not build a new local OTP system yet.

The current practical decision is:

```text
TFA is temporarily disabled for local development and testing.
The old Messend-based TFA flow has been removed.
The future authentication direction is Clerk.
```

## If Local OTP Is Needed Later

If the project decides not to move to Clerk, or if a custom OTP flow is still needed after Clerk, the recommended implementation is:

- Create `app/Services/LoginOtpService.php`.
- Create `app/Mail/LoginOtpMail.php`.
- Store OTP state in cache with a short TTL.
- Store only hashed OTP values.
- Send email with Laravel Mail.
- Queue email by default.
- Allow queueing to be disabled through a service method parameter.
- Keep `AuthController` thin and delegate OTP work to the service.

The service method can follow this shape:

```php
public function send(string $email, int $expired, bool $queue = true): string
```

Default behavior should use queued email. For local debugging or special cases, the caller can pass `false` to send immediately.

This design should be implemented only when the project has decided to keep a custom OTP flow.
