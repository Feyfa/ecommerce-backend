# TOK-49 AlamatController PHPStan Debt QA

## Purpose

This document tracks the focused removal of the 34 PHPStan findings previously
assigned to `AlamatController`. The change narrows authenticated-user and search
input types without changing address ownership, transactions, audit behavior,
database queries, HTTP responses, or public API contracts.

Revision under review:

- branch: `task/jd-tok-49`;
- commit: this document is included in the reviewed TOK-49 implementation commit.

Status legend: ✅ verified, ⬜ not verified yet.

## Implementation Contract

- `resolveAuthenticatedUser()` accepts the request user only when it is the
  local `User` model and its database row still exists. The same model supplies
  the owner ID and actor for address audit events.
- `applyBuyerAddressSearch()` resolves `searchAlamat` as a string before using
  `trim()` or interpolating the value into the existing PostgreSQL `ILIKE`
  conditions for `place`, `name`, `phone`, and `alamat`.
- Existing empty-value handling and the untrimmed search keyword are preserved.
- The historical TOK-41 QA record remains unchanged. TOK-49 owns this
  follow-up implementation and its release evidence.

## Baseline Reduction

| Metric | Before | After |
| --- | ---: | ---: |
| Findings | 357 | 323 |
| Baseline entries | 238 | 231 |
| Files represented | 33 | 32 |
| `AlamatController` findings | 34 | 0 |

The seven removed baseline entries represented five mixed user-ID property
accesses, five invalid `trim()` arguments, four nullable audit actors, and 20
mixed search-string interpolations. No ignore rule was added or widened, and
the baseline was not regenerated.

## Verification Status

| ID | Status | Verification | Evidence |
| --- | --- | --- | --- |
| TOK-49-BE-01 | ✅ | Verify the focused baseline reduction with unmatched-ignore reporting enabled. | `composer analyse` passed with no errors after all seven stale `AlamatController` entries were removed. The baseline now contains 323 findings across 231 entries and 32 files. |
| TOK-49-BE-02 | ✅ | Review authenticated-user narrowing and audit actor reuse. | All five buyer address endpoints reject a missing or non-local user with the existing `401` response. Create, update, delete, and select audit calls reuse the verified `User` model. |
| TOK-49-BE-03 | ✅ | Review address search narrowing without changing query behavior. | One typed helper applies the existing four PostgreSQL `ILIKE` conditions. Empty-string handling and the original untrimmed keyword remain unchanged. |
| TOK-49-BE-04 | ✅ | Run PHP syntax and scoped Pint checks for the changed controller. | PHP 8.3 syntax validation and `vendor/bin/pint --test app/Http/Controllers/AlamatController.php` passed. |
| TOK-49-BE-05 | ✅ | Run focused address location and audit regression suites. | `AddressLocationTest` and `AddressAuditLogTest` passed 27 tests and 111 assertions. |
| TOK-49-BE-06 | ✅ | Run repository-wide formatting, tests, and diff validation. | `composer format:check` passed 172 files, the full backend suite passed 193 tests and 1,275 assertions, and `git diff --check` passed. |
| TOK-49-BE-07 | ⬜ | Run task-branch backend CI. | Pending user review, commit, and push. |
| TOK-49-BE-08 | ⬜ | Promote and validate the change in staging. | Pending task-branch CI, staging integration, deployment, and runtime validation. |
| TOK-49-BE-09 | ⬜ | Promote and validate the change in production. | Pending successful staging evidence, production merge, deployment, health checks, and repository synchronization. |

## Test Environment Limitation

The standard automated suite uses SQLite, while the existing address search
query intentionally uses PostgreSQL `ILIKE`. A non-empty HTTP search case would
therefore test an unsupported SQLite operator rather than this type-narrowing
change. Static analysis verifies the string contract, the diff preserves the
four production query conditions, and deployed PostgreSQL search remains part
of the staging runtime verification.

## Release Status

No migration, seeder, frontend change, or deployment-configuration change is
required. The Jira task must remain In Progress until CI, staging, production,
health checks, and final local synchronization have all succeeded.
