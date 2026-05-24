# Authentication Notes

This document explains the current authentication state of the backend and records the pending work around TFA, Messend, and the future Clerk migration.

The goal is not to redesign authentication immediately. The goal is to make the current risk visible so future work can be planned without forgetting why TFA is temporarily unsafe to rely on.

## Current Login Flow

The backend currently uses Laravel Sanctum for API authentication.

The normal login flow is:

1. The frontend sends email and password to `POST /api/login`.
2. The backend validates the credentials.
3. If the user does not use TFA, the backend creates a Sanctum token.
4. The frontend stores the token, user data, and company data in local storage.
5. Authenticated requests use the Sanctum bearer token.

This flow still works when the user has TFA disabled.

## Current TFA Flow

The user table has a `tfa` column.

Current known values:

- `F`: TFA is disabled.
- `Email`: TFA should send an OTP to the user's email.
- `Phone`: currently excluded from the email OTP flow.

When `tfa` is set to `Email`, the backend does not generate and verify OTP locally. Instead, it delegates the OTP work to the old Messend package.

The current backend TFA flow is:

1. The frontend calculates an expiration timestamp.
2. The backend sends the email, expiration timestamp, and secret key to Messend.
3. Messend generates the OTP and returns an `otp_secret_key`.
4. The backend sends the OTP email through Messend.
5. The frontend opens the OTP modal.
6. The user enters the OTP.
7. The backend asks Messend to verify the OTP.
8. If Messend accepts the OTP, the backend creates a Sanctum token.

## Current Problem

The Messend package was a personal package created for an older project.

It still calls the old hosted Messend API. That hosted service is no longer active, so the TFA flow can fail during login.

The observed error is a cURL connection failure while calling the hosted OTP endpoint. In practice, this means:

- Email and password can be correct.
- The user can still fail to log in.
- The failure happens after the backend tries to generate the OTP.
- The application depends on an external service that is no longer maintained.

This is why TFA should not be used for local testing right now.

## Temporary Local Workaround

For current PostgreSQL migration testing, keep TFA disabled.

Use:

```text
tfa = F
```

This keeps the normal login flow working while database, product, cart, checkout, and payment flows are tested.

This is a temporary workaround, not the final authentication design.

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
2. Keep TFA disabled during local testing.
3. Migrate authentication to Clerk in a dedicated task.
4. Remove the old Messend dependency after Clerk replaces the current login/TFA behavior.
5. Revisit email OTP only if the application still needs a custom OTP flow after Clerk integration.

## Decision For Now

Do not fix Messend inside the current migration task.

Do not build a new local OTP system yet.

Do not remove Messend yet unless the authentication migration task is actively being worked on.

The current practical decision is:

```text
TFA is temporarily disabled for local development and testing.
The Messend-based TFA flow is pending replacement.
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
