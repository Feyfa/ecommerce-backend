# Security

This document explains the backend API contract for security behavior in `Akun Saya`.

## Applies To

Buyer and seller authenticated users.

## Main Files

- `routes/api.php`
- `app/Http/Controllers/UserController.php`
- `app/Models/User.php`

## Password

Route:

```text
PUT /api/user/change/password
```

Required request fields:

- `id`
- `oldPassword`
- `newPassword`

Validation rules:

- `id` must be a UUID.
- `oldPassword` is required.
- `newPassword` is required.
- `oldPassword` must match the current stored user password.

Success response:

```json
{
  "result": "success",
  "message": "Change Password Successfully"
}
```

Validation error response:

```json
{
  "status": 422,
  "message": {}
}
```

Old password invalid response:

```json
{
  "result": "error",
  "error_type": "old_password_invalid",
  "message": "Old Password Invalid"
}
```

## TFA

TFA is currently updated through:

```text
PUT /api/user/{id}
```

Request field:

- `tfa`

Current accepted frontend values:

- `F`
- `Email`
- `Phone`

Backend note:

- `updateUser` stores the submitted `tfa` value directly.
- There is currently no backend enum validation for TFA values.
- If stricter TFA validation is added later, update the frontend select options and this document together.

