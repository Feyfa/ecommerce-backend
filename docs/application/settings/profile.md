# Profile

This document explains the backend API contract for user profile behavior in `Pengaturan`.

## Applies To

Buyer and seller authenticated users.

## Main Files

- `routes/api.php`
- `app/Http/Controllers/UserController.php`

## Routes

```text
GET /api/user
PUT /api/user/{id}
POST /api/user/image
DELETE /api/user/image
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

Updates profile fields for the authenticated user only.

Required request fields:

- `phone`

Optional request fields:

- `jenis_kelamin`
- `tanggal_lahir`

Validation rules:

- `id` must be a UUID.
- `id` must match the authenticated user.
- `phone` is required, must be a string, max 15 characters, and unique in `users` except the current user.
- `email` is not accepted by this endpoint because account email is synchronized from the authentication provider.
- `name` is not accepted by this endpoint because account name is synchronized from the authentication provider.

Success response:

```json
{
  "status": 200,
  "message": "User Update Successfully",
  "user": {}
}
```

### Audit Log

Each successful profile settings update records `profile.updated` with the
user-facing title `Pengaturan Pengguna Diperbarui`. The audit snapshot includes
only `phone`, `tanggal_lahir`, and `jenis_kelamin`; `name` and `email` are
excluded because they are managed by the authentication provider.

An update without value changes still records one event with an empty `changes`
list. Phone numbers are masked in the Audit Log collection and exposed in full
only through the owner-scoped detail response.

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
- `id` must match the authenticated user.
- `file` is required.
- `file` must be an image.
- Allowed image MIME extensions: `jpeg`, `png`, `jpg`, `gif`, `svg`.
- Max file size: `1024` KB.

Side effects:

- Stores the new image under `user-imgs`.
- Updates `users.img`.
- Records `profile.image_uploaded` with title `Foto Profil Diperbarui`.
- Deletes the previous file only after the user update and audit persistence succeed.

Success response:

```json
{
  "status": 200,
  "message": "Upload Image Successfully",
  "user": {}
}
```

## DELETE /api/user/image

Deletes the authenticated user's current image by submitted image path.

Required request fields:

- `img`

Validation rules:

- `img` is required and must be a string.
- `img` must match the authenticated user's current image path.

Side effects:

- Sets `users.img` to `null`.
- Deletes the file from the public disk.
- Records `profile.image_deleted` with title `Foto Profil Dihapus`.

Profile photo audit stores only `has_profile_image`; path, URL, and historical
image preview are never stored or exposed through Audit Log.

Success response:

```json
{
  "status": 200,
  "message": "Delete Image Success",
  "user": {}
}
```

## Buyer/Seller Mode

Buyer/seller active UI mode is handled by the frontend per browser tab. The profile API does not expose an endpoint that mutates account mode in the database.
