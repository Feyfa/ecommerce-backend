# TOK-61 User Profile PHPStan and Image Upload Security QA

## Purpose

This document records local verification for removing six PHPStan findings from
`UserController` while retaining the profile API response shape, owner scope,
and audit transaction boundary. The two `Validator::make()` calls in
`updateUser()` remain separate so route UUID validation and ownership checks
precede form validation.

The expanded task also restricts profile, company, and product image uploads to
JPEG, PNG, and GIF, and protects public storage from active legacy file types.

Implementation branch: `task/jd-tok-61`.

## Contract

- `GET /api/user` reads the authenticated local `User` before accessing its ID;
  a missing user still yields the documented `404` response when the controller
  is reached without one.
- Profile update still returns `422` for an invalid route UUID, `403` for a
  valid foreign UUID, and `422` for an invalid form owned by the caller. Gender
  is a nullable string of at most 20 characters; birth date is nullable and
  uses `YYYY-MM-DD`. Empty strings clear either optional field to `null`.
- An image upload requires one validated `UploadedFile` and writes to a unique
  path, including two PNG uploads in the same second. A storage failure stops
  before database or audit changes. An audit failure rolls back the reference
  and removes only the new file; successful replacement removes the old file
  after the transaction commits.
- Profile, company, and product images use Laravel's `store()` filenames. The
  profile and company flows explicitly choose the public disk; a JPEG submitted
  as `.html` is stored as `.jpg`. SVG is rejected before storage, database, or
  audit changes in all three upload flows.
- Nginx rejects requests for active file extensions under `/storage/`, including
  legacy SVG and HTML paths, before the generic PHP handler. Remaining image
  responses carry `X-Content-Type-Options: nosniff`.
- Three frontend image pickers offer and check JPEG, PNG, and GIF. Backend
  validation remains authoritative. No migration or schema change is needed.

## Baseline Reduction

| Metric | Before | After |
| --- | ---: | ---: |
| Findings | 213 | 207 |
| Baseline entries | 155 | 149 |
| Files represented | 15 | 14 |
| `UserController` findings | 6 | 0 |

PHPStan reported each of the six scoped ignore patterns as unmatched after its
corresponding change. Only those entries were removed; the baseline was not
regenerated and no ignore was added.

## Verification Status

| ID | Status | Verification | Evidence |
| --- | --- | --- | --- |
| TOK-61-BE-01 | ✅ | Preserve profile read behavior. | `ProfileAuditLogTest` confirms a local user receives the profile and a missing user returns `404` when middleware is bypassed. |
| TOK-61-BE-02 | ✅ | Preserve ownership precedence and validate optional inputs. | Focused tests confirm invalid UUID `422`, foreign UUID `403` even with a bad form, owned bad form `422`, nullable and empty optional fields, and rejection of invalid gender/date values without audit. |
| TOK-61-BE-03 | ✅ | Preserve image and audit state across success and failures. | Focused tests cover two PNG uploads at a frozen second, replacement/deletion, rejection of a foreign user ID or an array of files, storage returning `false`, and audit rollback that retains the old image while removing the new file. |
| TOK-61-BE-04 | ✅ | Check configured static analysis and baseline. | PHP 8.3 `composer analyse` passed with `[OK] No errors` after removing six unmatched entries. |
| TOK-61-BE-05 | ✅ | Check PHP formatting and automated tests. | PHP 8.3 `composer format:check` passed for 178 PHP files. Focused profile, company, and product tests passed: 36 tests, 252 assertions. Full PHP 8.3 `artisan test` passed: 257 tests, 3,358 assertions. |
| TOK-61-BE-06 | ✅ | Review final diff and whitespace. | Backend and frontend scoped diffs were reviewed; `git diff --check` passed in both repositories. |
| TOK-61-BE-07 | ✅ | Reject active uploads and retain image state. | Profile/company tests reject SVG named `.html`, store JPEG bytes named `.html` with a `.jpg` path using Laravel's generated name, and preserve audit boundaries. Product create/update tests reject SVG before changing files, rows, or audit. |
| TOK-61-BE-08 | ✅ | Verify frontend build and local Nginx routing. | Frontend lint and Prettier checks passed; 25 unit tests passed; Vite build succeeded. Local Nginx syntax check passed using a temporary copy with its Docker-only upstream replaced by localhost. On localhost port 18080, JPEG returned `200` with `nosniff`; SVG, HTML, and PHP each returned `404`. The temporary server was stopped. |

## Verification Boundary

Feature tests use the repository's in-memory SQLite configuration and a fake
public disk. This page records local implementation evidence before remote
release; task-branch CI, pull requests, deployment, and runtime checks are
tracked separately on Jira TOK-61.

Read-only SSH inventory on September 28, 2026 found 13 files on staging and 5
on production in the active `public` disk volumes. Neither environment had a
file ending in `.svg` or another extension denied by the new Nginx rule.
Detected file contents included no SVG. Counts of paths ending in those denied
extensions were zero across `users.img`, `companies.img`, `products.img`, and
`product_images.path` on both databases. One existing WebP file on staging is
outside the new upload formats but remains servable. This is a point-in-time
inventory, not a guarantee about future uploads or browser caches.

The local HTTP check used a temporary Nginx configuration and does not prove
the behavior of the deployed Docker containers. Task-branch CI, staging and
production runtime checks, and browser smoke testing remain separate release
steps.
