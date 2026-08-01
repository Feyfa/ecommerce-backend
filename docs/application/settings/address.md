# Address

This document explains the backend API contract for buyer address behavior in `Pengaturan`.

## Applies To

Buyer account context.

## Main Files

- `routes/api.php`
- `app/Http/Controllers/AlamatController.php`
- `app/Models/Alamat.php`

## Routes

```text
GET /api/alamat/buyer
POST /api/alamat/buyer
PUT /api/alamat-enable/buyer/{id}
PUT /api/alamat/buyer/{id}
DELETE /api/alamat/buyer/{id}
```

Buyer address rows use `alamats.type = buyer`.

## GET /api/alamat/buyer

Returns up to 5 buyer address rows for the authenticated user.

Optional query/request fields:

- `searchAlamat`

Search fields:

- `place`
- `name`
- `phone`
- `alamat`

Search uses PostgreSQL `ILIKE`, so it is case-insensitive.

Ordering:

- enabled/default address first.

Success response:

```json
{
  "result": "suceess",
  "alamats": []
}
```

The current response contains the typo `suceess`. Do not change frontend assumptions without coordinating this response shape.

## POST /api/alamat/buyer

Adds a buyer address.

Required request fields:

- `place`
- `name`
- `phone`
- `enable`
- `location_source` (`map`)
- `latitude`, `longitude`, and `address_detail`

Optional map field:

- `geoapify_place_id`

Validation rules:

- All required fields must be present.
- A buyer may have at most 5 buyer addresses.
- Latitude must be between `-90` and `90` and longitude between `-180` and `180`.
- Address detail is required.
- The backend reverse-geocodes the coordinate with Geoapify, requires
  `country_code=id`, and combines the detail with the provider address.
- Client `formatted_address` and `geoapify_place_id` values are not trusted.
- Provider/configuration failures return `503` with
  `LOCATION_VERIFICATION_UNAVAILABLE`; the write is not applied.

Side effects:

- If `enable` is true, existing enabled buyer addresses are set to `0`.
- If this is the first buyer address, `enable` is forced to `1`.
- The response returns the refreshed address list.

Success response:

```json
{
  "result": "success",
  "alamats": [],
  "message": "Alamat Berhasil Ditambah"
}
```

## PUT /api/alamat-enable/buyer/{id}

Sets the selected/default buyer address.

Validation rules:

- The address must belong to the authenticated user.
- The address must have `type = buyer`.
- The address must be a verified pinpoint. Legacy manual rows return `409` with
  `ADDRESS_REQUIRES_VERIFICATION` and remain unchanged.

Side effects:

- All buyer addresses are disabled.
- The selected address is enabled.
- The response returns the refreshed list and current enabled address.

Success response:

```json
{
  "result": "success",
  "alamats": [],
  "currentAlamat": {},
  "message": "Alamat Berhasil Dipilih"
}
```

## PUT /api/alamat/buyer/{id}

Updates a buyer address.

Required request fields:

- `place`
- `name`
- `phone`
- `location_source=map`, `latitude`, `longitude`, and `address_detail`

Validation rules:

- All required fields must be present.
- The address must belong to the authenticated user.
- The address must have `type = buyer`.

Success response:

```json
{
  "result": "success",
  "alamats": [],
  "message": "Alamat Berhasil Diubah"
}
```

## DELETE /api/alamat/buyer/{id}

Deletes a buyer address.

Side effects:

- Deletes only an address matching the id, authenticated `user_id`, and `type = buyer`.
- Deletion and fallback selection are atomic with the remaining buyer-address rows.
- If no enabled address remains, the newest verified Pinpoint address is enabled.
- Legacy manual rows remain disabled when no verified fallback exists.
- The response returns the refreshed address list.

Success response:

```json
{
  "result": "success",
  "alamats": [],
  "message": "Alamat Berhasil Dihapus"
}
```

## QA Coverage

- [TOK-8 Pinpoint Address QA](../../qa/tok-8-pinpoint-address.md) tracks backend
  buyer-address verification; the matching frontend checklist is available at
  `frontend-repo:/docs/qa/tok-8-pinpoint-address.md`.
