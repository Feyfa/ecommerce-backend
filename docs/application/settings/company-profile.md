# Company Profile

This document explains the backend API contract for seller company profile behavior in `Pengaturan`.

## Applies To

Seller account context.

## Main Files

- `routes/api.php`
- `app/Http/Controllers/CompanyController.php`
- `app/Services/CompanyService.php`
- `app/Models/Company.php`
- `app/Models/Alamat.php`

## Routes

```text
GET /api/company
PUT /api/company
POST /api/company/image
DELETE /api/company/image
```

## GET /api/company

Returns formatted seller company data for the authenticated user.

`CompanyService` also reads the seller address from `alamats` where:

- `user_id` equals the authenticated user id;
- `type` equals `seller`;
- `enable` equals `1`.

The response includes `location_source`, latitude, longitude, Geoapify place id,
formatted address, and seller-provided address detail so the frontend can reopen
the saved pinpoint.

Success response:

```json
{
  "status": "success",
  "company": {}
}
```

## PUT /api/company

Updates seller company data and seller address.

Required request fields:

- `name`
- `email`
- `phone`
- `location_source` (`map`)
- `latitude`, `longitude`, and `address_detail`

Optional request fields:

- `description`

Validation rules:

- `name` is required and must be a string.
- Coordinates must be valid and address detail is required.
- The backend reverse-geocodes the coordinate, accepts only Indonesia, and
  persists the provider address instead of client address metadata.
- Provider/configuration failures return `503` with
  `LOCATION_VERIFICATION_UNAVAILABLE` without partially updating the profile.
- `email` is required, must be a valid email, max 255 characters, and must not exist in another `users` row or another `companies` row.
- `phone` is required, must be a string, max 20 characters, and must not exist in another `users` row or another `companies` row.

Side effects:

- `companies` is updated or created by `user_id`.
- Seller address is updated or created in `alamats` with `type = seller` and `enable = 1`.
- The backend builds the final display address for pinpoint mode.

Success response:

```json
{
  "status": "success",
  "message": "Company Update Successfully",
  "company": {}
}
```

Validation error response:

```json
{
  "status": "error",
  "message": {}
}
```

## POST /api/company/image

Uploads and replaces the authenticated seller company image.

Required request fields:

- `file`

Validation rules:

- `file` is required.
- `file` must be an image.
- Allowed image MIME extensions: `jpeg`, `png`, `jpg`, `gif`, `svg`.
- Max file size: `1024` KB.

Side effects:

- Deletes the previous company image from the public disk when it exists.
- Stores the new image under `company-imgs`.
- Updates or creates `companies.img` for the authenticated user.

Success response:

```json
{
  "status": "success",
  "message": "Upload Image Successfully",
  "company": {}
}
```

## DELETE /api/company/image

Deletes the authenticated seller company image.

Side effects:

- Deletes the existing company image from the public disk.
- Sets `companies.img` to `null`.

Success response:

```json
{
  "status": "success",
  "message": "Delete Image Success",
  "company": {}
}
```

Error responses:

- `400` with `Company Is Empty` when the company row does not exist.
- `400` with `Delete Image Error, Path File Empty` when the image file path is empty or missing on disk.

## QA Coverage

- [TOK-8 Pinpoint Address QA](../../qa/tok-8-pinpoint-address.md) tracks backend
  store-location verification; the matching frontend checklist is available at
  `frontend-repo:/docs/qa/tok-8-pinpoint-address.md`.
