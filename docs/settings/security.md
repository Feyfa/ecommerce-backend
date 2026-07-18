# Security

This document explains the backend API contract for the Clerk-backed `Keamanan` settings page.

## Applies To

Buyer and seller authenticated users.

## Main Files

- `routes/api.php`
- `app/Http/Controllers/SecurityController.php`
- `app/Services/Clerk/ClerkSecurityService.php`
- `app/Http/Middleware/AuthenticateApiRequest.php`
- `app/Services/Clerk/ClerkBackendClientService.php`
- `app/Models/User.php`

## Current Behavior

The backend no longer exposes local password-change or local TFA update endpoints for the active auth flow.

Current rules:

- password authentication is owned by Clerk;
- password setup and password update are performed through Clerk user methods from the frontend;
- MFA/TOTP is owned by Clerk;
- passkeys are owned by Clerk;
- active session data is read from Clerk through the backend SDK;
- Laravel exposes safe security endpoints that verify the authenticated local user boundary before returning or mutating Clerk-owned state;
- `PUT /api/user/change/password` has been removed;
- `PUT /api/user/{id}` no longer accepts or stores the old local `tfa` payload.

The old `users.password`, `users.remember_token`, `users.email_verified_at`, and
`users.tfa` columns, together with the local `password_reset_tokens` table, were
removed through `2026_07_13_000001_remove_legacy_local_auth_schema.php`. Clerk
remains the only account-security and password-reset authority.

## Routes

```text
GET  /api/security/summary
GET  /api/security/sessions
POST /api/security/google/link/validate
POST /api/security/sessions/{sessionId}/revoke
POST /api/security/sessions/revoke-others
```

All security routes are protected by `auth.api`.

## GET /api/security/summary

Returns Clerk-owned sign-in method and additional protection status for the authenticated Clerk user.

Success response:

```json
{
  "status": 200,
  "message": "Security summary retrieved successfully.",
  "security": {
    "sign_in_methods": [
      {
        "key": "password",
        "label": "Password",
        "status": "active",
        "status_label": "Aktif",
        "description": "Gunakan email utama akun ini untuk masuk dengan password.",
        "action_label": "Ubah password",
        "is_enabled": true
      },
      {
        "key": "google",
        "label": "Google",
        "status": "connected",
        "status_label": "Terhubung",
        "description": "Masuk menggunakan akun Google.",
        "action_label": "",
        "is_enabled": true
      },
      {
        "key": "passkey",
        "label": "Passkey",
        "status": "active",
        "status_label": "Aktif",
        "description": "Gunakan biometrik atau PIN perangkat untuk login lebih aman.",
        "action_label": "Kelola",
        "is_enabled": true,
        "meta": {
          "total": 1,
          "passkeys": []
        }
      }
    ],
    "additional_protections": [
      {
        "key": "mfa",
        "label": "Two-Factor Authentication",
        "status": "inactive",
        "status_label": "Belum aktif",
        "description": "Tambahkan verifikasi tambahan menggunakan aplikasi authenticator.",
        "action_label": "Aktifkan",
        "is_enabled": false,
        "meta": {
          "totp_enabled": false,
          "backup_code_enabled": false
        }
      }
    ]
  }
}
```

Behavior notes:

- Password status comes from Clerk `passwordEnabled`.
- Google status is derived from Clerk external accounts that contain the Google provider.
- Passkey status is derived from Clerk passkey count and includes formatted passkey metadata.
- MFA status is active when Clerk `twoFactorEnabled` or `totpEnabled` is true.

## GET /api/security/sessions

Returns active Clerk sessions for the authenticated user.

The middleware stores the current Clerk session id from the verified token into request attributes. The controller uses that value to mark the current session.

Success response:

```json
{
  "status": 200,
  "message": "Security sessions retrieved successfully.",
  "session_data": {
    "current_session_id": "sess_xxx",
    "sessions": [
      {
        "id": "sess_xxx",
        "status": "active",
        "is_current": true,
        "device_label": "Chrome di Desktop",
        "location_label": "Jakarta, Indonesia",
        "last_active_at": "2026-06-25T20:15:00+00:00",
        "last_active_at_timestamp": 1782422100
      }
    ]
  }
}
```

Behavior notes:

- Only active Clerk sessions are listed.
- Session rows are sorted by newest activity first.
- Device and location labels are formatted by `ClerkSecurityService`.
- The current session is identified by the Clerk session id from the authenticated request.

## POST /api/security/google/link/validate

Validates the Google external account that was linked through Clerk OAuth from the frontend security settings page.

The backend validates that:

- the authenticated Clerk user has at least one Google external account;
- the Google external account email matches the local authenticated user's email;
- the Google email is verified;
- the same Google external account is not already attached to another Clerk user.

If invalid Google accounts are found, the service removes them from the current Clerk user so the local account is not left with the wrong provider link.

Cleanup rules:

- Google and Facebook responses can expose an identification ID (`idn_...`) as the model `id`, while Clerk's delete endpoint requires the external account resource ID (`eac_...`).
- The service resolves `external_account_id` from Clerk's raw user response and keys it by provider plus identification ID so different providers cannot be mixed up.
- A not-found deletion response is only treated as an idempotent success after the latest Clerk user state confirms that the rejected external account is no longer connected.
- The success response returns the resolved `eac_...` resource ID, not the `idn_...` identification ID.

Success response:

```json
{
  "status": 200,
  "message": "Google account linked successfully.",
  "google": {
    "provider": "google",
    "email": "user@example.com",
    "external_account_id": "eac_xxx"
  }
}
```

Validation failure response:

```json
{
  "status": 422,
  "message": "Email Google harus sama dengan email akun Anda."
}
```

## POST /api/security/sessions/{sessionId}/revoke

Revokes one non-current session owned by the authenticated Clerk user.

Authorization rules:

- the session must belong to the authenticated Clerk user;
- the session must not be the current session.

Success response:

```json
{
  "status": 200,
  "message": "Security session revoked successfully.",
  "result": {
    "revoked_session_id": "sess_xxx"
  }
}
```

## POST /api/security/sessions/revoke-others

Revokes all active sessions except the current session.

Success response:

```json
{
  "status": 200,
  "message": "Other security sessions revoked successfully.",
  "result": {
    "revoked_total": 2,
    "revoked_session_ids": [
      "sess_xxx",
      "sess_yyy"
    ]
  }
}
```

## Source of Truth

```text
Clerk
- password sign-in status
- Google external account connection
- MFA/TOTP status
- passkeys
- sessions

Laravel
- authenticated API boundary
- safe frontend-facing security endpoints
- local user ownership checks
- Google link validation against the local user email
```

## Authorization Rules

- The authenticated Clerk user can only see their own sessions.
- The authenticated Clerk user can only revoke their own sessions.
- Session ownership must be checked before calling Clerk revoke APIs.
- The current session should not be revoked by the single-session revoke action.
- `revoke-others` should preserve the current session and revoke only other active sessions.
- Google external account linking must be validated against the local authenticated user's email.

## Out of Scope

The current Clerk-backed security settings implementation does not include:

- local password changes;
- local TFA updates through `users.tfa`;
- OTP Email as custom login protection setting;
- business audit log;
- bank account change history;
- address change history;
- store profile change history;
- account self-delete.

Email code verification during manual registration remains a registration verification flow, not a backend settings security feature.
