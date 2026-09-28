# TOK-62 Location Verification PHPStan Debt QA

## Purpose

This document records the focused removal of seven PHPStan findings from
`GeoapifyService` and `AlamatService`. The change keeps valid buyer and seller
pinpoint requests, stored coordinates, formatted addresses, and API responses
unchanged.

Implementation branch: `task/jd-tok-62`.

## Contract

- Geoapify API key, URL, and timeout values that are scalar or `null` retain
  their existing cast behavior. Arrays and objects now return the existing
  `503` `LOCATION_VERIFICATION_UNAVAILABLE` response before an HTTP request or
  database write.
- `verifyIndonesiaLocation()` declares the actual normalized result shape:
  float coordinates, a nullable string place ID, and a string formatted address.
  Provider response handling is unchanged because the raw PHPStan diagnostics
  identified the three Geoapify findings at the configuration casts.
- `locationAttributes()` requires a request already checked with
  `locationRules()`. The buyer address and seller company callers both meet
  this precondition. Local type annotations express the validated numeric
  coordinates and string address detail without changing request reads, casts,
  provider calls, or the constructed address.
- No migration, seeder, frontend change, or deployment configuration change
  is required.

## Baseline Reduction

| Metric | Before | After |
| --- | ---: | ---: |
| Findings | 207 | 200 |
| Baseline entries | 149 | 144 |
| Files represented | 14 | 12 |
| Findings in the two scoped services | 7 | 0 |

An analysis of the two services without the baseline located the seven findings
at `GeoapifyService` lines 28, 37, and 38 and `AlamatService` lines 73, 74, 76,
and 79 before the changes. The same analysis passed after the changes. Only the
five ignore entries for these services were removed from the configured
baseline; no ignore was added or widened.

## Local Verification

| ID | Status | Verification | Evidence |
| --- | --- | --- | --- |
| TOK-62-BE-01 | ✅ | Verify malformed Geoapify configuration stops before provider access or address writes. | `AddressLocationTest` covers array and object values for each of the key, URL, and timeout settings. All six cases return `503` with `LOCATION_VERIFICATION_UNAVAILABLE`; no HTTP request or address row is created. |
| TOK-62-BE-02 | ✅ | Preserve buyer and seller location, audit, and provider-failure behavior. | `AddressLocationTest`, `AddressAuditLogTest`, and `CompanyAuditLogTest` passed: 38 tests, 225 assertions. |
| TOK-62-BE-03 | ✅ | Check the complete backend test suite. | PHP 8.3 `artisan test` passed: 258 tests, 3,382 assertions. |
| TOK-62-BE-04 | ✅ | Check static analysis and baseline matching. | PHP 8.3 `composer analyse` passed with `[OK] No errors`; `level: max` and `reportUnmatchedIgnoredErrors: true` remain enabled. |
| TOK-62-BE-05 | ✅ | Check formatting and the working diff. | PHP 8.3 `composer format:check` passed for 178 files; scoped Pint checks and `git diff --check` passed. |

## Verification Boundary

Feature tests use the local in-memory SQLite test database and fake Geoapify
responses. This page records local implementation evidence. Task-branch CI,
pull requests, staging and production deployment, runtime health, and Jira
completion remain separate release steps. TOK-62 remains In Progress.
