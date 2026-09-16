# TOK-50 ProductController PHPStan Debt QA

## Purpose

This document tracks the focused removal of the 10 PHPStan findings previously
assigned to `ProductController`. The change documents the authenticated-user,
pagination, and image-ID contracts without changing authorization, queries,
transactions, storage, audit behavior, API responses, or public contracts.

Revision under review:

- branch: `task/jd-tok-50`;
- commit: this document is included in the reviewed TOK-50 implementation commit.

Status legend: ✅ verified, ⬜ not verified yet.

## Implementation Contract

- `authenticatedUser()` exposes the local `User` already guaranteed and attached
  to the request by the `auth.api` middleware. It does not perform another query
  or introduce a new response branch.
- Product list, detail, create, update, and delete operations reuse that user for
  ownership checks. Create, update, and delete audit events reuse the same actor.
- A next cursor is encoded only when lookahead proves another batch exists. The
  last retained product is typed locally without changing the collection query,
  page size, cursor payload, or response metadata.
- Existing and retained product-image IDs are documented as string UUID arrays.
  The comparison, filtering, counts, cover detection, and order detection remain
  unchanged.

## Baseline Reduction

| Metric | Before | After |
| --- | ---: | ---: |
| Findings | 323 | 313 |
| Baseline entries | 231 | 225 |
| Files represented | 32 | 31 |
| `ProductController` findings | 10 | 0 |

The six removed baseline entries represented five nullable authenticated-user
property accesses, three nullable audit actors, one nullable cursor product, and
one image-ID callback type mismatch. No ignore rule was added or widened, and
the baseline was not regenerated.

## Verification Status

| ID | Status | Verification | Evidence |
| --- | --- | --- | --- |
| TOK-50-BE-01 | ✅ | Verify the focused baseline reduction with unmatched-ignore reporting enabled. | `composer analyse` passed with no errors after the six stale `ProductController` entries were removed. The baseline now contains 313 findings across 225 entries and 31 files. |
| TOK-50-BE-02 | ✅ | Review authenticated-user narrowing and audit actor reuse. | All five product endpoints use the `User` guaranteed by `auth.api`; create, update, and delete pass the same user to audit recording without adding queries or response branches. |
| TOK-50-BE-03 | ✅ | Review next-cursor narrowing without changing pagination behavior. | Cursor encoding remains guarded by the existing lookahead result, and terminal responses still return `next_cursor: null`. |
| TOK-50-BE-04 | ✅ | Review product-image ID typing and readable method-chain formatting. | Existing and retained IDs are typed as string UUID arrays; filtering, count, cover, and order comparisons are unchanged. |
| TOK-50-BE-05 | ✅ | Run PHP syntax and scoped Pint checks for the changed controller. | PHP 8.3 syntax validation and `vendor/bin/pint --test app/Http/Controllers/ProductController.php` passed. |
| TOK-50-BE-06 | ✅ | Run focused product, availability, image, filtering, audit, and cursor regression suites. | The five focused feature files passed 63 tests and 687 assertions. |
| TOK-50-BE-07 | ✅ | Run repository-wide formatting, tests, and diff validation. | `composer format:check` passed 172 files, the full backend suite passed 193 tests and 1,275 assertions, and `git diff --check` passed. |
| TOK-50-BE-08 | ⬜ | Run task-branch backend CI. | Pending user review, commit, and push. |
| TOK-50-BE-09 | ⬜ | Promote and validate the change in staging. | Pending task-branch CI, staging integration, deployment, and runtime validation. |
| TOK-50-BE-10 | ⬜ | Promote and validate the change in production. | Pending successful staging evidence, production merge, deployment, health checks, and repository synchronization. |

## Release Status

No migration, seeder, frontend change, or deployment-configuration change is
required. Jira TOK-50 must remain To Do or In Progress until implementation
review begins, and it must not move to Done until CI, staging, production,
health checks, and final local synchronization have all succeeded.
