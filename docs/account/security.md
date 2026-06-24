# Security

This document explains the backend security boundary for `Akun Saya` after the Clerk migration.

## Applies To

Buyer and seller authenticated users.

## Main Files

- `routes/api.php`
- `app/Http/Controllers/UserController.php`
- `app/Models/User.php`

## Current Behavior

The backend no longer exposes local password-change or local TFA update endpoints for the active auth flow.

Current rules:

- password authentication is owned by the identity provider;
- MFA should be configured in the identity provider if the product enables it later;
- `PUT /api/user/change/password` has been removed;
- `PUT /api/user/{id}` no longer accepts or stores the old local `tfa` payload.

The old `users.password` and `users.tfa` columns may still exist for historical schema compatibility, but they should not be treated as the active account-security contract.
