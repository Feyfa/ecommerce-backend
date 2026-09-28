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
- `jenis_kelamin` is optional, nullable, and must be a string of at most 20 characters when present.
- `tanggal_lahir` is optional, nullable, and must use the `YYYY-MM-DD` date format when present.
- An empty string for either optional field clears its stored value to `null`.
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
- Allowed image formats: JPEG, PNG, and GIF. SVG is rejected.
- Max file size: `1024` KB.

Side effects:

- Stores the new image under `user-imgs`.
- Laravel `store()` creates a unique filename on the public disk for each image, including rapid replacements with the same extension.
- The generated extension follows the detected image content rather than the submitted filename.
- Updates `users.img`.
- Records `profile.image_uploaded` with title `Foto Profil Diperbarui`.
- Deletes the previous file only after the user update and audit persistence succeed.
- If storage cannot save the new image, the request fails with a server error before changing the user row or recording an audit event. The previous image remains in place.
- If the later database or audit transaction fails, the new file is removed while the previous image remains in place.

Success response:

```json
{
  "status": 200,
  "message": "Foto profil berhasil diunggah.",
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
  "message": "Foto profil berhasil dihapus.",
  "user": {}
}
```

## Buyer/Seller Mode

Buyer/seller active UI mode is handled by the frontend per browser tab. The profile API does not expose an endpoint that mutates account mode in the database.

## QA Coverage

- [TOK-61 User Profile PHPStan and Image Upload Security QA](../../qa/tok-61-user-profile-phpstan.md)
  tracks type-contract, validation, and image-storage regression checks.
