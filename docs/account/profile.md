# Profile

This document explains the backend API contract for user profile behavior in `Akun Saya`.

## Applies To

Buyer and seller authenticated users.

## Main Files

- `routes/api.php`
- `app/Http/Controllers/UserController.php`
- `app/Services/CompanyService.php`

## Routes

```text
GET /api/user
PUT /api/user/{id}
POST /api/user/image
DELETE /api/user/image
PUT /api/user/account/type
```

## GET /api/user

Returns the authenticated user.

Success response:

```json
{
  "status": 200,
  "user": {}
}
```

Not found response:

```json
{
  "status": 404,
  "message": "User Not Found"
}
```

## PUT /api/user/{id}

Updates user profile fields and TFA status.

Required request fields:

- `name`
- `email`
- `phone`

Optional request fields:

- `jenis_kelamin`
- `tanggal_lahir`
- `tfa`

Validation rules:

- `id` must be a UUID.
- `name` is required and must be a string.
- `email` is required, must be a valid email, max 255 characters, and unique in `users` except the current user.
- `phone` is required, must be a string, max 15 characters, and unique in `users` except the current user.

Success response:

```json
{
  "status": 200,
  "message": "User Update Successfully",
  "user": {}
}
```

Validation error response:

```json
{
  "status": 422,
  "result": "error",
  "message": {}
}
```

## POST /api/user/image

Uploads and replaces the authenticated user's profile image.

Required request fields:

- `id`
- `file`

Validation rules:

- `id` must be a UUID.
- `file` is required.
- `file` must be an image.
- Allowed image MIME extensions: `jpeg`, `png`, `jpg`, `gif`, `svg`.
- Max file size: `1024` KB.

Side effects:

- Deletes the previous file from the public disk when it exists.
- Stores the new image under `user-imgs`.
- Updates `users.img`.

Success response:

```json
{
  "status": 200,
  "message": "Upload Image Successfully",
  "user": {}
}
```

## DELETE /api/user/image

Deletes the current user image by submitted image path.

Required request fields:

- `img`

Validation rules:

- `img` is required and must be a string.

Side effects:

- Finds the user row by `img`.
- Sets `users.img` to `null`.
- Deletes the file from the public disk.

Success response:

```json
{
  "status": 200,
  "message": "Delete Image Success",
  "user": {}
}
```

## PUT /api/user/account/type

Toggles the authenticated user's account type.

Behavior:

- `buyer` changes to `seller`.
- Any other current value changes to `buyer`.
- The response includes the current company payload from `CompanyService`.

Success response:

```json
{
  "status": "success",
  "user": {},
  "company": {},
  "message": "Switch Account Successfully"
}
```

